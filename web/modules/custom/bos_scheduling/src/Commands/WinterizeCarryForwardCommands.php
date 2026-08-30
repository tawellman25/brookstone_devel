<?php

declare(strict_types=1);

namespace Drupal\bos_scheduling\Commands;

use Drupal\bos_scheduling\Service\ScheduleWriter;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\user\Entity\User;
use Drush\Commands\DrushCommands;

/**
 * Winterizing 2026 schedule carry-forward.
 *
 * `bos:winterize:plan` — READ-ONLY. Proposes 2026 sprinkler_winterizing
 * schedules from prior-season history (same calendar date → one weekday later, actual
 * technician, and the route order the crew actually DROVE last fall), and writes
 * a reviewable CSV. It writes NO entity — the office edits the CSV, then
 * `bos:winterize:apply` (separate) applies the reviewed file.
 *
 * Signals (chosen from the §0.5 probe, 2026-08-21):
 *  - ORDER: sign-off timestamp (field_date_completed) → clock → status → planned.
 *  - TECH:  actual signer (wo_complete_info.field_signed_off_by) → planned
 *           (scheduling.field_assigned_to) fallback.
 *
 * Never averages across years — most recent source year with data wins; older
 * years supply coverage + disagreement flags only.
 */
final class WinterizeCarryForwardCommands extends DrushCommands {

  private const WINTERIZING = 'sprinkler_winterizing';
  private const COMPLETE_TID = 1097;

  /**
   * Canceled + every done state. Written out in full — a "done set" that
   * omitted Paid (1504) has already produced a live bug class.
   */
  private const EXCLUDED_STATUS_TIDS = [1098, 1097, 1283, 1281, 1504];

  public function __construct(
    private readonly EntityTypeManagerInterface $etm,
    private readonly EntityFieldManagerInterface $efm,
    private readonly Connection $db,
    private readonly ScheduleWriter $writer,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {
    parent::__construct();
  }

  /**
   * Propose 2026 winterizing schedules from prior-season history (read-only CSV).
   *
   * @command bos:winterize:plan
   * @option target-year The season year being scheduled.
   * @option source-years Ordered preference list (recency wins; NOT averaged).
   * @option out CSV output path.
   * @usage drush bos:winterize:plan
   * @usage drush bos:winterize:plan --source-years=2025,2024 --out=/tmp/plan.csv
   */
  public function plan(array $options = ['target-year' => 2026, 'source-years' => '2025,2024', 'out' => NULL]): void {
    $tz = new \DateTimeZone(date_default_timezone_get());
    $targetYear = (int) $options['target-year'];
    $sourceYears = array_values(array_filter(array_map('intval', explode(',', (string) $options['source-years']))));
    $out = $options['out'] ?: sys_get_temp_dir() . "/winterize_plan_{$targetYear}_" . date('Ymd_His') . '.csv';

    // ── 1. Candidate WOs: winterizing, created this SEASON, not excluded,
    //    without a scheduling record. Authority for "the target set" is WO
    //    `created` from Apr 1 through Dec 31 of the target year. The Apr-1 floor
    //    excludes off-season catch-ups — a WO created Jan–Mar is a prior-season
    //    winterize done late (e.g. a property forgotten in the fall and finally
    //    done in February), not part of this season's cycle.
    $yearStart = (new DrupalDateTime("$targetYear-04-01 00:00:00", $tz))->getTimestamp();
    $yearEnd = (new DrupalDateTime("$targetYear-12-31 23:59:59", $tz))->getTimestamp();
    $woIds = array_map('intval', $this->etm->getStorage('work_order')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::WINTERIZING)
      ->condition('created', $yearStart, '>=')
      ->condition('created', $yearEnd, '<=')
      ->sort('id', 'ASC')
      ->execute());

    $alreadyScheduled = $this->scheduledWoIds($woIds);
    $candidates = [];
    foreach ($this->etm->getStorage('work_order')->loadMultiple($woIds) as $wo) {
      $id = (int) $wo->id();
      $status = $wo->get('field_status')->isEmpty() ? 0 : (int) $wo->get('field_status')->target_id;
      if (in_array($status, self::EXCLUDED_STATUS_TIDS, TRUE)) {
        continue;
      }
      if (isset($alreadyScheduled[$id])) {
        continue;
      }
      $candidates[$id] = $wo;
    }
    $this->io()->text(sprintf('Target %d: %d winterizing WOs, %d already scheduled → %d candidates.',
      $targetYear, count($woIds), count($alreadyScheduled), count($candidates)));
    if (!$candidates) {
      $this->io()->warning('No candidates — are the ' . $targetYear . ' winterizing WOs created yet?');
      return;
    }

    // ── 2. Bulk-gather prior winterizing history for the candidate properties. ──
    $propByWo = [];
    foreach ($candidates as $id => $wo) {
      $propByWo[$id] = $wo->get('field_property')->isEmpty() ? NULL : (int) $wo->get('field_property')->target_id;
    }
    $candidateProps = array_values(array_unique(array_filter($propByWo)));
    $history = $this->gatherPriorHistory($candidateProps, $sourceYears, $tz);
    $batchDays = $this->batchSignoffGroups($history['signoffs'], $tz);
    $propInfo = $this->propertyInfo($candidateProps);

    // ── 3. Build one row per candidate. ──
    $rows = [];
    foreach ($candidates as $id => $wo) {
      $pid = $propByWo[$id];
      $rows[] = $this->buildRow($wo, $pid, $sourceYears, $targetYear, $history, $batchDays, $propInfo[$pid] ?? [], $tz);
    }

    // ── 3.5 Proximity fill: place no-prior (new-customer) rows on the nearest
    //    confidently-scheduled property's day, UNASSIGNED + flagged, so they land
    //    on the calendar tentatively instead of being scheduled from scratch. ──
    $this->proximityFill($rows, $propInfo);

    // ── 4. Dense route-order rank within each (proposed_date, proposed_tech) group. ──
    $this->assignRouteOrder($rows);

    // ── 5. Sort like the driven route: date, then order, then nickname. ──
    usort($rows, function ($a, $b) {
      return [$a['proposed_date'] ?: '9999', (int) ($a['proposed_route_order'] ?: 9999), $a['property_nickname']]
        <=> [$b['proposed_date'] ?: '9999', (int) ($b['proposed_route_order'] ?: 9999), $b['property_nickname']];
    });

    // ── 6. Write CSV + summary. ──
    $this->writeCsv($out, $rows);
    $this->printSummary($rows, $out);
  }

