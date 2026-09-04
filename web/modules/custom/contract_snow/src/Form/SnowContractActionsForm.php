<?php

declare(strict_types=1);

namespace Drupal\contract_snow\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Snow Removal Service Agreement workflow toolbar (P3).
 *
 * Rendered at the top of a snow contract page: current status + Preview PDF,
 * Mark Sent, Upload Signed, Activate. Drives field_contract_status through the
 * lifecycle (Created → Sent-Posted → Received Back → Executed/Active).
 */
class SnowContractActionsForm extends FormBase {

  public function getFormId(): string {
    return 'contract_snow_actions';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $contract = NULL): array {
    if (!$contract) {
      return $form;
    }
    $form_state->set('contract_id', $contract->id());

    $status = ($contract->hasField('field_contract_status') && !$contract->get('field_contract_status')->isEmpty())
      ? $contract->get('field_contract_status')->entity : NULL;
    $status_label = $status ? $status->label() : 'No status';
    $has_signed = $contract->hasField('field_snow_signed_pdf') && !$contract->get('field_snow_signed_pdf')->isEmpty();

    $form['#attributes']['class'][] = 'snow-contract-actions';
    $form['#attached']['library'][] = 'contract_snow/actions';

    $form['status'] = [
      '#markup' => '<span class="sca-status">Status: <strong>' . htmlspecialchars($status_label) . '</strong></span>'
        . ($has_signed ? ' <span class="sca-signed">✓ signed PDF on file</span>' : ''),
    ];

    $form['preview'] = [
      '#type' => 'link',
      '#title' => 'Preview / Print Agreement',
      '#url' => Url::fromRoute('contract_snow.agreement_pdf', ['contracts' => $contract->id()]),
      '#attributes' => ['class' => ['button'], 'target' => '_blank'],
    ];

    $form['mark_sent'] = [
      '#type' => 'submit',
      '#value' => 'Mark Sent',
      '#name' => 'mark_sent',
      '#submit' => ['::submitMarkSent'],
      '#attributes' => ['class' => ['button']],
    ];

    $form['upload'] = [
      '#type' => 'link',
      '#title' => $has_signed ? 'Replace Signed PDF' : 'Upload Signed',
      '#url' => Url::fromRoute('entity.contracts.edit_form', ['contracts' => $contract->id()]),
      '#attributes' => ['class' => ['button']],
    ];

    $form['activate'] = [
      '#type' => 'submit',
      '#value' => 'Activate',
      '#name' => 'activate',
      '#submit' => ['::submitActivate'],
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];
    if (!$has_signed) {
      $form['activate']['#disabled'] = TRUE;
      $form['activate']['#attributes']['title'] = 'Upload the signed PDF before activating.';
    }

    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * Required by FormBase; per-button handlers do the work.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function submitMarkSent(array &$form, FormStateInterface $form_state): void {
    $this->transition($form_state, 'Sent - Posted', 'marked Sent');
  }

  public function submitActivate(array &$form, FormStateInterface $form_state): void {
    $this->transition($form_state, 'Executed / Active', 'Activated (pricing + terms are now locked)');
  }

  /**
   * Set the contract's status to the named term and message the result.
   */
  protected function transition(FormStateInterface $form_state, string $term_name, string $message): void {
    $cid = $form_state->get('contract_id');
    $contract = \Drupal::entityTypeManager()->getStorage('contracts')->load($cid);
    $tid = contract_snow_status_tid($term_name);
    if (!$contract || !$tid) {
      $this->messenger()->addError('Could not update the contract status.');
      return;
    }
    $contract->set('field_contract_status', $tid);
    $contract->save();
    $this->messenger()->addStatus('Contract ' . $message . '.');
  }

}
