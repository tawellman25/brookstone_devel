<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\supplier_price_ingest\Service\StubCommitter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Phase 3.5 — Approve and Commit confirm form.
 *
 * Route: /admin/materials/supplier-ingest/batch/{batch}/approve
 * Permission: 'administer supplier price ingest'
 *
 * Transitions: dry_run_complete → awaiting_approval → approved
 *              (and the stub committer immediately flips approved → committed).
 *
 * In Phase 3.6 the stub committer is replaced by the real commit
 * pipeline. The two-hop transition (`awaiting_approval` then `approved`)
 * is intentional so the eventual async commit (batch-API or queue
 * worker) can park the batch in `awaiting_approval` while it runs and
 * graduate to `approved` / `committed` on completion. In 3.5 we hop
 * through both states synchronously within the submit handler.
 */
class ApproveBatchForm extends ConfirmFormBase {

  private ?EntityInterface $batch = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StubCommitter $stubCommitter,
    private readonly AccountInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('supplier_price_ingest.stub_committer'),
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

    return implode("\n\n", [
      sprintf('Will auto-apply %d rows (Tier 1 + Tier 2 + Tier 3 high).', $autoApply),
      sprintf('Will leave %d rows in the medium-confidence review queue.', $review),
      sprintf('Will leave %d rows in the discovery queue.', $discovery),
      'Phase 3.5 ships a STUB commit — the batch transitions to "committed" status but no material_suppliers or material_price_history mutations occur. Real commit logic lands in Phase 3.6.',
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

    // Stub commit — flips approved → committed and stamps row statuses.
    // Phase 3.6 will replace this call with the real commit service.
    $this->stubCommitter->commit($batch, $this->currentUser);

    $this->messenger()->addStatus($this->t(
      'Batch @id approved and committed (Phase 3.5 stub — no catalog mutations).',
      ['@id' => $batch->id()],
    ));

    $form_state->setRedirectUrl(Url::fromRoute(
      'supplier_price_ingest.batch_view',
      ['supplier_price_ingest_batch' => $batch->id()],
    ));
  }

}
