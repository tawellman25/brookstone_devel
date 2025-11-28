<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a aerating work order.
 *
 * @Action(
 *   id = "create_aerating_work_order_action",
 *   label = @Translation("Create Aerating Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class CreateAeratingWorkOrderAction extends ViewsBulkOperationsActionBase {
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

      // Load the referenced Aerating entity.
      $aerating_id = $contract->get('field_aerating_of_lawn')->target_id;
      $aerating_section = $entity_type_manager->getStorage('contract_sections')->load($aerating_id);
      if (!$aerating_section) {
        $messenger->addError('Referenced Aerating entity not found.');
        return;
      }

      // Check if a work order already exists.
      if (!$aerating_section->get('field_work_order')->isEmpty()) {
        $messenger->addError("An Aerating Work Order already exists for this Contract #$contract_id.");
        return;
      }

      // Get the value of the 'field_estimate' field from the Aerating entity & Extract the last number.
      $estimate_text = $aerating_section->get('field_estimate')->value;
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

      // Get Aerating Season from Contract Section
      $aeratingSeason = $aerating_section->get('field_aerating_season')->target_id;


      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;
      $property = $entity_type_manager->getStorage('properties')->load($property_id);
      if (!$property) {
        $messenger->addError('Referenced Property not found.');
        return;
      }

      // Create a new Aerating Work Order.
      $work_order = $entity_type_manager->getStorage('work_order')->create([
        'type' => 'aerating',
        'uid' => $current_user->id(),
        'created' => time(),
        'field_service' => 389,
        'field_property' => $property_id,
        'field_contract' => $contract_id,
        'field_invoiced' => 0,
        'field_status' => 1089,
        'field_estimated_price' => $contract_estimate,
        'field_aeration_season' => $aeratingSeason,
        'field_work_todo_description' => [
          'value' => "<p>" . date('Y') . " - Aerate lawn as described</p>",
          'format' => 'full_html',
        ],
      ]);
      $work_order->save();

      // Get the ID of the created work_order.
      $work_order_id = $work_order->id();

      // Set the value of the Contract Section's 'field_work_order' reference field to the ID of the created work_order.
      $aerating_section->set('field_work_order', $work_order_id);
      $aerating_section->save();

      // Display success message.
      $messenger->addMessage($this->t("Aerating work order created for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

