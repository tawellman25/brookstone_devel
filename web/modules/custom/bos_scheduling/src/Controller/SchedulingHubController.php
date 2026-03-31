<?php

namespace Drupal\bos_scheduling\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SchedulingHubController extends ControllerBase {

  protected Connection $database;

  const TOOLS = [
    'sprinkler' => [
      'label'       => 'Sprinkler systems',
      'path'        => '/admin/office/work-orders/scheduling/sprinkler',
      'active'      => TRUE,
      'bundles'     => [
        'sprinkler_start_up','sprinkler_winterizing','sprinkler_check_up',
        'sprinkler_repair','backflow_testing','sprinkler_design','sprinkler_installation',
      ],
      'type_labels' => 'Start Up · Winterizing · Check Up · Repair · Backflow · Design · Installation',
    ],
    'spraying' => [
      'label'       => 'Spraying',
      'path'        => '/admin/office/work-orders/scheduling/spraying',
      'active'      => FALSE,
      'bundles'     => [
        'pre_emergent','weed_spraying','dormant_oil','trunk_bore',
        'aspen_twig_gall','cooley_spruce_gall','grub_prevention','deciduous_bore',
      ],
      'type_labels' => 'Pre-emergent · Weed Control · Dormant Oil · Tree & Shrub Spraying',
    ],
    'clean_ups' => [
      'label'       => 'Clean-ups',
      'path'        => '/admin/office/work-orders/scheduling/clean-ups',
      'active'      => FALSE,
      'bundles'     => ['spring_cleanup','fall_cleanup','summer_pruning','dethatching','aerating'],
      'type_labels' => 'Spring Cleanup · Fall Cleanup · Summer Pruning · Dethatching · Aerating',
    ],
    'landscaping' => [
      'label'       => 'Landscaping',
      'path'        => '/admin/office/work-orders/scheduling/landscaping',
      'active'      => FALSE,
      'bundles'     => ['landscaping'],
      'type_labels' => 'Design-Build · Installation · Patios · Retaining Walls · Planting',
    ],
  ];

  const ACTIVE_STATUSES = [1089,1099,1095,1503,1091,1090,1092,1093,1094,1096];

  public function __construct(Connection $database) {
    $this->database = $database;
  }

  public static function create(ContainerInterface $container): static {
    return new static($container->get('database'));
  }

  public function page(): array {
    $counts  = $this->getUnscheduledCounts();
    $totals  = $this->getScheduledTotals();
    $tools   = [];
    $total_unscheduled = 0;

    foreach (self::TOOLS as $key => $tool) {
      $unscheduled = 0;
      $scheduled   = 0;
      $total       = 0;
      foreach ($tool['bundles'] as $bundle) {
        $unscheduled += $counts['unscheduled'][$bundle] ?? 0;
        $scheduled   += $totals['scheduled'][$bundle] ?? 0;
        $total       += $totals['total'][$bundle] ?? 0;
      }
      $total_unscheduled += $unscheduled;
      $tools[$key] = $tool + [
        'unscheduled'  => $unscheduled,
        'scheduled'    => $scheduled,
        'total'        => $total,
        'progress_pct' => $total > 0 ? round(($scheduled / $total) * 100) : 0,
      ];
    }

    $active_tools  = array_filter($tools, fn($t) => $t['active']);
    $planned_tools = array_filter($tools, fn($t) => !$t['active']);

    return [
      '#theme'         => 'bos_scheduling_hub',
      '#active_tools'  => $active_tools,
      '#planned_tools' => $planned_tools,
      '#stats'         => [
        'total_unscheduled' => $total_unscheduled,
        'scheduled_week'    => $this->getScheduledThisWeek(),
        'active_tools'      => count($active_tools),
        'planned_tools'     => count($planned_tools),
      ],
      '#attached' => ['library' => ['bos_scheduling/scheduling_hub']],
    ];
  }

  protected function getUnscheduledCounts(): array {
    $all_bundles = [];
    foreach (self::TOOLS as $tool) {
      $all_bundles = array_merge($all_bundles, $tool['bundles']);
    }
    $all_bundles = array_unique($all_bundles);
    $query = $this->database->select('work_order', 'w');
    $query->fields('w', ['type']);
    $query->addExpression('COUNT(w.id)', 'cnt');
    $query->join('work_order__field_status', 'wos', 'wos.entity_id = w.id AND wos.deleted = 0');
    $query->condition('wos.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    $query->condition('w.type', $all_bundles, 'IN');
    $subquery = $this->database->select('scheduling__field_work_order', 'swo');
    $subquery->fields('swo', ['field_work_order_target_id']);
    $subquery->condition('swo.deleted', 0);
    $query->condition('w.id', $subquery, 'NOT IN');
    $query->groupBy('w.type');
    $unscheduled = [];
    foreach ($query->execute()->fetchAll() as $row) {
      $unscheduled[$row->type] = (int) $row->cnt;
    }
    return ['unscheduled' => $unscheduled];
  }

  protected function getScheduledTotals(): array {
    $all_bundles = [];
    foreach (self::TOOLS as $tool) {
      $all_bundles = array_merge($all_bundles, $tool['bundles']);
    }
    $all_bundles = array_unique($all_bundles);
    $q = $this->database->select('work_order', 'w');
    $q->fields('w', ['type']);
    $q->addExpression('COUNT(w.id)', 'cnt');
    $q->join('work_order__field_status', 'wos', 'wos.entity_id = w.id AND wos.deleted = 0');
    $q->condition('wos.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    $q->condition('w.type', $all_bundles, 'IN');
    $q->groupBy('w.type');
    $total = [];
    foreach ($q->execute()->fetchAll() as $row) {
      $total[$row->type] = (int) $row->cnt;
    }
    $sq = $this->database->select('work_order', 'w2');
    $sq->fields('w2', ['type']);
    $sq->addExpression('COUNT(w2.id)', 'cnt');
    $sq->join('work_order__field_status', 'wos2', 'wos2.entity_id = w2.id AND wos2.deleted = 0');
    $sq->condition('wos2.field_status_target_id', self::ACTIVE_STATUSES, 'IN');
    $sq->condition('w2.type', $all_bundles, 'IN');
    $sq->join('scheduling__field_work_order', 'swo', 'swo.field_work_order_target_id = w2.id AND swo.deleted = 0');
    $sq->groupBy('w2.type');
    $scheduled = [];
    foreach ($sq->execute()->fetchAll() as $row) {
      $scheduled[$row->type] = (int) $row->cnt;
    }
    return ['total' => $total, 'scheduled' => $scheduled];
  }

  protected function getScheduledThisWeek(): int {
    $site_tz    = new \DateTimeZone(date_default_timezone_get());
    $week_start = (new \DateTime('monday this week', $site_tz))->setTime(0,0,0);
    $week_end   = (new \DateTime('sunday this week', $site_tz))->setTime(23,59,59);
    $query = $this->database->select('scheduling__field_date', 'fd');
    $query->condition('fd.field_date_value', $week_start->getTimestamp(), '>=');
    $query->condition('fd.field_date_value', $week_end->getTimestamp(), '<=');
    $query->condition('fd.deleted', 0);
    return (int) $query->countQuery()->execute()->fetchField();
  }

}
