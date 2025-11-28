<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Entity\EntityInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Clears stale work order references and resaves Contract Sections entities.
 *
 * @Action(
 *   id = "clear_work_order_references_action",
 *   label = @Translation("Clear Stale Work Order References and Re-save"),
 *   type = "contract_sections",
 *   confirm = TRUE
 * )
 */
class ReSaveContractSectionAction extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'contract_sections') {
      return $this->t('Invalid entity: #@id.', ['@id' => $entity ? $entity->id() : 'none']);
    }

    // Check and clean the field_work_order reference field.
    if ($entity->hasField('field_work_order')) {
      $references = $entity->get('field_work_order')->getValue();
      $valid_references = [];

      // Filter out references to non-existent work_order entities.
      foreach ($references as $reference) {
        $target_id = $reference['target_id'];
        $work_order = \Drupal::entityTypeManager()
          ->getStorage('work_order')
          ->load($target_id);

        if ($work_order) {
          $valid_references[] = ['target_id' => $target_id];
        }
      }

      // Update the field with only valid references.
      $entity->set('field_work_order', $valid_references);
    }

    // Save the entity to persist the changes.
    $entity->save();

    return $this->t('Cleared stale references and re-saved Contract Section #@id.', ['@id' => $entity->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}