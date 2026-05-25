<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

/**
 * Phase 3.6 — DTO returned by IngestCommitter::commitBatch().
 *
 * Mirrors the shape of ParseResult / MatchResult. The committer rolls
 * up per-row IngestRowResult instances into these counters; sub-counts
 * (applied / flagged_high / auto_created) sum to rowsCommitted.
 * rowsSkipped covers rows the committer didn't touch (medium-confidence,
 * discovery, skipped_*, already-committed); rowsErrored covers rows
 * that threw or returned an error result during commit.
 */
final readonly class CommitResult {

  /**
   * @param array<int, array{row_id: int, message: string}> $commitErrors
   *   Per-row failure messages, for log inspection.
   */
  public function __construct(
    public int $rowsCommitted,
    public int $rowsApplied,
    public int $rowsFlaggedHigh,
    public int $rowsAutoCreated,
    public int $rowsSkipped,
    public int $rowsErrored,
    public array $commitErrors,
  ) {}

  /**
   * One-line summary suitable for logging.
   */
  public function summary(): string {
    return sprintf(
      'committed: total=%d (applied=%d, flagged=%d, auto_created=%d) skipped=%d errored=%d',
      $this->rowsCommitted,
      $this->rowsApplied,
      $this->rowsFlaggedHigh,
      $this->rowsAutoCreated,
      $this->rowsSkipped,
      $this->rowsErrored,
    );
  }

}
