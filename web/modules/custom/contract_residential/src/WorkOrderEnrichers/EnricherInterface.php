<?php

namespace Drupal\contract_residential\WorkOrderEnrichers;

use Drupal\Core\Entity\EntityInterface;
use Drupal\contract_residential\Service\WorkOrderGenerationResult;
use Drupal\taxonomy\TermInterface;

interface EnricherInterface {

  /**
   * Apply enrichment defaults to a Work Order entity before it is saved.
   *
   * Implementations MUST be:
   * - additive (do not overwrite existing values)
   * - safe (only set fields that exist on the Work Order bundle)
   * - idempotent
   *
   * @param \Drupal\Core\Entity\EntityInterface $contract
   * @param \Drupal\Core\Entity\EntityInterface $section
   * @param \Drupal\taxonomy\TermInterface $service
   * @param \Drupal\Core\Entity\EntityInterface $work_order
   * @param array $context
   *   - contract_id
   *   - section_id
   *   - service_tid
   *   - wo_bundle
   *   - pointer_field
   * @param \Drupal\contract_residential\Service\WorkOrderGenerationResult $result
   * @param array $options
   */
  public function apply(
    EntityInterface $contract,
    EntityInterface $section,
    TermInterface $service,
    EntityInterface $work_order,
    array $context,
    WorkOrderGenerationResult $result,
    array $options
  ): void;

}
