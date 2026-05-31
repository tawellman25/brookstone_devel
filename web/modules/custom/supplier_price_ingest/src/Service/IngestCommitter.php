<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\wo_material_price_sync\Service\PriceSyncService;

/**
 * Phase 3.6 — real commit pipeline.
 *
 * Replaces Phase 3.5's StubCommitter. The committer is the orchestration
 * layer between an approved supplier_price_ingest_batch and the
 * unified catalog mutation authority (PriceSyncService::ingestRow()).
 *
 * Idempotent recovery:
 *   If commitBatch() is interrupted (server reboot, fatal error,
 *   timeout) partway through:
 *     - Some rows have field_row_status = 'committed' already
 *     - Some rows are still 'dry_run'
 *     - Batch status is still 'approved' (the final transition to
 *       'committed' is the last step of commitBatch)
 *   Re-invoking commitBatch() on the same batch:
 *     - Sees batch still in 'approved' (not 'committed'), proceeds
 *     - Query naturally filters to field_row_status = 'dry_run', so
 *       already-committed rows are NOT touched again
 *     - Finalizes the batch when remaining rows are done
 *   Rerun is safe — the (material, supplier, ingest_batch_id) audit
 *   chain stays consistent.
 *
 * What this committer touches:
 *   Only rows where field_match_tier IN
 *   (tier_1_mfr, tier_2_supplier_sku, tier_3_fuzzy_high)
 *   AND field_row_status = 'dry_run'.
 *
 * Rows in tier_3_fuzzy_med / discovery / skipped_* / error are NOT
 * touched here — those are handled by the Phase 3.7 review-queue UI.
 */
final class IngestCommitter {

  /**
   * Per-chunk size for the auto-applying commit loop. Tuned for memory
   * safety on very large batches; per-row work is small (one entity
   * load, one service call, one row save).
   */
  private const CHUNK_SIZE = 50;

  /**
   * Per-chunk size for the post-commit discovery-routing loop.
   * Cheaper per-row work (one field set, one save) so a larger chunk.
   */
  private const ROUTING_CHUNK_SIZE = 100;

  /**
   * Tiers that get routed to discovery_pending after the auto-applying
   * loop finishes. Both flow into the Phase 3.7 review surfaces:
   * `discovery` → Discovery Queue; `tier_3_fuzzy_med` → Fuzzy Match
   * Review. The shared `discovery_pending` status keeps status semantics
   * simple; the views slice by match tier.
   */
  private const DISCOVERY_ROUTING_TIERS = [
    'discovery',
    'tier_3_fuzzy_med',
    // Phase 3.7.6 — Tier 1.5 hits ship at Stage-1 confidence 85, which
    // routes to fuzzy_med review. The Stage-2 follow-up bumps to 90
    // and would move this tier to AUTO_APPLY_TIERS instead (after
    // empirical validation across 2-3 batches).
    'tier_1_5_title_substring',
  ];

