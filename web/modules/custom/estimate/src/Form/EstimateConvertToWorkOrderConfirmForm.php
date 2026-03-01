<?php

declare(strict_types=1);

namespace Drupal\estimate\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\estimate\Service\WorkOrderConverter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm form: Convert an accepted Estimate to a Work Order.
 *
 * Governance:
 * - Conversion must be explicit (confirm form).
 * - Converter enforces guardrails:
 *   - Stage = Accepted (config)
 *   - Current revision only
 *   - No duplicate WOs
 *   - No silent creation
 */
final class EstimateConvertToWorkOrderConfirmForm extends ConfirmFormBase {

  private int $estimateId = 0;

  public function __construct(
    private readonly WorkOrderConverter $workOrderConverter,
    private readonly LoggerInterface $logger,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('estimate.work_order_converter'),
      $container->get('logger.channel.estimate'),
    );
  }

  public function getFormId(): string {
    return 'estimate_convert_to_work_order_confirm';
  }

  public function getQuestion(): string {
    return $this->t('Convert this Estimate to a Work Order?');
  }

  public function getConfirmText(): string {
    return $this->t('Convert');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('entity.estimate.canonical', ['estimate' => $this->estimateId]);
  }

  /**
   * Route parameter is "estimate" (entity:estimate).
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $estimate = NULL): array {
    if (!$estimate || $estimate->getEntityTypeId() !== 'estimate') {
      throw new \InvalidArgumentException('Missing or invalid estimate route parameter.');
    }

    $this->estimateId = (int) $estimate->id();

    // Summary (helpful context).
    $total = '-';
    if ($estimate->hasField('field_estimate_total') && !$estimate->get('field_estimate_total')->isEmpty()) {
      $total = (string) $estimate->get('field_estimate_total')->value;
    }

    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Estimate summary'),
      '#markup' => '<p><strong>' . htmlspecialchars($estimate->label() ?: ('Estimate ' . $estimate->id())) . '</strong><br/>'
        . 'Bundle: ' . htmlspecialchars($estimate->bundle()) . '<br/>'
        . 'Total: ' . htmlspecialchars($total) . '</p>',
    ];

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<p>This will create a Work Order and link it back to this Estimate. Conversion will fail if the Estimate is not Accepted, not the current revision, already linked, or missing required inputs (e.g., Contact).</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Reload the estimate to ensure current entity state.
    /** @var \Drupal\Core\Entity\EntityInterface|null $estimate */
    $estimate = $this->entityTypeManager()->getStorage('estimate')->load($this->estimateId);

    if (!$estimate) {
      $this->messenger()->addError($this->t('Estimate could not be loaded.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    try {
      $work_order = $this->workOrderConverter->convert($estimate);

      $this->messenger()->addStatus($this->t('Work Order @wid created from Estimate @eid.', [
        '@wid' => $work_order->id(),
        '@eid' => $estimate->id(),
      ]));

      $form_state->setRedirectUrl(Url::fromRoute('entity.work_order.canonical', [
        'work_order' => $work_order->id(),
      ]));
    }
    catch (\Throwable $e) {
      $this->logger->error('Estimate conversion failed for @eid: @msg', [
        '@eid' => $this->estimateId,
        '@msg' => $e->getMessage(),
      ]);

      $this->messenger()->addError($this->t('Conversion failed: @msg', [
        '@msg' => $e->getMessage(),
      ]));

      $form_state->setRedirectUrl($this->getCancelUrl());
    }
  }

}
