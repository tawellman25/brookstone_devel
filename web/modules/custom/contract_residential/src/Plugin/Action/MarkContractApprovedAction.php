<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\contract_residential\ContractActionLogWriter;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Mark Contract as Approved (contract_status = 1123) with guardrails.
 * Admin override: role "administrator" may bypass guardrails.
 *
 * Guardrail:
 * - Requires at least one file uploaded to field_paper_contract_pdf before approval
 *   (PDF or photos), unless administrator override is used.
 *
 * @Action(
 *   id = "contract_residential_mark_approved",
 *   label = @Translation("Mark Contract: Approved"),
 *   category = @Translation("Contracts"),
 *   confirm = TRUE
 * )
 */
class MarkContractApprovedAction extends ViewsBulkOperationsActionBase {
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

    $action_key = 'contract_residential_mark_approved';
    $target_tid = 1123; // Approved

    $current_tid = !$entity->get('field_contract_status')->isEmpty()
      ? (int) $entity->get('field_contract_status')->target_id
      : 0;

    if ($current_tid === $target_tid) {
      return;
    }

    $label = $entity->label() ?: $this->t('Contract @id', ['@id' => $entity->id()]);
    $is_admin_override = \Drupal::currentUser()->hasRole('administrator');

    $has_signed_contract = $entity->hasField('field_paper_contract_pdf')
      && !$entity->get('field_paper_contract_pdf')->isEmpty();

    $context = NULL;

    if (!$has_signed_contract && !$is_admin_override) {
      $this->messenger()->addError($this->t(
        '@label cannot be approved until a signed contract file is uploaded.',
        ['@label' => $label]
      ));
      return;
    }

    if (!$has_signed_contract && $is_admin_override) {
      $this->messenger()->addWarning($this->t(
        'Administrator override: Contract approved without a signed contract file uploaded.'
      ));
      $context = 'admin_override_without_signed_contract';
      // Continue.
    }

    if (!$is_admin_override) {
      $allowed_from = [
        1122, // Changes Entered
        1121, // Received Back (optional)
        1126, // On Hold (optional)
      ];

      $disallowed_from = [
        1124, // Work Orders Created
        1125, // Assigned
        1127, // Completed for the Year
        1128, // Canceled
      ];

      if (in_array($current_tid, $disallowed_from, TRUE)) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Approved because it is already past that stage. Current status TID: @current.',
          ['@label' => $label, '@current' => $current_tid ?: 'none']
        ));
        return;
      }

      if (!in_array($current_tid, $allowed_from, TRUE)) {
        $this->messenger()->addError($this->t(
          '@label cannot be marked Approved from the current status (TID: @current). Allowed from: Changes Entered, Received Back, On Hold.',
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
      'staff',
      $context
    );

    if ($is_admin_override) {
      $this->messenger()->addWarning($this->t('@label marked Approved (administrator override).', [
        '@label' => $label,
      ]));
      return;
    }

    $this->messenger()->addMessage($this->t('@label marked Approved.', [
      '@label' => $label,
    ]));
  }

  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
