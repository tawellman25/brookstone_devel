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
 *   id = "create_weed_pulling_work_order_action",
 *   label = @Translation("Create Weed Pulling Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class CreateWeedPullingWorkOrderAction extends ViewsBulkOperationsActionBase {
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

      // Load the referenced Weed Pulling entity.
      $weed_pulling_value = $contract->get('field_weeds_in_shrubs_removal')->value;
      
      // Check if the value is not null and matches the pattern.
      if ($weed_pulling_value !== null) {
        // Check if the estimate text contains a hyphen.
        if ($weed_pulling_value == 0) {
          // If Weed Pulling is marked as No, Set message.
          $messenger->addError("Contract $contract_id does NOT require Weed Pulling.");
          return;
        } 
      }

      // Load the Property referenced in the Contract.
      $property_id = $contract->get('field_property')->target_id;
      $property = $entity_type_manager->getStorage('properties')->load($property_id);
      if (!$property) {
        $messenger->addError('Referenced Property not found.');
        return;
      }

      // Create a new Weed Pulling Work Order.
      $work_order = $entity_type_manager->getStorage('work_order')->create([
        'type' => 'weed_pulling',
        'uid' => $current_user->id(),
        'created' => time(),
        'field_service' => 416,
        'field_property' => $property_id,
        'field_contract' => $contract_id,
        'field_status' => 1089,
        'field_invoiced' => 0,
        'field_work_todo_description' => [
          'value' => "<p>" . date('Y') . " - Weed Pulling as described</p>",
          'format' => 'full_html',
        ],
      ]);
      $work_order->save();

      // Get the ID of the created work_order.
      $work_order_id = $work_order->id();

      // Display success message.
      $messenger->addMessage($this->t("Weed Pulling work order created for Contract #$contract_id."));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}

