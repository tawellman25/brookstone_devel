<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a deer_prevention work order.
 *
 * @Action(
 *   id = "create_deer_prevention_take_down_work_order_action",
 *   label = @Translation("Create TAKE DOWN Deer Prevention Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class CreateDeerPreventionTakeDownWorkOrderAction extends ViewsBulkOperationsActionBase {
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

      // Load the referenced Deer Prevention entity.
      $deer_prevention_id = $contract->get('field_deer_protection_wire_for_t')->target_id;
      $deer_prevention_section = $entity_type_manager->getStorage('contract_sections')->load($deer_prevention_id);
      if (!$deer_prevention_section) {
        $messenger->addError('Referenced Deer Prevention entity not found.');
        return;
      }

      // Check if a work order already exists.
      if (!$deer_prevention_section->get('field_2nd_work_order')->isEmpty()) {
        $messenger->addError("A TAKE DOWN Deer Prevention Work Order already exists for this Contract #$contract_id.");
        return;
      }

      // Get the value of the 'field_estimate' field from the Deer Prevention entity & Extract the last number.
      $estimate_text = $deer_prevention_section->get('field_take_down_estimate')->value;
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

      // Create a new Deer Prevention Work Order.
      $work_order = $entity_type_manager->getStorage('work_order')->create([
        'type' => 'deer_prevention',
        'uid' => $current_user->id(),
        'created' => time(),
        'field_service' => 409,
        'field_property' => $property_id,
        'field_contract' => $contract_id,
        'field_status' => 1089,
        'field_invoiced' => 0,
        'field_deer_prevent_application' => 1276,
        'field_estimated_price' => $contract_estimate,
        'field_work_todo_description' => [
          'value' => "<p>" . date('Y') . " - <strong>Take Down</strong> Deer Prevention as described</p>",
          'format' => 'full_html',
        ],
      ]);
      $work_order->save();

      // Get the ID of the created work_order.
      $work_order_id = $work_order->id();

      // Set the value of the Contract Section's 'field_2nd_work_order' reference field to the ID of the created work_order.
      $deer_prevention_section->set('field_2nd_work_order', $work_order_id);
      $deer_prevention_section->save();

      // Display success message.
      $messenger->addMessage($this->t("Deer Prevention work order created for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

