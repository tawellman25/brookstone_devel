<?php

namespace Drupal\contract_residential\Plugin\Action;

use Drupal\contract_residential\ContractActionLogWriter;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\views_bulk_operations\Action\ViewsBulkOperationsActionBase;

/**
 * Mark Contract as Canceled / Void (contract_status = 1128) with guardrails.
 * Admin override: role "administrator" may cancel without a reason.
 *
 * Guardrails:
 * - Captures cancellation reason via VBO confirmation form.
 * - Reason required for non-administrators.
 * - Reason written to contracts.field_cancellation_reason for reporting.
 * - Reason echoed to contract_action_log.field_context for audit.
 *
 * Transition rules:
 * - Allowed from any non-terminal status.
 * - Disallowed from terminal: 1127 Completed for Year, 1128 Canceled.
 *
 * @Action(
 *   id = "contract_residential_mark_canceled",
 *   label = @Translation("Mark Contract: Canceled / Void"),
 *   category = @Translation("Contracts"),
 *   confirm = TRUE
 * )
 */
class MarkContractCanceledAction extends ViewsBulkOperationsActionBase {
  use MessengerTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'cancellation_reason' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $is_admin = \Drupal::currentUser()->hasRole('administrator');

    $form['cancellation_reason'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cancellation reason'),
      '#description' => $is_admin
        ? $this->t('Recommended. As an administrator you may leave this empty, but doing so will record an override.')
        : $this->t('Required. Briefly explain why these contracts are being canceled (e.g. customer request, property sold, lost to competitor).'),
      '#default_value' => $this->configuration['cancellation_reason'] ?? '',
      '#rows' => 3,
      '#required' => !$is_admin,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $this->configuration['cancellation_reason'] = trim((string) $form_state->getValue('cancellation_reason'));
  }

  /**
   * {@inheritdoc}
   */
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

    $action_key = 'contract_residential_mark_canceled';
    $target_tid = 1128;

    $current_tid = !$entity->get('field_contract_status')->isEmpty()
      ? (int) $entity->get('field_contract_status')->target_id
      : 0;

    if ($current_tid === $target_tid) {
      return;
    }

    $label = $entity->label() ?: $this->t('Contract @id', ['@id' => $entity->id()]);
    $is_admin_override = \Drupal::currentUser()->hasRole('administrator');
    $reason = trim((string) ($this->configuration['cancellation_reason'] ?? ''));
    $context = NULL;

    // Reason guardrail — required for non-admins.
    if ($reason === '' && !$is_admin_override) {
      $this->messenger()->addError($this->t(
        '@label cannot be canceled without a cancellation reason.',
        ['@label' => $label]
      ));
      return;
    }

    if ($reason === '' && $is_admin_override) {
      $this->messenger()->addWarning($this->t(
        'Administrator override: @label canceled without a cancellation reason.',
        ['@label' => $label]
      ));
      $context = 'admin_override_without_reason';
    }
    else {
      $context = $reason;
    }

    // Transition guardrail — block from terminal statuses (admin bypass).
    if (!$is_admin_override) {
      $disallowed_from = [
        1127, // Completed for the Year
        1128, // Canceled (idempotent guard already returned above)
      ];

      if (in_array($current_tid, $disallowed_from, TRUE)) {
        $this->messenger()->addError($this->t(
          '@label cannot be canceled because it is already in a terminal status (TID: @current).',
          ['@label' => $label, '@current' => $current_tid]
        ));
        return;
      }
    }

    $from_tid = $current_tid;

    // Persist reason on the contract (if field exists).
    if ($entity->hasField('field_cancellation_reason') && $reason !== '') {
      $entity->set('field_cancellation_reason', $reason);
    }

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

    if ($is_admin_override && $reason === '') {
      // Already shown the override warning above.
      return;
    }

    if ($is_admin_override) {
      $this->messenger()->addWarning($this->t('@label canceled (administrator).', [
        '@label' => $label,
      ]));
      return;
    }

    $this->messenger()->addMessage($this->t('@label canceled.', [
      '@label' => $label,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    return $object->access('update', $account, $return_as_object);
  }

}
