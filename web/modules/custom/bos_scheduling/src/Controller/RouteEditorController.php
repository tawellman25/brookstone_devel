<?php

declare(strict_types=1);

namespace Drupal\bos_scheduling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Map-backed route editor on the scheduling day view (STAGE 2: read-only).
 *
 * Shows a date range (default: the week containing the selected day) of
 * scheduled sprinkler WOs on one Google map, colored by day, with one route
 * line per (day, tech) drawn in field_scheduled_oder sequence. No editing yet.
 *
 * tz-safe: field_date is a smartdate Unix timestamp; we compare raw integers
 * and format in PHP (America/Denver) — never FROM_UNIXTIME (the new VPS runs
 * MariaDB in UTC; see drupal_bos_gotchas.md).
 */
final class RouteEditorController extends ControllerBase {

  /**
   * Work-order bundles this build routes now. WO-type agnostic by design —
   * add bundles here (or make it config) to extend beyond sprinkler.
   */
  private const ROUTED_BUNDLES = [
    'sprinkler_winterizing', 'sprinkler_start_up', 'sprinkler_check_up',
    'sprinkler_repair', 'sprinkler_installation', 'backflow_testing',
  ];

  /**
   * Sane bounding box for western Colorado. Coordinates outside → "No location"
   * bucket, never plotted (a bad point silently drags the viewport to the sea).
   */
  private const BBOX = ['lat_min' => 36.5, 'lat_max' => 41.5, 'lng_min' => -109.5, 'lng_max' => -105.0];

  /**
   * Work-order statuses that CLOSE a stop to schedule edits, no exceptions:
   * Complete (1097), Warrantied (1283), Invoiced (1281), Paid (1504),
   * Canceled (1098). The crews have done the work and/or the office has billed
   * it — re-dating or reassigning it rewrites finished history, and a
   * scheduling save fires wo_schedule, which writes a "Scheduled" (1091)
   * status record back onto the work order (see the 2026-09-29 incident, where
   * an order-only re-save knocked 17 invoiced work orders back to Scheduled).
   *
   * Every write endpoint here checks this set and refuses. Stated as a RULE —
   * "a finished work order is never rescheduled" — not as a list of the
   * transitions we happened to think of: enumerating the forbidden case is
   * exactly what let that incident through (the older guard in wo_schedule
   * names 1097 and 1098 and says nothing about 1281, 1504 or 1283).
   *
   * These stops are still listed (greyed, as context) but never plotted. That is
   * presentation; the guard is what actually holds. It is NOT redundant with the
   * greying: this page is long-lived and refetches only on load or after a write,
   * so a crew can sign a job off while it sits on someone's screen — the stop is
   * still drawn as editable until the next refetch, and only the guard sees
   * current truth.
   */
  private const LOCKED_STATUSES = [1097, 1283, 1281, 1504, 1098];

