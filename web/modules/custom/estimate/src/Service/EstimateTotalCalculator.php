<?php

declare(strict_types=1);

namespace Drupal\estimate\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;

/**
 * Recalculates estimate totals from estimate_items.
 *
 * Single source of truth:
 *
 * estimate.field_estimate_total =
 *   SUM(estimate_items.field_line_total
 *       WHERE field_estimate = estimate_id
 *       AND field_pricing_class = 'included')
 *
 * Notes:
 * - We intentionally exclude 'optional' and 'internal_only' from the client-facing
 *   estimate total.
 * - We avoid float drift by using bc math when available.
 */
final class EstimateTotalCalculator {

  private EntityStorageInterface $estimateStorage;
  private EntityStorageInterface $estimateItemStorage;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->estimateStorage = $this->entityTypeManager->getStorage('estimate');
    $this->estimateItemStorage = $this->entityTypeManager->getStorage('estimate_items');
  }

  /**
   * Recalculate total for a specific estimate ID.
   */
  public function recalculate(int $estimate_id): void {
    if ($estimate_id <= 0) {
      return;
    }

    $estimate = $this->estimateStorage->load($estimate_id);
    if (!$estimate || !$estimate->hasField('field_estimate_total')) {
      return;
    }

    // Query estimate_items referencing this estimate that count toward the total.
    // Authoritative rule: client-facing total = included only.
    $item_ids = $this->estimateItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_estimate', $estimate_id)
      ->condition('field_pricing_class', 'included')
      ->execute();

    $total = '0.00';

    if (!empty($item_ids)) {
      $items = $this->estimateItemStorage->loadMultiple($item_ids);

      foreach ($items as $item) {
        if ($item->hasField('field_line_total') && !$item->get('field_line_total')->isEmpty()) {
          $value = (string) $item->get('field_line_total')->value;
          if ($value !== '') {
            $total = function_exists('bcadd')
              ? bcadd($total, $value, 2)
              : number_format(((float) $total) + ((float) $value), 2, '.', '');
          }
        }
      }
    }

    // Only save when the value changes to avoid unnecessary churn.
    $current = (string) ($estimate->get('field_estimate_total')->value ?? '');
    if ($current !== $total) {
      $estimate->set('field_estimate_total', $total);
      $estimate->save();
    }
  }

}
