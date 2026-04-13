<?php

namespace Drupal\work_load_hub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Work Load Hub dashboard controller.
 */
class WorkLoadHubController extends ControllerBase {

  /**
   * Renders the Work Load Hub dashboard.
   */
  public function dashboard(Request $request) {
    $current_year = (int) date('Y');
    $year = (int) ($request->query->get('year') ?? $current_year);

    // Clamp to valid range.
    if ($year < 2017 || $year > $current_year + 1) {
      $year = $current_year;
    }

    $database = \Drupal::database();

    // Get all WO status terms in weight order.
    $statuses = $database->query("
      SELECT t.tid, t.name, t.weight
      FROM {taxonomy_term_field_data} t
      WHERE t.vid = 'wo_status'
      ORDER BY t.weight
    ")->fetchAllAssoc('tid');

    // Pivot query: count + totals per bundle per status.
    $rows = $database->query("
      SELECT
        wo.type AS bundle,
        s.field_status_target_id AS status_tid,
        COUNT(wo.id) AS wo_count,
        COALESCE(SUM(wt.field_wo_total_value), 0) AS total_billed
      FROM {work_order_field_data} wo
      INNER JOIN {work_order__field_status} s ON s.entity_id = wo.id
      LEFT JOIN {work_order__field_wo_total} wt ON wt.entity_id = wo.id
      WHERE YEAR(FROM_UNIXTIME(wo.created)) = :year
      GROUP BY wo.type, s.field_status_target_id
      ORDER BY wo.type, s.field_status_target_id
    ", [':year' => $year])->fetchAll();

    // Get bundle labels.
    $bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order');

    // Build pivot: $data[bundle][status_tid] = [count, total].
    $data = [];
    foreach ($rows as $row) {
      $bundle = $row->bundle;
      $tid = $row->status_tid;
      if (!isset($data[$bundle])) {
        $data[$bundle] = [];
      }
      $data[$bundle][$tid] = [
        'count' => (int) $row->wo_count,
        'total' => (float) $row->total_billed,
      ];
    }
    ksort($data);

    // Determine which statuses have any data this year.
    $active_statuses = [];
    foreach ($data as $bundle_data) {
      foreach ($bundle_data as $tid => $vals) {
        if ($vals['count'] > 0 && isset($statuses[$tid])) {
          $active_statuses[$tid] = $statuses[$tid];
        }
      }
    }
    // Sort by weight.
    uasort($active_statuses, function ($a, $b) {
      return $a->weight <=> $b->weight;
    });

    // TIDs for financial calculations.
    $invoiced_tids = [1281, 1504]; // Invoiced, Paid.
    $complete_tid = 1097;

    // Build the table header.
    $header = [['data' => 'Service', 'class' => ['work-load-service']]];
    foreach ($active_statuses as $tid => $term) {
      $header[] = ['data' => $term->name, 'class' => ['work-load-status']];
    }
    $header[] = ['data' => 'Total', 'class' => ['work-load-total']];
    $header[] = ['data' => 'To Invoice $', 'class' => ['work-load-money']];
    $header[] = ['data' => 'Invoiced $', 'class' => ['work-load-money']];
    $header[] = ['data' => 'Avg Value', 'class' => ['work-load-money']];

    // Build table rows.
    $table_rows = [];
    $totals_row = [
      'statuses' => array_fill_keys(array_keys($active_statuses), 0),
      'total' => 0,
      'invoiced' => 0.0,
      'to_invoice' => 0.0,
      'revenue_count' => 0,
    ];

    foreach ($data as $bundle => $status_data) {
      $label = $bundle_info[$bundle]['label'] ?? $bundle;
      $row_total = 0;
      $invoiced_revenue = 0.0;
      $to_invoice = 0.0;
      $revenue_count = 0;

      $service_url = '/admin/office/work-orders?' . http_build_query([
        'created_op' => 'between',
        'created' => ['value' => '', 'min' => $year . '-01-01', 'max' => $year . '-12-31'],
        'type' => [$bundle],
        'field_invoiced_value' => 'All',
        'id' => '',
        'field_nickname_value' => '',
        'title' => '',
      ]);
      $row = [['data' => ['#markup' => '<a href="' . $service_url . '">' . htmlspecialchars($label) . '</a>'], 'class' => ['work-load-service']]];

      foreach ($active_statuses as $tid => $term) {
        $count = $status_data[$tid]['count'] ?? 0;
        $total = $status_data[$tid]['total'] ?? 0.0;
        $row_total += $count;
        $totals_row['statuses'][$tid] += $count;

        if (in_array($tid, $invoiced_tids)) {
          $invoiced_revenue += $total;
          $revenue_count += $count;
        }
        if ($tid == $complete_tid) {
          $to_invoice += $total;
          $revenue_count += $count;
        }

        $cell_class = ['work-load-count'];
        if ($count === 0) {
          $cell_class[] = 'work-load-zero';
        }
        $row[] = ['data' => $count > 0 ? number_format($count) : '–', 'class' => $cell_class];
      }

      $avg = $revenue_count > 0 ? ($invoiced_revenue + $to_invoice) / $revenue_count : 0;

      $row[] = ['data' => number_format($row_total), 'class' => ['work-load-total']];
      $row[] = ['data' => $to_invoice > 0 ? '$' . number_format($to_invoice, 2) : '–', 'class' => ['work-load-money']];
      $row[] = ['data' => '$' . number_format($invoiced_revenue, 2), 'class' => ['work-load-money']];
      $row[] = ['data' => $avg > 0 ? '$' . number_format($avg, 2) : '–', 'class' => ['work-load-money']];

      $totals_row['total'] += $row_total;
      $totals_row['invoiced'] += $invoiced_revenue;
      $totals_row['to_invoice'] += $to_invoice;
      $totals_row['revenue_count'] += $revenue_count;

      $table_rows[] = $row;
    }

    // Footer totals row.
    $footer_row = [['data' => 'TOTALS', 'class' => ['work-load-service', 'work-load-footer']]];
    foreach ($active_statuses as $tid => $term) {
      $footer_row[] = [
        'data' => number_format($totals_row['statuses'][$tid]),
        'class' => ['work-load-count', 'work-load-footer'],
      ];
    }
    $footer_avg = $totals_row['revenue_count'] > 0
      ? ($totals_row['invoiced'] + $totals_row['to_invoice']) / $totals_row['revenue_count']
      : 0;
    $footer_row[] = ['data' => number_format($totals_row['total']), 'class' => ['work-load-total', 'work-load-footer']];
    $footer_row[] = ['data' => $totals_row['to_invoice'] > 0 ? '$' . number_format($totals_row['to_invoice'], 2) : '–', 'class' => ['work-load-money', 'work-load-footer']];
    $footer_row[] = ['data' => '$' . number_format($totals_row['invoiced'], 2), 'class' => ['work-load-money', 'work-load-footer']];
    $footer_row[] = ['data' => $footer_avg > 0 ? '$' . number_format($footer_avg, 2) : '–', 'class' => ['work-load-money', 'work-load-footer']];

    // Year selector options.
    $year_options = [];
    for ($y = $current_year; $y >= 2017; $y--) {
      $year_options[$y] = $y;
    }

    $build = [];

    $build['year_selector'] = [
      '#type' => 'inline_template',
      '#template' => '<form method="get" class="work-load-year-form"><label for="wl-year"><strong>Year:</strong></label> <select name="year" id="wl-year" class="form-select" onchange="this.form.submit()">{% for val, label in options %}<option value="{{ val }}"{{ val == current ? \' selected\' : \'\' }}>{{ label }}</option>{% endfor %}</select></form>',
      '#context' => [
        'options' => $year_options,
        'current' => $year,
      ],
    ];

    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $table_rows,
      '#footer' => [$footer_row],
      '#attributes' => ['class' => ['work-load-hub-table']],
      '#sticky' => TRUE,
      '#empty' => $this->t('No work orders found for @year.', ['@year' => $year]),
    ];

    $build['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'style',
        '#value' => '
          .work-load-year-form { margin-bottom: 1.5em; display: flex; gap: 8px; align-items: center; }
          .work-load-year-form select { width: auto; min-width: 100px; }
          .work-load-hub-table { width: 100%; }
          .work-load-hub-table th { text-align: center; white-space: nowrap; padding: 8px 10px; }
          .work-load-hub-table th:first-child { text-align: left; }
          .work-load-hub-table td { padding: 6px 10px; }
          .work-load-service { text-align: left; font-weight: 600; white-space: nowrap; }
          .work-load-count { text-align: center; }
          .work-load-zero { color: #999; }
          .work-load-total { text-align: center; font-weight: 600; }
          .work-load-money { text-align: right; white-space: nowrap; }
          .work-load-footer { font-weight: 700; border-top: 2px solid #333; background: #f5f5f5; }
          .work-load-hub-table tr:hover td { background: #f0f4ff; }
          .work-load-hub-table tfoot tr:hover td { background: #f5f5f5; }
        ',
      ],
      'work_load_hub_styles',
    ];

    $build['#cache'] = ['max-age' => 0];

    return $build;
  }

}
