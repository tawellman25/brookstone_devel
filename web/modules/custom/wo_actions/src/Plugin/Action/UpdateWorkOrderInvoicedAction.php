<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Updates Work Order status if marked as Invoiced but status hasn't been changed.
 *
 * @Action(
 *   id = "update_work_order_invoiced_action",
 *   label = @Translation("Update Completed WO to Invoiced"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "work_order"
 * )
 */
class UpdateWorkOrderInvoicedAction extends ViewsBulkOperationsActionBase {
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
      $currentUserName = $current_user->getDisplayName();
      $currentUserId = $current_user->id();

      // Extract Work Order ID.
      $workorder_id = $entity->id();

      // Load the Work Order entity.
      $work_order = $entity_type_manager->getStorage('work_order')->load($workorder_id);
      if (!$work_order) {
        $messenger->addError("Work Order #$workorder_id not found.");
        return;
      }

      // Check if the work order has been marked as invoiced but the status is not yet 'Invoiced'.
      if ($work_order->get('field_invoiced')->value == 1 && $work_order->get('field_status')->target_id != 1281) {
        // Update the status to 'Invoiced'.
        $work_order->set('field_status', 1281);
        
        // Optionally update 'field_invoiced' to ensure consistency, although it's already 1 based on the condition above.
        // $work_order->set('field_invoiced', 1);
        
        $work_order->save();

        // Create a Status Update for Marking Invoiced.
        $wo_status_updates = \Drupal::entityTypeManager()
        ->getStorage('wo_status_updates')
        ->create([
            'type' => 'update',
            'field_status_of_wo' => $workorder_id,
            'field_status' => 1281,
            'field_status_change_note' => "$currentUserName updated this Work Order status to Invoiced.",
            'uid' => $currentUserId,
        ]);

        $wo_status_updates->save();

        // Display success message.
        $messenger->addMessage($this->t("The Work Order #$workorder_id status has been updated to Invoiced."));
      } else {
        if ($work_order->get('field_status')->target_id == 1281) {
          $messenger->addMessage($this->t("The Work Order #$workorder_id is already marked as Invoiced."));
        } else {
          $messenger->addMessage($this->t("The Work Order #$workorder_id is not marked as invoiced, so its status was not changed."));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
