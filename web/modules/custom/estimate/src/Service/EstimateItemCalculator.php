<?php

declare(strict_types=1);

namespace Drupal\estimate\Service;

use Drupal\Core\Entity\EntityInterface;

/**
 * Calculates estimate_items monetary fields.
 *
 * Refactor rule (Option B):
 * - Only compute when required fields exist AND the minimal inputs are present.
 * - If inputs are missing, do nothing (allows manual/non-formula bundles).
 *
 * Supported fields (when present):
 * - field_quantity
 * - field_unit_price
 * - field_cost_subtotal
 * - field_markup_percent (preferred) OR field_markup (legacy)
 * - field_line_total
 *
 * Markup normalization:
 * - Preferred entry is percent (10 = 10%).
 * - If a decimal is provided (0.10), it is treated as 10% for compatibility.
 */
final class EstimateItemCalculator {

  /**
   * Apply calculation to an estimate_items entity.
   */
  public function apply(EntityInterface $item): void {
    if ($item->getEntityTypeId() !== 'estimate_items') {
      return;
    }

    // Required output fields must exist.
    foreach (['field_cost_subtotal', 'field_line_total'] as $required) {
      if (!$item->hasField($required)) {
        return;
      }
    }

    // Minimal inputs (Option B): qty + unit price must exist AND be non-empty.
    if (!$item->hasField('field_quantity') || $item->get('field_quantity')->isEmpty()) {
      return;
    }
    if (!$item->hasField('field_unit_price') || $item->get('field_unit_price')->isEmpty()) {
      return;
    }

    $qty_raw = (string) ($item->get('field_quantity')->value ?? '0');
    $unit_raw = (string) ($item->get('field_unit_price')->value ?? '0');

    $qty = (float) $qty_raw;
    $unit = (float) $unit_raw;

    // Defensive: disallow negatives.
    if ($qty < 0) {
      $qty = 0.0;
    }
    if ($unit < 0) {
      $unit = 0.0;
    }

    // Subtotal = qty * unit.
    $subtotal = $this->mulMoney($qty, $unit);

    // Optional markup (percent).
    $markup_percent = NULL;

    if ($item->hasField('field_markup_percent') && !$item->get('field_markup_percent')->isEmpty()) {
      $markup_percent = (float) ($item->get('field_markup_percent')->value ?? 0);
    }
    elseif ($item->hasField('field_markup') && !$item->get('field_markup')->isEmpty()) {
      // Legacy field support.
      $markup_percent = (float) ($item->get('field_markup')->value ?? 0);
    }

    $multiplier = '1.00';
    if ($markup_percent !== NULL) {
      if ($markup_percent < 0) {
        $markup_percent = 0.0;
      }
      // Normalize: allow either "10" or "0.10".
      $rate = ($markup_percent > 1.0) ? ($markup_percent / 100.0) : $markup_percent;
      $multiplier = $this->addMoney('1.00', $this->formatMoney($rate));
    }

    // line_total = subtotal * (1 + markup_rate)
    $line_total = $this->mulMoney((float) $subtotal, (float) $multiplier);

    $item->set('field_cost_subtotal', $subtotal);
    $item->set('field_line_total', $line_total);
  }

  /**
   * Multiply two numbers and return a 2-decimal string.
   */
  private function mulMoney(float $a, float $b): string {
    if (function_exists('bcmul')) {
      return bcmul($this->formatMoney($a), $this->formatMoney($b), 2);
    }
    return number_format($a * $b, 2, '.', '');
  }

  /**
   * Add two 2-decimal strings and return a 2-decimal string.
   */
  private function addMoney(string $a, string $b): string {
    if (function_exists('bcadd')) {
      return bcadd($a, $b, 2);
    }
    return number_format(((float) $a) + ((float) $b), 2, '.', '');
  }

  /**
   * Format numeric as 2-decimal string.
   */
  private function formatMoney(float $n): string {
    return number_format($n, 2, '.', '');
  }

}
