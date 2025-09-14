<?php

namespace Drupal\wo_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Enters Work Order "field_work_todo_description" with non Token description.
 *
 * @Action(
 *   id = "update_work_order_description_action",
 *   label = @Translation("Update Work Order Description"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "work_order"
 * )
 */
class UpdateWorkOrderDescriptionAction extends ViewsBulkOperationsActionBase {
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

      // Get the creation timestamp from the entity.
      $createdTimestamp = $work_order->get('created')->value;

      // Convert timestamp to DateTime object to get year and week number.
      $date = \Drupal::service('date.formatter')->format($createdTimestamp, 'custom', 'Y-W');

      // Split the formatted date to get year and week.
      list($year, $week) = explode('-', $date);

      // Get the bundle type of the work order.
      $bundleType = $work_order->bundle();

      // Update the field_work_todo_description with the correct year and week from creation.
      $work_order->set('field_work_todo_description', "$year - Week $week - " . ucwords(str_replace('_', ' ', $bundleType)));

      $work_order->save();

      // Display success message.
      $messenger->addMessage($this->t("The Work Order #$workorder_id description has been updated."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

