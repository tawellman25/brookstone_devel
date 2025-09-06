<?php

namespace Drupal\equipment_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Change Equipment Status to Active.
 *
 * @Action(
 *   id = "mark_equipment_active_action",
 *   label = @Translation("Mark Equipment Active"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class MarkEquipmentActiveAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'equipment') {
      // Retrieve necessary services.
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();
      $current_user = \Drupal::currentUser();
      $currentUserName = $current_user->getDisplayName();
      $currentUserId = $current_user->id();
      

      // Extract Equipment ID.
      $equipment_id = $entity->id();

      // Load the Equipment entity.
      $equipment = $entity_type_manager->getStorage('equipment')->load($equipment_id);
      if (!$equipment) {
        $messenger->addError("Equipment #$equipment_id not found.");
        return;
      }

      // Check if a Equipment Status Update already exists.
      if ($equipment->get('field_status')->value == 1301) {
        $messenger->addError("The Equipment #$equipment_id has already been marked as Active.");
        return;
      }

      $equipment->set('field_status', 1301);
      $equipment->save();

      // Create a Equipment Status Update for Marking Active.
      $equipment_status_updates = \Drupal::entityTypeManager()
      ->getStorage('equipment_status_update')
      ->create([
          'type' => 'update',
          'field_status_of' => $equipment_id,
          'field_status' => 1301,
          'field_reason_for_change' => "$currentUserName marked this Equipment as Active.",
          'uid' => $currentUserId,
      ]);

      $equipment_status_updates->save();

      // Display success message.
      $messenger->addMessage($this->t("The Equipment #$equipment_id has been Marked as Active."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

