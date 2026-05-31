<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.7 — reject a single ingest row from the Discovery Queue or
 * Fuzzy Match Review queue.
 *
 * Routes:
 *   /admin/materials/supplier-ingest/discovery/{row}/reject
 *   /admin/materials/supplier-ingest/fuzzy-review/{row}/reject
 *
 * Same form handles both routes. After submit, save-and-load-next
 * sends the reviewer to the next pending row in the SAME workflow
 * context (discovery or fuzzy review) — detected from the row's
 * current match tier BEFORE the reject mutates field_row_status.
 */
class RejectRowForm extends FormBase {
  use IngestRowFormTrait;

  private ?EntityInterface $row = NULL;
  /**
   * Workflow context captured at buildForm so the post-submit redirect
   * can route to the correct same-operation form / queue. The tier
   * read is BEFORE the reject mutation, when the row is still on its
   * originating queue's tier.
   */
  private string $context = self::CTX_DISCOVERY;
  private string $sameOperationRoute = 'supplier_price_ingest.discovery_reject';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'supplier_price_ingest_reject_row';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_row = NULL): array {
    $this->row = $supplier_price_ingest_row;
    if (!$this->row) {
      $form['error'] = ['#markup' => $this->t('No row loaded.')];
      return $form;
    }

    // Capture workflow context from the row's current tier — before the
    // reject mutation flips field_row_status. Used by submitForm to send
    // the reviewer to the next pending row in the SAME workflow.
    $tier = (string) ($this->row->get('field_match_tier')->value ?? '');
    if ($tier === self::CTX_TIER_FUZZY_REVIEW) {
      $this->context = self::CTX_FUZZY_REVIEW;
      $this->sameOperationRoute = 'supplier_price_ingest.fuzzy_reject';
    }
    else {
      $this->context = self::CTX_DISCOVERY;
      $this->sameOperationRoute = 'supplier_price_ingest.discovery_reject';
    }

    $form['back_link'] = $this->buildBackToQueueLink($this->context);
    $this->attachRowFormLibrary($form);

    $batch = $this->row->get('field_batch')->entity;
    $supplier = $batch ? $batch->get('field_supplier')->entity : NULL;

    $form['summary'] = [
      '#type' => 'item',
      '#title' => $this->t('Reject row'),
      '#markup' => $this->t(
        'Batch: @batch — Supplier: @supplier — Row #@n<br>Description: <em>@desc</em>',
        [
          '@batch' => $batch ? $batch->label() : '(unknown)',
          '@supplier' => $supplier ? $supplier->label() : '(unknown)',
          '@n' => (int) ($this->row->get('field_row_number')->value ?? 0),
          '@desc' => (string) ($this->row->get('field_description')->value ?? ''),
        ],
      ),
    ];

    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Rejection notes (optional)'),
      '#rows' => 3,
      '#description' => $this->t('Captured in the row\'s resolution notes for audit.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject Row'),
      '#button_type' => 'danger',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->getCancelUrl(),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $row = $this->row;
    if (!$row) {
      return;
    }
    $row->set('field_row_status', 'rejected');
    $row->set('field_resolution_action', 'rejected');
    $row->set('field_resolved_by', $this->currentUser->id());
    $row->set('field_resolved_on', gmdate('Y-m-d\TH:i:s'));

    $notes = trim((string) $form_state->getValue('notes'));
    $existing = trim((string) ($row->get('field_resolution_notes')->value ?? ''));
    $rejectLine = sprintf('Rejected by %s on %s.', $this->currentUser->getDisplayName(), date('m/d/Y g:i A'));
    if ($notes !== '') {
      $rejectLine .= ' Reason: ' . $notes;
    }
    $row->set('field_resolution_notes', $existing === '' ? $rejectLine : ($existing . "\n" . $rejectLine));
    $row->save();

    $this->messenger()->addStatus($this->t('Row #@n rejected.', ['@n' => $row->id()]));
    $form_state->setRedirectUrl($this->nextRowRedirect(
      $row,
      $this->sameOperationRoute,
      $this->context,
      $this->entityTypeManager,
      $this->messenger(),
    ));
  }

  /**
   * Cancel target — route back to whichever queue the row came from,
   * inferred from its current match tier (read before submit).
   */
  private function getCancelUrl(): Url {
    return $this->context === self::CTX_FUZZY_REVIEW
      ? Url::fromUserInput(self::QUEUE_URL_FUZZY_REVIEW)
      : Url::fromUserInput(self::QUEUE_URL_DISCOVERY);
  }

}
