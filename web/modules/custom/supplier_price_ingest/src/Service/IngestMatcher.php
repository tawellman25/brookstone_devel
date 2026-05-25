<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Phase 3.3 — Tier 1 + Tier 2 matcher.
 *
 * Tier 3 (fuzzy) lands in Phase 3.4 — the decision tree is structured
 * so adding it between Tier 2 and discovery is a clean insertion (see
 * matchRow()).
 *
 * Public API:
 *   matchBatch(EntityInterface $batch): MatchResult
 *
 * Side effects on each supplier_price_ingest_row processed:
 *   - field_match_tier         set to one of the tier values
 *   - field_match_confidence   set per the confidence convention (see
 *                              __BOS_AI/Entities/supplier_price_ingest_row.md)
 *   - field_matched_material   set when a clean match was made; NULL otherwise
 *   - field_existing_link      set for Tier 2 matches; NULL otherwise
 *   - field_resolution_notes   populated whenever the decision warrants
 *                              human explanation (ambiguous, discontinued,
 *                              excluded bundle, etc.)
 *
 * Side effects on the batch:
 *   - field_row_count_tier1 / tier2 / discovery / skipped rolled up from
 *     the persisted row entities (source of truth: the rows themselves)
 *   - field_status transitions pending_dry_run → dry_run_complete on
 *     successful run; → failed on unrecoverable exception
 */
class IngestMatcher {

  /**
   * Confidence values per __BOS_AI/Entities/supplier_price_ingest_row.md.
   */
  public const CONFIDENCE_TIER_DIRECT     = 100;
  public const CONFIDENCE_TIER_REPLACED   = 95;
  public const CONFIDENCE_TIER_AMBIGUOUS  = 50;
  public const CONFIDENCE_DISCOVERY       = 0;

  /**
   * Batch size for streaming row processing.
   */
  private const CHUNK_SIZE = 100;

