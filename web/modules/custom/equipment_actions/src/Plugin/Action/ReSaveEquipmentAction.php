<?php

namespace Drupal\equipment_actions\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Resaves the Equipment entity.
 *
 * @Action(
 *   id = "re_save_equipment_action",
 *   label = @Translation("Re-save Equipment"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "equipment"
 * )
 */
class ReSaveEquipmentAction extends ViewsBulkOperationsActionBase {
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

      // Extract Equipment ID.
      $equipment_id = $entity->id();

      // Load the Equipment entity.
      $equipment = $entity_type_manager->getStorage('equipment')->load($equipment_id);
      if (!$equipment) {
        $messenger->addError("Equipment #$equipment_id not found.");
        return;
      }

      $equipment->save();

      // Display success message.
      $messenger->addMessage($this->t("The Equipment #$equipment_id has been Re-saved."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

