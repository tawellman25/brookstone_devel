<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

/**
 * Read-only DTO returned by IngestMatcher::matchBatch().
 *
 * Phase 3.3 — Tiers 1 and 2 only. Tier 3 fuzzy fields will be added
 * to this DTO when 3.4 ships.
 */
final class MatchResult {

  /**
   * @param int $rowsProcessed
   *   Rows the matcher considered (excludes parser-errored rows whose
   *   match_tier was already set before the matcher ran).
   * @param int $tier1Matches
   *   Clean Tier 1 (manufacturer item #) matches.
   * @param int $tier2Matches
   *   Clean Tier 2 (material_suppliers SKU) matches.
   * @param int $tier1Ambiguous
   *   Multiple BOS materials matched the same mfr + item # combo.
   *   Routed to tier_3_fuzzy_med so the existing fuzzy-review surface
   *   handles them.
   * @param int $discoveryRows
   *   Rows with no Tier 1 or Tier 2 match, routed to discovery because
   *   the supplier has at least one discovery-enabled bundle.
   * @param int $skippedDiscontinued
   *   Rows that matched a discontinued material with no replacement.
   * @param int $skippedExcludedBundle
   *   Rows whose matched material's bundle is excluded for this
   *   supplier, OR (when no match) the supplier has no discovery-
   *   enabled bundles so unmatched rows can't go to discovery.
   * @param int $skippedDoNotUse
   *   Rows skipped because the supplier is marked do_not_use.
   * @param int $errors
   *   Rows where the matcher itself threw an exception on the row.
   *   (Parser-errored rows are NOT counted here — those came in with
   *   field_match_tier='error' already.)
   * @param array<int, array{row_id: int, message: string}> $matchErrors
   *   Per-row exception messages, for log inspection.
   */
  public function __construct(
    public readonly int $rowsProcessed,
    public readonly int $tier1Matches,
    public readonly int $tier2Matches,
    public readonly int $tier1Ambiguous,
    public readonly int $discoveryRows,
    public readonly int $skippedDiscontinued,
    public readonly int $skippedExcludedBundle,
    public readonly int $skippedDoNotUse,
    public readonly int $errors,
    public readonly array $matchErrors,
  ) {}

  /**
   * One-line summary suitable for logging.
   */
  public function summary(): string {
    return sprintf(
      'matched: t1=%d t2=%d t1_ambig=%d discovery=%d skip_disc=%d skip_excl=%d skip_dnu=%d err=%d (of %d processed)',
      $this->tier1Matches,
      $this->tier2Matches,
      $this->tier1Ambiguous,
      $this->discoveryRows,
      $this->skippedDiscontinued,
      $this->skippedExcludedBundle,
      $this->skippedDoNotUse,
      $this->errors,
      $this->rowsProcessed,
    );
  }

}
