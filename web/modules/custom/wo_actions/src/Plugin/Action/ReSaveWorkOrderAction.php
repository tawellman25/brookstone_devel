<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Marks Work Order as NOT Invoiced.
 *
 * @Action(
 *   id = "re_save_work_order_action",
 *   label = @Translation("Re-save Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "work_order"
 * )
 */
class ReSaveWorkOrderAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'work_order') {
      // Retrieve necessary services.
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();
      $current_user = \Drupal::currentUser();

      // Extract Work Order ID.
      $workorder_id = $entity->id();

      // Load the Work Order entity.
      $work_order = $entity_type_manager->getStorage('work_order')->load($workorder_id);
      if (!$work_order) {
        $messenger->addError("Work Order #$workorder_id not found.");
        return;
      }

      // Check if a work order already exists.
      if ($work_order->get('field_invoiced')->isEmpty()) {
        $messenger->addError("The Work Order #$workorder_id did not have the Invoice Field. We just added it.");
        $work_order->set('field_invoiced', 0);
    }

      $work_order->save();

      // Display success message.
      $messenger->addMessage($this->t("The Work Order #$workorder_id has been Re-saved."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

