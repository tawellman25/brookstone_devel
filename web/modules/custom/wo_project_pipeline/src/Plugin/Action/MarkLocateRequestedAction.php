<?php

namespace Drupal\wo_project_pipeline\Plugin\Action;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Marks work order locate as requested with today's date.
 *
 * @Action(
 *   id = "wo_pipeline_mark_locate_requested",
 *   label = @Translation("Mark Locate Requested"),
 *   type = "work_order",
 *   confirm = TRUE
 * )
 */
class MarkLocateRequestedAction extends ViewsBulkOperationsActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'work_order') {
      return;
    }

    $today = date('Y-m-d', \Drupal::time()->getRequestTime());
    $entity->set('field_locate_requested', TRUE);
    $entity->set('field_locate_request_date', $today);
    $entity->save();

    $wo_id = $entity->get('field_work_order_id')->value ?: $entity->id();
    \Drupal::messenger()->addMessage("WO #$wo_id: Locate marked as requested.");
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
