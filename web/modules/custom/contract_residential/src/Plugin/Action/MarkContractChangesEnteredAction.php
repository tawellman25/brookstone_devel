<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\contract_residential\ContractActionLogWriter;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Mark Contract as Changes Entered (contract_status = 1122) with guardrails.
 * Admin override: role "administrator" may bypass guardrails.
 *
 * @Action(
 *   id = "contract_residential_mark_changes_entered",
 *   label = @Translation("Mark Contract: Changes Entered"),
 *   category = @Translation("Contracts"),
 *   confirm = TRUE
 * )
 */
class MarkContractChangesEnteredAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  public function execute(EntityInterface $entity = NULL) {
    if (!$entity || $entity->getEntityTypeId() !== 'contracts') {
      return;
    }

    if (!$entity->hasField('field_contract_status')) {
      $this->messenger()->addError($this->t('Contract @id is missing field_contract_status.', [
        '@id' => $entity->id(),
      ]));
      return;
    }

    $action_key = 'contract_residential_mark_changes_entered';
    $target_tid = 1122; // Changes Entered

    $current_tid = !$entity->get('field_contract_status')->isEmpty()
      ? (int) $entity->get('field_contract_status')->target_id
      : 0;

    if ($current_tid === $target_tid) {
      return;
    }

    $label = $entity->label() ?: $this->t('Contract @id', ['@id' => $entity->id()]);
    $is_admin_override = \Drupal::currentUser()->hasRole('administrator');

    if (!$is_admin_override) {
      $allowed_from = [
        1121, // Received Back
        1120, // Client Viewed (optional)
        1126, // On Hold (optional)
      ];

      $disallowed_from = [
        1123, // Approved
        1124, // Work Orders Created
        1125, // Assigned
        1127, // Completed for the Year
        1128, // Canceled
      ];

      if (in_array($current_tid, $disallowed_from, TRUE)) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Changes Entered because it is already past that stage. Current status TID: @current.',
          ['@label' => $label, '@current' => $current_tid ?: 'none']
        ));
        return;
      }

      if (!in_array($current_tid, $allowed_from, TRUE)) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Changes Entered from the current status (TID: @current). Allowed from: Received Back, Client Viewed, On Hold.',
          ['@label' => $label, '@current' => $current_tid ?: 'none']
        ));
        return;
      }
    }

    $from_tid = $current_tid;

    $entity->set('field_contract_status', $target_tid);
    $entity->save();

    ContractActionLogWriter::write(
      $entity,
      $action_key,
      $from_tid,
      $target_tid,
      $is_admin_override,
      'staff'
    );

    if ($is_admin_override) {
      $this->messenger()->addWarning($this->t('@label marked Changes Entered (administrator override).', ['@label' => $label]));
      return;
    }

    $this->messenger()->addMessage($this->t('@label marked Changes Entered.', ['@label' => $label]));
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
