<?php

namespace Drupal\admin_calendar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns completed Work Order events for the calendar overlay.
 *
 * Queries wo_complete_info via field_date_completed.
 * Filtered by the same teammate/department params as the scheduled layer.
 *
 * Query parameters:
 *   start      — ISO date string (FullCalendar range start)
 *   end        — ISO date string (FullCalendar range end)
 *   department — (optional) department entity ID
 *   teammate   — (optional) user ID (matches field_those_on_crew)
 */
class AdminCalendarCompletedController extends ControllerBase {

  protected Connection $database;

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function completed(Request $request): JsonResponse {
    $start = $request->query->get('start');
    $end   = $request->query->get('end');
    $department_filter = $request->query->get('department');
    $teammate_filter   = $request->query->get('teammate');

    if (!$start || !$end) {
      return new JsonResponse([]);
    }

    // Use Drupal's configured site timezone for timestamp conversion.
    // field_date_completed_value is stored using the server's local timezone
    // via Drupal's time system, not UTC-normalized. Must match for range queries.
    $site_tz  = new \DateTimeZone(date_default_timezone_get());
    $start_ts = (new \DateTime($start, $site_tz))->getTimestamp();
    $end_ts   = (new \DateTime($end, $site_tz))->getTimestamp();

    $query = $this->database->select('wo_complete_info_field_data', 'wci');
    $query->fields('wci', ['id', 'created']);

    // Completion date — Unix timestamp, convert to UTC ISO for FullCalendar.
    $query->join(
      'wo_complete_info__field_date_completed',
      'dc',
      'dc.entity_id = wci.id AND dc.deleted = 0'
    );
    $query->addExpression(
      "DATE_FORMAT(FROM_UNIXTIME(dc.field_date_completed_value), :fmt)",
      'completed_utc',
      [':fmt' => '%Y-%m-%dT%H:%i:%s']
    );
    $query->addField('dc', 'field_date_completed_value', 'completed_ts');

    // Filter to requested date range.
    $query->condition('dc.field_date_completed_value', $start_ts, '>=');
    $query->condition('dc.field_date_completed_value', $end_ts, '<');

    // Work order reference.
    $query->join(
      'wo_complete_info__field_work_order',
      'wciwo',
      'wciwo.entity_id = wci.id AND wciwo.deleted = 0'
    );
    $query->addField('wciwo', 'field_work_order_target_id');

    // Work order base.
    $query->leftJoin(
      'work_order',
      'wo',
      'wo.id = wciwo.field_work_order_target_id'
    );

    // Exclude canceled WOs.
    $query->leftJoin(
      'work_order__field_status',
      'wos',
      'wos.entity_id = wo.id AND wos.deleted = 0'
    );
    $query->condition('wos.field_status_target_id', [1098], 'NOT IN');

    // Property nickname.
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

    // Service + SOP code.
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
    $query->leftJoin(
      'taxonomy_term__field_sop_code',
      'svccode',
      'svccode.entity_id = svc.tid AND svccode.deleted = 0'
    );
    $query->addField('svccode', 'field_sop_code_value', 'service_code');

    // Department + color.
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

    // Crew on completion record (field_those_on_crew — multi-value user ref).
    $query->leftJoin(
      'wo_complete_info__field_those_on_crew',
      'crew',
      'crew.entity_id = wci.id AND crew.deleted = 0'
    );
    $query->addField('crew', 'field_those_on_crew_target_id', 'crew_uid');

    // Crew member name from profile.
    $query->leftJoin(
      'profile',
      'cp',
      'cp.uid = crew.field_those_on_crew_target_id AND cp.type = :pt AND cp.status = 1',
      [':pt' => 'teammate_profile']
    );
    $query->leftJoin(
      'profile__field_first_name',
      'cpfn',
      'cpfn.entity_id = cp.profile_id AND cpfn.deleted = 0'
    );
    $query->leftJoin(
      'profile__field_last_name',
      'cpln',
      'cpln.entity_id = cp.profile_id AND cpln.deleted = 0'
    );
    $query->addField('cpfn', 'field_first_name_value', 'crew_first');
    $query->addField('cpln', 'field_last_name_value', 'crew_last');

    // Optional filters.
    if (!empty($department_filter)) {
      $query->condition('dept.id', $department_filter);
    }
    if (!empty($teammate_filter)) {
      $query->condition('crew.field_those_on_crew_target_id', $teammate_filter);
    }

    $query->orderBy('dc.field_date_completed_value', 'ASC');
    $query->distinct();

    $results = $query->execute()->fetchAll();

    // Deduplicate — multi-value crew field causes multiple rows per WO.
    // Group by wo_complete_info id, collect crew names.
    $grouped = [];
    foreach ($results as $row) {
      $key = $row->id;
      if (!isset($grouped[$key])) {
        $grouped[$key] = $row;
        $grouped[$key]->crew_names = [];
      }
      $crew_name = trim(($row->crew_first ?? '') . ' ' . ($row->crew_last ?? ''));
      if ($crew_name) {
        $grouped[$key]->crew_names[] = $crew_name;
      }
    }

    $events = [];
    foreach ($grouped as $row) {
      try {
        $wo_url = Url::fromRoute(
          'entity.work_order.canonical',
          ['work_order' => $row->field_work_order_target_id]
        )->toString();
      }
      catch (\Exception $e) {
        $wo_url = '/admin/content';
      }

      $property = trim($row->property_nickname ?? '') ?: 'Unknown Property';
      $property_short = strlen($property) > 22
        ? substr($property, 0, 21) . '…'
        : $property;

      $service_code = strtoupper(trim($row->service_code ?? ''))
        ?: (trim($row->service_name ?? '') ?: '?');

      $crew_names = array_unique($row->crew_names ?? []);
      $crew_str   = implode(', ', $crew_names);

      // Completed events are all-day on the completion date.
      $date_only = substr($row->completed_utc, 0, 10);

      // Check if backdated: completed_ts vs created more than 4 hours apart.
      $backdated = (abs($row->completed_ts - $row->created) > 14400);

      // Color: gray = completed same day, dark gray = backdated.
      // Gray avoids conflict with department colors (green = Landscaping, etc).
      $color = $backdated ? '#495057' : '#6c757d';

      $events[] = [
        'id'     => 'completed_' . $row->id,
        'title'  => '✓ ' . $property_short . ' — ' . $service_code,
        'start'  => $date_only,
        'end'    => $date_only,
        'allDay' => TRUE,
        'color'  => $color,
        'url'    => $wo_url,
        'extendedProps' => [
          'woEntityId'     => (int) $row->field_work_order_target_id,
          'propertyNickname' => $property,
          'serviceName'    => trim($row->service_name ?? ''),
          'serviceCode'    => $service_code,
          'departmentName' => trim($row->department_title ?? '') ?: 'Unassigned',
          'crewNames'      => $crew_str,
          'backdated'      => $backdated,
          'completedLayer' => TRUE,
          'description'    => 'Crew: ' . ($crew_str ?: 'Unknown') . ($backdated ? ' · Backdated' : ''),
        ],
      ];
    }

    return new JsonResponse($events);
  }

}
