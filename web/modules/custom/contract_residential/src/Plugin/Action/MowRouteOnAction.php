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
 *   id = "put_on_mow_route_action",
 *   label = @Translation("Put On Mow Route"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class MowRouteOnAction extends ViewsBulkOperationsActionBase {
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
      $lawnMowing_frequency = $lawnMowing_section->get('field_mowing_frequency')->target_id;

      // Check if the value is not null and matches the pattern.
      if ($lawnMowing_want !== null) {
        // Check if the value is equal to No(or 0).
        if ($lawnMowing_want == 0) {
          // If Lawn Mowing is marked as No, Set message.
          $messenger->addError("Contract $contract_id does NOT require Lawn Mowing.");
          return;
        } 
      }

      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;

      $property_properties = [
        'type' => 'lawn_maintenance_info',
        'field_property' => $property_id,
      ];
      
      $property_info = $entity_type_manager->getStorage('property_lawn_maintenance')->loadByProperties($property_properties);
      if (!$property_info) {
        $property_mowing_info = $entity_type_manager->getStorage('property_lawn_maintenance')->create([
          'type' => 'lawn_maintenance_info',
          'field_property' => $property_id,
          // Add any additional fields or properties you want to set for the new entity.
        ]);
        $messenger->addError("Property $property_id referenced Lawn Maintenance Info section was created.");
      } else {
        $property_mowing_info = reset($property_info);
      }

      $propertyMowingRoute = $property_mowing_info->get('field_mowing_contracted')->value;

      // Update Property Spraying Info.
      if ($propertyMowingRoute == 1) {
        $property_mowing_info->set('field_mowing_frequency', $lawnMowing_frequency);
        $property_mowing_info->save();
        $messenger->addError("Contract #$contract_id referenced Property is already on the Mow Route. The Frequency has also been updated.");
        return;
      } else {
        $property_mowing_info->set('field_mowing_contracted', 1);
        $property_mowing_info->set('field_mowing_frequency', $lawnMowing_frequency);
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

