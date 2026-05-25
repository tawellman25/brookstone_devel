<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Phase 3.5 — full dry-run report for a supplier_price_ingest_batch.
 *
 * Replaces BatchPlaceholderController. Same URL, same permission gate.
 * The controller branches on the batch's current field_status and
 * renders the appropriate state:
 *
 *   pending_dry_run     → "parsing in progress" + auto-refresh meta
 *   dry_run_complete    → full dry-run report (the main work here)
 *   awaiting_approval   → "approval in progress" + auto-refresh
 *   approved            → "commit in progress" + auto-refresh
 *                         (3.5 stubs commit to flip status to committed
 *                          immediately, so users rarely see this state)
 *   committed           → historical summary, same layout as dry-run
 *   rejected            → historical summary with rejection reason
 *   failed              → error state with parser/matcher failure
 *
 * All cases set `max-age: 0` because the rendering depends on a status
 * that may transition mid-request (parser → matcher → status update).
 *
 * Price-change-impact computation is bounded by a soft 5-second budget;
 * if exceeded, the section degrades to "see CSV export" without
 * blocking the rest of the report.
 */
class BatchDetailController extends ControllerBase {

  /**
   * Soft budget for the price-impact section. Hard ceiling — once
   * exceeded, the remaining rows are not loaded and the section shows
   * a degraded message pointing at the CSV export.
   */
  private const PRICE_IMPACT_BUDGET_SECONDS = 5.0;

  /**
   * Price-change threshold (±10%) used to split auto-apply from
   * review-queue routing. Matches the convention used by
   * wo_material_price_sync's review queue.
   */
  private const PRICE_CHANGE_REVIEW_THRESHOLD_PCT = 10.0;

  /**
   * Page callback.
   */
  public function view(EntityInterface $supplier_price_ingest_batch): array {
    $batch = $supplier_price_ingest_batch;
    $status = (string) ($batch->get('field_status')->value ?? '');

    $header = $this->buildHeader($batch);

    $build = [
      '#theme' => 'supplier_price_ingest_batch_detail',
      '#header' => $header,
      '#status' => $status,
      '#actions' => $this->buildActions($batch, $status),
      '#cache' => [
        'tags' => $batch->getCacheTags(),
        'max-age' => 0,
      ],
    ];

    // States that show the "report" layout: dry_run_complete, committed,
    // rejected. The other states show a minimal "in-progress" or "error"
    // shell.
    switch ($status) {
      case 'dry_run_complete':
      case 'committed':
        $build['#report'] = $this->buildReport($batch);
        break;

      case 'rejected':
        $build['#report'] = $this->buildReport($batch);
        $build['#rejection_reason'] = (string) ($batch->get('field_dry_run_report')->value ?? '');
        break;

      case 'failed':
        $build['#failure_report'] = (string) ($batch->get('field_dry_run_report')->value ?? '');
        break;

      case 'pending_dry_run':
      case 'awaiting_approval':
      case 'approved':
        // In-progress states. Twig will render an auto-refresh notice.
        $build['#auto_refresh_seconds'] = 5;
        break;
    }

    return $build;
  }

  /**
   * Title callback — kept for routing.
   */
  public function title(EntityInterface $supplier_price_ingest_batch): string {
    return (string) ($supplier_price_ingest_batch->label() ?? 'Supplier Price Ingest Batch');
  }

  // ────────────────────────────────────────────────────────────────────
  // Section builders
  // ────────────────────────────────────────────────────────────────────

