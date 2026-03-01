<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\taxonomy\TermInterface;

final class DescriptionOverrideHelper {

  /**
   * Marker for BOS-generated generic descriptions (safe to overwrite).
   */
  private const BOS_AUTO_MARKER = '<!-- bos:auto -->';

  public static function isOverwriteEligible(
    EntityInterface $work_order,
    EntityInterface $contract,
    TermInterface $service
  ): bool {

    if (!$work_order->hasField('field_work_todo_description')) {
      return FALSE;
    }

    $current = (string) ($work_order->get('field_work_todo_description')->value ?? '');
    $trimmed = trim($current);

    if ($trimmed === '') {
      return TRUE;
    }

    // Token-defaults (bundle defaults).
    if (strpos($trimmed, '[current-date:') !== FALSE) {
      return TRUE;
    }

    // Our generic descriptions are always safe to overwrite later.
    if (strpos($trimmed, self::BOS_AUTO_MARKER) !== FALSE) {
      return TRUE;
    }

    // Generator fallback detection (new global format).
    $contract_id = (int) $contract->id();
    $service_label = trim((string) $service->label());

    $year = '';
    if ($contract->hasField('field_contract_year') && !$contract->get('field_contract_year')->isEmpty()) {
      $year = trim((string) $contract->get('field_contract_year')->value);
    }

    $prefix = ($year !== '') ? "{$year} — " : '';
    $service_part = ($service_label !== '') ? "{$service_label} as needed." : "Service as needed.";

    $expected_fallback = "<p>{$prefix}{$service_part}<br><br>See Contract #{$contract_id} for details.</p>";

    return $trimmed === $expected_fallback;
  }

}
