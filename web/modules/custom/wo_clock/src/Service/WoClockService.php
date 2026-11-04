<?php

declare(strict_types=1);

namespace Drupal\wo_clock\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Clock-in/out domain service for wo_time_clock entries.
 *
 * Owns entry creation/closure, open-entry lookups, silent GPS storage, and
 * the Haversine distance-from-property calculation. Deliberately transport-
 * agnostic: the controller (AJAX) and any future caller drive it the same way.
 *
 * Datetime storage: field_start_time / field_end_time are datetime fields
 * stored as UTC 'Y-m-d\TH:i:s' (the BOS convention — see wo_sign_off).
 *
 * GPS is stored as geofield WKT "POINT (lon lat)". All GPS is silent-optional:
 * a null lat/lon simply leaves the location field empty; nothing blocks.
 */
final class WoClockService {

  /**
   * Datetime field storage format (UTC).
   */
  private const DT = 'Y-m-d\TH:i:s';

  /**
   * WO statuses where the guard-bypass flag is applied for retroactive close.
   * Invoiced (1281) / Paid (1504) — matches wo_total_time Phase 1 Guard 4.
   */
  private const LOCKED_STATUSES = [1281, 1504];

  /**
   * Earth radius in feet (6371 km).
   */
  private const EARTH_RADIUS_FEET = 20902231.0;

  /**
   * Structured note formats (sprintf). Timestamps are site-tz MM/DD/YYYY h:i AM/PM.
   * Notes accumulate newline-separated across a start and a later end.
   */
  public const NOTE_BUTTON_START = '[Start: Button %s]';
  public const NOTE_BUTTON_END = '[End: Button %s]';
  public const NOTE_INTERVENTION_END = '[End: Intervention %s by %s]';
  public const NOTE_CREW_START = '[Start: Crew clock-in %s by %s]';
  public const NOTE_CREW_END = '[End: Crew clock-out %s by %s]';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Now, as a UTC datetime storage string.
   */
  private function nowUtc(): string {
    return gmdate(self::DT, $this->time->getRequestTime());
  }

  /**
   * A UTC datetime storage string for an arbitrary timestamp.
   */
  private function tsUtc(int $timestamp): string {
    return gmdate(self::DT, $timestamp);
  }

  private function tcStorage() {
    return $this->entityTypeManager->getStorage('wo_time_clock');
  }

