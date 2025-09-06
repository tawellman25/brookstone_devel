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
 *   id = "mark_work_order_not_invoiced_action",
 *   label = @Translation("Mark WO NOT Invoiced"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class MarkWorkOrderNotInvoicedAction extends ViewsBulkOperationsActionBase {
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

      // Check if a work order already exists.
      if ($work_order->get('field_invoiced')->value == 0) {
        $messenger->addError("The Work Order #$workorder_id has already been marked as NOT Invoiced.");
        return;
      }

      $work_order->set('field_invoiced', 0);
      $work_order->save();

      // Create a Status Update for Marking Invoiced.
      $wo_status_updates = \Drupal::entityTypeManager()
      ->getStorage('wo_status_updates')
      ->create([
          'type' => 'update',
          'field_status_of_wo' => $workorder_id,
          'field_status' => 1097,
          'field_status_change_note' => "$currentUserName marked this Work Order as Complete and NOT Invoiced.",
          'uid' => $currentUserId,
      ]);

      $wo_status_updates->save();


      // Display success message.
      $messenger->addMessage($this->t("The Work Order #$workorder_id has been Marked as NOT Invoiced."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

