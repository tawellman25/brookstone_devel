<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Re-saves all associated wo_tasks_list entities for selected Work Orders.
 *
 * @Action(
 *   id = "re_save_associated_tasks_action",
 *   label = @Translation("Re-save Tasks Lists"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "work_order"
 * )
 */
class ReSaveAssociatedTasksAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'work_order') {
      // Retrieve necessary services.
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();

      // Extract Work Order ID.
      $workorder_id = $entity->id();

      // Load the Work Order entity.
      $work_order = $entity_type_manager->getStorage('work_order')->load($workorder_id);
      if (!$work_order) {
        $messenger->addError("Work Order #$workorder_id not found.");
        return;
      }

      // Load all associated wo_tasks_list entities.
      $tasks_lists = $entity_type_manager->getStorage('wo_tasks_list')
        ->loadByProperties(['field_work_order' => $workorder_id]);

      $saved_count = 0;
      $error_count = 0;
      foreach ($tasks_lists as $task_list) {
        // Only save if changes have been made or if you want to ensure all data is up to date.
        // Here, you might want to implement some logic to check if saving is necessary.
        // For now, we'll save all of them:
        try {
          $task_list->save();
          $saved_count++;
        } catch (\Exception $e) {
          $messenger->addError("Error saving task list #{$task_list->id()}: " . $e->getMessage());
          $error_count++;
        }
      }

      if ($saved_count > 0) {
        $messenger->addMessage("Successfully re-saved {$saved_count} task lists associated with Work Order #$workorder_id.");
      }
      if ($error_count > 0) {
        $messenger->addWarning("There were {$error_count} errors while re-saving task lists.");
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