  public function __construct(protected Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  /**
   * The editor shell: map container, legend, day columns, no-location bucket.
   */
  public function page(Request $request): array {
    $tz = new \DateTimeZone(date_default_timezone_get());
    [$start, $end, $days] = $this->resolveRange($request, $tz);
    $n = count($days);
    $range = (string) $n;

    // Prev/Next shift the window by its own length, anchored on the start day.
    $prev = (clone $start)->modify("-{$n} days")->format('Y-m-d');
    $next = (clone $start)->modify("+{$n} days")->format('Y-m-d');

    $gmap_key = (string) $this->config('geofield_map.settings')->get('gmap_api_key');

    return [
      '#theme' => 'bos_scheduling_route_editor',
      '#start' => $start->format('Y-m-d'),
      '#end'   => $end->format('Y-m-d'),
      '#range' => $range,
      '#prev'  => $prev,
      '#next'  => $next,
      '#has_map_key' => $gmap_key !== '',
      '#attached' => [
        'library' => ['bos_scheduling/route_editor'],
        'drupalSettings' => [
          'bosRouteEditor' => [
            'dataUrl'  => Url::fromRoute('bos_scheduling.route_editor_data')->toString(),
            'start'    => $start->format('Y-m-d'),
            'end'      => $end->format('Y-m-d'),
            'range'    => $range,
            'gmapKey'  => $gmap_key,
            'assignUrl' => Url::fromRoute('bos_scheduling.route_editor_assign')->toString(),
            'reorderUrl' => Url::fromRoute('bos_scheduling.route_editor_reorder')->toString(),
            'rescheduleUrl' => Url::fromRoute('bos_scheduling.route_editor_reschedule')->toString(),
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Permanent redirect from the retired /teammates/calendar/route-editor path.
   *
   * The editor is gated to office + supervisor (no teammates), so it never
   * belonged under /teammates/; it now sits beside the sprinkler bulk scheduler
   * under /admin/office/work-orders/scheduling/. The query string is carried over
   * so a bookmarked day or range still lands where it used to.
   */
  public function legacyRedirect(Request $request): RedirectResponse {
    $url = Url::fromRoute('bos_scheduling.route_editor', [], [
      'query' => $request->query->all(),
    ])->toString();
    return new RedirectResponse($url, 301);
  }

  /**
   * JSON: stops (with coords), no-location bucket, techs, origin, day list.
   */
  public function data(Request $request): CacheableJsonResponse {
    $tz = new \DateTimeZone(date_default_timezone_get());
    [$start, $end, $days] = $this->resolveRange($request, $tz);
    $start_ts = (clone $start)->setTime(0, 0, 0)->getTimestamp();
    $end_ts   = (clone $end)->setTime(23, 59, 59)->getTimestamp();

    $rows = $this->fetch($start_ts, $end_ts);

    $stops = [];
    $no_location = [];
    $techs = [];
    foreach ($rows as $r) {
      $day = (new \DateTime('@' . (int) $r->date_ts))->setTimezone($tz)->format('Y-m-d');
      $uid = (int) ($r->assigned_uid ?? 0);
      $tech = trim((string) ($r->teammate_name ?? '')) ?: ($uid ? 'Unknown' : 'Unassigned');
      if ($uid && !isset($techs[$uid])) {
        $techs[$uid] = ['uid' => $uid, 'name' => $tech];
      }
      try {
        $wo_url = Url::fromRoute('entity.work_order.canonical', ['work_order' => $r->wo_id])->toString();
      }
      catch (\Throwable $e) {
        $wo_url = '/';
      }
      $base = [
        'scheduling_id' => (int) $r->id,
        'wo_id' => (int) $r->wo_id,
        'wo_url' => $wo_url,
        'date' => $day,
        'assigned_uid' => $uid,
        'tech' => $tech,
        'order' => (int) ($r->schedule_order ?? 0),
        'route_order_set' => (bool) ($r->route_order_set ?? 0),
        'nickname' => mb_substr(trim((string) ($r->property_nickname ?? '')) ?: 'Unknown', 0, 120),
        'service_code' => strtoupper(trim((string) ($r->service_code ?? ''))) ?: '?',
        'status_tid' => (int) ($r->status_tid ?? 0),
        'status_label' => DispatchController::STATUS_LABELS[(int) ($r->status_tid ?? 0)] ?? 'Unknown',
        // Drives the whole read-only treatment client-side: greyed in the list,
        // no pin on the map, skipped by the route line and by select-all.
        'locked' => in_array((int) ($r->status_tid ?? 0), self::LOCKED_STATUSES, TRUE),
      ];
      $coord = $this->parsePoint((string) ($r->geofield ?? ''));
      if ($coord === NULL) {
        $no_location[] = $base + ['reason' => trim((string) ($r->geofield ?? '')) === '' ? 'missing' : 'out_of_bounds'];
      }
      else {
        $stops[] = $base + ['lat' => $coord['lat'], 'lng' => $coord['lng']];
      }
    }

    // Route origin (the shop) from config → property → geofield. Fail loud.
    $origin = $this->originPoint();

    $payload = [
      'range' => [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d'),
        'days' => array_map(fn($d) => $d->format('Y-m-d'), $days),
      ],
      'origin' => $origin,
      'techs' => array_values($techs),
      'teammates' => $this->roster(),
      'stops' => $stops,
      'no_location' => $no_location,
      'counts' => ['stops' => count($stops), 'no_location' => count($no_location)],
    ];

    $response = new CacheableJsonResponse($payload);
    $response->addCacheableDependency((new CacheableMetadata())->setCacheMaxAge(0));
    return $response;
  }

  /**
   * STAGE 3 (write): reassign the crew member on one or more scheduling records.
   *
   * POST JSON: {scheduling_ids: [int], uid: int}  (uid 0 = unassign).
   * CSRF-guarded (X-CSRF-Token header, see routing). Each save runs through the
   * normal scheduling save path, so wo_schedule writes the "Re-assigned to …"
   * audit note + WO status update for us. Teammate email notifications are
   * suppressed — a bulk map reassignment must not fire a blast of emails.
   */
  public function assign(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE) ?: [];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['scheduling_ids'] ?? [])))));
    $uid = (int) ($data['uid'] ?? 0);

    if (!$ids) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'No stops selected.'], 400);
    }
    // uid 0 = unassign; any other must be an active teammate.
    if ($uid !== 0) {
      $valid = FALSE;
      foreach ($this->roster() as $t) {
        if ((int) $t['uid'] === $uid) { $valid = TRUE; break; }
      }
      if (!$valid) {
        return new JsonResponse(['ok' => FALSE, 'error' => 'Unknown crew member.'], 400);
      }
    }

    $storage = $this->entityTypeManager()->getStorage('scheduling');
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $blocked = [];
    foreach ($ids as $id) {
      try {
        $entity = $storage->load($id);
        if (!$entity || $entity->bundle() !== 'work_order') {
          $skipped++;
          continue;
        }
        // A finished work order is never reassigned. See LOCKED_STATUSES.
        $woId = (int) ($entity->get('field_work_order')->target_id ?? 0);
        if ($woId && ($why = $this->lockedReason($woId)) !== NULL) {
          $blocked[] = $this->stopLabel($entity) . ' is ' . $why;
          continue;
        }
        $current = (int) ($entity->get('field_assigned_to')->target_id ?? 0);
        if ($current === $uid) {
          $skipped++;
          continue;
        }
        $entity->set('field_assigned_to', $uid === 0 ? NULL : $uid);
        // Never fire teammate notification emails on a bulk map reassignment.
        if ($entity->hasField('field_notify_assigned_teammate')) {
          $entity->set('field_notify_assigned_teammate', FALSE);
        }
        $entity->save();
        $updated++;
      }
      catch (\Throwable $e) {
        $errors[] = "#$id: " . $e->getMessage();
      }
    }

    return new JsonResponse([
      'ok' => empty($errors),
      'updated' => $updated,
      'skipped' => $skipped,
      'blocked' => $blocked,
      'errors' => $errors,
    ]);
  }

  /**
   * STAGE 6 (write): move one or more stops to a different DAY.
   *
   * POST JSON: {scheduling_ids: [int], date: 'YYYY-MM-DD'}.
   * CSRF-guarded (see routing).
   *
   * Writes field_date in exactly ScheduleWriter's shape — an all-day smart-date
   * span at LOCAL midnight (duration 1439) — so a moved stop is indistinguishable
   * from one the bulk scheduler or the carry-forward created. The legacy
   * field_scheduled_date_and_time daterange and the all-day flag are NOT written
   * here: wo_schedule's presave syncs the daterange from field_date and
   * custom_date_all_day sets the flag on every scheduling save.
   *
   * Midnight is computed in the SITE timezone and stored as a Unix timestamp.
   * Doing this in SQL would bucket the day wrong for half the year — MariaDB runs
   * UTC on this VPS while the site is America/Denver (drupal_bos_gotchas.md).
   *
   * Route order is deliberately left alone. A stop landing on a new day keeps its
   * old sequence number, which may collide with a stop already there; the office
   * fixes that by dragging or hitting Optimize on the receiving route, which is a
   * decision about driving order, not something to guess at here.
   *
   * field_scheduled_firm (the "we told the customer this day" commitment flag) is
   * also untouched — moving a firm stop does not quietly un-promise it.
   *
   * wo_schedule writes the "Rescheduled" audit note and the status record for us,
   * so the move is traceable on the work order without anything written directly.
   */
  public function reschedule(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE) ?: [];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['scheduling_ids'] ?? [])))));
    $date = trim((string) ($data['date'] ?? ''));

    if (!$ids) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'No stops selected.'], 400);
    }
    // Strict Y-m-d, and a real calendar date (rejects 2026-02-31).
    $tz = new \DateTimeZone(date_default_timezone_get());
    $target = \DateTime::createFromFormat('!Y-m-d', $date, $tz);
    if (!$target || $target->format('Y-m-d') !== $date) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'Pick a valid date (YYYY-MM-DD).'], 400);
    }
    $startTs = (clone $target)->setTime(0, 0, 0)->getTimestamp();

    $storage = $this->entityTypeManager()->getStorage('scheduling');
    $updated = 0;
    $skipped = 0;
    $blocked = [];
    $errors = [];
    foreach ($ids as $id) {
      try {
        $entity = $storage->load($id);
        if (!$entity || $entity->bundle() !== 'work_order') {
          $skipped++;
          continue;
        }
        // THE RULE: a finished work order is never rescheduled. Read live.
        $woId = (int) ($entity->get('field_work_order')->target_id ?? 0);
        if ($woId && ($why = $this->lockedReason($woId)) !== NULL) {
          $blocked[] = $this->stopLabel($entity) . ' is ' . $why;
          continue;
        }
        if ((int) ($entity->get('field_date')->value ?? 0) === $startTs) {
          $skipped++;
          continue;
        }
        $entity->set('field_date', [
          'value' => $startTs,
          'end_value' => $startTs + 86340,
          'duration' => 1439,
        ]);
        // Never fire teammate notification emails on a bulk map move: the update
        // hook emails on ANY save where notify == 1, not just on reassignment.
        if ($entity->hasField('field_notify_assigned_teammate')) {
          $entity->set('field_notify_assigned_teammate', FALSE);
        }
        $entity->save();
        $updated++;
      }
      catch (\Throwable $e) {
        $errors[] = "#$id: " . $e->getMessage();
      }
    }

    return new JsonResponse([
      'ok' => empty($errors),
      'updated' => $updated,
      'skipped' => $skipped,
      'blocked' => $blocked,
      'errors' => $errors,
      'date' => $date,
    ]);
  }

  /**
   * Short human label for a stop, for refusal messages ("Corn, Larry (WO 52582)").
   */
  protected function stopLabel(EntityInterface $entity): string {
    $woId = (int) ($entity->get('field_work_order')->target_id ?? 0);
    $name = '';
    if ($woId) {
      $q = $this->database->select('work_order__field_property', 'wop');
      $q->condition('wop.entity_id', $woId);
      $q->condition('wop.deleted', 0);
      $q->leftJoin('properties__field_nickname', 'nick', 'nick.entity_id = wop.field_property_target_id AND nick.deleted = 0');
      $q->addField('nick', 'field_nickname_value', 'nickname');
      $q->range(0, 1);
      $name = trim((string) ($q->execute()->fetchField() ?: ''));
    }
    return ($name !== '' ? $name : 'Stop #' . $entity->id()) . ($woId ? " (WO $woId)" : '');
  }

  /**
   * STAGE 4 (write): reorder the stops within one route (a day+tech column).
   *
   * POST JSON: {ordered_ids: [scheduling_id, …]} in the desired sequence.
   * Writes field_scheduled_oder = 1..N densely. CSRF-guarded (see routing).
   *
   * Order is intentionally NOT a wo_schedule-tracked field, so a reorder writes
   * no audit note. We still suppress field_notify_assigned_teammate because the
   * update hook emails on ANY save where notify==1 (not gated on assignment
   * changing) — a reorder must never fire an assignment email. We deliberately
   * do NOT go through ScheduleWriter here: it would reset field_scheduled_firm
   * (the customer-commitment flag — untouched) and rewrite the date. Only the
   * sequence changes.
   *
   * Arranging a route this way stamps field_route_order_set = TRUE on each stop
   * (drag AND Optimize both post here). The winterize carry-forward reuses a
   * route-order-set route's planned order next year instead of reconstructing
   * it from the driven order — so the office's arranging effort carries forward.
   */
  public function reorder(Request $request): JsonResponse {
    $data = json_decode((string) $request->getContent(), TRUE) ?: [];
    $ids = array_values(array_filter(array_map('intval', (array) ($data['ordered_ids'] ?? []))));
    if (!$ids) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'No stops to reorder.'], 400);
    }

    $storage = $this->entityTypeManager()->getStorage('scheduling');
    $updated = 0;
    $skipped = 0;
    $errors = [];
    foreach ($ids as $i => $id) {
      $pos = $i + 1;
      try {
        $entity = $storage->load($id);
        if (!$entity || $entity->bundle() !== 'work_order') {
          $skipped++;
          continue;
        }
        // A finished stop holds its position: its slot is still counted above,
        // so the stops around it keep coherent numbering, but it is not saved.
        $woId = (int) ($entity->get('field_work_order')->target_id ?? 0);
        if ($woId && $this->lockedReason($woId) !== NULL) {
          $skipped++;
          continue;
        }
        $needOrder = (int) ($entity->get('field_scheduled_oder')->value ?? 0) !== $pos;
        $hasSetField = $entity->hasField('field_route_order_set');
        $needStamp = $hasSetField && !((bool) $entity->get('field_route_order_set')->value);
        if (!$needOrder && !$needStamp) {
          $skipped++;
          continue;
        }
        if ($needOrder) {
          $entity->set('field_scheduled_oder', $pos);
        }
        if ($hasSetField) {
          $entity->set('field_route_order_set', TRUE);
        }
        if ($entity->hasField('field_notify_assigned_teammate')) {
          $entity->set('field_notify_assigned_teammate', FALSE);
        }
        $entity->save();
        $updated++;
      }
      catch (\Throwable $e) {
        $errors[] = "#$id: " . $e->getMessage();
      }
    }

    return new JsonResponse([
      'ok' => empty($errors),
      'updated' => $updated,
      'skipped' => $skipped,
      'errors' => $errors,
    ]);
  }

  /**
   * Is this scheduling record's work order closed to schedule edits?
   *
   * Reads the work order's CURRENT status straight from the field table — never
   * a value the browser sent, and never the one baked into the page, which may
   * be minutes stale (on 2026-09-29 six work orders were invoiced inside the
   * window between two runs of a repair script, and a clock-time cutoff got
   * them wrong).
   *
   * @return string|null
   *   The blocking status label, or NULL when the stop may be edited.
   */
  protected function lockedReason(int $woId): ?string {
    $tid = (int) ($this->database->select('work_order__field_status', 'wos')
      ->fields('wos', ['field_status_target_id'])
      ->condition('wos.entity_id', $woId)
      ->condition('wos.deleted', 0)
      ->range(0, 1)
      ->execute()->fetchField() ?: 0);

    if (!in_array($tid, self::LOCKED_STATUSES, TRUE)) {
      return NULL;
    }
    return DispatchController::STATUS_LABELS[$tid]
      ?? (self::LOCKED_STATUS_LABELS[$tid] ?? 'a closed status');
  }

  /**
   * Labels for the locked statuses DispatchController doesn't name (they sit
   * outside VISIBLE_STATUSES, so its map has no entry for them). Only reached
   * if a stop's status changes to one of these after the page was drawn.
   */
  private const LOCKED_STATUS_LABELS = [1281 => 'Invoiced', 1504 => 'Paid', 1098 => 'Canceled'];

  /**
   * Active teammates assignable to a route (uid + display name), last-name sort.
   * Mirrors SprinklerSchedulingController::getTeammates().
   */
  protected function roster(): array {
    $q = $this->database->select('users_field_data', 'u');
    $q->fields('u', ['uid']);
    $q->join('user__roles', 'ur', 'ur.entity_id = u.uid AND ur.roles_target_id = :role', [':role' => 'teammates']);
    $q->join('profile', 'tp', 'tp.uid = u.uid AND tp.type = :pt AND tp.status = 1', [':pt' => 'teammate_profile']);
    $q->leftJoin('profile__field_first_name', 'fn', 'fn.entity_id = tp.profile_id AND fn.deleted = 0');
    $q->leftJoin('profile__field_last_name', 'ln', 'ln.entity_id = tp.profile_id AND ln.deleted = 0');
    $q->addExpression("TRIM(CONCAT(COALESCE(fn.field_first_name_value,''),' ',COALESCE(ln.field_last_name_value,'')))", 'name');
    $q->condition('u.status', 1);
    $q->orderBy('ln.field_last_name_value', 'ASC');
    $q->orderBy('fn.field_first_name_value', 'ASC');

    $out = [];
    foreach ($q->execute()->fetchAll() as $r) {
      $out[] = ['uid' => (int) $r->uid, 'name' => trim((string) $r->name) ?: ('User ' . $r->uid)];
    }
    return $out;
  }

  /**
   * Range query — reuses the dispatch join pattern, generalized to a date
   * window + geofield + routed bundles. Ordered deterministically.
   */
  protected function fetch(int $start_ts, int $end_ts): array {
    $q = $this->database->select('scheduling_field_data', 's');
    $q->fields('s', ['id']);
    $q->join('scheduling__field_date', 'fd', 's.id = fd.entity_id AND fd.deleted = 0');
    $q->addField('fd', 'field_date_value', 'date_ts');
    $q->condition('fd.field_date_value', $start_ts, '>=');
    $q->condition('fd.field_date_value', $end_ts, '<=');

    $q->join('scheduling__field_work_order', 'swo', 's.id = swo.entity_id AND swo.deleted = 0');
    $q->addField('swo', 'field_work_order_target_id', 'wo_id');

    // Routed bundles only (WO-type agnostic via the constant).
    $q->join('work_order_field_data', 'wo', 'wo.id = swo.field_work_order_target_id');
    $q->condition('wo.type', self::ROUTED_BUNDLES, 'IN');

    // Finished stops ARE fetched: they render greyed out in the day columns, so
    // the office can see what the crew has already knocked out, but they are not
    // plotted — no pin, and the route line skips them (js/route-editor.js keys
    // both on the 'locked' flag below). Keeping them out of the query instead
    // would empty the list too.
    $q->leftJoin('work_order__field_status', 'wos', 'wos.entity_id = swo.field_work_order_target_id AND wos.deleted = 0');
    $q->condition('wos.field_status_target_id', DispatchController::VISIBLE_STATUSES, 'IN');
    $q->addField('wos', 'field_status_target_id', 'status_tid');

    $q->leftJoin('scheduling__field_assigned_to', 'sat', 's.id = sat.entity_id AND sat.deleted = 0');
    $q->addField('sat', 'field_assigned_to_target_id', 'assigned_uid');
    $q->leftJoin('scheduling__field_scheduled_oder', 'sord', 's.id = sord.entity_id AND sord.deleted = 0');
    $q->addField('sord', 'field_scheduled_oder_value', 'schedule_order');
    $q->leftJoin('scheduling__field_route_order_set', 'sros', 's.id = sros.entity_id AND sros.deleted = 0');
    $q->addField('sros', 'field_route_order_set_value', 'route_order_set');

    $q->leftJoin('work_order__field_property', 'wop', 'wop.entity_id = swo.field_work_order_target_id AND wop.deleted = 0');
    $q->leftJoin('properties__field_nickname', 'nick', 'nick.entity_id = wop.field_property_target_id AND nick.deleted = 0');
    $q->addField('nick', 'field_nickname_value', 'property_nickname');
    $q->leftJoin('properties__field_geofield', 'geo', 'geo.entity_id = wop.field_property_target_id AND geo.deleted = 0');
    $q->addField('geo', 'field_geofield_value', 'geofield');

    $q->leftJoin('work_order__field_service', 'wosvc', 'wosvc.entity_id = swo.field_work_order_target_id AND wosvc.deleted = 0');
    $q->leftJoin('taxonomy_term__field_sop_code', 'svccode', 'svccode.entity_id = wosvc.field_service_target_id AND svccode.deleted = 0');
    $q->addField('svccode', 'field_sop_code_value', 'service_code');

    $q->leftJoin('profile', 'tp', 'tp.uid = sat.field_assigned_to_target_id AND tp.type = :pt AND tp.status = 1', [':pt' => 'teammate_profile']);
    $q->leftJoin('profile__field_first_name', 'pfn', 'pfn.entity_id = tp.profile_id AND pfn.deleted = 0');
    $q->leftJoin('profile__field_last_name', 'pln', 'pln.entity_id = tp.profile_id AND pln.deleted = 0');
    $q->addExpression("TRIM(CONCAT(COALESCE(pfn.field_first_name_value,''),' ',COALESCE(pln.field_last_name_value,'')))", 'teammate_name');

    // Deterministic ordering: date, tech, then sequence (never range without sort).
    $q->orderBy('fd.field_date_value', 'ASC');
    $q->orderBy('pln.field_last_name_value', 'ASC');
    $q->orderBy('sord.field_scheduled_oder_value', 'ASC');

    return $q->execute()->fetchAll();
  }

  /**
   * Resolve [start, end, days[]] from ?date + ?range (1|3|7). Default: the
   * Sunday-anchored week containing the selected/target day.
   */
  protected function resolveRange(Request $request, \DateTimeZone $tz): array {
    $date_param = (string) $request->query->get('date', '');
    $sel = ($date_param && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_param))
      ? new \DateTime($date_param, $tz) : new \DateTime('today', $tz);
    $sel->setTime(0, 0, 0);
    $range = (int) $request->query->get('range', 7);
    $range = in_array($range, [1, 3, 7], TRUE) ? $range : 7;

    if ($range === 7) {
      $start = (clone $sel)->modify('-' . (int) $sel->format('w') . ' days');
    }
    else {
      $start = clone $sel;
    }
    $days = [];
    for ($i = 0; $i < $range; $i++) {
      $days[] = (clone $start)->modify("+$i days");
    }
    $end = end($days);
    return [$start, $end, $days];
  }

  /**
   * Parse a "POINT (lng lat)" WKT to ['lat','lng'], or NULL if missing/invalid/
   * outside the western-CO bounding box.
   */
  protected function parsePoint(string $wkt): ?array {
    if ($wkt === '' || !preg_match('/POINT\s*\(\s*(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s*\)/i', $wkt, $m)) {
      return NULL;
    }
    $lng = (float) $m[1];
    $lat = (float) $m[2];
    if ($lat < self::BBOX['lat_min'] || $lat > self::BBOX['lat_max']
      || $lng < self::BBOX['lng_min'] || $lng > self::BBOX['lng_max']) {
      return NULL;
    }
    return ['lat' => $lat, 'lng' => $lng];
  }

  /**
   * The route origin (shop): config route_origin_property_id → property →
   * geofield. Returns NULL (with a reason) if unusable, so the UI can disable
   * Optimize rather than route from (0,0).
   */
  protected function originPoint(): ?array {
    $pid = (int) ($this->config('bos_scheduling.settings')->get('route_origin_property_id') ?: 50413);
    $p = $this->entityTypeManager()->getStorage('properties')->load($pid);
    if (!$p) {
      return ['ok' => FALSE, 'reason' => "origin property $pid not found", 'pid' => $pid];
    }
    $coord = $this->parsePoint((string) $p->get('field_geofield')->value);
    if ($coord === NULL) {
      return ['ok' => FALSE, 'reason' => "origin property $pid has no usable geofield", 'pid' => $pid];
    }
    return ['ok' => TRUE, 'pid' => $pid, 'label' => $p->label(), 'lat' => $coord['lat'], 'lng' => $coord['lng']];
  }

}
