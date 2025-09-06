<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Updates Property Lawn Maintenance Info upon Views VBO Action.
 *
 * @Action(
 *   id = "take_off_mow_route_action",
 *   label = @Translation("Take Off Mow Route"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class MowRouteOffAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function execute(EntityInterface $entity = NULL) {
    if ($entity && $entity->getEntityTypeId() === 'contracts') {
      // Retrieve necessary services.
      $entity_type_manager = \Drupal::service('entity_type.manager');
      $messenger = \Drupal::messenger();
      $current_user = \Drupal::currentUser();

      // Extract contract ID.
      $contract_id = $entity->id();

      // Load the Contract entity.
      $contract = $entity_type_manager->getStorage('contracts')->load($contract_id);
      if (!$contract) {
        $messenger->addError('Contract not found.');
        return;
      }

      // Load the referenced Lawn Mowing entity.
      $lawnMowing_id = $contract->get('field_lawn_mowing_and_trimming')->target_id;
      $lawnMowing_section = $entity_type_manager->getStorage('contract_sections')->load($lawnMowing_id);
      if (!$lawnMowing_section) {
        $messenger->addError("Contract #$contract_id Referenced Lawn Mowing and Trimming entity not found.");
        return;
      }

      $lawnMowing_want = $lawnMowing_section->get('field_do_you_want')->value;

      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;

      $property_properties = [
        'type' => 'lawn_maintenance_info',
        'field_property' => $property_id,
      ];
      
      $property_info = $entity_type_manager->getStorage('property_lawn_maintenance')->loadByProperties($property_properties);
      if (!$property_info) {
        $messenger->addError("Property $property_id referenced Lawn Maintenance Info section not found.");
        return;
      } else {
        $property_mowing_info = reset($property_info);
      }

      $propertyMowingRoute = $property_mowing_info->get('field_mowing_contracted')->value;

      // Update Property Spraying Info.
      if ($propertyMowingRoute == 0) {
        $messenger->addError("Contract #$contract_id referenced Property is already Off the Mow Route. The Frequency has also been updated.");
        return;
      } else {
        $property_mowing_info->set('field_mowing_contracted', 0);
        $property_mowing_info->save();  
      }

      // Display success message.
      $messenger->addMessage($this->t("Property added to Mow Route and frequency updated for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

