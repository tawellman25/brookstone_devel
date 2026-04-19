<?php

declare(strict_types=1);

namespace Drupal\estimate\Controller;

use Drupal\Core\Url;

/**
 * Admin report: Accepted Estimates Missing Work Order.
 *
 * Router-safe implementation:
 * - No ControllerBase inheritance.
 * - No constructor injection / ContainerInjectionInterface.
 * - Uses \Drupal::service() at runtime.
 *
 * This avoids route-rebuild reflection edge cases and property-collision fatals.
 */
final class AcceptedEstimatesMissingWorkOrderController {

  public function title(): string {
    return 'Accepted Estimates Missing Work Order';
  }

  public function build(): array {
    $config = \Drupal::config('estimate.settings');
    $accepted_tid = (int) $config->get('accepted_stage_tid');

    if ($accepted_tid <= 0) {
      return [
        '#type' => 'status_messages',
        '#messages' => [
          'error' => [
            t('estimate.settings.accepted_stage_tid is not configured. This report cannot run until it is set.'),
          ],
        ],
      ];
    }

    $storage = \Drupal::entityTypeManager()->getStorage('estimate');

    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('field_stage', $accepted_tid)
      ->condition('field_is_current_revision', 1)
      ->notExists('field_work_order')
      ->sort('changed', 'DESC')
      ->execute();

    $header = [
      t('Estimate'),
      t('Type'),
      t('Estimate Request'),
      t('Total'),
      t('Updated'),
      t('Actions'),
    ];

    $rows = [];
    if (!empty($ids)) {
      $estimates = $storage->loadMultiple($ids);
      $date_formatter = \Drupal::service('date.formatter');
      $renderer = \Drupal::service('renderer');

      foreach ($estimates as $estimate) {
        $estimate_label = $estimate->label() ?: ('Estimate ' . $estimate->id());
        $estimate_url = $estimate->toUrl()->toString();

        $bundle = $estimate->bundle();

        $req_html = '-';
        if ($estimate->hasField('field_estimate_request') && !$estimate->get('field_estimate_request')->isEmpty()) {
          $req = $estimate->get('field_estimate_request')->entity;
          if ($req) {
            $req_label = $req->label() ?: ('Request ' . $req->id());
            $req_url = $req->toUrl()->toString();
            $req_html = '<a href="' . htmlspecialchars($req_url) . '">' . htmlspecialchars($req_label) . '</a>';
          }
        }

        $total = '-';
        if ($estimate->hasField('field_estimate_total') && !$estimate->get('field_estimate_total')->isEmpty()) {
          $total = (string) $estimate->get('field_estimate_total')->value;
        }

        $updated = $date_formatter->format((int) $estimate->getChangedTime(), 'short');

        $convert_url = Url::fromRoute('estimate.convert_to_work_order', ['estimate' => $estimate->id()])->toString();

        $rows[] = [
          ['data' => ['#markup' => '<a href="' . htmlspecialchars($estimate_url) . '">' . htmlspecialchars($estimate_label) . '</a>']],
          $bundle,
          ['data' => ['#markup' => $req_html]],
          $total,
          $updated,
          ['data' => ['#markup' => '<a href="' . htmlspecialchars($convert_url) . '">Convert</a>']],
        ];
      }
    }

    return [
      '#type' => 'container',
      'intro' => [
        '#type' => 'markup',
        '#markup' => '<p>This report is an operational backstop. It lists Accepted, Current estimates that are missing a linked Work Order.</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => t('No accepted estimates are missing a Work Order.'),
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

}
