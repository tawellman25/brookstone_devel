<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.5 — Reject Batch confirm form.
 *
 * Route: /admin/materials/supplier-ingest/batch/{batch}/reject
 * Permission: 'administer supplier price ingest'
 *
 * Allowed source statuses: dry_run_complete (normal case), failed
 * (lets office staff close out a failed batch cleanly). Other statuses
 * reject with a form-level error.
 *
 * On submit: batch.field_status = 'rejected'; every child row gets
 * field_row_status = 'rejected'. No deletes — batch and rows remain
 * for audit. The user who rejected is captured in field_committed_by
 * (we deliberately re-use the field for whoever made the final
 * decision rather than introducing a separate field_decided_by).
 */
class RejectBatchForm extends ConfirmFormBase {

  private const CHUNK_SIZE = 100;

  private ?EntityInterface $batch = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'supplier_price_ingest_batch_reject';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_batch = NULL): array {
    $this->batch = $supplier_price_ingest_batch;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    $label = $this->batch ? (string) ($this->batch->get('field_source_filename')->value ?? $this->batch->label() ?? '') : '';
    return sprintf('Reject batch "%s"?', $label !== '' ? $label : ('#' . ($this->batch ? $this->batch->id() : '?')));
  }

  public function getDescription(): string {
    if (!$this->batch) {
      return '';
    }
    $rowCount = (int) ($this->batch->get('field_row_count_total')->value ?? 0);
    return sprintf(
      'This will mark the batch and all %d of its rows as rejected. No catalog changes will be made. The batch and rows remain in the database for audit. This action cannot be undone.',
      $rowCount,
    );
  }

  public function getConfirmText() {
    return $this->t('Reject Batch');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute(
      'supplier_price_ingest.batch_view',
      ['supplier_price_ingest_batch' => $this->batch?->id() ?? 0],
    );
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (!$this->batch) {
      $form_state->setErrorByName('', $this->t('No batch loaded.'));
      return;
    }
    $status = (string) ($this->batch->get('field_status')->value ?? '');
    if (!in_array($status, ['dry_run_complete', 'failed'], TRUE)) {
      $form_state->setErrorByName(
        '',
        $this->t('Batch is in status "@status"; only "Dry Run Complete" or "Failed" batches can be rejected.', [
          '@status' => $status,
        ]),
      );
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $batch = $this->batch;
    if (!$batch) {
      return;
    }

    $batch->set('field_status', 'rejected');
    $batch->set('field_committed_by', $this->currentUser->id());
    $batch->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
    $batch->save();

    // Mark every row as rejected.
    $rowStorage = $this->entityTypeManager->getStorage('supplier_price_ingest_row');
    $rowIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->sort('id', 'ASC')
      ->execute();

    $marked = 0;
    foreach (array_chunk(array_values($rowIds), self::CHUNK_SIZE) as $chunk) {
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $row->set('field_row_status', 'rejected');
        $row->save();
        $marked++;
      }
      $rowStorage->resetCache(array_keys($rows));
    }

    \Drupal::logger('supplier_price_ingest')->info(
      'Batch @bid rejected by user @uid. Marked @marked rows as field_row_status=rejected.',
      ['@bid' => $batch->id(), '@uid' => $this->currentUser->id(), '@marked' => $marked],
    );

    $this->messenger()->addStatus($this->t(
      'Batch @id rejected. @marked rows marked rejected.',
      ['@id' => $batch->id(), '@marked' => $marked],
    ));

    $form_state->setRedirectUrl(Url::fromRoute(
      'supplier_price_ingest.batch_view',
      ['supplier_price_ingest_batch' => $batch->id()],
    ));
  }

}
