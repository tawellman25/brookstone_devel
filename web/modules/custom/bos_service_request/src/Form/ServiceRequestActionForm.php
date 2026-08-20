<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Secondary office actions on a service request: Mark Duplicate, Mark Already
 * Covered (links the existing WO/contract), Reject (requires a reason). Per-row
 * confirm forms — not VBO.
 */
final class ServiceRequestActionForm extends ConfirmFormBase {

  protected $serviceRequest;
  protected $op;
  protected $statusResolver;
  protected $eligibility;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->statusResolver = $container->get('bos_service_request.status_resolver');
    $instance->eligibility = $container->get('bos_service_request.eligibility');
    return $instance;
  }

  public function getFormId(): string {
    return 'bos_service_request_action_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->serviceRequest = $this->getRouteMatch()->getParameter('service_request');
    $this->op = (string) $this->getRouteMatch()->getParameter('op');
    $form = parent::buildForm($form, $form_state);
    if ($this->op === 'reject') {
      $form['reason'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Reason for rejection'),
        '#required' => TRUE,
        '#rows' => 3,
        '#weight' => -5,
        '#description' => $this->t('Recorded in the office notes.'),
      ];
    }
    return $form;
  }

  public function getQuestion() {
    $ref = ($this->serviceRequest->hasField('field_public_ref') && !$this->serviceRequest->get('field_public_ref')->isEmpty())
      ? (string) $this->serviceRequest->get('field_public_ref')->value : '#' . $this->serviceRequest->id();
    return match ($this->getRouteMatch()->getParameter('op')) {
      'duplicate' => $this->t('Mark request @ref as a duplicate?', ['@ref' => $ref]),
      'already-covered' => $this->t('Mark request @ref as already covered?', ['@ref' => $ref]),
      'reject' => $this->t('Reject request @ref?', ['@ref' => $ref]),
      default => $this->t('Update request @ref?', ['@ref' => $ref]),
    };
  }

  public function getCancelUrl(): Url {
    return Url::fromUri('internal:/admin/operations/service-requests');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $sr = $this->serviceRequest;
    switch ($this->op) {
      case 'duplicate':
        $sr->set('field_request_status', $this->statusResolver->tid(ServiceRequestStatusResolver::DUPLICATE));
        $this->messenger()->addStatus($this->t('Request marked Duplicate.'));
        break;

      case 'already-covered':
        $sr->set('field_request_status', $this->statusResolver->tid(ServiceRequestStatusResolver::ALREADY_COVERED));
        // Link the existing WO/contract if eligibility can find one.
        if (!$sr->get('field_property')->isEmpty() && !$sr->get('field_service')->isEmpty() && !$sr->get('field_service_year')->isEmpty()) {
          $elig = $this->eligibility->evaluate(
            (int) $sr->get('field_property')->target_id,
            (int) $sr->get('field_service')->target_id,
            (int) $sr->get('field_service_year')->value,
            (int) $sr->id()
          );
          if ($elig->existingWorkOrderId) {
            $sr->set('field_existing_work_order', $elig->existingWorkOrderId);
          }
          if ($elig->existingContractId) {
            $sr->set('field_existing_contract', $elig->existingContractId);
          }
        }
        $this->messenger()->addStatus($this->t('Request marked Already Covered.'));
        break;

      case 'reject':
        $sr->set('field_request_status', $this->statusResolver->tid(ServiceRequestStatusResolver::REJECTED));
        $reason = trim((string) $form_state->getValue('reason'));
        $existing = (!$sr->get('field_office_notes')->isEmpty()) ? $sr->get('field_office_notes')->value . "\n" : '';
        $sr->set('field_office_notes', $existing . 'Rejected: ' . $reason);
        $this->messenger()->addStatus($this->t('Request rejected.'));
        break;
    }
    $sr->save();
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
