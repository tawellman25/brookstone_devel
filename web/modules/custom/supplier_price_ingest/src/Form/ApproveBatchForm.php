<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\supplier_price_ingest\Service\IngestCommitter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.5 — Approve and Commit confirm form.
 * Phase 3.6 — wired to real IngestCommitter (replaces Phase 3.5 stub).
 *
 * Route: /admin/materials/supplier-ingest/batch/{batch}/approve
 * Permission: 'administer supplier price ingest'
 *
 * Transitions: dry_run_complete → awaiting_approval → approved → committed
 *
 * Commit-path selection by auto-applying row count:
 *   < BATCH_API_THRESHOLD → synchronous commit inside submitForm()
 *   ≥ BATCH_API_THRESHOLD → Batch API path (per-50-row operations + finish callback)
 *
 * The two-hop status transition (dry_run_complete → awaiting_approval
 * → approved) is preserved across both paths so the eventual queue-
 * worker variant (deferred) can re-use the same approve handler.
 */
class ApproveBatchForm extends ConfirmFormBase {

  /**
   * Auto-applying row count above which the approve handler hands off
   * to Drupal's Batch API instead of committing synchronously.
   */
  private const BATCH_API_THRESHOLD = 500;

  private ?EntityInterface $batch = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly IngestCommitter $committer,
    private readonly AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('supplier_price_ingest.committer'),
      $container->get('current_user'),
    );
  }

  public function getFormId(): string {
    return 'supplier_price_ingest_batch_approve';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?EntityInterface $supplier_price_ingest_batch = NULL): array {
    $this->batch = $supplier_price_ingest_batch;
    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    $label = $this->batch ? (string) ($this->batch->get('field_source_filename')->value ?? $this->batch->label() ?? '') : '';
    return sprintf('Approve and commit batch "%s"?', $label !== '' ? $label : ('#' . ($this->batch ? $this->batch->id() : '?')));
  }

  public function getDescription(): string {
    if (!$this->batch) {
      return '';
    }
    $autoApply = (int) ($this->batch->get('field_row_count_tier1')->value ?? 0)
      + (int) ($this->batch->get('field_row_count_tier2')->value ?? 0)
      + (int) ($this->batch->get('field_row_count_tier3_high')->value ?? 0);
    $review = (int) ($this->batch->get('field_row_count_tier3_med')->value ?? 0);
    $discovery = (int) ($this->batch->get('field_row_count_discovery')->value ?? 0);

    $note = $autoApply >= self::BATCH_API_THRESHOLD
      ? sprintf('Commit will run via Drupal Batch API (≥%d auto-applying rows). Stay on the progress page until it completes.', self::BATCH_API_THRESHOLD)
      : 'Commit will run synchronously on submit.';

    return implode("\n\n", [
      sprintf('Will auto-apply %d rows (Tier 1 + Tier 2 + Tier 3 high) — catalog will be updated where the price change is ≤ ±10%%, flagged for review where the increase exceeds +10%%.', $autoApply),
      sprintf('Will leave %d rows in the medium-confidence review queue (Phase 3.7).', $review),
      sprintf('Will leave %d rows in the discovery queue (Phase 3.7).', $discovery),
      $note,
    ]);
  }

  public function getConfirmText() {
    return $this->t('Approve and Commit');
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
    if ($status !== 'dry_run_complete') {
      $form_state->setErrorByName(
        '',
        $this->t('Batch is in status "@status"; only "Dry Run Complete" batches can be approved.', [
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

    // Two-hop transition: dry_run_complete → awaiting_approval → approved.
    // The intermediate save means a poll request mid-submit would see
    // 'awaiting_approval' rather than the stale dry_run_complete value.
    $batch->set('field_status', 'awaiting_approval');
    $batch->save();

    $batch->set('field_status', 'approved');
    $batch->set('field_committed_by', $this->currentUser->id());
    $batch->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
    $batch->save();

    // Decide path by auto-applying row count.
    $autoIds = $this->committer->queryAutoApplyingRowIds((int) $batch->id());
    $count = count($autoIds);

    if ($count >= self::BATCH_API_THRESHOLD) {
      $this->setUpBatchApiCommit($batch, $autoIds, $form_state);
      return;
    }

    // Synchronous path.
    try {
      $result = $this->committer->commitBatch($batch);
      $this->messenger()->addStatus($this->t(
        'Batch @id committed: @summary',
        ['@id' => $batch->id(), '@summary' => $result->summary()],
      ));
    }
    catch (\Throwable $e) {
      // Mark batch as failed and surface the error. The committer's
      // per-row error containment already swallowed per-row issues;
      // anything reaching this catch is a batch-level failure.
      \Drupal::logger('supplier_price_ingest')->error(
        'Batch @id commit failed: @cls @msg',
        ['@id' => $batch->id(), '@cls' => get_class($e), '@msg' => $e->getMessage()],
      );
      $batch->set('field_status', 'failed');
      $batch->set('field_dry_run_report', json_encode([
        'commit_fatal_error' => $e->getMessage(),
        'exception_class' => get_class($e),
        'occurred_at' => gmdate('Y-m-d\TH:i:s'),
      ], JSON_PRETTY_PRINT));
      $batch->save();
      $this->messenger()->addError($this->t(
        'Batch @id commit failed: @msg',
        ['@id' => $batch->id(), '@msg' => $e->getMessage()],
      ));
    }

    $form_state->setRedirectUrl(Url::fromRoute(
      'supplier_price_ingest.batch_view',
      ['supplier_price_ingest_batch' => $batch->id()],
    ));
  }

  /**
   * Hand the commit off to Drupal's Batch API.
   *
   * Each operation processes one chunk of 50 rows by calling
   * ApproveBatchForm::batchCommitOperation() (public static). The
   * finish callback runs IngestCommitter::finalizeBatch() and
   * redirects to the batch detail view.
   */
  private function setUpBatchApiCommit(EntityInterface $batch, array $autoIds, FormStateInterface $form_state): void {
    $chunkSize = 50;
    $batchId = (int) $batch->id();
    $operations = [];
    foreach (array_chunk($autoIds, $chunkSize) as $chunk) {
      $operations[] = [
        [self::class, 'batchCommitOperation'],
        [$batchId, $chunk],
      ];
    }

    \batch_set([
      'title' => $this->t('Committing batch @id…', ['@id' => $batchId]),
      'init_message' => $this->t('Starting commit of @count auto-applying rows.', ['@count' => count($autoIds)]),
      'progress_message' => $this->t('Committed @current of @total chunks…'),
      'error_message' => $this->t('Batch commit encountered an error.'),
      'operations' => $operations,
      'finished' => [self::class, 'batchCommitFinished'],
    ]);

    $form_state->setRedirectUrl(Url::fromRoute(
      'supplier_price_ingest.batch_view',
      ['supplier_price_ingest_batch' => $batchId],
    ));
  }

  /**
   * Batch API operation — commit one chunk of rows.
   *
   * @param int $batchId
   *   The supplier_price_ingest_batch id.
   * @param int[] $rowIds
   *   The supplier_price_ingest_row ids to commit in this chunk.
   * @param array $context
   *   Batch API context (passed by reference by core).
   */
  public static function batchCommitOperation(int $batchId, array $rowIds, array &$context): void {
    $etm = \Drupal::entityTypeManager();
    /** @var \Drupal\supplier_price_ingest\Service\IngestCommitter $committer */
    $committer = \Drupal::service('supplier_price_ingest.committer');

    $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batchId);
    if (!$batch) {
      $context['results']['errors'][] = "Batch $batchId disappeared mid-commit.";
      return;
    }
    $supplier = $batch->get('field_supplier')->entity;
    if (!$supplier) {
      $context['results']['errors'][] = "Batch $batchId has no supplier.";
      return;
    }

    if (!isset($context['results']['applied'])) {
      $context['results'] = [
        'batch_id' => $batchId,
        'applied' => 0,
        'flagged_high' => 0,
        'auto_created' => 0,
        'errored' => 0,
        'errors' => [],
      ];
    }

    $rowStorage = $etm->getStorage('supplier_price_ingest_row');
    $rows = $rowStorage->loadMultiple($rowIds);
    foreach ($rows as $row) {
      try {
        $outcome = $committer->commitOneRow($batch, $supplier, $row);
        switch ($outcome->status) {
          case 'applied':      $context['results']['applied']++; break;
          case 'flagged_high': $context['results']['flagged_high']++; break;
          case 'auto_created': $context['results']['auto_created']++; break;
          case 'error':
            $context['results']['errored']++;
            $context['results']['errors'][] = [
              'row_id' => (int) $row->id(),
              'message' => $outcome->message,
            ];
            break;
        }
      }
      catch (\Throwable $e) {
        $context['results']['errored']++;
        $context['results']['errors'][] = [
          'row_id' => (int) $row->id(),
          'message' => get_class($e) . ': ' . $e->getMessage(),
        ];
      }
    }
    $rowStorage->resetCache(array_keys($rows));

    $context['message'] = t('Committed @applied applied / @flagged flagged / @created created so far…', [
      '@applied' => $context['results']['applied'],
      '@flagged' => $context['results']['flagged_high'],
      '@created' => $context['results']['auto_created'],
    ]);
  }

  /**
   * Batch API finish callback — flip batch to 'committed' (or 'failed'),
   * post a summary status message.
   */
  public static function batchCommitFinished(bool $success, array $results, array $operations): void {
    $batchId = $results['batch_id'] ?? 0;
    $etm = \Drupal::entityTypeManager();
    $batch = $batchId ? $etm->getStorage('supplier_price_ingest_batch')->load($batchId) : NULL;

    if (!$batch) {
      \Drupal::messenger()->addError(t('Commit finished but batch entity could not be reloaded.'));
      return;
    }

    if (!$success) {
      // Batch API itself signaled failure (an operation crashed beyond
      // the per-row try/catch). Mark the batch failed.
      $batch->set('field_status', 'failed');
      $batch->set('field_dry_run_report', json_encode([
        'commit_fatal_error' => 'Batch API operation failed',
        'partial_counters' => $results,
        'occurred_at' => gmdate('Y-m-d\TH:i:s'),
      ], JSON_PRETTY_PRINT));
      $batch->save();
      \Drupal::messenger()->addError(t('Batch @id commit failed mid-flight; batch marked failed. Partial: @partial', [
        '@id' => $batchId,
        '@partial' => json_encode($results),
      ]));
      return;
    }

    /** @var \Drupal\supplier_price_ingest\Service\IngestCommitter $committer */
    $committer = \Drupal::service('supplier_price_ingest.committer');
    $committer->finalizeBatch($batch);

    $applied = (int) ($results['applied'] ?? 0);
    $flagged = (int) ($results['flagged_high'] ?? 0);
    $created = (int) ($results['auto_created'] ?? 0);
    $errored = (int) ($results['errored'] ?? 0);

    \Drupal::messenger()->addStatus(t(
      'Batch @id committed: @applied applied, @flagged flagged for review, @created auto-created links, @errored errored.',
      [
        '@id' => $batchId,
        '@applied' => $applied,
        '@flagged' => $flagged,
        '@created' => $created,
        '@errored' => $errored,
      ],
    ));
  }

}
