<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityInterface;

/**
 * Marks or re-marks Work Order as Invoiced.
 *
 * @Action(
 *   id = "mark_work_order_invoiced_action",
 *   label = @Translation("Mark WO Invoiced"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class MarkWorkOrderInvoicedAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'work_order') {
      $messenger = \Drupal::messenger();
      $current_user = \Drupal::currentUser();
      $currentUserName = $current_user->getDisplayName();
      $currentUserId = $current_user->id();

      $workorder_id = $entity->id();

      $work_order = \Drupal::entityTypeManager()
        ->getStorage('work_order')
        ->load($workorder_id);

      if (!$work_order) {
        $messenger->addError("Work Order #$workorder_id not found.");
        return;
      }

      // Determine existing invoiced value.
      $was_invoiced = $work_order->get('field_invoiced')->value;

      // Set invoiced if not already.
      if ($was_invoiced != 1) {
        $work_order->set('field_invoiced', 1);
        $work_order->save();
      }

      // Build status note depending on whether it was already invoiced.
      $status_note = $was_invoiced != 1
        ? "$currentUserName marked this Work Order as Invoiced."
        : "$currentUserName updated this Work Order status to Invoiced.";

      // Create Status Update entity.
      $wo_status_updates = \Drupal::entityTypeManager()
        ->getStorage('wo_status_updates')
        ->create([
          'type' => 'update',
          'field_status_of_wo' => $workorder_id,
          'field_status' => 1281, // TID for Invoiced.
          'field_status_change_note' => $status_note,
          'uid' => $currentUserId,
        ]);

      $wo_status_updates->save();

      // Success message.
      $messenger->addMessage("The Work Order #$workorder_id has been updated to Invoiced.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
