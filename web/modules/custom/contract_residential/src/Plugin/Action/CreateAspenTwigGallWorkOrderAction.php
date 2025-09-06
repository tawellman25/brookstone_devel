<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;
use Drupal\Core\Entity\EntityInterface;

/**
 * Creates an Aspen Twig Gall work order.
 *
 * @Action(
 *   id = "create_aspen_twig_gall_work_order_action",
 *   label = @Translation("Create Aspen Twig Gall Work Order"),
 *   category = @Translation("Custom"),
 *   confirm = TRUE
 * )
 */
class CreateAspenTwigGallWorkOrderAction extends ViewsBulkOperationsActionBase {

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

    // Load Contract.
    $contract = $etm->getStorage('contracts')->load($contract_id);
    if (!$contract) {
      $messenger->addError($this->t('Contract not found.'));
      return;
    }

    // Load Aspen Twig Gall section.
    $section_id = $contract->get('field_aspen_twig_gall_control')->target_id ?? NULL;
    if (!$section_id) {
      $messenger->addError($this->t('Referenced Aspen Twig Gall section is missing.'));
      return;
    }
    $section = $etm->getStorage('contract_sections')->load($section_id);
    if (!$section) {
      $messenger->addError($this->t('Referenced Aspen Twig Gall section not found.'));
      return;
    }

    // Prevent duplicate WO.
    if (!$section->get('field_work_order')->isEmpty()) {
      $messenger->addError($this->t('An Aspen Twig Gall Work Order already exists for Contract #@id.', ['@id' => $contract_id]));
      return;
    }

    // Estimate: take upper bound if a range exists.
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

    // Optional: lookup turf sq ft (kept to match original behavior).
    $current_turf_sq_footage = '0';
    $pld_storage = $etm->getStorage('property_landscape_details');
    $result = $pld_storage->getQuery()
      ->condition('field_property', $property_id)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();
    if (!empty($result)) {
      $pld = $pld_storage->load((int) array_key_first($result));
      if ($pld && !$pld->get('field_turf_sq_footage')->isEmpty()) {
        $current_turf_sq_footage = (string) $pld->get('field_turf_sq_footage')->value;
      }
    }

    // Create Work Order.
    $work_order = $etm->getStorage('work_order')->create([
      'type' => 'aspen_twig_gall',
      'uid' => $current_user->id(),
      'created' => \Drupal::time()->getRequestTime(),
      'field_service' => 399,
      'field_property' => $property_id,
      'field_contract' => $contract_id,
      'field_status' => 1089,
      'field_invoiced' => 0,
      'field_estimated_price' => $contract_estimate,
      'field_work_todo_description' => [
        'value' => '<p>' . date('Y') . ' - Spray Aspen Twig Gall as described</p>',
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

    $messenger->addStatus($this->t('Aspen Twig Gall work order created for Contract #@id.', ['@id' => $contract_id]));
  }

  /**
   * Access control mirrors update access on the Contract.
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