  /**
   * Apply a reviewed plan CSV as real scheduling records.
   *
   * Re-reads the CSV (does NOT recompute — the edited file is the authority),
   * re-validates every row against LIVE state, and creates scheduling records
   * via the shared writer. Only action=schedule rows are processed. Idempotent:
   * a WO that already has a scheduling record is skipped, so a second run of the
   * same file is a no-op. One bad row never aborts the run.
   *
   * @command bos:winterize:apply
   * @option file The reviewed plan CSV (required).
   * @option actor Office user uid to attribute the scheduling to (required; uid 1
   *   is rejected unless --allow-superuser is passed).
   * @option allow-superuser Permit --actor=1 (the superuser). Off by default so a
   *   batch is not lazily attributed to uid 1; pass it only when uid 1 is the real
   *   person consciously owning the run.
   * @option target-year Season year used for the date-window sanity check.
   * @option limit Apply at most N rows (0 = all) — for a cautious first pass.
   * @usage drush bos:winterize:apply --file=/tmp/winterize_plan_2026.csv --actor=6165 --limit=10
   */
  public function apply(array $options = ['file' => NULL, 'actor' => NULL, 'allow-superuser' => FALSE, 'target-year' => 2026, 'limit' => 0]): void {
    $file = (string) $options['file'];
    $actorUid = (int) $options['actor'];
    $targetYear = (int) $options['target-year'];
    $limit = (int) $options['limit'];
    $tz = new \DateTimeZone(date_default_timezone_get());

    if ($file === '' || !is_readable($file)) {
      $this->io()->error('--file is required and must be readable.');
      return;
    }
    $allowSuperuser = (bool) $options['allow-superuser'];
    if ($actorUid < 1) {
      $this->io()->error('--actor is required (the office user uid to attribute the scheduling to).');
      return;
    }
    if ($actorUid === 1 && !$allowSuperuser) {
      $this->io()->error('--actor=1 is the superuser; batch attribution to uid 1 is off by default. Pass --allow-superuser only when uid 1 is the real person consciously owning this run.');
      return;
    }
    $actor = User::load($actorUid);
    if (!$actor || !$actor->isActive()) {
      $this->io()->error("--actor uid $actorUid is not an active user.");
      return;
    }
    $winStart = (new DrupalDateTime("$targetYear-08-01 00:00:00", $tz))->getTimestamp();
    $winEnd = (new DrupalDateTime("$targetYear-12-31 23:59:59", $tz))->getTimestamp();

    // Parse CSV into associative rows.
    $fh = fopen($file, 'r');
    $header = fgetcsv($fh);
    if (!$header) {
      $this->io()->error('Empty CSV.');
      fclose($fh);
      return;
    }
    $rows = [];
    while (($line = fgetcsv($fh)) !== FALSE) {
      $rows[] = array_combine($header, array_pad($line, count($header), ''));
    }
    fclose($fh);

    // Attribute + access-check as the office user for the whole run.
    $this->accountSwitcher->switchTo($actor);
    $results = [];
    $ok = 0;
    $skip = 0;
    $rowNum = 1; // header is row 1
    try {
      foreach ($rows as $r) {
        $rowNum++;
        if ($limit > 0 && $ok >= $limit) {
          break;
        }
        [$status, $reason, $schedId] = $this->applyRow($r, $winStart, $winEnd, $tz, $actorUid);
        $results[] = ['wo_id' => $r['wo_id'] ?? '', 'scheduling_id' => $schedId ?? '', 'result' => $status, 'reason' => $reason, 'row' => $rowNum];
        if ($status === 'ok') {
          $ok++;
        }
        else {
          $skip++;
          if ($status !== 'not_schedule_action') {
            $this->io()->text(sprintf('  row %d WO %s: %s — %s', $rowNum, $r['wo_id'] ?? '?', $status, $reason));
          }
        }
      }
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    // Applied-results CSV.
    $appliedPath = preg_replace('/\.csv$/i', '', $file) . '_applied.csv';
    $afh = fopen($appliedPath, 'w');
    fputcsv($afh, ['wo_id', 'scheduling_id', 'result', 'reason', 'row']);
    foreach ($results as $res) {
      fputcsv($afh, [$res['wo_id'], $res['scheduling_id'], $res['result'], $res['reason'], $res['row']]);
    }
    fclose($afh);

    $this->io()->newLine();
    $this->io()->success(sprintf('Applied %d scheduling records · %d skipped.', $ok, $skip));
    $this->io()->text('Results CSV: ' . $appliedPath);
    if ($limit > 0) {
      $this->io()->note("--limit=$limit was set; re-run without --limit to apply the rest.");
    }
  }

  /**
   * Validate one CSV row against live state and (if valid) create the record.
   *
   * @return array{0:string,1:string,2:?int} [result, reason, scheduling_id]
   */
  private function applyRow(array $r, int $winStart, int $winEnd, \DateTimeZone $tz, int $actorUid): array {
    if (($r['action'] ?? '') !== 'schedule') {
      return ['not_schedule_action', 'action=' . ($r['action'] ?? ''), NULL];
    }
    $woId = (int) ($r['wo_id'] ?? 0);
    if (!$woId) {
      return ['invalid', 'no wo_id', NULL];
    }
    $wo = $this->etm->getStorage('work_order')->load($woId);
    if (!$wo || $wo->bundle() !== self::WINTERIZING) {
      return ['invalid', 'WO missing or wrong bundle', NULL];
    }
    $status = $wo->get('field_status')->isEmpty() ? 0 : (int) $wo->get('field_status')->target_id;
    if (in_array($status, self::EXCLUDED_STATUS_TIDS, TRUE)) {
      return ['excluded_status', 'WO status ' . $status, NULL];
    }
    if ($this->writer->hasSchedule($woId)) {
      return ['already_scheduled', 'WO already has a scheduling record', NULL];
    }
    // Date: parse + sanity window (guards a fat-fingered year).
    $dateStr = trim((string) ($r['proposed_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
      return ['bad_date', "unparseable proposed_date '$dateStr'", NULL];
    }
    $dt = DrupalDateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' 00:00:00', $tz);
    if (!$dt || $dt->hasErrors()) {
      return ['bad_date', "invalid proposed_date '$dateStr'", NULL];
    }
    $ts = $dt->getTimestamp();
    if ($ts < $winStart || $ts > $winEnd) {
      return ['date_out_of_window', "proposed_date '$dateStr' outside season window", NULL];
    }
    // Tech: blank OR an active teammate.
    $techUid = ($r['proposed_tech_uid'] ?? '') !== '' ? (int) $r['proposed_tech_uid'] : NULL;
    if ($techUid !== NULL) {
      $tech = $this->etm->getStorage('user')->load($techUid);
      if (!$tech || !$tech->isActive() || !in_array('teammates', $tech->getRoles(), TRUE)) {
        return ['bad_tech', "tech uid $techUid not an active teammate", NULL];
      }
    }
    $order = ($r['proposed_route_order'] ?? '') !== '' ? (int) $r['proposed_route_order'] : NULL;

    $result = $this->writer->schedule($woId, $ts, [
      'teammate_uid' => $techUid,
      'order' => $order,
      'firm' => FALSE,
      'notify' => FALSE,
      'uid' => $actorUid,
      'scheduling_note' => $this->carryNote($r),
    ]);
    if ($result['status'] !== 'ok') {
      return [$result['status'], 'writer skipped', $result['scheduling_id']];
    }
    return ['ok', '', $result['scheduling_id']];
  }

  private function carryNote(array $r): string {
    $prior = trim((string) ($r['prior_date'] ?? ''));
    if ($prior !== '') {
      return sprintf('Carried forward from %s (%s) — %s route.',
        $prior, $r['prior_weekday'] ?? '', $r['source_year'] ?? '');
    }
    $note = trim((string) ($r['note'] ?? ''));
    return $note !== '' ? $note : 'Auto-scheduled (winterizing carry-forward).';
  }

  // ==========================================================================

  /** WO ids that already have a scheduling record → set [id => TRUE]. */
  private function scheduledWoIds(array $woIds): array {
    $out = [];
    foreach (array_chunk($woIds, 400) as $ch) {
      if (!$ch) {
        continue;
      }
      $ids = $this->etm->getStorage('scheduling')->getQuery()->accessCheck(FALSE)
        ->condition('field_work_order', $ch, 'IN')->sort('id', 'ASC')->execute();
      foreach ($this->etm->getStorage('scheduling')->loadMultiple($ids) as $s) {
        if (!$s->get('field_work_order')->isEmpty()) {
          $out[(int) $s->get('field_work_order')->target_id] = TRUE;
        }
      }
    }
    return $out;
  }

  /**
   * Gather, per property + source year, the winning prior winterizing record
   * (scheduling + sign-off + order signals). Returns:
   *   ['prop' => [pid => [year => record]], 'signoffs' => [ [ts,uid], ... ] ]
   */
  private function gatherPriorHistory(array $props, array $sourceYears, \DateTimeZone $tz): array {
    if (!$props || !$sourceYears) {
      return ['prop' => [], 'signoffs' => []];
    }
    $minStart = (new DrupalDateTime(min($sourceYears) . '-08-15 00:00:00', $tz))->getTimestamp();
    $maxEnd = (new DrupalDateTime(max($sourceYears) . '-12-31 23:59:59', $tz))->getTimestamp();

    // Prior winterizing WOs on these properties (created broadly across sources).
    $priorWoIds = [];
    foreach (array_chunk($props, 400) as $ch) {
      $ids = $this->etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
        ->condition('type', self::WINTERIZING)
        ->condition('field_property', $ch, 'IN')
        ->condition('created', $minStart - 86400 * 60, '>=')
        ->condition('created', $maxEnd, '<=')
        ->sort('id', 'ASC')->execute();
      $priorWoIds = array_merge($priorWoIds, array_map('intval', $ids));
    }
    $priorWoIds = array_values(array_unique($priorWoIds));

    // Map: prior WO → property + the signals.
    $woProp = [];
    foreach (array_chunk($priorWoIds, 300) as $ch) {
      foreach ($this->etm->getStorage('work_order')->loadMultiple($ch) as $wo) {
        $woProp[(int) $wo->id()] = $wo->get('field_property')->isEmpty() ? NULL : (int) $wo->get('field_property')->target_id;
      }
    }
    $sched = $this->schedulingByWo($priorWoIds);
    $signoff = $this->signoffByWo($priorWoIds);
    $clock = $this->clockByWo($priorWoIds);
    $status = $this->statusCompleteByWo($priorWoIds);

    // Assemble per property/year, keeping the highest-scheduling-id record.
    $byProp = [];
    $allSignoffs = [];
    foreach ($priorWoIds as $wid) {
      if (empty($sched[$wid]) || $sched[$wid]['start'] === NULL) {
        continue;
      }
      $pid = $woProp[$wid] ?? NULL;
      if (!$pid) {
        continue;
      }
      $year = (int) DrupalDateTime::createFromTimestamp($sched[$wid]['start'], $tz)->format('Y');
      if (!in_array($year, $sourceYears, TRUE)) {
        continue;
      }
      // Season window for that year.
      $wStart = (new DrupalDateTime("$year-08-15 00:00:00", $tz))->getTimestamp();
      $wEnd = (new DrupalDateTime("$year-12-31 23:59:59", $tz))->getTimestamp();
      if ($sched[$wid]['start'] < $wStart || $sched[$wid]['start'] > $wEnd) {
        continue;
      }
      $rec = [
        'wo_id' => $wid,
        'sched_id' => $sched[$wid]['sid'],
        'start' => $sched[$wid]['start'],
        'assigned' => $sched[$wid]['assigned'],
        'planned_order' => $sched[$wid]['order'],
        'order_set' => $sched[$wid]['order_set'] ?? FALSE,
        'signoff' => $signoff[$wid] ?? NULL,
        'clock' => $clock[$wid] ?? NULL,
        'status' => $status[$wid] ?? NULL,
        'multiple_scheds' => FALSE,
      ];
      if (isset($signoff[$wid]) && $signoff[$wid]['completed'] !== NULL) {
        $allSignoffs[] = ['ts' => $signoff[$wid]['completed'], 'uid' => $signoff[$wid]['uid']];
      }
      if (!isset($byProp[$pid][$year])) {
        $byProp[$pid][$year] = $rec;
      }
      else {
        // multiple prior WOs / schedules in the same year — highest sched id wins.
        $byProp[$pid][$year]['multiple_scheds'] = TRUE;
        $rec['multiple_scheds'] = TRUE;
        if ($rec['sched_id'] > $byProp[$pid][$year]['sched_id']) {
          $byProp[$pid][$year] = $rec;
        }
      }
    }
    return ['prop' => $byProp, 'signoffs' => $allSignoffs];
  }

  private function schedulingByWo(array $woIds): array {
    $out = [];
    foreach (array_chunk($woIds, 300) as $ch) {
      $ids = $this->etm->getStorage('scheduling')->getQuery()->accessCheck(FALSE)
        ->condition('field_work_order', $ch, 'IN')->sort('id', 'ASC')->execute();
      foreach ($this->etm->getStorage('scheduling')->loadMultiple($ids) as $s) {
        if ($s->get('field_work_order')->isEmpty()) {
          continue;
        }
        $wid = (int) $s->get('field_work_order')->target_id;
        $rec = [
          'sid' => (int) $s->id(),
          'start' => $s->get('field_date')->isEmpty() ? NULL : (int) $s->get('field_date')->value,
          'assigned' => $s->get('field_assigned_to')->isEmpty() ? NULL : (int) $s->get('field_assigned_to')->target_id,
          'order' => $s->get('field_scheduled_oder')->isEmpty() ? NULL : (int) $s->get('field_scheduled_oder')->value,
          'order_set' => $s->hasField('field_route_order_set') && !$s->get('field_route_order_set')->isEmpty() && (bool) $s->get('field_route_order_set')->value,
        ];
        if (!isset($out[$wid]) || $rec['sid'] > $out[$wid]['sid']) {
          $out[$wid] = $rec;
        }
      }
    }
    return $out;
  }

  private function signoffByWo(array $woIds): array {
    $out = [];
    foreach (array_chunk($woIds, 300) as $ch) {
      $ids = $this->etm->getStorage('wo_complete_info')->getQuery()->accessCheck(FALSE)
        ->condition('field_work_order', $ch, 'IN')->sort('id', 'ASC')->execute();
      foreach ($this->etm->getStorage('wo_complete_info')->loadMultiple($ids) as $c) {
        if ($c->get('field_work_order')->isEmpty()) {
          continue;
        }
        $out[(int) $c->get('field_work_order')->target_id] = [
          'completed' => $c->get('field_date_completed')->isEmpty() ? NULL : (int) $c->get('field_date_completed')->value,
          'uid' => $c->get('field_signed_off_by')->isEmpty() ? NULL : (int) $c->get('field_signed_off_by')->target_id,
        ];
      }
    }
    return $out;
  }

  private function clockByWo(array $woIds): array {
    $field = NULL;
    foreach ($this->efm->getFieldDefinitions('wo_time_clock', 'entry') as $n => $d) {
      if ($d->getType() === 'entity_reference' && $d->getSetting('target_type') === 'work_order') {
        $field = $n;
        break;
      }
    }
    if (!$field) {
      return [];
    }
    $out = [];
    foreach (array_chunk($woIds, 300) as $ch) {
      $ids = $this->etm->getStorage('wo_time_clock')->getQuery()->accessCheck(FALSE)
        ->condition($field, $ch, 'IN')->exists('field_start_time')->sort('id', 'ASC')->execute();
      foreach ($this->etm->getStorage('wo_time_clock')->loadMultiple($ids) as $e) {
        $wid = (int) $e->get($field)->target_id;
        $raw = $e->get('field_start_time')->isEmpty() ? NULL : (string) $e->get('field_start_time')->value;
        $st = $raw === NULL ? NULL : (is_numeric($raw) ? (int) $raw : strtotime($raw . ' UTC'));
        if ($st !== NULL && (!isset($out[$wid]) || $st < $out[$wid])) {
          $out[$wid] = $st;
        }
      }
    }
    return $out;
  }

  private function statusCompleteByWo(array $woIds): array {
    $out = [];
    foreach (array_chunk($woIds, 300) as $ch) {
      $ids = $this->etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)
        ->condition('field_status_of_wo', $ch, 'IN')->condition('field_status', self::COMPLETE_TID)
        ->sort('id', 'ASC')->execute();
      foreach ($this->etm->getStorage('wo_status_updates')->loadMultiple($ids) as $u) {
        if ($u->get('field_status_of_wo')->isEmpty()) {
          continue;
        }
        $wid = (int) $u->get('field_status_of_wo')->target_id;
        $ct = (int) $u->get('created')->value;
        if (!isset($out[$wid]) || $ct < $out[$wid]) {
          $out[$wid] = $ct;
        }
      }
    }
    return $out;
  }

  /** (completed-date, signer) groups whose gaps are mostly sub-2-min → batch. */
  private function batchSignoffGroups(array $signoffs, \DateTimeZone $tz): array {
    $groups = [];
    foreach ($signoffs as $s) {
      if ($s['ts'] === NULL || $s['uid'] === NULL) {
        continue;
      }
      $day = DrupalDateTime::createFromTimestamp($s['ts'], $tz)->format('Y-m-d');
      $groups["$day|{$s['uid']}"][] = $s['ts'];
    }
    $batch = [];
    foreach ($groups as $key => $times) {
      if (count($times) < 2) {
        continue;
      }
      sort($times);
      $gaps = [];
      for ($i = 1; $i < count($times); $i++) {
        $gaps[] = $times[$i] - $times[$i - 1];
      }
      $under2 = count(array_filter($gaps, fn($g) => $g < 120)) / count($gaps);
      if ($under2 >= 0.5) {
        $batch[$key] = TRUE;
      }
    }
    return $batch;
  }

  private function propertyInfo(array $props): array {
    $out = [];
    $zones = $this->zonesByProperty($props);
    foreach (array_chunk($props, 300) as $ch) {
      foreach ($this->etm->getStorage('properties')->loadMultiple($ch) as $p) {
        $pid = (int) $p->id();
        $city = '';
        $zip = '';
        if ($p->hasField('field_zipcode_reference') && !$p->get('field_zipcode_reference')->isEmpty()) {
          $z = $p->get('field_zipcode_reference')->entity;
          if ($z) {
            $zip = (string) ($z->get('field_zipcode')->value ?? '');
            if ($z->hasField('field_city') && !$z->get('field_city')->isEmpty() && ($c = $z->get('field_city')->entity)) {
              $city = $c->label();
            }
          }
        }
        [$lat, $lon] = $this->propertyLatLon($p);
        $out[$pid] = [
          'nick' => (string) ($p->get('field_nickname')->value ?? ''),
          'street' => (string) ($p->get('field_street_address')->value ?? ''),
          'city' => $city,
          'zip' => $zip,
          'zones' => $zones[$pid] ?? '',
          'lat' => $lat,
          'lon' => $lon,
        ];
      }
    }
    return $out;
  }

  private function zonesByProperty(array $props): array {
    $out = [];
    foreach (array_chunk($props, 300) as $ch) {
      $ids = $this->etm->getStorage('property_sprinkler_system')->getQuery()->accessCheck(FALSE)
        ->condition('field_property', $ch, 'IN')->exists('field_total_zones')->sort('id', 'ASC')->execute();
      foreach ($this->etm->getStorage('property_sprinkler_system')->loadMultiple($ids) as $ps) {
        if ($ps->get('field_property')->isEmpty()) {
          continue;
        }
        $pid = (int) $ps->get('field_property')->target_id;
        if (!isset($out[$pid])) {
          $out[$pid] = (string) $ps->get('field_total_zones')->value;
        }
      }
    }
    return $out;
  }

  /** Build the CSV row for one candidate WO. */
  private function buildRow($wo, ?int $pid, array $sourceYears, int $targetYear, array $history, array $batchDays, array $pinfo, \DateTimeZone $tz): array {
    $woId = (int) $wo->id();
    $woNumber = $wo->hasField('field_work_order_id') && !$wo->get('field_work_order_id')->isEmpty()
      ? (string) $wo->get('field_work_order_id')->value : (string) $woId;
    $row = array_fill_keys([
      'wo_id', 'wo_number', 'property_id', 'property_nickname', 'street', 'city', 'zip', 'total_zones',
      'source_year', 'prior_wo_id', 'prior_sched_id', 'prior_date', 'prior_weekday', 'prior_ordinal',
      'prior_tech_uid', 'prior_tech_name', 'prior_route_order', 'order_source', 'tech_source',
      'prior_signoff_at', 'prior_signed_off_by',
      'alt_year', 'alt_date', 'alt_weekday', 'alt_ordinal', 'alt_tech_name', 'year_check',
      'proposed_date', 'proposed_tech_uid', 'proposed_tech_name', 'proposed_route_order',
      'action', 'flags', 'note',
    ], '');
    $row['wo_id'] = $woId;
    $row['wo_number'] = $woNumber;
    $row['property_id'] = $pid ?: '';
    $row['property_nickname'] = mb_substr($pinfo['nick'] ?? '', 0, 120);
    $row['street'] = mb_substr($pinfo['street'] ?? '', 0, 120);
    $row['city'] = $pinfo['city'] ?? '';
    $row['zip'] = $pinfo['zip'] ?? '';
    $row['total_zones'] = $pinfo['zones'] ?? '';
    $row['action'] = 'skip';
    $flags = [];
    // Internal (not CSV columns) — used only for ranking.
    $row['_order_value'] = PHP_INT_MAX;
    $row['_order_tier'] = 2;

    $yearRecs = $pid && isset($history['prop'][$pid]) ? $history['prop'][$pid] : [];
    // Winner = first source year (in preference order) that has a record.
    $winYear = NULL;
    foreach ($sourceYears as $y) {
      if (isset($yearRecs[$y])) {
        $winYear = $y;
        break;
      }
    }
    if ($winYear === NULL) {
      $row['flags'] = 'no_prior_wo';
      return $row;
    }
    $rec = $yearRecs[$winYear];
    $row['source_year'] = $winYear;
    $row['prior_wo_id'] = $rec['wo_id'];
    $row['prior_sched_id'] = $rec['sched_id'];
    if ($rec['multiple_scheds']) {
      $flags[] = 'multiple_prior_schedules';
    }

    // Prior date → proposed date via the CALENDAR-DATE rule: keep the prior
    // month/day in the target year, so it lands one weekday later than last
    // year (the season keeps its sequence + pace and slides forward a weekday).
    // Weekend rule: Sat & Sun roll forward to the next Monday (crews work
    // Mon–Fri). This replaced the old nth-weekday-of-month mapping, which
    // preserved each customer's weekday but scrambled the route order year to
    // year (e.g. first-Wednesday customers leapfrogged to the 4th week).
    $prior = DrupalDateTime::createFromTimestamp($rec['start'], $tz);
    $row['prior_date'] = $prior->format('Y-m-d');
    $row['prior_weekday'] = $prior->format('D');
    $iso = (int) $prior->format('N');
    $month = (int) $prior->format('n');
    $day = (int) $prior->format('j');
    $row['prior_ordinal'] = intdiv($day - 1, 7) + 1;
    $proposed = new DrupalDateTime(sprintf('%04d-%02d-%02d 00:00:00', $targetYear, $month, $day), $tz);
    // Feb-29 guard (prior on a leap day, target not a leap year → last of month).
    if ((int) $proposed->format('n') !== $month) {
      $proposed = new DrupalDateTime(sprintf('%04d-%02d-01 00:00:00', $targetYear, $month), $tz);
      $proposed->modify('last day of this month');
    }
    $dow = (int) $proposed->format('N'); // 1=Mon … 7=Sun
    if ($dow === 6) {
      $proposed->modify('+2 days');
      $flags[] = 'weekend_roll';
    }
    elseif ($dow === 7) {
      $proposed->modify('+1 day');
      $flags[] = 'weekend_roll';
    }
    $row['proposed_date'] = $proposed->format('Y-m-d');
    // Holiday = informational (winterizing works through holidays, e.g. Columbus
    // Day); closure = office genuinely closed → blocks. Business calendar
    // currently tags everything 'holiday', so holidays schedule with a flag.
    $cal = $this->calendarType($proposed);
    if ($cal === 'closure') {
      $flags[] = 'closure_collision';
    }
    elseif ($cal === 'holiday') {
      $flags[] = 'holiday_collision';
    }

    // Tech — actual signer first, planned fallback.
    $signoff = $rec['signoff'];
    if ($signoff && $signoff['completed'] !== NULL) {
      $row['prior_signoff_at'] = DrupalDateTime::createFromTimestamp($signoff['completed'], $tz)->format('Y-m-d H:i');
    }
    $techUid = NULL;
    $techSource = '';
    if ($signoff && $signoff['uid']) {
      $techUid = $signoff['uid'];
      $techSource = 'signoff';
      $row['prior_signed_off_by'] = $this->userName($techUid);
    }
    elseif ($rec['assigned']) {
      $techUid = $rec['assigned'];
      $techSource = 'planned';
    }
    $row['prior_tech_uid'] = $techUid ?: '';
    $row['prior_tech_name'] = $techUid ? $this->userName($techUid) : '';
    $row['tech_source'] = $techSource;
    [$propTechUid, $techFlag] = $this->validateTech($techUid);
    if ($techFlag) {
      $flags[] = $techFlag;
    }
    $row['proposed_tech_uid'] = $propTechUid ?: '';
    $row['proposed_tech_name'] = $propTechUid ? $this->userName($propTechUid) : '';

    // Order signal precedence: route-order-set (office arranged it in the Route
    // Editor → authoritative) → sign-off → clock → status → planned → none.
    // A route-order-set route means the office deliberately sequenced it, so its
    // planned order wins over the order the truck happened to drive.
    $orderSource = 'none';
    $orderValue = PHP_INT_MAX;
    $tier = 2;
    if (!empty($rec['order_set']) && $rec['planned_order'] !== NULL) {
      $orderSource = 'planned_set';
      $orderValue = $rec['planned_order'];
      $tier = 0;
      $flags[] = 'route_order_set';
    }
    elseif ($signoff && $signoff['completed'] !== NULL) {
      $orderSource = 'signoff';
      $orderValue = $signoff['completed'];
      $tier = 0;
      $key = $prior->format('Y-m-d') . '|' . ($signoff['uid'] ?? '');
      if (isset($batchDays[$key])) {
        $flags[] = 'batch_signoff';
      }
    }
    elseif ($rec['clock'] !== NULL) {
      $orderSource = 'clock';
      $orderValue = $rec['clock'];
      $tier = 0;
    }
    elseif ($rec['status'] !== NULL) {
      $orderSource = 'status';
      $orderValue = $rec['status'];
      $tier = 0;
    }
    elseif ($rec['planned_order'] !== NULL) {
      $orderSource = 'planned';
      $orderValue = $rec['planned_order'];
      $tier = 1;
    }
    else {
      $flags[] = 'no_route_order';
    }
    $row['order_source'] = $orderSource;
    $row['prior_route_order'] = $rec['planned_order'] ?? '';
    $row['_order_value'] = $orderValue;
    $row['_order_tier'] = $tier;

    // Alt year (next source year with a record) + disagreement flags.
    $altYear = NULL;
    foreach ($sourceYears as $y) {
      if ($y !== $winYear && isset($yearRecs[$y])) {
        $altYear = $y;
        break;
      }
    }
    if ($altYear !== NULL) {
      $alt = $yearRecs[$altYear];
      $altDt = DrupalDateTime::createFromTimestamp($alt['start'], $tz);
      $row['alt_year'] = $altYear;
      $row['alt_date'] = $altDt->format('Y-m-d');
      $row['alt_weekday'] = $altDt->format('D');
      $altOrd = intdiv(((int) $altDt->format('j')) - 1, 7) + 1;
      $row['alt_ordinal'] = $altOrd;
      $altTech = ($alt['signoff'] && $alt['signoff']['uid']) ? $alt['signoff']['uid'] : $alt['assigned'];
      $row['alt_tech_name'] = $altTech ? $this->userName($altTech) : '';
      // Year comparison is INFORMATIONAL only (recency wins) — collapsed into a
      // single year_check column so it never clutters the actionable flags.
      $diffs = [];
      if ($altOrd !== $ordinal) { $diffs[] = 'ordinal'; }
      if ((int) $altDt->format('N') !== $iso) { $diffs[] = 'weekday'; }
      if ((int) $altDt->format('n') !== $month) { $diffs[] = 'month'; }
      if ($altTech && $techUid && $altTech !== $techUid) { $diffs[] = 'tech'; }
      $row['year_check'] = $diffs ? ('differs: ' . implode(', ', $diffs)) : 'agree';
    }

    // Action: schedule everything with a usable date — dead-tech rows land
    // UNASSIGNED (blank tech → calendar's unassigned bucket, supervisor assigns).
    // Weekend-landing dates auto-roll to Monday (weekend_roll, non-blocking), so
    // only a genuine office closure is held for manual review. New-customer
    // (no_prior) rows are proximity-filled in a later pass. All rows here are
    // field_scheduled_firm = FALSE, so everything is a soft proposal the
    // supervisor confirms. The office can still flip any action in the CSV.
    $row['flags'] = implode('|', $flags);
    // Only a genuine office closure holds for manual — a bad date proximity
    // can't fix. Weekends roll to Monday; holidays (worked) schedule soft.
    $blocking = array_intersect($flags, ['closure_collision']);
    $row['action'] = $blocking ? 'review' : 'schedule';
    return $row;
  }

  /**
   * Place no-prior (new-customer) rows on the nearest confidently-scheduled
   * property's day (unassigned, flagged proximity_fill). Rows with no GPS or no
   * neighbour within the threshold stay action=skip (truly manual).
   */
  private function proximityFill(array &$rows, array $propInfo): void {
    $maxMiles = 10.0;
    // Anchors = already-scheduled rows with a date + GPS.
    $anchors = [];
    foreach ($rows as $r) {
      if ($r['action'] !== 'schedule' || !$r['proposed_date'] || !$r['property_id']) {
        continue;
      }
      $pi = $propInfo[(int) $r['property_id']] ?? NULL;
      if ($pi && $pi['lat'] !== NULL && $pi['lon'] !== NULL) {
        $anchors[] = ['lat' => $pi['lat'], 'lon' => $pi['lon'], 'date' => $r['proposed_date'], 'nick' => $r['property_nickname']];
      }
    }
    if (!$anchors) {
      return;
    }
    foreach ($rows as &$r) {
      if ($r['action'] !== 'skip' || !str_contains((string) $r['flags'], 'no_prior_wo') || !$r['property_id']) {
        continue;
      }
      $pi = $propInfo[(int) $r['property_id']] ?? NULL;
      if (!$pi || $pi['lat'] === NULL || $pi['lon'] === NULL) {
        continue;
      }
      $best = NULL;
      $bestD = INF;
      foreach ($anchors as $a) {
        $d = $this->haversineMiles((float) $pi['lat'], (float) $pi['lon'], (float) $a['lat'], (float) $a['lon']);
        if ($d < $bestD) {
          $bestD = $d;
          $best = $a;
        }
      }
      if ($best && $bestD <= $maxMiles) {
        $r['proposed_date'] = $best['date'];
        $r['action'] = 'schedule';
        $r['flags'] = 'no_prior_wo|proximity_fill';
        $r['note'] = sprintf('New customer — placed near %s (%.1f mi); assign a tech.', mb_substr($best['nick'], 0, 40), $bestD);
        $r['_order_tier'] = 2;
        $r['_order_value'] = PHP_INT_MAX;
      }
    }
    unset($r);
  }

  private function haversineMiles(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 3958.8;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * asin(min(1.0, sqrt($a)));
  }

  /** @return array{0:?float,1:?float} [lat, lon] from field_geofield, or [null,null]. */
  private function propertyLatLon($p): array {
    if (!$p->hasField('field_geofield') || $p->get('field_geofield')->isEmpty()) {
      return [NULL, NULL];
    }
    $item = $p->get('field_geofield')->first();
    $lat = NULL;
    $lon = NULL;
    try {
      $lat = $item->get('lat')->getValue();
      $lon = $item->get('lon')->getValue();
    }
    catch (\Throwable $e) {
      // fall through to WKT parse
    }
    if (($lat === NULL || $lon === NULL || $lat === '' || $lon === '') && !empty($item->value)
      && preg_match('/POINT\s*\(\s*([-0-9.]+)\s+([-0-9.]+)\s*\)/i', (string) $item->value, $m)) {
      $lon = (float) $m[1];
      $lat = (float) $m[2];
    }
    return [is_numeric($lat) ? (float) $lat : NULL, is_numeric($lon) ? (float) $lon : NULL];
  }

  /** Dense 1..N route order within each (proposed_date, proposed_tech) group. */
  private function assignRouteOrder(array &$rows): void {
    $groups = [];
    foreach ($rows as $i => $r) {
      if ($r['action'] !== 'schedule') {
        continue;
      }
      $groups[$r['proposed_date'] . '|' . $r['proposed_tech_uid']][] = $i;
    }
    foreach ($groups as $idxs) {
      // Sort by tier (driven before planned before none), then the signal value.
      usort($idxs, function ($a, $b) use ($rows) {
        return [$rows[$a]['_order_tier'], $rows[$a]['_order_value'], $rows[$a]['property_nickname']]
          <=> [$rows[$b]['_order_tier'], $rows[$b]['_order_value'], $rows[$b]['property_nickname']];
      });
      $sources = [];
      $rank = 1;
      foreach ($idxs as $i) {
        $rows[$i]['proposed_route_order'] = $rank++;
        if (in_array($rows[$i]['order_source'], ['signoff', 'clock', 'status'], TRUE)) {
          $sources[$rows[$i]['order_source']] = TRUE;
        }
      }
      // Mixed signal: some driven, some planned/none in one group.
      $tiers = array_unique(array_map(fn($i) => $rows[$i]['_order_tier'], $idxs));
      if (count($tiers) > 1) {
        foreach ($idxs as $i) {
          $f = $rows[$i]['flags'];
          $rows[$i]['flags'] = $f === '' ? 'mixed_order_signal' : $f . '|mixed_order_signal';
        }
      }
    }
  }


  /** business_calendar type ('holiday' | 'closure' | '') for a date (site tz). */
  private function calendarType(DrupalDateTime $date): string {
    if (!$this->etm->hasDefinition('business_calendar')) {
      return '';
    }
    static $map = NULL;
    if ($map === NULL) {
      $map = [];
      try {
        $ids = $this->etm->getStorage('business_calendar')->getQuery()->accessCheck(FALSE)->sort('id', 'ASC')->execute();
        foreach ($this->etm->getStorage('business_calendar')->loadMultiple($ids) as $e) {
          $type = '';
          foreach (['field_event_type', 'field_calendar_event_type', 'field_type'] as $f) {
            if ($e->hasField($f) && !$e->get($f)->isEmpty()) {
              $type = strtolower((string) $e->get($f)->value);
              break;
            }
          }
          if (!in_array($type, ['holiday', 'closure'], TRUE)) {
            continue;
          }
          foreach ($e->getFields() as $fn => $fi) {
            $ft = $fi->getFieldDefinition()->getType();
            if (in_array($ft, ['datetime', 'daterange', 'smartdate', 'timestamp'], TRUE) && !$fi->isEmpty()) {
              $v = $fi->value;
              $d = is_numeric($v) ? date('Y-m-d', (int) $v) : substr((string) $v, 0, 10);
              // closure beats holiday if a date somehow has both.
              if (!isset($map[$d]) || $type === 'closure') {
                $map[$d] = $type;
              }
              break;
            }
          }
        }
      }
      catch (\Throwable $e) {
        $map = [];
      }
    }
    return $map[$date->format('Y-m-d')] ?? '';
  }

  /** @return array{0:?int,1:?string} [proposedTechUid|null, flag|null] */
  private function validateTech(?int $uid): array {
    if (!$uid) {
      return [NULL, 'tech_missing'];
    }
    $user = $this->etm->getStorage('user')->load($uid);
    if (!$user) {
      return [NULL, 'tech_deleted'];
    }
    if (!$user->isActive() || !in_array('teammates', $user->getRoles(), TRUE)) {
      return [NULL, 'tech_inactive'];
    }
    return [$uid, NULL];
  }

  private function userName(?int $uid): string {
    if (!$uid) {
      return '';
    }
    static $cache = [];
    if (!array_key_exists($uid, $cache)) {
      $u = $this->etm->getStorage('user')->load($uid);
      $cache[$uid] = $u ? $u->getDisplayName() : "uid $uid";
    }
    return $cache[$uid];
  }

  private function writeCsv(string $path, array $rows): void {
    $cols = $this->csvColumns();
    $fh = fopen($path, 'w');
    fputcsv($fh, $cols);
    foreach ($rows as $r) {
      $line = [];
      foreach ($cols as $c) {
        $line[] = $r[$c] ?? '';
      }
      fputcsv($fh, $line);
    }
    fclose($fh);
  }

  private function csvColumns(): array {
    return ['wo_id', 'wo_number', 'property_id', 'property_nickname', 'street', 'city', 'zip', 'total_zones',
      'source_year', 'prior_wo_id', 'prior_sched_id', 'prior_date', 'prior_weekday', 'prior_ordinal',
      'prior_tech_uid', 'prior_tech_name', 'prior_route_order', 'order_source', 'tech_source',
      'prior_signoff_at', 'prior_signed_off_by',
      'alt_year', 'alt_date', 'alt_weekday', 'alt_ordinal', 'alt_tech_name', 'year_check',
      'proposed_date', 'proposed_tech_uid', 'proposed_tech_name', 'proposed_route_order',
      'action', 'flags', 'note'];
  }

  private function printSummary(array $rows, string $path): void {
    $isSched = fn($r) => $r['action'] === 'schedule';
    $assigned = array_filter($rows, fn($r) => $isSched($r) && $r['proposed_tech_uid'] !== '');
    $unassigned = array_filter($rows, fn($r) => $isSched($r) && $r['proposed_tech_uid'] === '');
    $review = array_filter($rows, fn($r) => $r['action'] === 'review');
    $skip = array_filter($rows, fn($r) => $r['action'] === 'skip');

    $this->io()->newLine();
    $this->io()->section('Winterizing carry-forward plan');
    $this->io()->text([
      sprintf('Candidates: %d', count($rows)),
      sprintf('  ✅ auto-schedule, tech assigned  : %d   (apply schedules these; nothing to review)', count($assigned)),
      sprintf('  🅿  auto-schedule, UNASSIGNED     : %d   (land on the calendar; confirm + assign a tech)', count($unassigned)),
      sprintf('  ✋ manual — date conflict         : %d   (office closure; move the date yourself)', count($review)),
      sprintf('  🆕 skip — no history, no neighbour: %d   (schedule from scratch)', count($skip)),
    ]);

    // Actionable flag counts (year_check noise already excluded from flags).
    $flagCounts = [];
    foreach ($rows as $r) {
      foreach (array_filter(explode('|', (string) $r['flags'])) as $f) {
        $flagCounts[$f] = ($flagCounts[$f] ?? 0) + 1;
      }
    }
    arsort($flagCounts);
    if ($flagCounts) {
      $this->io()->text('Flags:');
      foreach ($flagCounts as $f => $n) {
        $this->io()->text(sprintf('   %-24s %d', $f, $n));
      }
    }

    // Focused review file: only the rows that want a human — date conflicts,
    // unassigned (need a tech), and true skips.
    $reviewPath = preg_replace('/\.csv$/i', '', $path) . '_REVIEW.csv';
    $keep = ['proposed_date', 'proposed_route_order', 'property_nickname', 'street', 'city',
      'proposed_tech_name', 'order_source', 'prior_date', 'prior_weekday', 'action', 'flags', 'note'];
    $fh = fopen($reviewPath, 'w');
    fputcsv($fh, $keep);
    foreach (array_merge($review, $unassigned, $skip) as $r) {
      fputcsv($fh, array_map(fn($c) => $r[$c] ?? '', $keep));
    }
    fclose($fh);

    $this->io()->newLine();
    $this->io()->success('Full plan CSV: ' . $path);
    $this->io()->text('Focused review CSV (only rows needing a look): ' . $reviewPath);
    $this->io()->text('Then: drush bos:winterize:apply --file=' . $path . ' --actor=<uid>');
  }

}
