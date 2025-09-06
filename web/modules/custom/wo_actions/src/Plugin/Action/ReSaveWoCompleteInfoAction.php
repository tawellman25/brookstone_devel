<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Re-saves Work Order Complete Info to update related property_spraying_info.
 *
 * @Action(
 *   id = "re_save_wo_complete_info_action",
 *   label = @Translation("Re-save Work Order Complete Info"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class ReSaveWoCompleteInfoAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'wo_complete_info') {
      // Retrieve necessary services.
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();

      // Extract Work Order Complete Info ID.
      $complete_info_id = $entity->id();

      // Load the Work Order Complete Info entity.
      $wo_complete_info = $entity_type_manager->getStorage('wo_complete_info')->load($complete_info_id);
      if (!$wo_complete_info) {
        $messenger->addError("Work Order Complete Info #$complete_info_id not found.");
        return;
      }

      // Get the referenced Work Order.
      $work_order_id = $wo_complete_info->get('field_work_order')->target_id ?? NULL;
      if (!$work_order_id) {
        $messenger->addError("No Work Order referenced in Work Order Complete Info #$complete_info_id.");
        return;
      }

      // Load the Work Order entity.
      $work_order = $entity_type_manager->getStorage('work_order')->load($work_order_id);
      if (!$work_order) {
        $messenger->addError("Work Order #$work_order_id not found for Work Order Complete Info #$complete_info_id.");
        return;
      }

      // Trigger a save on the Work Order to invoke presave hooks.
      $work_order->save();

      // Display success message.
      $messenger->addMessage($this->t("Work Order Complete Info #$complete_info_id has been re-saved, updating related Work Order #$work_order_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}