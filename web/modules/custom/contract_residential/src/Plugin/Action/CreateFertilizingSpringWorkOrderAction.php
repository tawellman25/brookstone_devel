<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a fertilizing work order.
 *
 * @Action(
 *   id = "create_fertilizing_spring_work_order_action",
 *   label = @Translation("Create SRING Lawn Fertilizing Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class CreateFertilizingSpringWorkOrderAction extends ViewsBulkOperationsActionBase {
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
        return new RedirectResponse(\Drupal::url('<front>'));
      }

      // Load the referenced Fertilizing entity.
      $fertilizing_spring_id = $contract->get('field_lawn_fertilizing_broadleaf')->target_id;
      $fertilizing_spring_section = $entity_type_manager->getStorage('contract_sections')->load($fertilizing_spring_id);
      if (!$fertilizing_spring_section) {
        $messenger->addError('Referenced Fertilizing Section entity not found.');
        return;
      }

      // Check if a work order already exists.
      if (!$fertilizing_spring_section->get('field_work_order')->isEmpty()) {
        $messenger->addError("A Spring Fertilizing Work Order already exists for this Contract #$contract_id.");
        return;
      }

      // Get the value of the 'field_estimate' field from the Fertilizing entity & Extract the last number.
      $estimate_text = $fertilizing_spring_section->get('field_estimate')->value;
      // Check if the estimate text is not null and matches the pattern.
      if ($estimate_text !== null) {
        // Check if the estimate text contains a hyphen.
        if (strpos($estimate_text, '-') !== false) {
          // If the estimate text contains a hyphen, use the second number as the estimated price.
          preg_match('/(\d+)\s*-\s*(\d+)/', $estimate_text, $matches);
          if (!empty($matches)) {
            $contract_estimate = (float) $matches[2];
          } else {
            // If the pattern match fails, set the estimated price to 0.0.
            $contract_estimate = 0.0;
          }
        } else {
          // If the estimate text does not contain a hyphen, use the entire text as the estimated price.
          $contract_estimate = (float) $estimate_text;
        }
      } else {
        // If the estimate text is null, set the estimated price to 0.0.
        $contract_estimate = 0.0;
      }

      // Load the referenced Property entity.
      $property_id = $contract->get('field_property')->target_id;
      $property = $entity_type_manager->getStorage('properties')->load($property_id);
      if (!$property) {
        $messenger->addError('Referenced Property not found.');
        return new RedirectResponse(\Drupal::url('<front>'));
      }

      // Get the landscape detail from the referrenced Property
      $query = \Drupal::entityTypeManager()->getStorage('property_landscape_details')->getQuery()
      ->condition('field_property', $property_id)
      ->accessCheck(FALSE); // It's important to explicitly set access checks as per your requirements.

      $result = $query->execute();

      if ($result) {
        $landscape_details_ids = array_keys($result);
        $landscape_details_id = reset($landscape_details_ids); // Assuming you're interested in the first result.
        $property_landscape_details = \Drupal::entityTypeManager()->getStorage('property_landscape_details')->load($landscape_details_id);
        $property_fertilizing_info = \Drupal::entityTypeManager()->getStorage('property_landscape_details')->load($landscape_details_id);


        // Now, $property_landscape_details is the entity you're looking for.
        // You can access 'field_turf_sq_footage' or any other fields you need.
        if ($property_landscape_details && !$property_landscape_details->get('field_turf_sq_footage')->isEmpty()) {
          // Assuming you meant 'field_turf_sq_footage' here, not 'field_current_turf_sq_footage'
          $current_turf_sq_footage = $property_landscape_details->get('field_turf_sq_footage')->value;
        } else {
          $current_turf_sq_footage = '0'; // Default value if the field is empty or doesn't exist
        }

      } else {
      // Handle the case where no matching property_landscape_details entity is found
      $current_turf_sq_footage = '0'; // Default value
      } 

      // Get the Fertilizing Info from the referrenced Property
      $query = \Drupal::entityTypeManager()->getStorage('property_fertilizing_info')->getQuery()
      ->condition('field_property', $property_id)
      ->accessCheck(FALSE); // It's important to explicitly set access checks as per your requirements.

      $result = $query->execute();

      if ($result) {
        $fertilizing_info_ids = array_keys($result);
        $fertilizing_info_id = reset($fertilizing_info_ids); // Assuming you're interested in the first result.
        $property_fertilizing_info = \Drupal::entityTypeManager()->getStorage('property_fertilizing_info')->load($fertilizing_info_id);


        // Now, $property_fertilizing_info is the entity you're looking for.
        // You can access 'field_turf_pre_emergent_property' or any other fields you need.
        if ($property_fertilizing_info && !$property_fertilizing_info->get('field_turf_pre_emergent_property')->value == 1) {
          // Assuming you meant 'field_turf_sq_footage' here, not 'field_current_turf_sq_footage'
          $fertilizing_season = 'pre';
        } else {
          $fertilizing_season = 'spring'; // Default value if the field is empty or doesn't exist
        }

      } else {
      // Handle the case where no matching property_landscape_details entity is found
      $fertilizing_season = 'spring';
      } 

      
      // Create a new Fertilizing Work Order.
      $work_order = $entity_type_manager->getStorage('work_order')->create([
        'type' => 'fertilizing',
        'uid' => $current_user->id(),
        'created' => time(),
        'field_service' => 367,
        'field_property' => $property_id,
        'field_contract' => $contract_id,
        'field_status' => 1089,
        'field_invoiced' => 0,
        'field_estimated_price' => $contract_estimate,
        'field_fertilizing_season' => 'spring',
        'field_current_turf_sq_footage' => $current_turf_sq_footage,
        'field_work_todo_description' => [
          'value' => "<p>" . date('Y') . " - <strong>Spring</strong> Fertilizing as described</p>",
          'format' => 'full_html',
        ],
      ]);
      $work_order->save();

      // Get the ID of the created work_order.
      $work_order_id = $work_order->id();

      // Set the value of the Contract Section's 'field_work_order' reference field to the ID of the created work_order.
      $fertilizing_spring_section->set('field_work_order', $work_order_id);
      $fertilizing_spring_section->save();

      // Display success message.
      $messenger->addMessage($this->t("<strong>Spring</strong> Fertilizing work order created for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

