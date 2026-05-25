<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Service;

/**
 * Read-only DTO returned by IngestParser::parseUploadedFile().
 *
 * Phase 3.1 / 3.2 — purely a value object. Future stages may add
 * methods but the field set is intentionally narrow.
 */
final class ParseResult {

  /**
   * @param int $rowsCreated
   *   Number of supplier_price_ingest_row entities saved successfully.
   * @param int $rowsSkipped
   *   Source rows skipped due to missing identifier OR missing unit cost
   *   (no row entity created). Counted but not persisted.
   * @param int $rowsErrored
   *   supplier_price_ingest_row entities created with
   *   field_match_tier = 'error' (malformed cost, JSON encoding failure,
   *   unmappable UOM with no default, etc.). These are persisted for
   *   audit, but the matcher (3.3) will skip them.
   * @param array<int, array{row_number: int, message: string}> $parseErrors
   *   Per-row error notes captured during parse. Includes both skipped
   *   and errored rows. Keys are arbitrary; the row_number field inside
   *   each entry is the source-file row index.
   */
  public function __construct(
    public readonly int $rowsCreated,
    public readonly int $rowsSkipped,
    public readonly int $rowsErrored,
    public readonly array $parseErrors,
  ) {}

  /**
   * One-line summary suitable for logging.
   */
  public function summary(): string {
    return sprintf(
      'parsed: %d created, %d skipped, %d errored',
      $this->rowsCreated,
      $this->rowsSkipped,
      $this->rowsErrored,
    );
  }

}