  /**
   * Bundle policy values accepted in supplier_ingest_config.field_bundle_policy.
   */
  public const POLICY_MATCHED_ONLY = 'matched_only';
  public const POLICY_DISCOVERY    = 'discovery';
  public const POLICY_BOTH         = 'both';
  public const POLICY_EXCLUDED     = 'excluded';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Run Tier 1 + Tier 2 matching across all unmatched rows of a batch.
   *
   * @throws \RuntimeException
   *   If the batch isn't in pending_dry_run status. Other unrecoverable
   *   issues (missing config, etc.) are caught and surfaced via batch
   *   status='failed' + logged.
   */
  public function matchBatch(EntityInterface $batch): MatchResult {
    $logger = $this->loggerFactory->get('supplier_price_ingest');
    $startStatus = (string) ($batch->get('field_status')->value ?? '');
    if ($startStatus !== 'pending_dry_run') {
      throw new \RuntimeException(sprintf(
        'Batch %d is in status "%s"; matcher only accepts batches in pending_dry_run. Re-matching an already-matched batch is a separate admin operation (deferred to a later phase).',
        $batch->id(),
        $startStatus,
      ));
    }

    $rowsProcessed = 0;
    $matchErrors   = [];

    try {
      // ── 1. Load supplier + config ─────────────────────────────────
      $supplier = $batch->get('field_supplier')->entity;
      if (!$supplier) {
        throw new \RuntimeException(sprintf('Batch %d has no resolvable supplier entity.', $batch->id()));
      }

      // Supplier-level do_not_use short-circuit.
      $supplierStatus = $supplier->hasField('field_supplier_status')
        ? trim((string) ($supplier->get('field_supplier_status')->value ?? ''))
        : '';
      if ($supplierStatus === 'do_not_use') {
        $logger->warning(
          'Batch @bid: supplier @sid is marked do_not_use; routing all rows as skipped_do_not_use.',
          ['@bid' => $batch->id(), '@sid' => $supplier->id()],
        );
        return $this->routeAllAsDoNotUse($batch);
      }

      $configs = $this->entityTypeManager
        ->getStorage('supplier_ingest_config')
        ->loadByProperties(['field_supplier' => $supplier->id()]);
      if (!$configs) {
        throw new \RuntimeException(sprintf(
          'No supplier_ingest_config exists for supplier %d. Matcher needs a config to read bundle policy from.',
          $supplier->id(),
        ));
      }
      $config = reset($configs);
      $policy = $this->parseBundlePolicy($config);

      // ── 2. Iterate rows in chunks ─────────────────────────────────
      $rowStorage = $this->entityTypeManager->getStorage('supplier_price_ingest_row');
      $rowIds = $rowStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_batch', $batch->id())
        // Only rows the parser didn't already error or otherwise tag.
        ->notExists('field_match_tier')
        ->sort('field_row_number', 'ASC')
        ->execute();

      foreach (array_chunk(array_values($rowIds), self::CHUNK_SIZE) as $chunk) {
        $rows = $rowStorage->loadMultiple($chunk);
        foreach ($rows as $row) {
          $rowsProcessed++;
          try {
            $this->matchRow($row, $batch, $supplier, $policy);
            $row->save();
          }
          catch (\Throwable $e) {
            $matchErrors[] = ['row_id' => (int) $row->id(), 'message' => $e->getMessage()];
            $logger->error(
              'Batch @bid row @rid: matcher failed: @msg',
              ['@bid' => $batch->id(), '@rid' => $row->id(), '@msg' => $e->getMessage()],
            );
            try {
              $row->set('field_match_tier', 'error');
              $existing = (string) ($row->get('field_resolution_notes')->value ?? '');
              $row->set(
                'field_resolution_notes',
                trim($existing . "\nMatcher exception: " . $e->getMessage()),
              );
              $row->save();
            }
            catch (\Throwable $inner) {
              // If even tagging the row fails, swallow and move on so
              // the batch as a whole still finishes.
              $logger->critical('Batch @bid row @rid: failed to tag row as error: @msg', [
                '@bid' => $batch->id(),
                '@rid' => $row->id(),
                '@msg' => $inner->getMessage(),
              ]);
            }
          }
        }
        // Free memory between chunks.
        $rowStorage->resetCache(array_keys($rows));
      }

      // ── 3. Roll up counts from persisted row entities ─────────────
      $counts = $this->countRowsByTier($batch);
      $this->writeRollupCounts($batch, $counts);

      // ── 4. Transition status ──────────────────────────────────────
      $batch->set('field_status', 'dry_run_complete');
      $batch->save();

      $result = new MatchResult(
        rowsProcessed: $rowsProcessed,
        tier1Matches: $counts['tier1'],
        tier2Matches: $counts['tier2'],
        tier1Ambiguous: $counts['tier1_ambiguous'],
        discoveryRows: $counts['discovery'],
        skippedDiscontinued: $counts['skipped_discontinued'],
        skippedExcludedBundle: $counts['skipped_excluded_bundle'],
        skippedDoNotUse: $counts['skipped_do_not_use'],
        errors: $counts['error_total'],
        matchErrors: $matchErrors,
      );
      $logger->info('Batch @bid: @summary', ['@bid' => $batch->id(), '@summary' => $result->summary()]);
      return $result;
    }
    catch (\Throwable $e) {
      // Whole-batch failure path.
      try {
        $batch->set('field_status', 'failed');
        $existing = (string) ($batch->get('field_dry_run_report')->value ?? '');
        $batch->set(
          'field_dry_run_report',
          $existing ?: json_encode([
            'matcher_fatal_error' => $e->getMessage(),
            'rows_processed_before_failure' => $rowsProcessed,
            'match_errors_collected' => $matchErrors,
          ], JSON_PRETTY_PRINT),
        );
        $batch->save();
      }
      catch (\Throwable $inner) {
        $logger->critical('Batch @bid: failed to record matcher failure status: @msg', [
          '@bid' => $batch->id(),
          '@msg' => $inner->getMessage(),
        ]);
      }
      $logger->error('Batch @bid matcher failed: @msg', [
        '@bid' => $batch->id(),
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  // ────────────────────────────────────────────────────────────────────
  // Decision tree
  // ────────────────────────────────────────────────────────────────────

  /**
   * Apply the matcher decision tree to a single row.
   *
   * Mutates $row but does not save it (caller saves).
   */
  private function matchRow(EntityInterface $row, EntityInterface $batch, EntityInterface $supplier, array $policy): void {
    // ── Tier 1 ─────────────────────────────────────────────────────
    $t1 = $this->attemptTier1($row, $policy);
    if ($t1 !== NULL) {
      $this->applyMatch($row, $t1, $policy, 'tier_1_mfr');
      return;
    }

    // ── Tier 2 ─────────────────────────────────────────────────────
    $t2 = $this->attemptTier2($row, $supplier);
    if ($t2 !== NULL) {
      $this->applyMatch($row, $t2, $policy, 'tier_2_supplier_sku');
      return;
    }

    // ── (Tier 3 fuzzy ships in 3.4 — insert above discovery here) ──

    // ── Discovery / excluded-bundle routing ───────────────────────
    $this->routeUnmatched($row, $policy);
  }

  /**
   * Tier 1 — manufacturer + item# exact match.
   *
   * Returns NULL when no candidate found (caller falls through). Returns
   * an array describing the outcome:
   *   ['kind' => 'direct',    'material' => Material]
   *   ['kind' => 'ambiguous', 'material' => Material, 'count' => int]
   */
  private function attemptTier1(EntityInterface $row, array $policy): ?array {
    $mfrName  = trim((string) ($row->get('field_manufacturer_name')->value ?? ''));
    $itemNum  = trim((string) ($row->get('field_manufacturer_item_number')->value ?? ''));
    if ($mfrName === '' || $itemNum === '') {
      return NULL;
    }
    // Resolve manufacturer by case-insensitive label match.
    $mfrIds = $this->entityTypeManager->getStorage('manufacturer')->getQuery()
      ->accessCheck(FALSE)
      ->condition('title', $mfrName, '=')
      ->range(0, 1)
      ->execute();
    if (!$mfrIds) {
      return NULL;
    }
    $mfrId = (int) reset($mfrIds);

    // Find candidate materials. Don't pre-filter excluded bundles —
    // applyMatch() needs to see the match so it can route it to
    // skipped_excluded_bundle per the supplier's policy. Pre-filtering
    // here would silently drop matches and let the row fall through
    // to discovery instead, which loses the audit trail of what the
    // match WOULD have been.
    $materialIds = array_values(
      $this->entityTypeManager->getStorage('material')->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_manufacturer', $mfrId)
        ->condition('field_manufacturer_item_number', $itemNum, '=')
        ->execute()
    );

    if (count($materialIds) === 0) {
      return NULL;
    }
    if (count($materialIds) === 1) {
      $material = $this->entityTypeManager->getStorage('material')->load($materialIds[0]);
      return ['kind' => 'direct', 'material' => $material];
    }
    // Ambiguous — pick the first deterministically (lowest ID) so the
    // reviewer has a starting point.
    sort($materialIds, SORT_NUMERIC);
    $material = $this->entityTypeManager->getStorage('material')->load($materialIds[0]);
    return [
      'kind'     => 'ambiguous',
      'material' => $material,
      'count'    => count($materialIds),
      'all_ids'  => $materialIds,
    ];
  }

  /**
   * Tier 2 — existing material_suppliers SKU exact match.
   *
   * Returns NULL when no candidate found. Returns an outcome array:
   *   ['kind' => 'direct',    'material' => Material, 'link' => MaterialSuppliers]
   *   ['kind' => 'defensive', 'material' => Material, 'link' => MaterialSuppliers]
   *     — meaning multi-match (shouldn't happen by design but we
   *     handle defensively)
   */
  private function attemptTier2(EntityInterface $row, EntityInterface $supplier): ?array {
    $supplierSku = trim((string) ($row->get('field_supplier_sku')->value ?? ''));
    if ($supplierSku === '') {
      return NULL;
    }
    $linkIds = $this->entityTypeManager->getStorage('material_suppliers')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_supplier', $supplier->id())
      ->condition('field_supplier_item_number', $supplierSku, '=')
      ->execute();
    if (!$linkIds) {
      return NULL;
    }
    $linkIds = array_values($linkIds);
    sort($linkIds, SORT_NUMERIC);
    $link = $this->entityTypeManager->getStorage('material_suppliers')->load($linkIds[0]);
    $materialId = (int) ($link->get('field_material')->target_id ?? 0);
    $material = $materialId ? $this->entityTypeManager->getStorage('material')->load($materialId) : NULL;
    if (!$material) {
      return NULL;
    }
    return [
      'kind'     => count($linkIds) === 1 ? 'direct' : 'defensive',
      'material' => $material,
      'link'     => $link,
      'count'    => count($linkIds),
      'all_ids'  => $linkIds,
    ];
  }

  /**
   * Apply a Tier 1 or Tier 2 outcome to the row entity. Handles:
   *   - direct vs ambiguous/defensive routing
   *   - discontinued material retargeting (or skipped_discontinued)
   *   - bundle policy enforcement against the final target material
   */
  private function applyMatch(EntityInterface $row, array $outcome, array $policy, string $cleanTier): void {
    $material = $outcome['material'];
    $kind     = $outcome['kind'];

    if ($kind === 'ambiguous') {
      // Tier 1 ambiguity — multiple materials share the same mfr + item #.
      // Route to fuzzy_med review and note all candidates.
      $row->set('field_matched_material', $material->id());
      $row->set('field_match_tier', 'tier_3_fuzzy_med');
      $row->set('field_match_confidence', self::CONFIDENCE_TIER_AMBIGUOUS);
      $row->set(
        'field_resolution_notes',
        sprintf(
          "Tier 1 ambiguous: %d materials match mfr '%s' item # '%s' (ids: %s). Picked first (%d) for reference. Review and choose the correct match.",
          $outcome['count'],
          (string) ($row->get('field_manufacturer_name')->value ?? ''),
          (string) ($row->get('field_manufacturer_item_number')->value ?? ''),
          implode(',', $outcome['all_ids']),
          $material->id(),
        ),
      );
      return;
    }

    if ($kind === 'defensive') {
      // Tier 2 defensive — shouldn't happen by design but handle.
      $row->set('field_matched_material', $material->id());
      $row->set('field_existing_link', $outcome['link']->id());
      $row->set('field_match_tier', 'tier_3_fuzzy_med');
      $row->set('field_match_confidence', self::CONFIDENCE_TIER_AMBIGUOUS);
      $row->set(
        'field_resolution_notes',
        sprintf(
          'Tier 2 unexpected multi-match: %d material_suppliers links matched supplier+sku (ids: %s). Picked first (%d). This violates the (material × supplier) uniqueness convention and warrants investigation.',
          $outcome['count'],
          implode(',', $outcome['all_ids']),
          $outcome['link']->id(),
        ),
      );
      $this->loggerFactory->get('supplier_price_ingest')->warning(
        'Tier 2 defensive multi-match on row @rid (link ids: @ids).',
        ['@rid' => $row->id(), '@ids' => implode(',', $outcome['all_ids'])],
      );
      return;
    }

    // Direct match — apply discontinued handling, then bundle policy.
    $finalMaterial = $material;
    $confidence    = self::CONFIDENCE_TIER_DIRECT;
    $note          = NULL;

    if ($this->isDiscontinued($material)) {
      $replacement = $this->resolveReplacement($material);
      if ($replacement === NULL) {
        // Orphaned discontinued — reject the match, route to discovery
        // queue via skipped_discontinued.
        $row->set('field_match_tier', 'skipped_discontinued');
        $row->set('field_match_confidence', NULL);
        $row->set('field_matched_material', NULL);
        if ($cleanTier === 'tier_2_supplier_sku' && isset($outcome['link'])) {
          $row->set('field_existing_link', $outcome['link']->id());
        }
        $row->set(
          'field_resolution_notes',
          sprintf(
            "Matched discontinued material #%d ('%s') with no replacement specified. Row description: '%s'. Consider whether this row is a replacement candidate — if so, set field_replaced_by on the discontinued material.",
            $material->id(),
            $material->label(),
            (string) ($row->get('field_description')->value ?? ''),
          ),
        );
        return;
      }
      // Retargeted via field_replaced_by.
      $finalMaterial = $replacement;
      $confidence    = self::CONFIDENCE_TIER_REPLACED;
      $note          = sprintf(
        'Original match was discontinued material #%d (%s); retargeted to replacement #%d (%s).',
        $material->id(),
        $material->label(),
        $replacement->id(),
        $replacement->label(),
      );
    }

    // Bundle policy on the FINAL target.
    $bundle = $finalMaterial->bundle();
    $bundlePolicy = $policy[$bundle] ?? self::POLICY_MATCHED_ONLY;
    if ($bundlePolicy === self::POLICY_EXCLUDED) {
      $row->set('field_match_tier', 'skipped_excluded_bundle');
      $row->set('field_match_confidence', NULL);
      $row->set('field_matched_material', NULL);
      $reason = $note
        ? $note . "\n"
        : '';
      $row->set(
        'field_resolution_notes',
        $reason . sprintf(
          'Final target material (#%d, bundle=%s) is excluded by this supplier\'s bundle policy. Match rejected.',
          $finalMaterial->id(),
          $bundle,
        ),
      );
      return;
    }

    // Clean match (post-discontinued, post-policy).
    $row->set('field_matched_material', $finalMaterial->id());
    if ($cleanTier === 'tier_2_supplier_sku' && isset($outcome['link'])) {
      $row->set('field_existing_link', $outcome['link']->id());
    }
    $row->set('field_match_tier', $cleanTier);
    $row->set('field_match_confidence', $confidence);
    if ($note !== NULL) {
      $row->set('field_resolution_notes', $note);
    }
  }

  /**
   * Routes a row that exited Tier 1 + Tier 2 without a match.
   *
   * If the supplier has at least one discovery-enabled bundle, the row
   * gets 'discovery'. Otherwise it gets 'skipped_excluded_bundle' with
   * a note explaining the supplier's policy left nowhere to route it.
   */
  private function routeUnmatched(EntityInterface $row, array $policy): void {
    $hasDiscovery = FALSE;
    foreach ($policy as $p) {
      if ($p === self::POLICY_DISCOVERY || $p === self::POLICY_BOTH) {
        $hasDiscovery = TRUE;
        break;
      }
    }
    if ($hasDiscovery) {
      $row->set('field_match_tier', 'discovery');
      $row->set('field_match_confidence', self::CONFIDENCE_DISCOVERY);
      return;
    }
    $row->set('field_match_tier', 'skipped_excluded_bundle');
    $row->set('field_match_confidence', NULL);
    $row->set(
      'field_resolution_notes',
      'Supplier has no discovery-enabled bundles; row could not be matched and discovery routing is disabled for this supplier.',
    );
  }

  /**
   * Mark every unmatched row in the batch as skipped_do_not_use.
   * Used when supplier.field_supplier_status = 'do_not_use'.
   */
  private function routeAllAsDoNotUse(EntityInterface $batch): MatchResult {
    $rowStorage = $this->entityTypeManager->getStorage('supplier_price_ingest_row');
    $rowIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->notExists('field_match_tier')
      ->execute();
    $processed = 0;
    foreach (array_chunk(array_values($rowIds), self::CHUNK_SIZE) as $chunk) {
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $row->set('field_match_tier', 'skipped_do_not_use');
        $row->set('field_match_confidence', NULL);
        $row->set(
          'field_resolution_notes',
          'Supplier is marked do_not_use; batch parsed but no rows were matched.',
        );
        $row->save();
        $processed++;
      }
      $rowStorage->resetCache(array_keys($rows));
    }
    $counts = $this->countRowsByTier($batch);
    $this->writeRollupCounts($batch, $counts);
    $batch->set('field_status', 'dry_run_complete');
    $batch->save();
    return new MatchResult(
      rowsProcessed: $processed,
      tier1Matches: $counts['tier1'],
      tier2Matches: $counts['tier2'],
      tier1Ambiguous: $counts['tier1_ambiguous'],
      discoveryRows: $counts['discovery'],
      skippedDiscontinued: $counts['skipped_discontinued'],
      skippedExcludedBundle: $counts['skipped_excluded_bundle'],
      skippedDoNotUse: $counts['skipped_do_not_use'],
      errors: $counts['error_total'],
      matchErrors: [],
    );
  }

  // ────────────────────────────────────────────────────────────────────
  // Helpers
  // ────────────────────────────────────────────────────────────────────

  /**
   * Decode field_bundle_policy JSON from a supplier_ingest_config.
   * Returns [] (empty policy → everything defaults to matched_only) on
   * empty or invalid JSON.
   */
  private function parseBundlePolicy(EntityInterface $config): array {
    $raw = (string) ($config->get('field_bundle_policy')->value ?? '');
    if ($raw === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      $this->loggerFactory->get('supplier_price_ingest')->warning(
        'supplier_ingest_config @cid: field_bundle_policy is not valid JSON; defaulting to empty policy. Decode error: @err',
        ['@cid' => $config->id(), '@err' => json_last_error_msg()],
      );
      return [];
    }
    return $decoded;
  }

  /**
   * Whether a material has field_discontinued = TRUE.
   * Defensive: only checks when the field is present on the bundle.
   */
  private function isDiscontinued(EntityInterface $material): bool {
    if (!$material->hasField('field_discontinued')) {
      return FALSE;
    }
    return (bool) ($material->get('field_discontinued')->value ?? FALSE);
  }

  /**
   * Resolve field_replaced_by to a material entity, with cycle protection.
   * Single hop: we deliberately don't chase chains because field_replaced_by
   * isn't a chain field (each discontinued item points to the current
   * replacement, not to another discontinued one).
   */
  private function resolveReplacement(EntityInterface $material): ?EntityInterface {
    if (!$material->hasField('field_replaced_by')) {
      return NULL;
    }
    $targetId = (int) ($material->get('field_replaced_by')->target_id ?? 0);
    if ($targetId <= 0 || $targetId === (int) $material->id()) {
      return NULL;
    }
    return $this->entityTypeManager->getStorage('material')->load($targetId);
  }

  /**
   * Count rows of each match_tier classification for a batch.
   *
   * Source of truth: the row entities themselves. Counts are used both
   * for the batch field rollups and for the MatchResult DTO.
   */
  private function countRowsByTier(EntityInterface $batch): array {
    $counts = [
      'tier1' => 0, 'tier2' => 0, 'tier1_ambiguous' => 0,
      'discovery' => 0,
      'skipped_discontinued' => 0,
      'skipped_excluded_bundle' => 0,
      'skipped_do_not_use' => 0,
      'error_total' => 0,
    ];
    $rowStorage = $this->entityTypeManager->getStorage('supplier_price_ingest_row');
    $allIds = $rowStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $batch->id())
      ->execute();
    foreach (array_chunk(array_values($allIds), self::CHUNK_SIZE) as $chunk) {
      $rows = $rowStorage->loadMultiple($chunk);
      foreach ($rows as $row) {
        $tier = (string) ($row->get('field_match_tier')->value ?? '');
        switch ($tier) {
          case 'tier_1_mfr':
            $counts['tier1']++; break;
          case 'tier_2_supplier_sku':
            $counts['tier2']++; break;
          case 'tier_3_fuzzy_med':
            // In Phase 3.3 the only way to land here is ambiguous
            // Tier 1 / defensive Tier 2 — count as tier1_ambiguous.
            // (3.4 will start producing real fuzzy_med matches; this
            // bucket count will need refining then.)
            $counts['tier1_ambiguous']++; break;
          case 'discovery':
            $counts['discovery']++; break;
          case 'skipped_discontinued':
            $counts['skipped_discontinued']++; break;
          case 'skipped_excluded_bundle':
            $counts['skipped_excluded_bundle']++; break;
          case 'skipped_do_not_use':
            $counts['skipped_do_not_use']++; break;
          case 'error':
            $counts['error_total']++; break;
        }
      }
      $rowStorage->resetCache(array_keys($rows));
    }
    return $counts;
  }

  /**
   * Write the batch entity's row count fields from a counts array.
   */
  private function writeRollupCounts(EntityInterface $batch, array $counts): void {
    $batch->set('field_row_count_tier1',     $counts['tier1']);
    $batch->set('field_row_count_tier2',     $counts['tier2']);
    $batch->set('field_row_count_tier3_med', $counts['tier1_ambiguous']);
    $batch->set('field_row_count_discovery', $counts['discovery']);
    $batch->set(
      'field_row_count_skipped',
      $counts['skipped_discontinued'] + $counts['skipped_excluded_bundle'] + $counts['skipped_do_not_use'],
    );
    // field_row_count_total stays as the parser set it (total rows
    // persisted = created + errored). field_row_count_tier3_high stays 0
    // until Phase 3.4.
  }

}
