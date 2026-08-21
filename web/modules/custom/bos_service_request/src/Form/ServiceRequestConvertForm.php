<?php

declare(strict_types=1);

namespace Drupal\bos_service_request\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Approve & Create Work Order — per-row confirm form. Calls the Gate 2
 * converter (locked, transactional, idempotent). No WO-creation logic here.
 */
final class ServiceRequestConvertForm extends ConfirmFormBase {

  protected $serviceRequest;
  protected $converter;
  protected $eligibility;

  public static function create(ContainerInterface $container): static {
    $instance = new static();
    $instance->converter = $container->get('bos_service_request.converter');
    $instance->eligibility = $container->get('bos_service_request.eligibility');
    return $instance;
  }

  public function getFormId(): string {
    return 'bos_service_request_convert_confirm';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $this->serviceRequest = $this->getRouteMatch()->getParameter('service_request');
    $form = parent::buildForm($form, $form_state);
    $form['summary'] = [
      '#theme' => 'item_list',
      '#items' => $this->summaryItems(),
      '#weight' => -10,
    ];
    return $form;
  }

  public function getQuestion() {
    $ref = $this->requestValue('field_public_ref');
    return $this->t('Create a work order from request @ref?', ['@ref' => $ref ?: '#' . $this->serviceRequest->id()]);
  }

  public function getDescription() {
    return $this->t('This creates a real Work Order (status Open) for the matched property and marks the request Converted. Eligibility is re-checked first.');
  }

  public function getConfirmText() {
    return $this->t('Create Work Order');
  }

  public function getCancelUrl(): Url {
    return Url::fromUri('internal:/admin/office/service-requests');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $result = $this->converter->convert($this->serviceRequest, $this->currentUser());
    switch ($result['status']) {
      case 'converted':
        $this->messenger()->addStatus($this->t('Work order @wo created and request marked Converted.', ['@wo' => $result['work_order_id']]));
        break;

      case 'already_converted':
        $this->messenger()->addWarning($this->t('This request was already converted (work order @wo).', ['@wo' => $result['work_order_id'] ?? '?']));
        break;

      case 'not_eligible':
        $this->messenger()->addError($this->t('@msg', ['@msg' => $result['message'] ?? 'No longer eligible.']));
        break;

      case 'no_property':
        $this->messenger()->addError($this->t('Assign a matched property to this request before converting.'));
        break;

      default:
        $this->messenger()->addError($this->t('Conversion failed: @msg', ['@msg' => $result['message'] ?? 'unknown error']));
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  private function requestValue(string $field): string {
    return ($this->serviceRequest->hasField($field) && !$this->serviceRequest->get($field)->isEmpty())
      ? (string) $this->serviceRequest->get($field)->value : '';
  }

  private function summaryItems(): array {
    $sr = $this->serviceRequest;
    $items = [];
    $items[] = $this->t('Submitted: @n — @a @z', [
      '@n' => $this->requestValue('field_submitted_name'),
      '@a' => $this->requestValue('field_submitted_address'),
      '@z' => $this->requestValue('field_submitted_zip'),
    ]);
    $propId = (!$sr->get('field_property')->isEmpty()) ? (int) $sr->get('field_property')->target_id : 0;
    if ($propId) {
      $prop = $sr->get('field_property')->entity;
      $items[] = $this->t('Matched property: @p (#@id)', ['@p' => $prop ? $prop->label() : '?', '@id' => $propId]);
      $term = (!$sr->get('field_service')->isEmpty()) ? (int) $sr->get('field_service')->target_id : 0;
      $year = (!$sr->get('field_service_year')->isEmpty()) ? (int) $sr->get('field_service_year')->value : 0;
      if ($term && $year) {
        $elig = $this->eligibility->evaluate($propId, $term, $year, (int) $sr->id());
        $items[] = $this->t('Current eligibility: @o', ['@o' => $elig->outcome]);
      }
    }
    else {
      $items[] = $this->t('⚠ No property matched — assign one before converting.');
    }
    $flags = $this->requestValue('field_review_flags');
    if ($flags !== '') {
      $items[] = $this->t('Flags: @f', ['@f' => str_replace("\n", ', ', $flags)]);
    }
    return $items;
  }

}
