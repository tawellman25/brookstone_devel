<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates a sprinkler_winterizing work order.
 *
 * @Action(
 *   id = "create_sprinkler_winterizing_work_order_action",
 *   label = @Translation("Create Sprinkler Winterizing Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class CreateSprinklerWinterizingWorkOrderAction extends ViewsBulkOperationsActionBase {

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

  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'contracts') {
      return;
    }

    $etm = \Drupal::entityTypeManager();
    $messenger = \Drupal::messenger();
    $current_user = \Drupal::currentUser();
    $contract_id = $entity->id();

    // Load contract.
    $contract = $etm->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      $messenger->addError($this->t('Contract not found.'));
      return;
    }

    // Section: irrigation shut down.
    $section_id = $contract->get('field_irrigation_shut_down')->target_id ?? NULL;
    if (!$section_id) {
      $messenger->addError($this->t('Referenced Winterizing section is missing.'));
      return;
    }
    $section = $etm->getStorage('contract_sections')->load($section_id);
    if (!$section) {
      $messenger->addError($this->t('Referenced Winterizing section not found.'));
      return;
    }
    if (!$section->get('field_work_order')->isEmpty()) {
      $messenger->addError($this->t('A Winterizing Work Order already exists for Contract #@id.', ['@id' => $contract_id]));
      return;
    }

    // Estimate (use upper bound if range).
    $estimate_text = (string) ($section->get('field_estimate')->value ?? '');
    $contract_estimate = 0.0;
    if ($estimate_text !== '' && preg_match('/(\d+(?:\.\d+)?)(?:\s*-\s*(\d+(?:\.\d+)?))?/', $estimate_text, $m)) {
      $contract_estimate = isset($m[2]) ? (float) $m[2] : (float) $m[1];
    }

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
      'type' => 'sprinkler_winterizing',
      'uid' => $current_user->id(),
      'created' => \Drupal::time()->getRequestTime(),
      'field_service' => 369,
      'field_property' => $property_id,
      'field_contract' => $contract_id,
      'field_status' => 1089,
      'field_invoiced' => 0,
      'field_estimated_price' => $contract_estimate,
      'field_work_todo_description' => [
        'value' => '<p>' . date('Y') . ' - Winterizing Sprinkler System as needed.</p>',
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

    // Link back to section.
    $section->set('field_work_order', $work_order->id());
    $section->save();

    $messenger->addStatus($this->t('Sprinkler System Winterizing work order created for Contract #@id.', ['@id' => $contract_id]));
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
