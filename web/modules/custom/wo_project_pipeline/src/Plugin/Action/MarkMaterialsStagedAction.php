<?php

namespace Drupal\wo_project_pipeline\Plugin\Action;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Marks work order materials as staged.
 *
 * @Action(
 *   id = "wo_pipeline_mark_materials_staged",
 *   label = @Translation("Mark Materials Staged"),
 *   type = "work_order",
 *   confirm = TRUE
 * )
 */
class MarkMaterialsStagedAction extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'work_order') {
      return;
    }

    $entity->set('field_materials_staged', TRUE);
    $entity->save();

    $wo_id = $entity->get('field_work_order_id')->value ?: $entity->id();
    \Drupal::messenger()->addMessage("WO #$wo_id: Materials marked as staged.");
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
