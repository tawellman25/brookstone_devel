<?php

namespace Drupal\admin_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns scheduling events as FullCalendar-compatible JSON.
 *
 * Query parameters:
 *   start       — ISO date string (FullCalendar range start)
 *   end         — ISO date string (FullCalendar range end)
 *   department  — (optional) department entity ID to filter by
 *   teammate    — (optional) user ID to filter by field_assigned_to
 *   firm_only   — (optional) 1 to show only firm-scheduled events
 *   statuses    — (optional) comma-separated list of WO status TIDs to include
 *                 Default: active statuses only (excludes Complete/Invoiced/Paid/Canceled)
 *                 Pass specific TIDs to include historical statuses.
 *
 * Verified table names (from live DB inspection):
 *   - scheduling_field_data          ECK scheduling base
 *   - work_order                     ECK work_order base (no _field_data suffix)
 *   - property_field_data            ECK property (has _field_data)
 *   - properties__field_nickname     Note: plural "properties" prefix
 *   - scheduling__field_scheduled_oder  Note: typo in field name (oder not order)
 */
class AdminCalendarEventsController extends ControllerBase {

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Active WO statuses that appear on the dispatch calendar by default.
   * Per work_order_status.md visibility rules.
   */
  const ACTIVE_STATUSES = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096];

  /**
   * All statuses available for filtering (active + historical).
   */
  const ALL_STATUSES = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096, 1097, 1283, 1281, 1504, 1098];

  /**
   * Status TID to label map for extendedProps.
   */
  const STATUS_LABELS = [
    1089 => 'Open',
    1099 => 'Needs Confirmed',
    1095 => 'Waiting for Customer Response',
    1503 => 'Accepted',
    1091 => 'Scheduled',
    1090 => 'Assigned',
    1092 => 'In Progress',
    1093 => 'Needs Parts',
    1094 => 'Parts Ordered',
    1096 => 'Needs Access',
    1097 => 'Complete',
    1283 => 'Warrantied',
    1281 => 'Invoiced',
    1504 => 'Paid',
    1098 => 'Canceled',
  ];

  /**
   * {@inheritdoc}
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database')
    );
  }

  /**
   * Returns JSON array of FullCalendar event objects.
   */
  public function events(Request $request): JsonResponse {
    $start = $request->query->get('start');
    $end = $request->query->get('end');
    $department_filter = $request->query->get('department');
    $teammate_filter = $request->query->get('teammate');
    $firm_only = $request->query->get('firm_only');
    $statuses_param = $request->query->get('statuses');

    if (!$start || !$end) {
      return new JsonResponse([]);
    }

    // Parse status filter — default to active statuses only.
    if (!empty($statuses_param)) {
      $requested = array_map('intval', explode(',', $statuses_param));
      $statuses = array_intersect($requested, self::ALL_STATUSES);
      if (empty($statuses)) {
        $statuses = self::ACTIVE_STATUSES;
      }
    }
    else {
      $statuses = self::ACTIVE_STATUSES;
    }

    $events = $this->buildEvents($start, $end, $department_filter, $teammate_filter, $firm_only, $statuses);
    return new JsonResponse($events);
  }

  /**
   * Builds initials code from first and last name.
   * Format: First 2 chars of first name + first char of last name, e.g. ToW.
   */
  protected function buildInitials(string $first_name, string $last_name): string {
    $first = trim($first_name);
    $last = trim($last_name);
    if (!$first && !$last) {
      return '';
    }
    $initials = '';
    if (strlen($first) >= 2) {
      $initials .= strtoupper(substr($first, 0, 1)) . strtolower(substr($first, 1, 1));
    }
    elseif (strlen($first) === 1) {
      $initials .= strtoupper($first);
    }
    if ($last) {
      $initials .= strtoupper(substr($last, 0, 1));
    }
    return $initials;
  }

  /**
   * Queries scheduling entities and builds FullCalendar event objects.
   */
  protected function buildEvents(
    string $start,
    string $end,
    ?string $department_filter,
    ?string $teammate_filter,
    ?string $firm_only,
    array $statuses
  ): array {

    $query = $this->database->select('scheduling_field_data', 's');
    $query->fields('s', ['id', 'title']);

    // ── Date range ───────────────────────────────────────────────────────
    // field_date is smartdate — values stored as Unix timestamps (UTC).
    // All-day detection: duration >= 1365 AND <= 1440 minutes.
    // FullCalendar handles UTC→MT conversion via timeZone: 'America/Denver'.
    $query->join(
      'scheduling__field_date',
      'sdt',
      's.id = sdt.entity_id AND sdt.deleted = 0'
    );
    // Smartdate stores timestamps that FROM_UNIXTIME converts using
    // MariaDB's session timezone (America/Denver in DDEV and on live).
    // No CONVERT_TZ needed — output is already in site-local time.
    // FullCalendar timeZone is set to match, so times render correctly.
    $query->addExpression("DATE_FORMAT(FROM_UNIXTIME(sdt.field_date_value), :fmt_start)", 'date_start', [':fmt_start' => '%Y-%m-%dT%H:%i:%s']);
    $query->addExpression("DATE_FORMAT(FROM_UNIXTIME(sdt.field_date_end_value), :fmt_end)", 'date_end', [':fmt_end' => '%Y-%m-%dT%H:%i:%s']);
    $query->addField('sdt', 'field_date_duration', 'date_duration');
    $query->addField('sdt', 'field_date_value', 'date_ts');

    // Convert FullCalendar ISO range params to Unix timestamps for comparison.
    $site_tz  = new \DateTimeZone(date_default_timezone_get());
    $start_ts = (new \DateTime($start, $site_tz))->getTimestamp();
    $end_ts   = (new \DateTime($end, $site_tz))->getTimestamp();
    $query->condition('sdt.field_date_value', $end_ts, '<');
    $query->condition('sdt.field_date_end_value', $start_ts, '>=');

    // ── Work order reference ─────────────────────────────────────────────
    $query->join(
      'scheduling__field_work_order',
      'swo',
      's.id = swo.entity_id AND swo.deleted = 0'
    );
    $query->addField('swo', 'field_work_order_target_id');

    $query->leftJoin(
      'work_order',
      'wo',
      'wo.id = swo.field_work_order_target_id'
    );

    // Note: field_work_order_id is a migration artifact staged for deletion.
    // Not referenced in event output.

    // ── Status filter ────────────────────────────────────────────────────
    $query->leftJoin(
      'work_order__field_status',
      'wos',
      'wos.entity_id = wo.id AND wos.deleted = 0'
    );
    $query->condition('wos.field_status_target_id', $statuses, 'IN');
    $query->addField('wos', 'field_status_target_id', 'status_tid');

    // ── Property nickname ────────────────────────────────────────────────
    $query->leftJoin(
      'work_order__field_property',
      'wop',
      'wop.entity_id = wo.id AND wop.deleted = 0'
    );
    $query->leftJoin(
      'properties__field_nickname',
      'nick',
      'nick.entity_id = wop.field_property_target_id AND nick.deleted = 0'
    );
    $query->addField('nick', 'field_nickname_value', 'property_nickname');

    // ── Service taxonomy term + SOP code ─────────────────────────────────
    $query->leftJoin(
      'work_order__field_service',
      'wosvc',
      'wosvc.entity_id = wo.id AND wosvc.deleted = 0'
    );
    $query->leftJoin(
      'taxonomy_term_field_data',
      'svc',
      'svc.tid = wosvc.field_service_target_id'
    );
    $query->addField('svc', 'name', 'service_name');
    $query->addField('svc', 'tid', 'service_tid');

    // SOP code from services taxonomy term — authoritative service abbreviation.
    $query->leftJoin(
      'taxonomy_term__field_sop_code',
      'svccode',
      'svccode.entity_id = svc.tid AND svccode.deleted = 0'
    );
    $query->addField('svccode', 'field_sop_code_value', 'service_code');

    // ── Department ───────────────────────────────────────────────────────
    $query->leftJoin(
      'taxonomy_term__field_department',
      'svcdept',
      'svcdept.entity_id = svc.tid AND svcdept.deleted = 0'
    );
    $query->leftJoin(
      'department_field_data',
      'dept',
      'dept.id = svcdept.field_department_target_id'
    );
    $query->addField('dept', 'id', 'department_id');
    $query->addField('dept', 'title', 'department_title');

    // ── Department color ─────────────────────────────────────────────────
    $query->leftJoin(
      'department__field_color',
      'deptc',
      'deptc.entity_id = dept.id AND deptc.deleted = 0'
    );
    $query->addField('deptc', 'field_color_value', 'department_color');

    // ── Assigned teammate ────────────────────────────────────────────────
    $query->leftJoin(
      'scheduling__field_assigned_to',
      'sat',
      's.id = sat.entity_id AND sat.deleted = 0'
    );
    $query->addField('sat', 'field_assigned_to_target_id', 'assigned_uid');

    $query->leftJoin(
      'profile',
      'tp',
      'tp.uid = sat.field_assigned_to_target_id AND tp.type = :profile_type AND tp.status = 1',
      [':profile_type' => 'teammate_profile']
    );
    $query->leftJoin(
      'profile__field_first_name',
      'pfn',
      'pfn.entity_id = tp.profile_id AND pfn.deleted = 0'
    );
    $query->leftJoin(
      'profile__field_last_name',
      'pln',
      'pln.entity_id = tp.profile_id AND pln.deleted = 0'
    );
    $query->addField('pfn', 'field_first_name_value', 'first_name');
    $query->addField('pln', 'field_last_name_value', 'last_name');

    // ── Schedule order (field_scheduled_oder — typo is in field machine name) ──
    $query->leftJoin(
      'scheduling__field_scheduled_oder',
      'sord',
      's.id = sord.entity_id AND sord.deleted = 0'
    );
    $query->addField('sord', 'field_scheduled_oder_value', 'schedule_order');

    // ── Firm/tentative flag ──────────────────────────────────────────────
    $query->leftJoin(
      'scheduling__field_scheduled_firm',
      'sfirm',
      's.id = sfirm.entity_id AND sfirm.deleted = 0'
    );
    $query->addField('sfirm', 'field_scheduled_firm_value', 'is_firm');

    // ── Scheduling note ──────────────────────────────────────────────────
    $query->leftJoin(
      'scheduling__field_scheduling_note',
      'snote',
      's.id = snote.entity_id AND snote.deleted = 0'
    );
    $query->addField('snote', 'field_scheduling_note_value', 'scheduling_note');

    // ── Optional filters ─────────────────────────────────────────────────
    if (!empty($department_filter)) {
      $query->condition('dept.id', $department_filter);
    }
    if (!empty($teammate_filter)) {
      $query->condition('sat.field_assigned_to_target_id', $teammate_filter);
    }
    if (!empty($firm_only)) {
      $query->condition('sfirm.field_scheduled_firm_value', 1);
    }

    $query->orderBy('sdt.field_date_value', 'ASC');
    $query->orderBy('sord.field_scheduled_oder_value', 'ASC');

    $results = $query->execute()->fetchAll();

    // ── Build FullCalendar event objects ─────────────────────────────────
    $events = [];
    foreach ($results as $row) {
      try {
        $wo_url = Url::fromRoute(
          'entity.work_order.canonical',
          ['work_order' => $row->field_work_order_target_id]
        )->toString();
      }
      catch (\Exception $e) {
        $wo_url = '/admin/content';
      }

      $first_name = trim($row->first_name ?? '');
      $last_name  = trim($row->last_name ?? '');
      $teammate   = trim($first_name . ' ' . $last_name);
      $initials   = $this->buildInitials($first_name, $last_name);

      // Order code: initials + zero-padded order number, e.g. ToW-01.
      $order_num = (int) ($row->schedule_order ?? 0);
      if ($initials && $order_num > 0) {
        $order_code = $initials . '-' . str_pad($order_num, 2, '0', STR_PAD_LEFT);
      }
      elseif ($initials) {
        $order_code = $initials;
      }
      else {
        $order_code = '';
      }

      // Property nickname truncated to 22 chars.
      $property_full = trim($row->property_nickname ?? '') ?: 'Unknown Property';
      $property_short = strlen($property_full) > 22
        ? substr($property_full, 0, 21) . '…'
        : $property_full;

      // Service abbreviation from SOP code; normalize to uppercase.
      $service_code = trim($row->service_code ?? '');
      $service_code = $service_code ? strtoupper($service_code) : (trim($row->service_name ?? '') ?: '?');

      // Build event title: [ToW-01] Property — SVC
      $title_parts = [];
      if ($order_code) {
        $title_parts[] = '[' . $order_code . ']';
      }
      $title_parts[] = $property_short;
      $title_parts[] = $service_code;
      $title = implode(' — ', $title_parts);

      $color  = trim($row->department_color ?? '') ?: '#888888';
      $status_tid = (int) ($row->status_tid ?? 0);
      $status_label = self::STATUS_LABELS[$status_tid] ?? 'Unknown';

      // Desaturate color for completed/invoiced/paid statuses.
      if (in_array($status_tid, [1097, 1283, 1281, 1504])) {
        $color = '#aaaaaa';
      }
      // Red tint for canceled.
      if ($status_tid === 1098) {
        $color = '#c0392b';
      }

      $desc_parts = [];
      if ($teammate) {
        $desc_parts[] = 'Assigned: ' . $teammate;
      }
      $desc_parts[] = !empty($row->is_firm) ? '✓ Firm' : '~ Tentative';
      $desc_parts[] = $status_label;
      if (!empty($row->scheduling_note)) {
        $desc_parts[] = $row->scheduling_note;
      }

      // For all-day events, omit end or set equal to start to prevent
      // FullCalendar's exclusive end date from spanning an extra day.
      // All-day detection: smartdate stores duration in minutes.
      // Duration 1365-1440 = all-day patterns (24h, 23h59m, 23h45m, UTC offsets).
      $duration = (int) ($row->date_duration ?? 0);
      $is_all_day = ($duration >= 1365 && $duration <= 1440);

      // For all-day events send date only (no time) — FullCalendar renders
      // these as all-day blocks. For timed events send full ISO datetime.
      if ($is_all_day) {
        // Use PHP to convert timestamp to date in site timezone.
        // Avoids FROM_UNIXTIME server-timezone dependency.
        $site_tz  = new \DateTimeZone(date_default_timezone_get());
        $fc_start = (new \DateTime('@' . $row->date_ts, $site_tz))
          ->setTimezone($site_tz)
          ->format('Y-m-d');
        $fc_end   = $fc_start;
      }
      else {
        $fc_start = $row->date_start;
        $fc_end   = $row->date_end;
      }

      $events[] = [
        'id'     => $row->id,
        'title'  => $title,
        'start'  => $fc_start,
        'end'    => $fc_end,
        'allDay' => $is_all_day,
        'color'  => $color,
        'url'    => $wo_url,
        'extendedProps' => [
          'woEntityId'       => (int) $row->field_work_order_target_id,
          'propertyNickname' => $property_full,
          'propertyShort'    => $property_short,
          'serviceName'      => trim($row->service_name ?? ''),
          'serviceCode'      => $service_code,
          'departmentName'   => trim($row->department_title ?? '') ?: 'Unassigned',
          'teammateName'     => $teammate,
          'initials'         => $initials,
          'orderCode'        => $order_code,
          'scheduleOrder'    => $order_num,
          'statusTid'        => $status_tid,
          'statusLabel'      => $status_label,
          'isFirm'           => !empty($row->is_firm),
          'note'             => trim($row->scheduling_note ?? ''),
          'description'      => implode(' · ', $desc_parts),
        ],
      ];
    }

    return $events;
  }

}
