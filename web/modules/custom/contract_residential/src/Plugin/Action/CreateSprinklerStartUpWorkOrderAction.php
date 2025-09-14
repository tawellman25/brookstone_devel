<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a sprinkler_start_up work order.
 *
 * @Action(
 *   id = "create_sprinkler_start_up_work_order_action",
 *   label = @Translation("Create Sprinkler Start Up Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class CreateSprinklerStartUpWorkOrderAction extends ViewsBulkOperationsActionBase {
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

      // Load the referenced Spring Cleanup entity.
      $sprinkler_start_up_id = $contract->get('field_irrigation_start_up')->target_id;
      $sprinkler_start_up_section = $entity_type_manager->getStorage('contract_sections')->load($sprinkler_start_up_id);
      if (!$sprinkler_start_up_section) {
        $messenger->addError('Referenced Spring Cleanup entity not found.');
        return;
      }

      // Check if a work order already exists.
      if (!$sprinkler_start_up_section->get('field_work_order')->isEmpty()) {
        $messenger->addError("A Spring Cleanup Work Order already exists for this Contract #$contract_id.");
        return;
      }

      // Get the value of the 'field_estimate' field from the Spring Cleanup entity & Extract the last number.
      $estimate_text = $sprinkler_start_up_section->get('field_estimate')->value;
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

      // Create a new Spring Cleanup Work Order.
      $work_order = $entity_type_manager->getStorage('work_order')->create([
        'type' => 'sprinkler_start_up',
        'uid' => $current_user->id(),
        'created' => time(),
        'field_service' => 375,
        'field_property' => $property_id,
        'field_contract' => $contract_id,
        'field_status' => 1089,
        'field_invoiced' => 0,
        'field_estimated_price' => $contract_estimate,
        'field_work_todo_description' => [
          'value' => "<p>" . date('Y') . " - Sprinkler System Startup as needed.</p>",
          'format' => 'full_html',
        ],
      ]);
      $work_order->save();

      // Get the ID of the created work_order.
      $work_order_id = $work_order->id();

      // Set the value of the Contract Section's 'field_work_order' reference field to the ID of the created work_order.
      $sprinkler_start_up_section->set('field_work_order', $work_order_id);
      $sprinkler_start_up_section->save();

      // Display success message.
      $messenger->addMessage($this->t("Sprinkler System Start Up work order created for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

