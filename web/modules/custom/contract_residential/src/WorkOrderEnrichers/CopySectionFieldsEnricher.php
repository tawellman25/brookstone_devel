<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\taxonomy\TermInterface;

final class CopySectionFieldsEnricher implements EnricherInterface {

  /**
   * Field names that may exist on both Contract Section and Work Order bundle.
   * These are copied only if:
   * - section has field and not empty
   * - work order has field and is empty
   *
   * Add to this list as you discover additional “nuances”.
   *
   * @var string[]
   */
  private const FIELD_ALLOWLIST = [
    // Seasons (when bundles carry season refs).
    'field_fertilizing_season',
    'field_pre_emergent_season',
    'field_aeration_season',

    // Ips / beetle specifics (if present on section bundles).
    'field_ips_beetle_season_ref',
    'field_pinyon_pine_tree_count',

    // Turf sizing (sometimes present directly on sections).
    'field_current_turf_sq_footage',

    // General intent that some legacy actions propagated.
    'field_specific_plants',
    'field_man_hours',
    'field_set_your_budget',
  ];

  public function apply(
    EntityInterface $contract,
    EntityInterface $section,
    TermInterface $service,
    EntityInterface $work_order,
    array $context,
    WorkOrderGenerationResult $result,
    array $options
  ): void {
    foreach (self::FIELD_ALLOWLIST as $field_name) {
      if (!$section->hasField($field_name) || $section->get($field_name)->isEmpty()) {
        continue;
      }
      if (!$work_order->hasField($field_name) || !$work_order->get($field_name)->isEmpty()) {
        continue;
      }

      // Copy raw field item values to preserve reference types.
      $work_order->set($field_name, $section->get($field_name)->getValue());
    }
  }

}
