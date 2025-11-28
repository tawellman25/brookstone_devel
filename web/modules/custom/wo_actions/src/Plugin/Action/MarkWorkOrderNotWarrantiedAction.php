<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityInterface;

/**
 * Marks Work Order as NOT Warrantied.
 *
 * @Action(
 *   id = "mark_work_order_not_warrantied_action",
 *   label = @Translation("Mark WO NOT Warrantied"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class MarkWorkOrderNotWarrantiedAction extends ViewsBulkOperationsActionBase {
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

      // Create a Status Update entry.
      $wo_status_updates = \Drupal::entityTypeManager()
        ->getStorage('wo_status_updates')
        ->create([
          'type' => 'update',
          'field_status_of_wo' => $workorder_id,
          'field_status' => 1282,
          'field_status_change_note' => "$currentUserName marked this Work Order as NOT Warrantied.",
          'uid' => $currentUserId,
        ]);

      $wo_status_updates->save();

      $messenger->addMessage("The Work Order #$workorder_id has been Marked as NOT Warrantied.");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