  /**
   * Section 1 — batch header.
   */
  private function buildHeader(EntityInterface $batch): array {
    $supplier = $batch->get('field_supplier')->entity;
    $uploadedBy = $batch->get('field_uploaded_by')->entity;
    $committedBy = $batch->hasField('field_committed_by') && !$batch->get('field_committed_by')->isEmpty()
      ? $batch->get('field_committed_by')->entity
      : NULL;
    $file = $batch->get('field_source_file')->entity;

    return [
      'id'              => (int) $batch->id(),
      'title'           => (string) ($batch->label() ?? ''),
      'status'          => (string) ($batch->get('field_status')->value ?? ''),
      'supplier_label'  => $supplier ? (string) $supplier->label() : '(missing)',
      'source_filename' => (string) ($batch->get('field_source_filename')->value ?? ''),
      'source_file_url' => $file ? \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()) : NULL,
      'uploaded_by'     => $uploadedBy ? $uploadedBy->getDisplayName() : '(unknown)',
      'uploaded_on'     => $this->formatTimestamp((string) ($batch->get('field_uploaded_on')->value ?? '')),
      'committed_by'    => $committedBy ? $committedBy->getDisplayName() : NULL,
      'committed_on'    => $this->formatTimestamp((string) ($batch->get('field_committed_on')->value ?? '')),
      'total_rows'      => (int) ($batch->get('field_row_count_total')->value ?? 0),
    ];
  }

  /**
   * Section 2 — match summary table.
   *
   * Counts come from the batch entity's rollup fields, populated by the
   * matcher in Phase 3.3/3.4. The Tier 1 ambiguous bucket is a subset
   * of field_row_count_tier3_med, identified by resolution-notes prefix.
   */
  private function buildMatchSummary(EntityInterface $batch): array {
    $tier3_med_total = (int) ($batch->get('field_row_count_tier3_med')->value ?? 0);
    $tier1_ambiguous = $this->countTier1Ambiguous((int) $batch->id());
    $real_tier3_med  = max(0, $tier3_med_total - $tier1_ambiguous);

    return [
      'tier1'              => (int) ($batch->get('field_row_count_tier1')->value ?? 0),
      'tier2'              => (int) ($batch->get('field_row_count_tier2')->value ?? 0),
      'tier3_high'         => (int) ($batch->get('field_row_count_tier3_high')->value ?? 0),
      'tier3_med'          => $real_tier3_med,
      'tier1_ambiguous'    => $tier1_ambiguous,
      'discovery'          => (int) ($batch->get('field_row_count_discovery')->value ?? 0),
      'skipped'            => (int) ($batch->get('field_row_count_skipped')->value ?? 0),
      'skipped_disc'       => $this->countByTier((int) $batch->id(), 'skipped_discontinued'),
      'skipped_excl'       => $this->countByTier((int) $batch->id(), 'skipped_excluded_bundle'),
      'skipped_dnu'        => $this->countByTier((int) $batch->id(), 'skipped_do_not_use'),
      'errors'             => $this->countByTier((int) $batch->id(), 'error'),
      'total'              => (int) ($batch->get('field_row_count_total')->value ?? 0),
    ];
  }

  /**
   * Section 3 — price-change impact for auto-applying tiers.
   *
   * Returns a structure even on timeout so the template renders the
   * "degraded — see CSV" notice in place of the numbers.
   */
  private function buildPriceImpact(EntityInterface $batch): array {
    $start = microtime(TRUE);
    $supplierId = (int) ($batch->get('field_supplier')->target_id ?? 0);

    $autoTiers = ['tier_1_mfr', 'tier_2_supplier_sku', 'tier_3_fuzzy_high'];
    $rowStorage = $this->entityTypeManager()->getStorage('supplier_price_ingest_row');
    $rowIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->condition('field_match_tier', $autoTiers, 'IN')
      ->sort('id', 'ASC')
      ->execute();

    $newLinks = 0;
    $existingWithinThreshold = 0;
    $existingExceedsThreshold = 0;
    $degraded = FALSE;

    $linkStorage = $this->entityTypeManager()->getStorage('material_suppliers');

    foreach (array_chunk(array_values($rowIds), 50) as $chunk) {
      if ((microtime(TRUE) - $start) > self::PRICE_IMPACT_BUDGET_SECONDS) {
        $degraded = TRUE;
        break;
      }
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $materialId = (int) ($row->get('field_matched_material')->target_id ?? 0);
        if ($materialId <= 0) {
          continue;
        }
        $newCost = (float) ($row->get('field_unit_cost')->value ?? 0);
        $existingLinkIds = $linkStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('field_material', $materialId)
          ->condition('field_supplier', $supplierId)
          ->sort('id', 'ASC')
          ->range(0, 1)
          ->execute();
        if (empty($existingLinkIds)) {
          $newLinks++;
          continue;
        }
        $link = $linkStorage->load(reset($existingLinkIds));
        $oldCost = $link && $link->hasField('field_supplier_unit_cost')
          ? (float) ($link->get('field_supplier_unit_cost')->value ?? 0)
          : 0.0;
        if ($oldCost <= 0.0) {
          // No baseline — treat as a defensive within-threshold update.
          $existingWithinThreshold++;
          continue;
        }
        $deltaPct = abs(($newCost - $oldCost) / $oldCost) * 100.0;
        if ($deltaPct > self::PRICE_CHANGE_REVIEW_THRESHOLD_PCT) {
          $existingExceedsThreshold++;
        }
        else {
          $existingWithinThreshold++;
        }
      }
      $rowStorage->resetCache($chunk);
    }

    return [
      'new_links'                 => $newLinks,
      'existing_within_threshold' => $existingWithinThreshold,
      'existing_exceeds_threshold' => $existingExceedsThreshold,
      'threshold_pct'             => self::PRICE_CHANGE_REVIEW_THRESHOLD_PCT,
      'degraded'                  => $degraded,
      'elapsed'                   => round(microtime(TRUE) - $start, 2),
    ];
  }

  /**
   * Section 4 — sample rows per tier (up to 10 each).
   */
  private function buildSamples(EntityInterface $batch): array {
    $samples = [];
    $tiers = [
      'tier_1_mfr', 'tier_2_supplier_sku',
      'tier_3_fuzzy_high', 'tier_3_fuzzy_med',
      'discovery', 'error',
    ];
    foreach ($tiers as $tier) {
      $samples[$tier] = $this->loadSampleRowsForTier((int) $batch->id(), $tier);
    }
    return $samples;
  }

  /**
   * Section 5 — discovery breakdown by inferred bundle.
   *
   * Parses the first inferred-bundle line out of resolution_notes when
   * present (Phase 3.4 writes "inferred bundles: ..." on overflow rows).
   * For rows without a parseable note, the bundle is classified as
   * "(uninferred)" — these are rows that took the discovery path due
   * to empty bundle inference or low-confidence scoring.
   */
  private function buildDiscoveryBreakdown(EntityInterface $batch): array {
    $rowStorage = $this->entityTypeManager()->getStorage('supplier_price_ingest_row');
    $rowIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->condition('field_match_tier', 'discovery')
      ->sort('id', 'ASC')
      ->execute();

    $matcher = \Drupal::service('supplier_price_ingest.matcher');
    $breakdown = [];
    foreach (array_chunk(array_values($rowIds), 100) as $chunk) {
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $bundle = $this->inferBundleFromRow($row, $matcher);
        $breakdown[$bundle] = ($breakdown[$bundle] ?? 0) + 1;
      }
      $rowStorage->resetCache($chunk);
    }
    arsort($breakdown, SORT_NUMERIC);
    return $breakdown;
  }

  /**
   * Action buttons (rendered as Twig vars containing URLs + disabled state).
   */
  private function buildActions(EntityInterface $batch, string $status): array {
    $disabled = $status !== 'dry_run_complete';
    return [
      'approve_url' => Url::fromRoute(
        'supplier_price_ingest.batch_approve',
        ['supplier_price_ingest_batch' => $batch->id()],
      )->toString(),
      'reject_url' => Url::fromRoute(
        'supplier_price_ingest.batch_reject',
        ['supplier_price_ingest_batch' => $batch->id()],
      )->toString(),
      'export_url' => Url::fromRoute(
        'supplier_price_ingest.batch_export',
        ['supplier_price_ingest_batch' => $batch->id()],
      )->toString(),
      'approve_disabled' => $disabled,
      'reject_disabled'  => $disabled && $status !== 'failed',
    ];
  }

  /**
   * Top-level report assembler. Called for dry_run_complete /
   * committed / rejected states.
   */
  private function buildReport(EntityInterface $batch): array {
    return [
      'match_summary'       => $this->buildMatchSummary($batch),
      'price_impact'        => $this->buildPriceImpact($batch),
      'samples'             => $this->buildSamples($batch),
      'discovery_breakdown' => $this->buildDiscoveryBreakdown($batch),
    ];
  }

  // ────────────────────────────────────────────────────────────────────
  // Helpers
  // ────────────────────────────────────────────────────────────────────

  /**
   * Count rows in a batch whose tier is tier_3_fuzzy_med AND whose
   * resolution_notes start with "Tier 1 ambiguous" (the matcher's
   * convention for Tier 1 multi-match cases that get routed to the
   * fuzzy_med review surface).
   *
   * Done by entity_query with a STARTS_WITH on resolution_notes — cheap
   * compared to loading every row.
   */
  private function countTier1Ambiguous(int $batchId): int {
    $count = (int) $this->entityTypeManager()
      ->getStorage('supplier_price_ingest_row')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batchId)
      ->condition('field_match_tier', 'tier_3_fuzzy_med')
      ->condition('field_resolution_notes', 'Tier 1 ambiguous%', 'LIKE')
      ->count()
      ->execute();
    return $count;
  }

  /**
   * Count rows in a batch with a specific field_match_tier value.
   */
  private function countByTier(int $batchId, string $tier): int {
    return (int) $this->entityTypeManager()
      ->getStorage('supplier_price_ingest_row')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batchId)
      ->condition('field_match_tier', $tier)
      ->count()
      ->execute();
  }

  /**
   * Load up to 10 sample rows for a tier, normalized into the
   * Twig-consumable shape the template expects.
   */
  private function loadSampleRowsForTier(int $batchId, string $tier): array {
    $rowStorage = $this->entityTypeManager()->getStorage('supplier_price_ingest_row');
    $rowIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batchId)
      ->condition('field_match_tier', $tier)
      ->sort('field_row_number', 'ASC')
      ->range(0, 10)
      ->execute();
    if (empty($rowIds)) {
      return [];
    }
    $rows = $rowStorage->loadMultiple($rowIds);
    $out = [];
    foreach ($rows as $row) {
      $matchedMat = NULL;
      if (!$row->get('field_matched_material')->isEmpty()) {
        $matchedMat = $row->get('field_matched_material')->entity;
      }
      $out[] = [
        'row_number'       => (int) ($row->get('field_row_number')->value ?? 0),
        'description'      => $this->truncate((string) ($row->get('field_description')->value ?? ''), 60),
        'supplier_sku'     => (string) ($row->get('field_supplier_sku')->value ?? ''),
        'mfr_item'         => (string) ($row->get('field_manufacturer_item_number')->value ?? ''),
        'unit_cost'        => (string) ($row->get('field_unit_cost')->value ?? ''),
        'cost_uom'         => (string) ($row->get('field_cost_uom')->value ?? ''),
        'match_confidence' => (string) ($row->get('field_match_confidence')->value ?? ''),
        'matched_material_title' => $matchedMat ? (string) $matchedMat->label() : NULL,
        'matched_material_id'    => $matchedMat ? (int) $matchedMat->id() : NULL,
        'resolution_notes' => $this->truncate((string) ($row->get('field_resolution_notes')->value ?? ''), 240),
      ];
    }
    return $out;
  }

  /**
   * Infer bundle label for a discovery row. Re-uses the matcher's
   * public inference. Returns "(uninferred)" when inference is empty.
   */
  private function inferBundleFromRow(EntityInterface $row, $matcher): string {
    $desc = (string) ($row->get('field_description')->value ?? '');
    $inferred = $matcher->inferCandidateBundles($desc);
    if (empty($inferred)) {
      return '(uninferred)';
    }
    return reset($inferred);
  }

  /**
   * Truncate a string to N characters, appending an ellipsis if cut.
   * Uses mb_strlen / mb_substr to respect multi-byte boundaries (per
   * the json_encode UTF-8 gotcha documented in drupal_bos_gotchas.md).
   */
  private function truncate(string $s, int $limit): string {
    $s = trim($s);
    if (mb_strlen($s) <= $limit) {
      return $s;
    }
    return rtrim(mb_substr($s, 0, $limit)) . '…';
  }

  /**
   * Format an ISO-ish datetime string as US format MM/DD/YYYY h:i AM/PM.
   * Returns the input untouched if parse fails or input is empty.
   */
  private function formatTimestamp(string $value): string {
    if ($value === '') {
      return '';
    }
    try {
      $dt = new \DateTime($value, new \DateTimeZone('UTC'));
      $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
      return $dt->format('m/d/Y g:i A');
    }
    catch (\Throwable $e) {
      return $value;
    }
  }

}
