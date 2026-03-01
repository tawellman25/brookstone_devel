<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\taxonomy\TermInterface;

final class GenericSeasonDefaultEnricher implements EnricherInterface {

  // Shared Seasons vocabulary tids (your BOS reality).
  private const TID_SPRING = 1100;
  private const TID_FALL = 1102;

  /**
   * Explicit defaults where BOS has special intent.
   *
   * @var array<string,int>
   */
  private const FIELD_DEFAULT_TIDS = [
    'field_pre_emergent_season' => self::TID_FALL,
  ];

  private EntityTypeManagerInterface $etm;

  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->etm = $entity_type_manager;
  }

  public function apply(
    EntityInterface $contract,
    EntityInterface $section,
    TermInterface $service,
    EntityInterface $work_order,
    array $context,
    WorkOrderGenerationResult $result,
    array $options
  ): void {
    // Pre-save only.
    if (($options['enricher_phase'] ?? 'pre_save') === 'post_save') {
      return;
    }

    foreach ($work_order->getFieldDefinitions() as $field_name => $def) {
      if (strpos($field_name, 'field_') !== 0) {
        continue;
      }
      if (!str_ends_with($field_name, '_season')) {
        continue;
      }
      if ($def->getType() !== 'entity_reference') {
        continue;
      }
      if (!$work_order->hasField($field_name) || !$work_order->get($field_name)->isEmpty()) {
        continue;
      }

      // Copy from section if it has the same field.
      if ($section->hasField($field_name) && !$section->get($field_name)->isEmpty()) {
        $work_order->set($field_name, $section->get($field_name)->getValue());
        continue;
      }

      // Default.
      $default_tid = self::FIELD_DEFAULT_TIDS[$field_name] ?? self::TID_SPRING;
      $work_order->set($field_name, ['target_id' => $default_tid]);
    }
  }

}