  /**
   * Open wo_time_clock entries for a user (field_end_time IS NULL).
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   Oldest-first (by field_start_time).
   */
  public function getOpenEntriesForUser(int $uid, ?int $excludeWoId = NULL): array {
    if ($uid <= 0) {
      return [];
    }
    // field_teammate is the canonical "whose time". Owner uid is the fallback
    // for legacy entries where field_teammate was never synced; query both and
    // merge so neither convention is missed.
    $ids = [];
    foreach (['field_teammate', 'uid'] as $field) {
      $q = $this->tcStorage()->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $uid)
        ->notExists('field_end_time')
        ->sort('field_start_time', 'ASC');
      if ($excludeWoId !== NULL) {
        $q->condition('field_work_order', $excludeWoId, '<>');
      }
      foreach ($q->execute() as $id) {
        $ids[$id] = TRUE;
      }
    }
    if (!$ids) {
      return [];
    }
    $entries = $this->tcStorage()->loadMultiple(array_keys($ids));
    // Re-sort merged set oldest-first.
    uasort($entries, function (EntityInterface $a, EntityInterface $b) {
      return strcmp((string) ($a->get('field_start_time')->value ?? ''), (string) ($b->get('field_start_time')->value ?? ''));
    });
    return array_values($entries);
  }

  /**
   * The user's current open entry on a specific WO, or NULL.
   */
  public function getCurrentEntryOnWo(int $uid, int $woId): ?EntityInterface {
    if ($uid <= 0 || $woId <= 0) {
      return NULL;
    }
    foreach (['field_teammate', 'uid'] as $field) {
      $ids = $this->tcStorage()->getQuery()
        ->accessCheck(FALSE)
        ->condition($field, $uid)
        ->condition('field_work_order', $woId)
        ->notExists('field_end_time')
        ->sort('field_start_time', 'DESC')
        ->range(0, 1)
        ->execute();
      if ($ids) {
        return $this->tcStorage()->load(reset($ids));
      }
    }
    return NULL;
  }

  /**
   * Build an OPEN wo_time_clock entry (unsaved), guaranteeing field_end_time
   * is cleared.
   *
   * ⚠ field_end_time carries an instance default_value of "now", so a plain
   * ->create() AUTO-CLOSES the entry (a subtle, data-corrupting trap — an entry
   * meant to be open is silently born closed, and open-entry lookups + the
   * sign-off guards then never see it). Any code that programmatically creates
   * an intended-open entry MUST clear field_end_time. Route all such creation
   * through this method so the guarantee lives in exactly one place.
   *
   * Returns the entity UNSAVED so callers can add GPS / notes before saving.
   *
   * @param array $extra
   *   Additional field values to merge into create() (e.g. a note).
   */
  public function createOpenEntry(int $uid, int $woId, array $extra = []): EntityInterface {
    $entry = $this->tcStorage()->create([
      'type' => 'entry',
      'field_teammate' => $uid,
      'field_work_order' => $woId,
      'field_start_time' => $this->nowUtc(),
    ] + $extra);
    // The guarantee: never inherit the "now" default on an open entry.
    $entry->set('field_end_time', NULL);
    // Clear the field_notes default of "Manually Entered" — this is a BUTTON
    // clock-in, not a manual entry; the structured note below is the real
    // attribution. (This default is the source of the "Manually Entered"
    // mislabel — same default-value trap as field_end_time's "now".)
    $entry->set('field_notes', NULL);
    // Owner mirrors the teammate so downstream owner-based reads agree.
    if ($entry->hasField('uid')) {
      $entry->set('uid', $uid);
    }
    // Origin attribution: a button clock-in, with a structured start note.
    $entry->set('field_source', 'wo_clock_button');
    $this->appendNote($entry, sprintf(self::NOTE_BUTTON_START, $this->usDisplay($this->time->getRequestTime())));
    $entry->_wo_clock_write = TRUE;
    return $entry;
  }

  /**
   * Create + save a new clock-in entry (open, via createOpenEntry()).
   */
  public function clockIn(int $uid, int $woId, ?float $lat = NULL, ?float $lon = NULL, ?string $noteContext = NULL): EntityInterface {
    // Billed/closed WOs (Invoiced 1281 / Paid 1504) are closed to time entry:
    // no one may clock back in on any path. A forgotten-entry correction on a
    // billed WO is an office action (reconciliation), not a crew clock-in.
    if ($this->woIsLocked($woId)) {
      throw new \RuntimeException('This work order has already been invoiced and is closed to time entry. If a time correction is needed, please contact the office.');
    }
    $entry = $this->createOpenEntry($uid, $woId);
    if ($lat !== NULL && $lon !== NULL) {
      $entry->set('field_clock_in_location', $this->wkt($lat, $lon));
    }
    if ($noteContext !== NULL && $noteContext !== '') {
      $this->appendNote($entry, $noteContext);
    }
    $entry->save();
    // Tell the office somebody has started. @see markInProgress().
    $this->markInProgress($woId, 'Teammate Clocked In');
    return $entry;
  }

  /**
   * Set field_end_time = now on an entry, capturing clock-out GPS.
   *
   * Respects Phase 1 guards — a guard throw propagates to the caller.
   */
  public function clockOut(int $entryId, ?float $lat = NULL, ?float $lon = NULL, bool $override = FALSE): EntityInterface {
    $entry = $this->loadEntry($entryId);
    $entry->set('field_end_time', $this->nowUtc());
    if ($lat !== NULL && $lon !== NULL) {
      $entry->set('field_clock_out_location', $this->wkt($lat, $lon));
    }
    // Crew confirmed a genuinely-long entry — set the override so Guard 6
    // accepts it (and stamps its own audit note attributing the confirmer).
    if ($override && $entry->hasField('field_time_limit_override')) {
      $entry->set('field_time_limit_override', TRUE);
    }
    // field_source stays as set at clock-in; record the button clock-out.
    $this->appendNote($entry, sprintf(self::NOTE_BUTTON_END, $this->usDisplay($this->time->getRequestTime())));
    $entry->_wo_clock_write = TRUE;
    $entry->save();
    return $entry;
  }

  /**
   * If clocking out NOW would push this entry over its bundle's single-entry
   * cap (wo_total_time Guard 6), return ['hours' => float, 'cap' => float];
   * otherwise NULL. Lets the button ask the crew to confirm a long entry
   * instead of dead-ending, since the override checkbox isn't on the button.
   */
  public function capExceedanceHours(EntityInterface $entry): ?array {
    if ($entry->get('field_start_time')->isEmpty()) {
      return NULL;
    }
    try {
      $start = new \DateTime($entry->get('field_start_time')->value, new \DateTimeZone('UTC'));
    }
    catch (\Throwable $e) {
      return NULL;
    }
    $hours = ($this->time->getRequestTime() - $start->getTimestamp()) / 3600;
    if ($hours <= 0) {
      return NULL;
    }
    $wo = $entry->get('field_work_order')->entity;
    if (!$wo) {
      return NULL;
    }
    $cap = function_exists('_wo_total_time_get_max_entry_hours')
      ? (float) _wo_total_time_get_max_entry_hours($wo->bundle())
      : 4.0;
    return $hours > $cap ? ['hours' => round($hours, 2), 'cap' => $cap] : NULL;
  }

  /**
   * Close an entry retroactively (alert-region / modal intervention).
   *
   * @param int|null $endTimestamp
   *   Unix timestamp for the close, or NULL for now.
   * @param bool $auditNote
   *   Whether to prepend a "[Closed via intervention …]" audit note.
   */
  public function closeEntry(int $entryId, ?int $endTimestamp = NULL, bool $auditNote = TRUE): EntityInterface {
    $entry = $this->loadEntry($entryId);
    $end = $endTimestamp !== NULL ? $this->tsUtc($endTimestamp) : $this->nowUtc();
    $entry->set('field_end_time', $end);

    // The intervention is now the defining attribution — the end time is
    // retroactive — so it overrides the original wo_clock_button value.
    $entry->set('field_source', 'wo_clock_intervention');
    if ($auditNote) {
      // Stamp reflects the resolved end time, not the moment of close.
      $stamp = $this->usDisplay($endTimestamp ?? $this->time->getRequestTime());
      $this->appendNote($entry, sprintf(self::NOTE_INTERVENTION_END, $stamp, $this->currentUser->getDisplayName()));
    }
    $entry->_wo_clock_write = TRUE;

    // Bypass the Phase 1 wo_time_clock guards ONLY when the parent WO is
    // locked (Invoiced/Paid) — a legitimate office correction on billed work.
    if ($this->parentWoIsLocked($entry)) {
      $entry->_signoff_reconciliation = TRUE;
    }

    $entry->save();
    return $entry;
  }

  /**
   * Which sign-off (`wo_complete_info`) bundle a work order bundle belongs to.
   *
   * ⚠ DERIVED FROM THE DATA, not invented: counted across ~46,000 real
   * sign-offs, every WO bundle resolves to one dominant sign-off bundle.
   * `backflow_testing` is the only genuinely mixed one (19 `complete` vs 12
   * `irrigation_crew`) and is mapped to irrigation, which is who does the work.
   *
   * `complete` is the generic sign-off and maps to NO crew — those WO bundles
   * fall through to the scheduling list rather than guessing a crew.
   */
  private const WO_BUNDLE_SIGNOFF = [
    'aerating' => 'clean_up_crew',
    'dethatching' => 'clean_up_crew',
    'fall_cleanup' => 'clean_up_crew',
    'spring_cleanup' => 'clean_up_crew',
    'special_mowing' => 'clean_up_crew',
    'summer_pruning' => 'clean_up_crew',
    'aspen_twig_gall' => 'spray_crew',
    'cooley_spruce_gall' => 'spray_crew',
    'deciduous_bore' => 'spray_crew',
    'dormant_oil' => 'spray_crew',
    'grub_prevention' => 'spray_crew',
    'pinion_pine_ips_beetle' => 'spray_crew',
    'pre_emergent' => 'spray_crew',
    'trunk_bore' => 'spray_crew',
    'weed_spraying' => 'spray_crew',
    'fertilizing' => 'fertilizing_crew',
    'fertilizing_trees_and_shrubs' => 'fertilizing_crew',
    'backflow_testing' => 'irrigation_crew',
    'sprinkler_check_up' => 'irrigation_crew',
    'sprinkler_design' => 'irrigation_crew',
    'sprinkler_installation' => 'irrigation_crew',
    'sprinkler_repair' => 'irrigation_crew',
    'sprinkler_start_up' => 'irrigation_crew',
    'sprinkler_winterizing' => 'irrigation_crew',
    'lawn_mowing' => 'lawn_mowing',
    'snow_removal' => 'snow_removal',
    'landscaping' => 'landscape_crew',
  ];

  /**
   * Sign-off bundle → `crew_types` entity id.
   *
   * The ids are stable; labels are given so a reader can check them without a
   * database. `complete` is deliberately absent — it is the catch-all sign-off
   * used by christmas_decorations, misc_services, in_house_tasks and the like,
   * and it names no crew.
   */
  private const SIGNOFF_CREW_TYPE = [
    'lawn_mowing' => 1,       // Lawn Maintenance Crew
    'clean_up_crew' => 2,     // Clean-up Crew
    'spray_crew' => 3,        // Spray Crew
    'irrigation_crew' => 4,   // Irrigation Crew
    'snow_removal' => 5,      // Snow Removal Crew
    'landscape_crew' => 6,    // Landscape Crew
    'fertilizing_crew' => 7,  // Fertilizing Crew
    'lighting_crew' => 9,     // Lighting Crew
  ];

  /**
   * Who the crew clock should offer for a work order.
   *
   * Three sources, and the distinction between them is the point:
   *
   *   - **The crew for this kind of work.** The WO bundle picks its sign-off
   *     type, which picks a `crew_types` entity, whose members come from
   *     `teammate_profile.field_assigned_crew`. Todd, 2026-11-03: *"The roster
   *     should come from the crew type. Irrigation crew for irrigation type of
   *     work orders."* All 25 active teammates carry a crew, so this is a far
   *     better source than scheduling, which is sparse.
   *   - **Scheduled.** Whoever was actually dispatched on this WO. These are
   *     PRE-TICKED, because that is the stated intent for today.
   *   - **Already clocked in.** Listed but not selectable — re-adding them
   *     would create a second open row and double-count their hours.
   *
   * ⚠ Crew members are listed but NOT pre-ticked. The Clean-up Crew has 19
   * people; ticking all of them by default would turn one careless press into
   * 19 false entries. The dispatcher's intent gets the tick; the rest of the
   * crew are there to be added in one tap when the morning differs.
   *
   * @return array
   *   [['uid','name','open','scheduled','in_crew'], …] sorted by name.
   */
  public function getCrewRosterForWo(int $woId): array {
    $db = \Drupal::database();

    $scheduled = array_map('intval', $db->query('SELECT DISTINCT a.field_assigned_to_target_id uid
      FROM {scheduling__field_assigned_to} a
      JOIN {scheduling__field_work_order} w ON w.entity_id = a.entity_id AND w.deleted = 0
      WHERE a.deleted = 0 AND w.field_work_order_target_id = :wo',
      [':wo' => $woId])->fetchCol());

    // The crew that does this kind of work.
    $crewMembers = [];
    $wo = $this->entityTypeManager->getStorage('work_order')->load($woId);
    if ($wo) {
      $signoff = self::WO_BUNDLE_SIGNOFF[$wo->bundle()] ?? NULL;
      $crewId = $signoff ? (self::SIGNOFF_CREW_TYPE[$signoff] ?? NULL) : NULL;
      if ($crewId) {
        $crewMembers = array_map('intval', $db->query('SELECT DISTINCT p.uid
          FROM {profile__field_assigned_crew} a
          JOIN {profile} p ON p.profile_id = a.entity_id
          WHERE a.deleted = 0 AND a.field_assigned_crew_target_id = :c',
          [':c' => $crewId])->fetchCol());
      }
    }

    // Anyone already clocked in belongs on the list however they got there.
    $openUids = [];
    foreach ($this->getOpenEntriesOnWo($woId) as $entry) {
      $uid = (int) $entry->get('field_teammate')->target_id;
      if ($uid) {
        $openUids[] = $uid;
      }
    }

    $all = array_values(array_unique(array_merge($scheduled, $crewMembers, $openUids)));
    $out = [];
    $store = $this->entityTypeManager->getStorage('user');
    foreach ($all as $uid) {
      $u = $store->load($uid);
      if (!$u || !$u->isActive()) {
        continue;
      }
      $out[] = [
        'uid' => $uid,
        'name' => $u->getDisplayName(),
        'open' => in_array($uid, $openUids, TRUE),
        'scheduled' => in_array($uid, $scheduled, TRUE),
        'in_crew' => in_array($uid, $crewMembers, TRUE),
      ];
    }
    // Scheduled first — they are the ones already ticked — then the rest of
    // the crew alphabetically, so the common case is at the top of a phone.
    usort($out, function ($a, $b) {
      if ($a['scheduled'] !== $b['scheduled']) {
        return $a['scheduled'] ? -1 : 1;
      }
      return strcasecmp($a['name'], $b['name']);
    });
    return $out;
  }

  /**
   * Statuses a clock-in must NOT promote out of.
   *
   * Terminal set, matching wo_timer_flag_update: Complete, Warrantied,
   * Invoiced, Paid, Canceled. Everything else (Open / Scheduled / Assigned /
   * blocker states) promotes to In Progress.
   */
  private const TERMINAL_STATUSES = [1097, 1283, 1281, 1504, 1098];

  /**
   * In Progress.
   */
  private const STATUS_IN_PROGRESS = 1092;

  /**
   * Move a work order to In Progress when somebody starts work on it.
   *
   * ⚠ THIS WAS A SILENT REGRESSION. The legacy flag toggle
   * (`wo_timer_flag_update`) created a "Teammate Clocked In" status update, and
   * `wo_clock` replaced that toggle on the work order page — hiding the flag —
   * without carrying the status change across. Measured 2026-11-03: of **2,752
   * work orders clocked into since the button shipped on 07/03, only 995 ever
   * got a status update**, and 24 were sitting at Open or Scheduled with hours
   * already logged against them. Todd: *"a Status change needs to be created to
   * show the office that someone has started working on it."*
   *
   * Writes a `wo_status_updates` record rather than setting `field_status`
   * directly, so the change lands in the append-only timeline with an author
   * and a reason — the same mechanism the office's own actions use. The
   * record's presave propagates the status onto the work order.
   *
   * Skipping is side-effect-free: no record is created, so no propagation runs
   * and the WO is never saved.
   *
   * @return bool
   *   TRUE if a status update was written.
   */
  private function markInProgress(int $woId, string $note): bool {
    $wo = $this->entityTypeManager->getStorage('work_order')->load($woId);
    if (!$wo || !$wo->hasField('field_status') || $wo->get('field_status')->isEmpty()) {
      return FALSE;
    }
    $current = (int) $wo->get('field_status')->target_id;
    // Already started, or finished/billed — nothing to say.
    if ($current === self::STATUS_IN_PROGRESS || in_array($current, self::TERMINAL_STATUSES, TRUE)) {
      return FALSE;
    }
    try {
      $this->entityTypeManager->getStorage('wo_status_updates')->create([
        'type' => 'update',
        'field_status_of_wo' => $woId,
        'field_status' => self::STATUS_IN_PROGRESS,
        'field_status_change_note' => ['value' => $note],
        'uid' => $this->currentUser->id(),
      ])->save();
      return TRUE;
    }
    catch (\Throwable $e) {
      // ⚠ A failed status update must NOT cost somebody their clock-in. The
      // hours are the thing that matters; the status is reporting. Log and
      // carry on rather than throwing out of a clock-in.
      $this->loggerFactory->get('wo_clock')->error(
        'Could not set WO @wo to In Progress on clock-in: @msg',
        ['@wo' => $woId, '@msg' => $e->getMessage()]
      );
      return FALSE;
    }
  }

  /**
   * Everyone currently open on a work order, oldest start first.
   */
  public function getOpenEntriesOnWo(int $woId): array {
    if ($woId <= 0) {
      return [];
    }
    $ids = $this->tcStorage()->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_work_order', $woId)
      ->notExists('field_end_time')
      ->sort('field_start_time', 'ASC')
      ->execute();
    return $ids ? $this->tcStorage()->loadMultiple($ids) : [];
  }

  /**
   * Clock a whole crew IN on one work order.
   *
   * The crew model: the person pressing the button is attesting that everyone
   * named is on site, so each gets a real entry of their own rather than a
   * share of somebody else's. Entries are independent from the moment they
   * exist — one person leaving early closes only their own row.
   *
   * ⚠ Anyone who ALREADY has an open entry is skipped, not duplicated and not
   * overwritten. Somebody who clocked themselves in at 7:00 keeps their 7:00
   * when the crew button is pressed at 7:15; a second open row for the same
   * person on the same WO would double-count their hours into billing.
   *
   * @return array
   *   ['created' => [uid => entry], 'skipped' => [uid => reason]]
   */
  public function crewClockIn(int $woId, array $uids, ?float $lat = NULL, ?float $lon = NULL): array {
    if ($this->woIsLocked($woId)) {
      throw new \RuntimeException('This work order has already been invoiced and is closed to time entry. If a time correction is needed, please contact the office.');
    }
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    if (!$uids) {
      throw new \RuntimeException('Nobody was selected.');
    }

    $created = [];
    $skipped = [];
    $actor = $this->currentUser->getDisplayName();
    $stamp = $this->usDisplay($this->time->getRequestTime());

    // All-or-nothing: a half-clocked crew is worse than none, because the
    // missing half looks deliberate afterwards.
    $tx = \Drupal::database()->startTransaction();
    try {
      foreach ($uids as $uid) {
        if ($this->getCurrentEntryOnWo($uid, $woId)) {
          $skipped[$uid] = 'already clocked in';
          continue;
        }
        $entry = $this->createOpenEntry($uid, $woId);
        // Crew origin, so a reader can tell this was one attestation rather
        // than N independent clock-ins. Overrides the button default set by
        // createOpenEntry().
        $entry->set('field_source', 'wo_clock_crew');
        if ($lat !== NULL && $lon !== NULL) {
          // ⚠ This is the FOREMAN's location, stamped on everyone. Todd,
          // 2026-11-03: "if the foreman is including them they better also be
          // their" — so it is an attestation of presence, and field_source
          // above is what stops it being mistaken for N separate captures.
          $entry->set('field_clock_in_location', $this->wkt($lat, $lon));
        }
        $entry->set('field_notes', NULL);
        $this->appendNote($entry, sprintf(self::NOTE_CREW_START, $stamp, $actor));
        $entry->save();
        $created[$uid] = $entry;
      }
    }
    catch (\Throwable $e) {
      // Explicit. unset() would COMMIT the partial crew.
      $tx->rollBack();
      throw $e;
    }
    // Tell the office somebody has started — but only if we actually opened
    // something. A press that skipped everyone (all already clocked in) has
    // started nothing new and should not re-announce the job.
    if ($created) {
      $this->markInProgress($woId, sprintf('Crew Clocked In (%d) by %s', count($created), $actor));
    }
    return ['created' => $created, 'skipped' => $skipped];
  }

  /**
   * Clock a whole crew OUT on one work order.
   *
   * Closes every entry still open. Entries already closed are left alone —
   * that is the person who finished early, and their earlier end time must
   * survive the crew action.
   *
   * ⚠ CAP HANDLING IS ALL-OR-NOTHING AND CHECKED BEFORE ANY WRITE. The
   * single-entry cap is enforced on save, so closing five people one by one
   * could close three and reject two, leaving a crew half clocked out with no
   * obvious signal. Every entry is tested first; if any exceed, nothing is
   * written and the caller is handed the names to confirm.
   *
   * @return array
   *   ['closed' => [...]] on success, or
   *   ['needs_confirm' => [['name','hours','cap'], …]] when the cap would bite.
   */
  public function crewClockOut(int $woId, ?float $lat = NULL, ?float $lon = NULL, bool $override = FALSE): array {
    $open = $this->getOpenEntriesOnWo($woId);
    if (!$open) {
      throw new \RuntimeException('Nobody is clocked in on this work order.');
    }

    if (!$override) {
      $exceed = [];
      foreach ($open as $entry) {
        $cap = $this->capExceedanceHours($entry);
        if ($cap) {
          $u = $entry->get('field_teammate')->entity;
          $exceed[] = [
            'name' => $u ? $u->getDisplayName() : 'Unknown',
            'hours' => $cap['hours'],
            'cap' => $cap['cap'],
          ];
        }
      }
      if ($exceed) {
        // Nothing written. The caller asks, then calls back with override.
        return ['needs_confirm' => $exceed];
      }
    }

    $closed = [];
    $actor = $this->currentUser->getDisplayName();
    $stamp = $this->usDisplay($this->time->getRequestTime());
    $tx = \Drupal::database()->startTransaction();
    try {
      foreach ($open as $entry) {
        $entry->set('field_end_time', $this->nowUtc());
        if ($lat !== NULL && $lon !== NULL) {
          $entry->set('field_clock_out_location', $this->wkt($lat, $lon));
        }
        if ($override && $entry->hasField('field_time_limit_override')) {
          $entry->set('field_time_limit_override', TRUE);
        }
        $this->appendNote($entry, sprintf(self::NOTE_CREW_END, $stamp, $actor));
        $entry->_wo_clock_write = TRUE;
        $entry->save();
        $u = $entry->get('field_teammate')->entity;
        $closed[] = $u ? $u->getDisplayName() : ('uid ' . $entry->get('field_teammate')->target_id);
      }
    }
    catch (\Throwable $e) {
      // Explicit rollBack — unset() COMMITS, which would leave exactly the
      // partial close this method exists to prevent.
      $tx->rollBack();
      throw $e;
    }
    return ['closed' => $closed];
  }

  /**
   * Haversine distance in feet.
   */
  public function calculateDistanceFeet(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
      + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return self::EARTH_RADIUS_FEET * $c;
  }

  /**
   * The WO's property location from field_property → field_geofield.
   *
   * @return array{lat: float, lon: float}|null
   */
  public function getPropertyLocation(int $woId): ?array {
    if ($woId <= 0) {
      return NULL;
    }
    $wo = $this->entityTypeManager->getStorage('work_order')->load($woId);
    if (!$wo || !$wo->hasField('field_property') || $wo->get('field_property')->isEmpty()) {
      return NULL;
    }
    $property = $wo->get('field_property')->entity;
    if (!$property || !$property->hasField('field_geofield') || $property->get('field_geofield')->isEmpty()) {
      return NULL;
    }
    $geo = $property->get('field_geofield')->first()->getValue();
    if (!isset($geo['lat'], $geo['lon'])) {
      return NULL;
    }
    return ['lat' => (float) $geo['lat'], 'lon' => (float) $geo['lon']];
  }

  // -- internal helpers ------------------------------------------------------

  private function loadEntry(int $entryId): EntityInterface {
    $entry = $this->tcStorage()->load($entryId);
    if (!$entry) {
      throw new \InvalidArgumentException("wo_time_clock entry $entryId not found");
    }
    return $entry;
  }

  private function wkt(float $lat, float $lon): string {
    // Geofield WKT is POINT (lon lat).
    return sprintf('POINT (%F %F)', $lon, $lat);
  }

  /**
   * Append a structured note line to field_notes, newline-separated. Notes
   * accumulate across a start and a later end — existing content is never
   * replaced.
   */
  private function appendNote(EntityInterface $entry, string $line): void {
    $existing = (string) ($entry->get('field_notes')->value ?? '');
    $entry->set('field_notes', $existing === '' ? $line : $existing . "\n" . $line);
  }

  private function usDisplay(int $timestamp): string {
    $dt = new \DateTime('@' . $timestamp);
    $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return $dt->format('m/d/Y g:i A');
  }

  /**
   * TRUE when a WO (by id) is in a billed/locked status (Invoiced 1281 /
   * Paid 1504) that is closed to any new time entry — no clock-in allowed.
   */
  public function woIsLocked(int $woId): bool {
    if ($woId <= 0) {
      return FALSE;
    }
    $wo = $this->entityTypeManager->getStorage('work_order')->load($woId);
    if (!$wo || !$wo->hasField('field_status')) {
      return FALSE;
    }
    return in_array((int) $wo->get('field_status')->target_id, self::LOCKED_STATUSES, TRUE);
  }

  private function parentWoIsLocked(EntityInterface $entry): bool {
    if (!$entry->hasField('field_work_order') || $entry->get('field_work_order')->isEmpty()) {
      return FALSE;
    }
    $wo = $entry->get('field_work_order')->entity;
    if (!$wo || !$wo->hasField('field_status')) {
      return FALSE;
    }
    return in_array((int) $wo->get('field_status')->target_id, self::LOCKED_STATUSES, TRUE);
  }

}
