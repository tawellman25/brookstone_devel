<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates an aerating work order.
 *
 * @Action(
 *   id = "create_aerating_work_order_action",
 *   label = @Translation("Create Aerating Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE,
 *   type = "contracts"
 * )
 */
class CreateAeratingWorkOrderAction extends ViewsBulkOperationsActionBase {

  /**
   * Process only explicitly selected entities.
   */
  public function executeMultiple(array $entities) {
    if (empty($entities)) {
      \Drupal::messenger()->addError($this->t('No items selected.'));
      return;
    }

    $count = 0;
    foreach ($entities as $entity) {
      if ($entity instanceof EntityInterface && $entity->getEntityTypeId() === 'contracts') {
        $this->execute($entity);
        $count++;
      }
    }

    if ($count) {
      \Drupal::messenger()->addStatus($this->t('Processed @count item(s).', ['@count' => $count]));
    }
  }

  /**
   * Execute on a single Contract.
   */
  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'contracts') {
      return;
    }

    $etm = \Drupal::entityTypeManager();
    $messenger = \Drupal::messenger();
    $current_user = \Drupal::currentUser();
    $contract_id = $entity->id();

    // Load the Contract fresh.
    $contract = $etm->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      $messenger->addError($this->t('Contract not found.'));
      return;
    }

    // Load Aerating section from contract.
    $aerating_id = $contract->get('field_aerating_of_lawn')->target_id ?? NULL;
    if (!$aerating_id) {
      $messenger->addError($this->t('Referenced Aerating section is missing.'));
      return;
    }
    $aerating_section = $etm->getStorage('contract_sections')->load($aerating_id);
    if (!$aerating_section) {
      $messenger->addError($this->t('Referenced Aerating section not found.'));
      return;
    }

    // Prevent duplicate WO.
    if (!$aerating_section->get('field_work_order')->isEmpty()) {
      $messenger->addError($this->t('An Aerating Work Order already exists for Contract #@id.', ['@id' => $contract_id]));
      return;
    }

    // Estimate: take upper bound if a range exists.
    $estimate_text = (string) ($aerating_section->get('field_estimate')->value ?? '');
    $contract_estimate = 0.0;
    if ($estimate_text !== '' && preg_match('/(\d+(?:\.\d+)?)(?:\s*-\s*(\d+(?:\.\d+)?))?/', $estimate_text, $m)) {
      $contract_estimate = isset($m[2]) ? (float) $m[2] : (float) $m[1];
    }

    // Aerating season (e.g., term ID).
    $aerating_season = $aerating_section->get('field_aerating_season')->target_id ?? NULL;

    // Property.
    $property_id = $contract->get('field_property')->target_id ?? NULL;
    if (!$property_id) {
      $messenger->addError($this->t('Referenced Property is missing.'));
      return;
    }
    if (!$etm->getStorage('properties')->load($property_id)) {
      $messenger->addError($this->t('Referenced Property not found.'));
      return;
    }

    // Create work order.
    $work_order = $etm->getStorage('work_order')->create([
      'type' => 'aerating',
      'uid' => $current_user->id(),
      'created' => \Drupal::time()->getRequestTime(),
      'field_service' => 389,
      'field_property' => $property_id,
      'field_contract' => $contract_id,
      'field_invoiced' => 0,
      'field_status' => 1089,
      'field_estimated_price' => $contract_estimate,
      'field_aeration_season' => $aerating_season,
      'field_work_todo_description' => [
        'value' => '<p>' . date('Y') . ' - Aerate lawn as described</p>',
        'format' => 'full_html',
      ],
    ]);
    $work_order->save(); // 1st save -> ID assigned.

    // Double-save to trigger Automatic Label tokens (ID now available).
    if ($reloaded = $etm->getStorage('work_order')->load($work_order->id())) {
      if (method_exists($reloaded, 'setNewRevision')) {
        $reloaded->setNewRevision(FALSE);
      }
      $reloaded->save(); // 2nd save -> Automatic Label updates title.
    }

    // Link back to the contract section.
    $aerating_section->set('field_work_order', $work_order->id());
    $aerating_section->save();

    $messenger->addStatus($this->t('Aerating work order created for Contract #@id.', ['@id' => $contract_id]));
  }

  /**
   * Access control mirrors update access on the Contract.
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
