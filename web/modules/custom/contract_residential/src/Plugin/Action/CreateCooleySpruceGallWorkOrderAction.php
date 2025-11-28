<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a Cooley Spruce Gall work order.
 *
 * @Action(
 *   id = "create_cooley_spruce_gall_work_order_action",
 *   label = @Translation("Create Cooley Spruce Galld Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class CreateCooleySpruceGallWorkOrderAction extends ViewsBulkOperationsActionBase {
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

      // Load the referenced Cooley Spruce Galld entity.
      $cooley_spruce_gall_spray_id = $contract->get('field_cooley_spruce_gall_treatme')->target_id;
      $cooley_spruce_gall_spray_section = $entity_type_manager->getStorage('contract_sections')->load($cooley_spruce_gall_spray_id);
      if (!$cooley_spruce_gall_spray_section) {
        $messenger->addError('Referenced Cooley Spruce Gall entity not found.');
        return;
      }

      // Check if a work order already exists.
      if (!$cooley_spruce_gall_spray_section->get('field_work_order')->isEmpty()) {
        $messenger->addError("A Cooley Spruce Gall Work Order already exists for this Contract #$contract_id.");
        return;
      }

      // Get the value of the 'field_estimate' field from the Cooley Spruce Galld entity & Extract the last number.
      $estimate_text = $cooley_spruce_gall_spray_section->get('field_estimate')->value;
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

      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;
      $property = $entity_type_manager->getStorage('properties')->load($property_id);
      if (!$property) {
        $messenger->addError('Referenced Property not found.');
        return;
      }

      $query = \Drupal::entityTypeManager()->getStorage('property_landscape_details')->getQuery()
        ->condition('field_property', $property_id)
        ->accessCheck(FALSE); // It's important to explicitly set access checks as per your requirements.

      $result = $query->execute();

      if ($result) {
        $landscape_details_ids = array_keys($result);
        $landscape_details_id = reset($landscape_details_ids); // Assuming you're interested in the first result.
        $property_landscape_details = \Drupal::entityTypeManager()->getStorage('property_landscape_details')->load($landscape_details_id);

        // Now, $property_landscape_details is the entity you're looking for.
        // You can access 'field_turf_sq_footage' or any other fields you need.
        if ($property_landscape_details && !$property_landscape_details->get('field_turf_sq_footage')->isEmpty()) {
          $turf_sq_footage = $property_landscape_details->get('field_turf_sq_footage')->value;
          // Use $turf_sq_footage as needed.
        }

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
      
      // Create a new Cooley Spruce Galld Work Order.
      $work_order = $entity_type_manager->getStorage('work_order')->create([
        'type' => 'cooley_spruce_gall',
        'uid' => $current_user->id(),
        'created' => time(),
        'field_service' => 407,
        'field_property' => $property_id,
        'field_contract' => $contract_id,
        'field_status' => 1089,
        'field_invoiced' => 0,
        'field_estimated_price' => $contract_estimate,
        'field_work_todo_description' => [
          'value' => "<p>" . date('Y') . " - Spray Cooley Spruce Gall as described</p>",
          'format' => 'full_html',
        ],
      ]);
      $work_order->save();

      // Get the ID of the created work_order.
      $work_order_id = $work_order->id();

      // Set the value of the Contract Section's 'field_work_order' reference field to the ID of the created work_order.
      $cooley_spruce_gall_spray_section->set('field_work_order', $work_order_id);
      $cooley_spruce_gall_spray_section->save();

      // Display success message.
      $messenger->addMessage($this->t("Cooley Spruce Gall work order created for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