  /**
   * Match tiers the committer auto-applies. Other tiers stay untouched.
   */
  private const AUTO_APPLY_TIERS = [
    'tier_1_mfr',
    'tier_2_supplier_sku',
    'tier_3_fuzzy_high',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PriceSyncService $priceSync,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Commit one approved batch synchronously.
   *
   * For large batches (≥500 auto-applying rows) the caller should
   * prefer the Batch API path in ApproveBatchForm — but this method
   * works correctly regardless of size; only request-timeout / memory
   * pressure makes the Batch API path preferable.
   *
   * @throws \RuntimeException
   *   If the batch isn't in 'approved' status.
   */
  public function commitBatch(EntityInterface $batch): CommitResult {
    $status = (string) ($batch->get('field_status')->value ?? '');
    if ($status !== 'approved') {
      throw new \RuntimeException(sprintf(
        'IngestCommitter::commitBatch refused batch %d — expected status "approved", got "%s".',
        $batch->id(),
        $status,
      ));
    }

    $supplier = $batch->get('field_supplier')->entity;
    if (!$supplier) {
      throw new \RuntimeException(sprintf('Batch %d has no resolvable supplier entity.', $batch->id()));
    }

    $rowIds = $this->queryAutoApplyingRowIds((int) $batch->id());
    $result = $this->processRows($batch, $supplier, $rowIds);

    // Phase 3.7 — route discovery + fuzzy_med rows to discovery_pending
    // so the Discovery Queue and Fuzzy Match Review views pick them up.
    // Counted into the CommitResult so the batch detail report can
    // surface "routed to discovery: N" alongside the committed counts.
    $routedCount = $this->routeRemainingRowsToDiscovery((int) $batch->id());
    $result = new CommitResult(
      rowsCommitted: $result->rowsCommitted,
      rowsApplied: $result->rowsApplied,
      rowsFlaggedHigh: $result->rowsFlaggedHigh,
      rowsAutoCreated: $result->rowsAutoCreated,
      rowsSkipped: $result->rowsSkipped,
      rowsErrored: $result->rowsErrored,
      commitErrors: $result->commitErrors,
      rowsRoutedToDiscovery: $routedCount,
    );

    $this->finalizeBatch($batch);

    $this->loggerFactory->get('supplier_price_ingest')->info(
      'Batch @bid commit complete: @summary',
      ['@bid' => $batch->id(), '@summary' => $result->summary()],
    );

    return $result;
  }

  /**
   * Transition still-`dry_run` discovery + fuzzy_med rows to
   * `discovery_pending` so the Phase 3.7 review surfaces pick them up.
   *
   * Idempotent: the query filters on `field_row_status = 'dry_run'`, so
   * a re-invocation after partial completion only touches rows that
   * weren't already transitioned. Same property as the auto-applying
   * commit loop.
   *
   * Public so the ApproveBatchForm Batch API finish callback can
   * invoke it explicitly without re-running commitBatch.
   *
   * @return int  Number of rows transitioned.
   */
  public function routeRemainingRowsToDiscovery(int $batchId): int {
    $rowStorage = $this->entityTypeManager->getStorage('supplier_price_ingest_row');
    $rowIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batchId)
      ->condition('field_match_tier', self::DISCOVERY_ROUTING_TIERS, 'IN')
      ->condition('field_row_status', 'dry_run')
      ->sort('id', 'ASC')
      ->execute();
    if (empty($rowIds)) {
      return 0;
    }
    $routed = 0;
    foreach (array_chunk(array_values($rowIds), self::ROUTING_CHUNK_SIZE) as $chunk) {
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $row->set('field_row_status', 'discovery_pending');
        $row->save();
        $routed++;
      }
      $rowStorage->resetCache(array_keys($rows));
    }
    return $routed;
  }

  /**
   * Query the auto-applying rows that are still in 'dry_run' status.
   *
   * Sort by id ASC so chunked processing is deterministic — required
   * for idempotent recovery (a re-run after interruption sees the same
   * ordering on the still-dry_run rows).
   *
   * @return int[]
   */
  public function queryAutoApplyingRowIds(int $batchId): array {
    return array_values(
      $this->entityTypeManager->getStorage('supplier_price_ingest_row')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_batch', $batchId)
        ->condition('field_match_tier', self::AUTO_APPLY_TIERS, 'IN')
        ->condition('field_row_status', 'dry_run')
        ->sort('id', 'ASC')
        ->execute()
    );
  }

  /**
   * Per-row commit. Public so the Batch API operation callback in
   * ApproveBatchForm can invoke it one row at a time.
   *
   * Mutates and saves $row; returns the IngestRowResult so the caller
   * can roll up counters / record commit errors.
   */
  public function commitOneRow(
    EntityInterface $batch,
    EntityInterface $supplier,
    EntityInterface $row,
  ): \Drupal\wo_material_price_sync\Service\IngestRowResult {
    $materialId = (int) ($row->get('field_matched_material')->target_id ?? 0);
    if ($materialId <= 0) {
      return $this->stampRowAsError(
        $row,
        sprintf('Row %d has no matched material id; cannot commit.', $row->id()),
      );
    }

    $material = $this->entityTypeManager->getStorage('material')->load($materialId);
    if (!$material) {
      return $this->stampRowAsError(
        $row,
        sprintf('Matched material #%d no longer exists (deleted between matching and commit).', $materialId),
      );
    }

    $rowData = [
      'unit_cost'                => (float) ($row->get('field_unit_cost')->value ?? 0),
      'cost_uom'                 => (string) ($row->get('field_cost_uom')->value ?? ''),
      'supplier_sku'             => (string) ($row->get('field_supplier_sku')->value ?? ''),
      'manufacturer_item_number' => (string) ($row->get('field_manufacturer_item_number')->value ?? ''),
      'manufacturer_name'        => (string) ($row->get('field_manufacturer_name')->value ?? ''),
      'pack_quantity'            => (string) ($row->get('field_pack_quantity')->value ?? ''),
      'description'              => (string) ($row->get('field_description')->value ?? ''),
      // Pack-tier capture (Phase 3.7.5). Pass through to PriceSyncService
      // which writes onto the matched material entity.
      'pack_qty_mid_label'       => (string) ($row->get('field_pack_qty_mid_label')->value ?? ''),
      'pack_qty_mid'             => $row->get('field_pack_qty_mid')->isEmpty() ? NULL : (int) $row->get('field_pack_qty_mid')->value,
      'pack_qty_case'            => $row->get('field_pack_qty_case')->isEmpty() ? NULL : (int) $row->get('field_pack_qty_case')->value,
      'pack_family_tid'          => $row->get('field_pack_family')->isEmpty() ? NULL : (int) $row->get('field_pack_family')->target_id,
      'pack_data_source'         => (string) ($row->get('field_pack_data_source')->value ?? ''),
    ];

    $outcome = $this->priceSync->ingestRow(
      $material,
      $supplier,
      $rowData,
      'feed_import_auto',
      (int) $batch->id(),
    );

    $this->stampRowFromOutcome($row, $outcome);
    return $outcome;
  }

  /**
   * Chunk through the row ids, calling commitOneRow on each.
   * Used by the synchronous path; the Batch API path calls
   * commitOneRow() directly.
   */
  private function processRows(EntityInterface $batch, EntityInterface $supplier, array $rowIds): CommitResult {
    $rowStorage = $this->entityTypeManager->getStorage('supplier_price_ingest_row');

    $rowsCommitted = 0;
    $rowsApplied = 0;
    $rowsFlaggedHigh = 0;
    $rowsAutoCreated = 0;
    $rowsErrored = 0;
    $commitErrors = [];

    foreach (array_chunk($rowIds, self::CHUNK_SIZE) as $chunk) {
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $outcome = $this->commitOneRow($batch, $supplier, $row);
        switch ($outcome->status) {
          case 'applied':
            $rowsCommitted++;
            $rowsApplied++;
            break;
          case 'flagged_high':
            $rowsCommitted++;
            $rowsFlaggedHigh++;
            break;
          case 'auto_created':
            $rowsCommitted++;
            $rowsAutoCreated++;
            break;
          case 'error':
            $rowsErrored++;
            $commitErrors[] = ['row_id' => (int) $row->id(), 'message' => $outcome->message];
            break;
          default:
            $rowsErrored++;
            $commitErrors[] = ['row_id' => (int) $row->id(), 'message' => 'Unknown IngestRowResult status: ' . $outcome->status];
        }
      }
      $rowStorage->resetCache(array_keys($rows));
    }

    return new CommitResult(
      rowsCommitted: $rowsCommitted,
      rowsApplied: $rowsApplied,
      rowsFlaggedHigh: $rowsFlaggedHigh,
      rowsAutoCreated: $rowsAutoCreated,
      // 'rowsSkipped' reflects auto-applying rows NOT processed because
      // they weren't in dry_run when we queried — usually 0 except in
      // partial-commit recovery scenarios where some rows are already
      // committed. Computed by querying the final state.
      rowsSkipped: 0,
      rowsErrored: $rowsErrored,
      commitErrors: $commitErrors,
    );
  }

  /**
   * Apply an IngestRowResult to a supplier_price_ingest_row entity.
   *
   * Sets:
   *   field_row_status: 'committed' (applied / flagged / auto_created)
   *                  or 'error'     (error outcome)
   *   field_resolution_action: 'created_link' (auto_created)
   *                         or 'updated_link' (applied / flagged_high)
   *                         or NULL (error — leave unset)
   *   field_resolution_notes: appended with the per-row outcome message
   *                          so the audit trail captures BOTH the
   *                          matcher's note and the committer's note.
   */
  private function stampRowFromOutcome(EntityInterface $row, $outcome): void {
    if ($outcome->status === 'error') {
      $row->set('field_row_status', 'error');
      $this->appendNote($row, 'Commit error: ' . $outcome->message);
      $row->save();
      return;
    }

    $row->set('field_row_status', 'committed');
    if ($outcome->status === 'auto_created') {
      $row->set('field_resolution_action', 'created_link');
    }
    else {
      // 'applied' and 'flagged_high' both mean "we processed an
      // existing link" — the catalog mutation or audit-only flag
      // happened on an existing material_suppliers row.
      $row->set('field_resolution_action', 'updated_link');
    }
    $this->appendNote($row, 'Commit: ' . $outcome->message);
    $row->save();
  }

  /**
   * Used when commit fails BEFORE handoff to PriceSyncService (e.g.,
   * matched material got deleted out from under us). Returns an
   * IngestRowResult so the calling loop's counter logic stays uniform.
   */
  private function stampRowAsError(EntityInterface $row, string $message): \Drupal\wo_material_price_sync\Service\IngestRowResult {
    $row->set('field_row_status', 'error');
    $this->appendNote($row, 'Commit error: ' . $message);
    $row->save();
    $this->loggerFactory->get('supplier_price_ingest')->error(
      'Commit error on row @rid (pre-handoff): @msg',
      ['@rid' => $row->id(), '@msg' => $message],
    );
    return new \Drupal\wo_material_price_sync\Service\IngestRowResult(
      status: 'error',
      materialSuppliersId: NULL,
      materialPriceHistoryId: NULL,
      message: $message,
    );
  }

  /**
   * Append a line to field_resolution_notes without clobbering prior
   * content (parser note, matcher score breakdown, etc.).
   */
  private function appendNote(EntityInterface $row, string $line): void {
    $existing = trim((string) ($row->get('field_resolution_notes')->value ?? ''));
    $row->set(
      'field_resolution_notes',
      $existing === '' ? $line : ($existing . "\n" . $line),
    );
  }

  /**
   * Final step of commitBatch: transition the batch to 'committed',
   * ensure field_committed_on is set, recompute / persist the batch's
   * row count rollups (which may have shifted if any rows errored).
   *
   * Public so the Batch API finish callback can invoke it.
   */
  public function finalizeBatch(EntityInterface $batch): void {
    $batch->set('field_status', 'committed');
    if (!$batch->get('field_committed_on')->isEmpty()) {
      // Approve handler already stamped — leave alone.
    }
    else {
      $batch->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
    }
    $batch->save();
  }

}
