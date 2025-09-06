<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Updates Property Weed Spraying Info upon Views VBO Action.
 *
 * @Action(
 *   id = "put_on_landscape_weed_spray_route_action",
 *   label = @Translation("Put Landscape Beds On Spray Route"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class SprayRouteOnLandscapeWeedSprayAction extends ViewsBulkOperationsActionBase {
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

      $landscapeWeedSpray_id = $contract->get('field_weed_spraying_of_landscape')->target_id;
      $landscapeWeedSpray_section = $entity_type_manager->getStorage('contract_sections')->load($landscapeWeedSpray_id);
      if (!$landscapeWeedSpray_section) {
        $messenger->addError("Contract #$contract_id Referenced Landscape Bed Spray entity not found.");
        return;
      }

      $weedSpray_want = $landscapeWeedSpray_section->get('field_do_you_want')->value;
      $weedSpray_frequency = $landscapeWeedSpray_section->get('field_spraying_frequency')->target_id;

      // Check if weed spraying is wanted (1 = Yes, 2 = No).
      if ($weedSpray_want === '2') {
        $messenger->addError("Contract #$contract_id does NOT require Landscape Bed Weed Spraying.");
        return;
      }
      if ($weedSpray_want !== '1') {
        $messenger->addError("Contract #$contract_id has an invalid or unset value for Landscape Bed Weed Spraying preference.");
        return;
      }

      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;

      $property_properties = [
        'type' => 'weed_spraying',
        'field_property' => $property_id,
      ];

      $property_info = $entity_type_manager->getStorage('property_spraying_info')->loadByProperties($property_properties);
      if (!$property_info) {
        // Create a new property_spraying_info entity.
        $property_spray_info = $entity_type_manager->getStorage('property_spraying_info')->create([
          'type' => 'weed_spraying',
          'field_property' => $property_id,
          'field_spray_route' => 1,
          'field_weed_beds_contracted' => 1,
          'field_beds_spraying_frequency' => $weedSpray_frequency,
          'created' => time(),
          'uid' => $current_user->id(),
        ]);
        $property_spray_info->save();
        $messenger->addMessage($this->t("Created new Property Spraying Info for Property #$property_id and added to spray route for Contract #$contract_id."));
      } else {
        $property_spray_info = reset($property_info);
        $propertyMiscSprayRoute = $property_spray_info->get('field_spray_route')->value;

        // Update Property Spraying Info.
        if ($propertyMiscSprayRoute == 1) {
          $property_spray_info->set('field_weed_beds_contracted', 1);
          $property_spray_info->set('field_beds_spraying_frequency', $weedSpray_frequency);
          $property_spray_info->save();
          $messenger->addError("Contract #$contract_id referenced Property is already on Spray Route. The Frequency has also been updated.");
          return;
        } else {
          $property_spray_info->set('field_spray_route', 1);
          $property_spray_info->set('field_weed_beds_contracted', 1);
          $property_spray_info->set('field_beds_spraying_frequency', $weedSpray_frequency);
          $property_spray_info->save();
        }
        $messenger->addMessage($this->t("Property added to spray route and frequency updated for Contract #$contract_id."));
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