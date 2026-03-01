<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\taxonomy\TermInterface;

final class TurfSqFtEnricher implements EnricherInterface {

  private EntityTypeManagerInterface $etm;

  public function __construct(EntityTypeManagerInterface $etm) {
    $this->etm = $etm;
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
    // Only if the WO bundle supports it.
    if (!$work_order->hasField('field_current_turf_sq_footage')) {
      return;
    }
    if (!$work_order->get('field_current_turf_sq_footage')->isEmpty()) {
      return;
    }

    // Contract must have property.
    if (!$contract->hasField('field_property') || $contract->get('field_property')->isEmpty()) {
      return;
    }

    $property_id = (int) $contract->get('field_property')->target_id;
    if ($property_id <= 0) {
      return;
    }

    /** @var \Drupal\Core\Entity\EntityInterface|null $property */
    $property = $this->etm->getStorage('properties')->load($property_id);
    if (!$property) {
      return;
    }

    // Strategy A: property.field_turf_sq_footage
    $sqft = $this->readIntField($property, 'field_turf_sq_footage');

    // Strategy B: property.field_property_landscape_details -> field_turf_sq_footage
    if ($sqft === NULL && $property->hasField('field_property_landscape_details') && !$property->get('field_property_landscape_details')->isEmpty()) {
      $details_id = (int) $property->get('field_property_landscape_details')->target_id;
      if ($details_id > 0) {
        // We don't know the entity type name for landscape details; infer from field target entity.
        $target_type = $property->getFieldDefinition('field_property_landscape_details')->getSetting('target_type');
        if (is_string($target_type) && $target_type !== '') {
          $details = $this->etm->getStorage($target_type)->load($details_id);
          if ($details) {
            $sqft = $this->readIntField($details, 'field_turf_sq_footage');
          }
        }
      }
    }

    if ($sqft === NULL || $sqft <= 0) {
      return;
    }

    $work_order->set('field_current_turf_sq_footage', $sqft);
  }

  private function readIntField(EntityInterface $entity, string $field_name): ?int {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return NULL;
    }
    $v = (int) $entity->get($field_name)->value;
    return $v > 0 ? $v : NULL;
  }

}

