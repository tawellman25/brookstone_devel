<?php

namespace Drupal\bos_scheduling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Supervisor dispatch board — daily WO status view grouped by teammate.
 *
 * Path: /teammates/calendar/dispatch
 * Access: supervisor, administrator, site_admin, administration, site_assistant
 *
 * Shows all WOs scheduled for a given day, grouped by assigned teammate,
 * sorted by route order. Includes unassigned WOs at the bottom.
 * Auto-refreshes every 5 minutes via JS.
 */
class DispatchController extends ControllerBase {

  protected Connection $database;

  const VISIBLE_STATUSES = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096, 1097, 1283];

  const STATUS_LABELS = [
    1089 => 'Open',
    1099 => 'Needs Confirmed',
    1095 => 'Waiting',
    1503 => 'Accepted',
    1091 => 'Scheduled',
    1090 => 'Assigned',
    1092 => 'In Progress',
    1093 => 'Needs Parts',
    1094 => 'Parts Ordered',
    1096 => 'Needs Access',
    1097 => 'Complete',
    1283 => 'Warrantied',
  ];

  const STATUS_CLASS = [
    1089 => 'open',
    1099 => 'open',
    1095 => 'open',
    1503 => 'open',
    1091 => 'scheduled',
    1090 => 'assigned',
    1092 => 'inprogress',
    1093 => 'needsparts',
    1094 => 'needsparts',
    1096 => 'needsaccess',
    1097 => 'complete',
    1283 => 'complete',
  ];

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function page(Request $request): array {
    $site_tz = new \DateTimeZone(date_default_timezone_get());

    $date_param = $request->query->get('date');
    if ($date_param && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_param)) {
      $current = new \DateTime($date_param, $site_tz);
    }
    else {
      $current = new \DateTime('today', $site_tz);
    }

    $dept_filter = $request->query->get('dept', '');
    $data = $this->getDispatchData($current, $site_tz, $dept_filter);

    $today    = new \DateTime('today', $site_tz);
    $tomorrow = new \DateTime('tomorrow', $site_tz);
    $date_label = match(TRUE) {
      $current->format('Y-m-d') === $today->format('Y-m-d')    => 'Today — ' . $current->format('l, F j'),
      $current->format('Y-m-d') === $tomorrow->format('Y-m-d') => 'Tomorrow — ' . $current->format('l, F j'),
      default => $current->format('l, F j, Y'),
    };

    $prev_date = (clone $current)->modify('-1 day')->format('Y-m-d');
    $next_date = (clone $current)->modify('+1 day')->format('Y-m-d');

    return [
      '#theme'       => 'bos_scheduling_dispatch',
      '#date'        => $current->format('Y-m-d'),
      '#date_label'  => $date_label,
      '#prev_date'   => $prev_date,
      '#next_date'   => $next_date,
      '#dept_filter' => $dept_filter,
      '#departments' => $this->getDepartments(),
      '#teammates'   => $data['teammates'],
      '#unassigned'  => $data['unassigned'],
      '#stats'       => $data['stats'],
      '#attached'    => [
        'library' => ['bos_scheduling/dispatch'],
        'drupalSettings' => [
          'bosDispatch' => [
            'refreshInterval' => 300000,
          ],
        ],
      ],
    ];
  }

  protected function getDispatchData(\DateTime $date, \DateTimeZone $tz, string $dept_filter): array {
    $start_ts = (clone $date)->setTime(0, 0, 0)->getTimestamp();
    $end_ts   = (clone $date)->setTime(23, 59, 59)->getTimestamp();

    $query = $this->database->select('scheduling_field_data', 's');
    $query->fields('s', ['id']);

    $query->join('scheduling__field_date', 'fd', 's.id = fd.entity_id AND fd.deleted = 0');
    $query->condition('fd.field_date_value', $start_ts, '>=');
    $query->condition('fd.field_date_value', $end_ts, '<=');

    $query->join('scheduling__field_work_order', 'swo', 's.id = swo.entity_id AND swo.deleted = 0');
    $query->addField('swo', 'field_work_order_target_id', 'wo_id');

    $query->leftJoin('work_order__field_status', 'wos', 'wos.entity_id = swo.field_work_order_target_id AND wos.deleted = 0');
    $query->condition('wos.field_status_target_id', self::VISIBLE_STATUSES, 'IN');
    $query->addField('wos', 'field_status_target_id', 'status_tid');

    $query->leftJoin('scheduling__field_assigned_to', 'sat', 's.id = sat.entity_id AND sat.deleted = 0');
    $query->addField('sat', 'field_assigned_to_target_id', 'assigned_uid');

    $query->leftJoin('scheduling__field_scheduled_oder', 'sord', 's.id = sord.entity_id AND sord.deleted = 0');
    $query->addField('sord', 'field_scheduled_oder_value', 'schedule_order');

    $query->leftJoin('scheduling__field_scheduled_firm', 'sfirm', 's.id = sfirm.entity_id AND sfirm.deleted = 0');
    $query->addField('sfirm', 'field_scheduled_firm_value', 'is_firm');

    $query->leftJoin('work_order__field_property', 'wop', 'wop.entity_id = swo.field_work_order_target_id AND wop.deleted = 0');
    $query->leftJoin('properties__field_nickname', 'nick', 'nick.entity_id = wop.field_property_target_id AND nick.deleted = 0');
    $query->addField('nick', 'field_nickname_value', 'property_nickname');

    // Aeration flag heads.
    $query->leftJoin(
      'work_order__field_aeration_flag_heads',
      'afh',
      'afh.entity_id = swo.field_work_order_target_id AND afh.deleted = 0'
    );
    $query->addField('afh', 'field_aeration_flag_heads_value', 'aeration_flag');

    $query->leftJoin('work_order__field_service', 'wosvc', 'wosvc.entity_id = swo.field_work_order_target_id AND wosvc.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'svc', 'svc.tid = wosvc.field_service_target_id');
    $query->addField('svc', 'name', 'service_name');
    $query->leftJoin('taxonomy_term__field_sop_code', 'svccode', 'svccode.entity_id = svc.tid AND svccode.deleted = 0');
    $query->addField('svccode', 'field_sop_code_value', 'service_code');

    $query->leftJoin('taxonomy_term__field_department', 'svcdept', 'svcdept.entity_id = svc.tid AND svcdept.deleted = 0');
    $query->leftJoin('department_field_data', 'dept', 'dept.id = svcdept.field_department_target_id');
    $query->addField('dept', 'id', 'department_id');
    $query->addField('dept', 'title', 'department_title');
    $query->leftJoin('department__field_color', 'deptc', 'deptc.entity_id = dept.id AND deptc.deleted = 0');
    $query->addField('deptc', 'field_color_value', 'department_color');

    $query->leftJoin('profile', 'tp', 'tp.uid = sat.field_assigned_to_target_id AND tp.type = :pt AND tp.status = 1', [':pt' => 'teammate_profile']);
    $query->leftJoin('profile__field_first_name', 'pfn', 'pfn.entity_id = tp.profile_id AND pfn.deleted = 0');
    $query->leftJoin('profile__field_last_name', 'pln', 'pln.entity_id = tp.profile_id AND pln.deleted = 0');
    $query->addExpression("TRIM(CONCAT(COALESCE(pfn.field_first_name_value,''),' ',COALESCE(pln.field_last_name_value,'')))", 'teammate_name');

    if (!empty($dept_filter)) {
      $query->condition('dept.id', $dept_filter);
    }

    $query->orderBy('pln.field_last_name_value', 'ASC');
    $query->orderBy('sord.field_scheduled_oder_value', 'ASC');

    $results = $query->execute()->fetchAll();

    $teammates  = [];
    $unassigned = [];
    $stats = ['total' => 0, 'inprogress' => 0, 'complete' => 0, 'unassigned' => 0];

    foreach ($results as $row) {
      $stats['total']++;
      $status_tid   = (int) ($row->status_tid ?? 0);
      $status_class = self::STATUS_CLASS[$status_tid] ?? 'open';

      if (in_array($status_tid, [1097, 1283])) $stats['complete']++;
      if ($status_tid === 1092) $stats['inprogress']++;

      try {
        $wo_url = Url::fromRoute('entity.work_order.canonical', ['work_order' => $row->wo_id])->toString();
      } catch (\Exception $e) {
        $wo_url = '/';
      }

      $job = [
        'scheduling_id'    => $row->id,
        'wo_id'            => $row->wo_id,
        'wo_url'           => $wo_url,
        'schedule_order'   => (int) ($row->schedule_order ?? 0),
        'is_firm'          => (bool) ($row->is_firm ?? FALSE),
        'status_tid'       => $status_tid,
        'status_label'     => self::STATUS_LABELS[$status_tid] ?? 'Unknown',
        'status_class'     => $status_class,
        'property_nickname'=> trim($row->property_nickname ?? '') ?: 'Unknown',
        'service_code'     => strtoupper(trim($row->service_code ?? '')) ?: (trim($row->service_name ?? '') ?: '?'),
        'service_name'     => trim($row->service_name ?? ''),
        'department_color' => trim($row->department_color ?? '') ?: '#888888',
        'aeration_flag'    => (bool) ($row->aeration_flag ?? FALSE),
      ];

      if (empty($row->assigned_uid)) {
        $stats['unassigned']++;
        $unassigned[] = $job;
      }
      else {
        $uid = (int) $row->assigned_uid;
        if (!isset($teammates[$uid])) {
          $teammates[$uid] = [
            'uid'        => $uid,
            'name'       => trim($row->teammate_name ?? '') ?: 'Unknown',
            'department' => trim($row->department_title ?? '') ?: 'Unassigned',
            'dept_color' => trim($row->department_color ?? '') ?: '#888888',
            'jobs'       => [],
          ];
        }
        $teammates[$uid]['jobs'][] = $job;
      }
    }

    return [
      'teammates'  => array_values($teammates),
      'unassigned' => $unassigned,
      'stats'      => $stats,
    ];
  }

  protected function getDepartments(): array {
    $query = $this->database->query("SELECT id, title FROM department_field_data ORDER BY title");
    $depts = [];
    foreach ($query->fetchAll() as $row) {
      $depts[$row->id] = $row->title;
    }
    return $depts;
  }

}
