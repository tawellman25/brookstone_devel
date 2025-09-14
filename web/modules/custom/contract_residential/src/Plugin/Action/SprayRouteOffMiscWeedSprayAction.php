<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a weed_pulling work order.
 *
 * @Action(
 *   id = "take_off_misc_weed_spray_route_action",
 *   label = @Translation("Take Off Spray Route"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class SprayRouteOffMiscWeedSprayAction extends ViewsBulkOperationsActionBase {
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

      // Load the referenced Pre-emergent entity.
      $miscWeedSpray_id = $contract->get('field_weed_spraying_of_misc_area')->target_id;
      $miscWeedSpray_section = $entity_type_manager->getStorage('contract_sections')->load($miscWeedSpray_id);
      if (!$miscWeedSpray_section) {
        $messenger->addError('Referenced Misc Weed Spray entity not found.');
        return;
      }

      $landscapeWeedSpray_id = $contract->get('field_weed_spraying_of_landscape')->target_id;
      $landscapeWeedSpray_section = $entity_type_manager->getStorage('contract_sections')->load($landscapeWeedSpray_id);
      if (!$landscapeWeedSpray_section) {
        $messenger->addError('Referenced Misc Weed Spray entity not found.');
        return;
      }

      $weedSpray_want = $miscWeedSpray_section->get('field_do_you_want')->value;

      // Check if the value is not null and matches the pattern.
      if ($weedSpray_want !== null) {
        // Check if the estimate text contains a hyphen.
        if ($weedSpray_want == 0) {
          // If Weed Pulling is marked as No, Set message.
          $messenger->addError("Contract $contract_id does NOT require Weed Misc Weed Spraying.");
          return;
        } 
      }

      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;

      $property_properties = [
        'type' => 'weed_spraying',
        'field_property' => $property_id,
      ];
      
      $property_info = $entity_type_manager->getStorage('property_spraying_info')->loadByProperties($property_properties);
      if (!$property_info) {
        $messenger->addError("Property $property_id referenced Weed Spray Info Section not found.");
        return;
      } else {
        $property_spray_info = reset($property_info);
      }

      $propertyMiscSprayRoute = $property_spray_info->get('field_spray_route')->value;

      // Update Property Spraying Info.
      if ($propertyMiscSprayRoute == 0) {
        $property_spray_info->set('field_weed_misc_contracted', 0);
        $messenger->addError("Contract #$contract_id referenced Property already NOT on the Spray Route.");
        return;
      }
      $property_spray_info->set('field_weed_misc_contracted', 0);
      $property_spray_info->set('field_spray_route', 0);
      $property_spray_info->save();

      // Display success message.
      $messenger->addMessage($this->t("Property $property_id was taken OFF of the Spray Route for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

