<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\supplier_price_ingest\Matching\FuzzyScorer;
use Drupal\supplier_price_ingest\Matching\ScoreBreakdown;

/**
 * Phase 3.3 — Tier 1 + Tier 2 matcher.
 * Phase 3.4 — Tier 3 fuzzy matching (bundle inference + multi-factor
 * scoring + threshold routing) inserted between Tier 2 and discovery.
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

  /**
   * Tier 3 candidate-pool bounds. Above these, score quality collapses
   * and runtime blows up — better to fall to discovery than try anyway.
   */
  private const TIER3_PER_BUNDLE_CAP = 200;
  private const TIER3_TOTAL_CAP      = 600;
  private const TIER3_LOAD_CHUNK     = 50;
  private const TIER3_MAX_BUNDLES    = 3;

  /**
   * Bundle-inference keyword map. Cached at class scope — static so PHP
   * doesn't rebuild it per row. Order doesn't matter for inference (we
   * tally hits and sort), but keep groupings readable for future tuning.
   *
   * The 'misc' bundle has no keywords — it's only reachable through
   * supplier policy intent ("matched_only on misc"), never through
   * inference, because every row would fuzzy-match misc otherwise.
   */
  private const BUNDLE_KEYWORDS = [
    'pvc'             => ['pvc', 'sch 40', 'sch40', 'schedule 40', 'sch 80', 'schedule 80', 'slip', 'sxsxs', 'sxs', 'fipt', 'mipt', 'spigot'],
    'poly'            => ['poly', 'polyethylene', 'pe pipe', 'pep'],
    'brass'           => ['brass', 'bronze'],
    'copper'          => ['copper'],
    'galv'            => ['galvanized', 'galv', 'malleable iron'],
    'irrigation'      => ['rotor', 'spray head', 'sprinkler', 'pgp', 'mp rotator', 'mp rotor', 'drip', 'emitter', 'dripline', 'valve', 'solenoid', 'controller', 'ic-', 'esp-', 'rain bird', 'rainbird', 'hunter', 'toro', 'irritrol', 'rotator', 'pop-up', 'pop up'],
    'electric'        => ['wire', 'awg', 'conduit', 'breaker', 'gfci', 'romex', 'thhn'],
    'backflow'        => ['backflow', 'rpz', 'double check', 'dc valve', 'pvb', 'avb', 'wilkins', 'febco', 'watts'],
    'pumps'           => ['pump', 'submersible', 'jet pump', 'booster'],
    'decorative_rock' => ['rock', 'cobble', 'flagstone', 'boulder', 'river rock'],
    'bulk_material'   => ['topsoil', 'fill dirt', 'compost', 'sand', 'gravel', 'lime', 'gypsum', 'sulfur', 'decomposed granite'],
    'mulch'           => ['mulch', 'bark', 'wood chip'],
    'landscape'       => ['edging', 'paver edging', 'landscape fabric', 'weed barrier', 'staple'],
    'pavers'          => ['paver', 'block', 'retaining wall'],
    'supplies'        => ['glove', 'rag', 'tape measure'],
    'xmas'            => ['christmas', 'led light string'],
  ];

  /**
   * Per-batch index: manufacturer_id → [normalized_mfr_item_# → [material_ids]].
   * Built lazily on first Tier 1 query for a given manufacturer; reused
   * across every row in the same batch matching that manufacturer.
   * Cleared at the start of each matchBatch() call.
   */
  private array $tier1Index = [];

  /**
   * Per-batch index: supplier_id → [normalized_supplier_sku → [link_ids]].
   * Same cache lifecycle as $tier1Index.
   */
  private array $tier2Index = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly FuzzyScorer $fuzzyScorer,
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

    // Reset per-batch indexes. matchBatch is the only entry point that
    // builds these; clearing here keeps two batches in the same PHP
    // request from cross-contaminating (e.g., the verifier runs
    // multiple batches in one drush invocation).
    $this->tier1Index = [];
    $this->tier2Index = [];

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
      $thresholds = $this->loadFuzzyThresholds($config);
      $transformations = $this->parseSkuTransformations($config);

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
            $this->matchRow($row, $batch, $supplier, $policy, $thresholds, $transformations);
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
        tier3High: $counts['tier3_high'],
        tier3Med: $counts['tier3_med'],
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
   *
   * @param array{high: float, med: float} $thresholds
   *   Per-batch fuzzy thresholds; loaded once and threaded through.
   */
  private function matchRow(
    EntityInterface $row,
    EntityInterface $batch,
    EntityInterface $supplier,
    array $policy,
    array $thresholds,
    array $transformations,
  ): void {
    // ── Tier 1 ─────────────────────────────────────────────────────
    $t1 = $this->attemptTier1($row, $policy, $transformations);
    if ($t1 !== NULL) {
      $this->applyMatch($row, $t1, $policy, 'tier_1_mfr');
      return;
    }

    // ── Tier 2 ─────────────────────────────────────────────────────
    $t2 = $this->attemptTier2($row, $supplier, $transformations);
    if ($t2 !== NULL) {
      $this->applyMatch($row, $t2, $policy, 'tier_2_supplier_sku');
      return;
    }

    // ── Tier 3 fuzzy ───────────────────────────────────────────────
    if ($this->tryTier3($row, $policy, $thresholds)) {
      return;
    }

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
  private function attemptTier1(EntityInterface $row, array $policy, array $transformations): ?array {
    $mfrName  = trim((string) ($row->get('field_manufacturer_name')->value ?? ''));
    $itemNum  = trim((string) ($row->get('field_manufacturer_item_number')->value ?? ''));
    if ($mfrName === '' || $itemNum === '') {
      return NULL;
    }
    // Resolve manufacturer by case-insensitive label match.
    // AEL enforces title uniqueness on manufacturer entities, but a Drush
    // import that bypasses presave hooks could create duplicates. Explicit
    // sort ensures reproducible Tier 1 matches across runs — see
    // __BOS_AI/Reports/range_audit_2026-05-25.md.
    $mfrIds = $this->entityTypeManager->getStorage('manufacturer')->getQuery()
      ->accessCheck(FALSE)
      ->condition('title', $mfrName, '=')
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    if (!$mfrIds) {
      return NULL;
    }
    $mfrId = (int) reset($mfrIds);

    // Apply supplier-specific transformations BEFORE normalization
    // (per Phase 3.10 SKU-norm spec — strip distributor-specific
    // prefixes/suffixes so "R15H" becomes "15H" before lookup).
    $transformed = $this->applySkuTransformations($itemNum, $transformations);
    $normalized  = $this->normalizeSku($transformed);

    $index = $this->getTier1Index($mfrId);
    $materialIds = $index[$normalized] ?? [];

    if (count($materialIds) === 0) {
      return NULL;
    }
    if (count($materialIds) === 1) {
      $material = $this->entityTypeManager->getStorage('material')->load($materialIds[0]);
      return [
        'kind'        => 'direct',
        'material'    => $material,
        'audit_path'  => $this->classifyMatchPath($itemNum, $transformed, (string) $material->get('field_manufacturer_item_number')->value),
        'row_raw'     => $itemNum,
        'transformed' => $transformed,
        'mat_raw'     => (string) $material->get('field_manufacturer_item_number')->value,
      ];
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
   * Build (or return cached) per-manufacturer Tier 1 index. Loads every
   * material that references the manufacturer, normalizes its
   * field_manufacturer_item_number, and indexes id under the normalized
   * key. Empty mfr-item-# values are skipped (can't be matched anyway).
   *
   * @return array<string, int[]>  normalized SKU → [material_ids]
   */
  private function getTier1Index(int $mfrId): array {
    if (isset($this->tier1Index[$mfrId])) {
      return $this->tier1Index[$mfrId];
    }
    $matStorage = $this->entityTypeManager->getStorage('material');
    $ids = array_values(
      $matStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_manufacturer', $mfrId)
        ->sort('id', 'ASC')
        ->execute()
    );
    $index = [];
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($matStorage->loadMultiple($chunk) as $material) {
        if (!$material->hasField('field_manufacturer_item_number')) {
          continue;
        }
        $raw = trim((string) ($material->get('field_manufacturer_item_number')->value ?? ''));
        if ($raw === '') {
          continue;
        }
        $key = $this->normalizeSku($raw);
        if ($key === '') {
          continue;
        }
        $index[$key][] = (int) $material->id();
      }
      $matStorage->resetCache($chunk);
    }
    return $this->tier1Index[$mfrId] = $index;
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
  private function attemptTier2(EntityInterface $row, EntityInterface $supplier, array $transformations): ?array {
    $supplierSku = trim((string) ($row->get('field_supplier_sku')->value ?? ''));
    if ($supplierSku === '') {
      return NULL;
    }
    // Tier 2 also runs through transformations + normalization. Tier 2
    // comparisons are supplier-against-itself (the SKU column the
    // supplier writes today vs. what their last batch wrote), so
    // transformations are usually a no-op here — but applying them
    // symmetrically protects against the case where a supplier
    // started writing SKUs differently between batches (e.g.,
    // SiteOne began stripping their own R prefix mid-year; the
    // existing link still has "R15H", the new row has "15H").
    $transformed = $this->applySkuTransformations($supplierSku, $transformations);
    $normalized  = $this->normalizeSku($transformed);

    $index = $this->getTier2Index((int) $supplier->id());
    $linkIds = $index[$normalized] ?? [];
    if (!$linkIds) {
      return NULL;
    }
    sort($linkIds, SORT_NUMERIC);
    $link = $this->entityTypeManager->getStorage('material_suppliers')->load($linkIds[0]);
    $materialId = (int) ($link->get('field_material')->target_id ?? 0);
    $material = $materialId ? $this->entityTypeManager->getStorage('material')->load($materialId) : NULL;
    if (!$material) {
      return NULL;
    }
    $linkRaw = (string) ($link->get('field_supplier_item_number')->value ?? '');
    return [
      'kind'        => count($linkIds) === 1 ? 'direct' : 'defensive',
      'material'    => $material,
      'link'        => $link,
      'count'       => count($linkIds),
      'all_ids'     => $linkIds,
      'audit_path'  => $this->classifyMatchPath($supplierSku, $transformed, $linkRaw),
      'row_raw'     => $supplierSku,
      'transformed' => $transformed,
      'mat_raw'     => $linkRaw,
    ];
  }

  /**
   * Build (or return cached) per-supplier Tier 2 index. Loads every
   * material_suppliers link for the supplier, normalizes its
   * field_supplier_item_number, indexes link id under the normalized key.
   *
   * @return array<string, int[]>  normalized SKU → [material_suppliers link ids]
   */
  private function getTier2Index(int $supplierId): array {
    if (isset($this->tier2Index[$supplierId])) {
      return $this->tier2Index[$supplierId];
    }
    $linkStorage = $this->entityTypeManager->getStorage('material_suppliers');
    $ids = array_values(
      $linkStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('field_supplier', $supplierId)
        ->sort('id', 'ASC')
        ->execute()
    );
    $index = [];
    foreach (array_chunk($ids, 200) as $chunk) {
      foreach ($linkStorage->loadMultiple($chunk) as $link) {
        if (!$link->hasField('field_supplier_item_number')) {
          continue;
        }
        $raw = trim((string) ($link->get('field_supplier_item_number')->value ?? ''));
        if ($raw === '') {
          continue;
        }
        $key = $this->normalizeSku($raw);
        if ($key === '') {
          continue;
        }
        $index[$key][] = (int) $link->id();
      }
      $linkStorage->resetCache($chunk);
    }
    return $this->tier2Index[$supplierId] = $index;
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

    // Phase 3.10 audit-note transparency: when normalization or
    // transformation was load-bearing in producing this match, prepend
    // a note so the reviewer can see WHY the strings looked different
    // and trust the matcher's judgment. Exact matches stay silent
    // (preserves prior behavior; resolution_notes column stays empty
    // for the overwhelming-majority well-behaved case).
    $auditPath = (string) ($outcome['audit_path'] ?? 'exact');
    if ($auditPath !== 'exact') {
      $rowRaw     = (string) ($outcome['row_raw'] ?? '');
      $matRaw     = (string) ($outcome['mat_raw'] ?? '');
      $transformed = (string) ($outcome['transformed'] ?? $rowRaw);
      $sourceLabel = $cleanTier === 'tier_2_supplier_sku' ? 'supplier SKU' : 'mfr item #';
      $rowOrigin   = $cleanTier === 'tier_2_supplier_sku' ? 'row' : 'row';
      $note = $auditPath === 'transformed'
        ? sprintf(
            "Matched via %s transformation: %s '%s' stripped to '%s', normalized to BOS '%s'.",
            $sourceLabel, $rowOrigin, $rowRaw, $transformed, $matRaw,
          )
        : sprintf(
            "Matched via %s normalization: %s '%s' → BOS '%s'.",
            $sourceLabel, $rowOrigin, $rowRaw, $matRaw,
          );
    }

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
      $retargetNote = sprintf(
        'Original match was discontinued material #%d (%s); retargeted to replacement #%d (%s).',
        $material->id(),
        $material->label(),
        $replacement->id(),
        $replacement->label(),
      );
      // Combine with any audit note (normalization / transformation
      // path) that's already set.
      $note = $note === NULL ? $retargetNote : ($note . "\n" . $retargetNote);
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

  // ────────────────────────────────────────────────────────────────────
  // Tier 3 — fuzzy matching
  // ────────────────────────────────────────────────────────────────────

  /**
   * Attempt Tier 3 (fuzzy) matching on a single row.
   *
   * Returns TRUE when this method assigned a terminal field_match_tier
   * (tier_3_fuzzy_high / tier_3_fuzzy_med / skipped_excluded_bundle).
   * Returns FALSE when the row should continue to discovery routing —
   * which covers low-confidence wins, no candidates, inference failure,
   * and pool overflow. In those FALSE cases, this method has already
   * populated field_resolution_notes with an explanation so the audit
   * trail tells the reviewer *why* the row went to discovery.
   *
   * @param array{high: float, med: float} $thresholds
   */
  private function tryTier3(EntityInterface $row, array $policy, array $thresholds): bool {
    $description = trim((string) ($row->get('field_description')->value ?? ''));
    if ($description === '') {
      // Nothing to infer or score against. Quiet fall-through.
      return FALSE;
    }

    $inferred = $this->inferCandidateBundles($description);
    if ($inferred === []) {
      $this->appendNote($row, 'Tier 3: bundle inference returned no candidates.');
      return FALSE;
    }

    // Apply supplier bundle policy to inferred set.
    $excluded = [];
    $candidateBundles = [];
    foreach ($inferred as $b) {
      $p = $policy[$b] ?? self::POLICY_MATCHED_ONLY;
      if ($p === self::POLICY_EXCLUDED) {
        $excluded[] = $b;
        continue;
      }
      $candidateBundles[] = $b;
    }

    if ($candidateBundles === []) {
      // Every inferred bundle is excluded — this is a definitive outcome,
      // not a discovery fall-through, because the supplier policy
      // explicitly opted out of these bundles.
      $row->set('field_match_tier', 'skipped_excluded_bundle');
      $row->set('field_match_confidence', NULL);
      $row->set('field_matched_material', NULL);
      $row->set(
        'field_resolution_notes',
        sprintf(
          'Tier 3 bundle inference picked [%s]; all excluded by supplier policy.',
          implode(', ', $excluded),
        ),
      );
      return TRUE;
    }

    // Query candidate pool.
    $poolIds = $this->queryFuzzyPool($row, $candidateBundles);
    if ($poolIds === NULL) {
      // Overflow case — already logged inside queryFuzzyPool.
      $this->appendNote(
        $row,
        sprintf(
          'Tier 3: candidate pool exceeded %d (inferred bundles: %s). Routed to discovery.',
          self::TIER3_TOTAL_CAP,
          implode(', ', $candidateBundles),
        ),
      );
      return FALSE;
    }
    if ($poolIds === []) {
      $this->appendNote(
        $row,
        sprintf('Tier 3: no active candidates in bundles [%s].', implode(', ', $candidateBundles)),
      );
      return FALSE;
    }

    // Score candidates in chunks, retaining only the winner.
    $bestId = NULL;
    $bestBreakdown = NULL;
    $bestMaterial  = NULL;
    $materialStorage = $this->entityTypeManager->getStorage('material');
    foreach (array_chunk($poolIds, self::TIER3_LOAD_CHUNK) as $chunk) {
      $candidates = $materialStorage->loadMultiple($chunk);
      foreach ($candidates as $candidate) {
        // Defensive: filter discontinued at scoring time too. The pool
        // query already excludes them, but if a material is updated
        // between query and load (race), this catches it.
        if ($this->isDiscontinued($candidate)) {
          continue;
        }
        $breakdown = $this->fuzzyScorer->score($row, $candidate);
        if ($bestBreakdown === NULL || $breakdown->total > $bestBreakdown->total) {
          $bestId        = (int) $candidate->id();
          $bestBreakdown = $breakdown;
          $bestMaterial  = $candidate;
        }
      }
      $materialStorage->resetCache($chunk);
    }

    if ($bestBreakdown === NULL || $bestMaterial === NULL) {
      // Pool had candidates but none scored — shouldn't happen since the
      // scorer always returns something. Defensive only.
      $this->appendNote($row, 'Tier 3: no scorable candidates.');
      return FALSE;
    }

    // Route by score.
    $score = $bestBreakdown->total;
    if ($score >= $thresholds['high']) {
      $this->applyFuzzyMatch($row, $bestMaterial, $bestBreakdown, 'tier_3_fuzzy_high');
      return TRUE;
    }
    if ($score >= $thresholds['med']) {
      $this->applyFuzzyMatch($row, $bestMaterial, $bestBreakdown, 'tier_3_fuzzy_med');
      return TRUE;
    }
    // Low-confidence — bias against accepting bad matches by NOT
    // surfacing the candidate. Record the audit trail and let the row
    // continue to discovery (caller will set tier=discovery).
    if ($score > 0.0) {
      $this->appendNote(
        $row,
        sprintf(
          'Tier 3 low-confidence (below %.1f threshold): best candidate #%d %s; %s. Routed to discovery.',
          (float) $thresholds['med'],
          (int) $bestMaterial->id(),
          (string) $bestMaterial->label(),
          $bestBreakdown->summary(),
        ),
      );
    }
    return FALSE;
  }

  /**
   * Bundle inference from a row description.
   *
   * Returns at most TIER3_MAX_BUNDLES bundle machine names, ordered by
   * keyword-hit count DESC. Empty array when no keyword matched.
   *
   * Public for testability / future reuse (e.g., review-UI suggestions).
   */
  public function inferCandidateBundles(string $description): array {
    if (trim($description) === '') {
      return [];
    }
    // Normalize lightly — full normalization happens inside FuzzyScorer.
    // For keyword matching we just need lowercase + collapsed whitespace.
    $hay = strtolower($description);
    $hay = preg_replace('/\s+/', ' ', $hay) ?? $hay;
    $hay = ' ' . trim($hay) . ' ';

    $hits = [];
    foreach (self::BUNDLE_KEYWORDS as $bundle => $keywords) {
      $count = 0;
      foreach ($keywords as $kw) {
        $needle = ' ' . strtolower($kw) . ' ';
        // Substring match within a space-padded haystack approximates
        // word-boundary matching for multi-word keywords like "sch 40"
        // without the complexity of regex word boundaries (which would
        // split "sch 40" at the space).
        if (str_contains($hay, $needle)) {
          $count++;
        }
        elseif (str_contains($hay, ' ' . strtolower($kw))) {
          // Keyword ends the description.
          $count++;
        }
      }
      if ($count > 0) {
        $hits[$bundle] = $count;
      }
    }
    if ($hits === []) {
      return [];
    }
    arsort($hits, SORT_NUMERIC);
    return array_slice(array_keys($hits), 0, self::TIER3_MAX_BUNDLES);
  }

  /**
   * Build the Tier 3 candidate pool for one row.
   *
   * Returns NULL when the pool would exceed TIER3_TOTAL_CAP — caller
   * routes those to discovery. Returns [] when there are zero active
   * candidates across the inferred bundles.
   *
   * Per-bundle cap is enforced by a token-pre-filter on the largest
   * non-stopword token from the row's description: only materials whose
   * title contains that token are eligible. This is a strict reduction
   * that may miss some valid candidates (the bundle has rich vocabulary
   * the row didn't sample), but the alternative is unbounded scoring
   * which violates the performance budget.
   */
  private function queryFuzzyPool(EntityInterface $row, array $bundles): ?array {
    $description = (string) ($row->get('field_description')->value ?? '');
    $largestToken = $this->largestSignificantToken($description);

    $poolIds = [];
    $materialStorage = $this->entityTypeManager->getStorage('material');
    foreach ($bundles as $bundle) {
      // Don't filter field_discontinued at the query layer. Drupal entity
      // queries with `<>` exclude rows where the field is NULL (the default
      // state for materials that have never been touched), collapsing the
      // pool to nothing for clean fixtures. The scoring loop calls
      // isDiscontinued() per candidate before considering it a winner.
      //
      // Sort by id DESC so the pool is deterministic across calls and the
      // most recently-created materials win when the bundle is larger than
      // the per-bundle cap (newer SKUs are more likely to be current).
      $q = $materialStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->sort('id', 'DESC')
        ->range(0, self::TIER3_PER_BUNDLE_CAP + 1);
      $idsForBundle = array_values($q->execute());

      // If bundle has more than the cap, apply token pre-filter.
      if (count($idsForBundle) > self::TIER3_PER_BUNDLE_CAP && $largestToken !== '') {
        $q2 = $materialStorage->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', $bundle)
          ->condition('title', $largestToken, 'CONTAINS')
          ->sort('id', 'DESC')
          ->range(0, self::TIER3_PER_BUNDLE_CAP);
        $idsForBundle = array_values($q2->execute());
      }
      elseif (count($idsForBundle) > self::TIER3_PER_BUNDLE_CAP) {
        // Cap without a usable token — truncate to be safe.
        $idsForBundle = array_slice($idsForBundle, 0, self::TIER3_PER_BUNDLE_CAP);
      }
      foreach ($idsForBundle as $id) {
        $poolIds[(int) $id] = TRUE;
      }
      if (count($poolIds) > self::TIER3_TOTAL_CAP) {
        $this->loggerFactory->get('supplier_price_ingest')->warning(
          'Tier 3 candidate pool for row @rid exceeded @cap across bundles [@bundles]; row routed to discovery.',
          [
            '@rid' => $row->id(),
            '@cap' => self::TIER3_TOTAL_CAP,
            '@bundles' => implode(',', $bundles),
          ],
        );
        return NULL;
      }
    }
    return array_keys($poolIds);
  }

  /**
   * Pull the longest non-stopword token from the description for use as a
   * pre-filter. Same stopword list as FuzzyScorer (kept in sync via the
   * scorer's normalization — we re-run a tiny version here to avoid a
   * cross-class dependency on the scorer's tokenize internals).
   */
  private function largestSignificantToken(string $description): string {
    $s = strtolower($description);
    $s = preg_replace('/[^a-z0-9"\'\/\-.\s]+/u', ' ', $s) ?? $s;
    $tokens = preg_split('/\s+/', trim($s)) ?: [];
    $stopwords = ['the', 'a', 'an', 'with', 'for', 'of', 'in', 'to', 'and', 'or'];
    $best = '';
    foreach ($tokens as $t) {
      if ($t === '' || in_array($t, $stopwords, TRUE)) {
        continue;
      }
      if (strlen($t) < 3) {
        continue;
      }
      if (strlen($t) > strlen($best)) {
        $best = $t;
      }
    }
    return $best;
  }

  /**
   * Apply a successful Tier 3 outcome (high or medium) to the row.
   */
  private function applyFuzzyMatch(
    EntityInterface $row,
    EntityInterface $material,
    ScoreBreakdown $breakdown,
    string $tier,
  ): void {
    $confidence = round($breakdown->total, 1);
    $row->set('field_matched_material', $material->id());
    $row->set('field_match_tier', $tier);
    $row->set('field_match_confidence', $confidence);
    $label = $tier === 'tier_3_fuzzy_high' ? 'Tier 3 high-confidence match' : 'Tier 3 medium-confidence match';
    $row->set(
      'field_resolution_notes',
      sprintf('%s. %s.', $label, $breakdown->summary()),
    );
  }

  /**
   * Append a line to field_resolution_notes without clobbering existing
   * content (e.g., a prior parser note about UOM normalization).
   */
  private function appendNote(EntityInterface $row, string $line): void {
    $existing = trim((string) ($row->get('field_resolution_notes')->value ?? ''));
    $row->set(
      'field_resolution_notes',
      $existing === '' ? $line : ($existing . "\n" . $line),
    );
  }

  /**
   * Load fuzzy thresholds from supplier_ingest_config. Defaults from the
   * spec (90 / 70) when fields are empty.
   *
   * @return array{high: float, med: float}
   */
  private function loadFuzzyThresholds(EntityInterface $config): array {
    $high = $config->hasField('field_fuzzy_threshold_high')
      ? (float) ($config->get('field_fuzzy_threshold_high')->value ?? 90.0)
      : 90.0;
    $med = $config->hasField('field_fuzzy_threshold_med')
      ? (float) ($config->get('field_fuzzy_threshold_med')->value ?? 70.0)
      : 70.0;
    if ($high <= 0.0) {
      $high = 90.0;
    }
    if ($med <= 0.0) {
      $med = 70.0;
    }
    return ['high' => $high, 'med' => $med];
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
      tier3High: $counts['tier3_high'],
      tier3Med: $counts['tier3_med'],
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
   * Normalize a SKU for indexed matching: lowercase, trim, strip all
   * whitespace / hyphens / dots. Catches format drift like:
   *   "1806-PRS" (BOS)  vs  "1806PRS" (SiteOne)
   *   "PRO-SPRAY PROS-12" (BOS catalog)  vs  "PROSPRAY PROS12" (typo)
   *
   * Empirically the distributor-vs-manufacturer drift in the SiteOne
   * dry-run was all hyphen / case / whitespace; this single helper
   * collapses all of those into one canonical form.
   *
   * Applied to BOTH sides of every Tier 1 / Tier 2 comparison. The
   * matcher's per-batch index pre-normalizes BOS-side values once and
   * caches; the row's incoming value is normalized at lookup time.
   */
  private function normalizeSku(?string $value): string {
    if ($value === NULL) {
      return '';
    }
    $trimmed = strtolower(trim($value));
    if ($trimmed === '') {
      return '';
    }
    return preg_replace('/[\s\-\.]+/', '', $trimmed) ?? $trimmed;
  }

  /**
   * Apply supplier-specific SKU transformations BEFORE normalization.
   *
   * Distributor catalogs often prefix manufacturer SKUs with their own
   * letter — SiteOne writes "R15H" for Rain Bird's native "15H". This
   * helper strips configured prefixes/suffixes so the lookup compares
   * the manufacturer-native form against BOS's manufacturer-native
   * field_manufacturer_item_number.
   *
   * Rules (per Phase 3.10 SKU-norm spec):
   *   - Both keys (`strip_prefix`, `strip_suffix`) are optional; default empty.
   *   - Values are arrays of strings.
   *   - Strips in the order listed, first match wins, applied ONCE
   *     (not iteratively — "R" stripped from "RR15H" yields "R15H",
   *     not "15H"). Avoids surprising over-strips.
   *   - Case-sensitive matching here; Part A normalization handles
   *     case afterward.
   *
   * @param array $transformations  Decoded JSON from field_sku_transformations.
   */
  private function applySkuTransformations(string $value, array $transformations): string {
    $out = $value;
    foreach (($transformations['strip_prefix'] ?? []) as $prefix) {
      if ($prefix !== '' && str_starts_with($out, $prefix)) {
        $out = substr($out, strlen($prefix));
        break;
      }
    }
    foreach (($transformations['strip_suffix'] ?? []) as $suffix) {
      if ($suffix !== '' && str_ends_with($out, $suffix)) {
        $out = substr($out, 0, -strlen($suffix));
        break;
      }
    }
    return $out;
  }

  /**
   * Classify the path a match took for the audit-note column.
   *
   * Returns one of:
   *   'exact'        — raw row value === raw BOS value (no transformation,
   *                    no normalization mattered).
   *   'normalized'   — equal only after normalization (transformation
   *                    didn't change the row's value, but
   *                    hyphen/case/whitespace differed).
   *   'transformed'  — transformation changed the row's value; the
   *                    transformed form then normalized to the BOS form.
   */
  private function classifyMatchPath(string $rowRaw, string $rowTransformed, string $matRaw): string {
    if ($rowRaw === $matRaw) {
      return 'exact';
    }
    if ($rowTransformed === $rowRaw) {
      return 'normalized';
    }
    return 'transformed';
  }

  /**
   * Decode field_sku_transformations from a supplier_ingest_config.
   * Returns the empty-default shape when unset / invalid JSON so the
   * matcher's `applySkuTransformations()` is a guaranteed no-op for
   * suppliers that don't ship transformations.
   */
  private function parseSkuTransformations(EntityInterface $config): array {
    $default = ['strip_prefix' => [], 'strip_suffix' => []];
    if (!$config->hasField('field_sku_transformations')) {
      return $default;
    }
    $raw = (string) ($config->get('field_sku_transformations')->value ?? '');
    if (trim($raw) === '') {
      return $default;
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      $this->loggerFactory->get('supplier_price_ingest')->warning(
        'supplier_ingest_config @cid: field_sku_transformations is not valid JSON; treating as empty. Decode error: @err',
        ['@cid' => $config->id(), '@err' => json_last_error_msg()],
      );
      return $default;
    }
    return [
      'strip_prefix' => is_array($decoded['strip_prefix'] ?? NULL) ? array_values(array_filter($decoded['strip_prefix'], 'is_string')) : [],
      'strip_suffix' => is_array($decoded['strip_suffix'] ?? NULL) ? array_values(array_filter($decoded['strip_suffix'], 'is_string')) : [],
    ];
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
      'tier3_high' => 0, 'tier3_med' => 0,
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
          case 'tier_3_fuzzy_high':
            $counts['tier3_high']++; break;
          case 'tier_3_fuzzy_med':
            // Tier 1 ambiguity / Tier 2 defensive multi-match write
            // confidence = CONFIDENCE_TIER_AMBIGUOUS (50). Phase 3.4's
            // real fuzzy_med matches write the actual score (>=70). Use
            // confidence to split the bucket so reporting reflects the
            // distinct workflows correctly.
            $conf = (float) ($row->get('field_match_confidence')->value ?? 0);
            if ((int) $conf === self::CONFIDENCE_TIER_AMBIGUOUS) {
              $counts['tier1_ambiguous']++;
            }
            else {
              $counts['tier3_med']++;
            }
            break;
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
   *
   * field_row_count_tier3_med totals real fuzzy_med wins + tier-1
   * ambiguous + tier-2 defensive — all three surface in the same
   * medium-confidence review queue.
   */
  private function writeRollupCounts(EntityInterface $batch, array $counts): void {
    $batch->set('field_row_count_tier1',      $counts['tier1']);
    $batch->set('field_row_count_tier2',      $counts['tier2']);
    $batch->set('field_row_count_tier3_high', $counts['tier3_high']);
    $batch->set('field_row_count_tier3_med',  $counts['tier3_med'] + $counts['tier1_ambiguous']);
    $batch->set('field_row_count_discovery',  $counts['discovery']);
    $batch->set(
      'field_row_count_skipped',
      $counts['skipped_discontinued'] + $counts['skipped_excluded_bundle'] + $counts['skipped_do_not_use'],
    );
    // field_row_count_total stays as the parser set it.
  }

}
