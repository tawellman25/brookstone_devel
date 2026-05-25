<?php

declare(strict_types=1);

namespace Drupal\wo_material_price_sync\Service;

/**
 * Per-row outcome from PriceSyncService::ingestRow().
 *
 * Phase 3.6 — the feed-import entry point added to PriceSyncService
 * returns one of these instead of throwing on per-row issues. Callers
 * (today: IngestCommitter) inspect $status to decide whether to roll
 * up the row as committed, flagged, or errored.
 *
 * Status semantics:
 *   'applied'      → catalog updated; price within threshold or decreased.
 *   'flagged_high' → catalog NOT updated; >10% increase held for review.
 *                    The audit-history entry exists and surfaces in the
 *                    /admin/materials/price-review queue.
 *   'auto_created' → no existing material_suppliers row for this
 *                    (material, supplier); a new row was created with the
 *                    feed cost as its first-known price.
 *   'rejected'     → reserved (not produced by 3.6's auto-applying flow).
 *   'error'        → uncaught exception in the ingest path. Audit-history
 *                    entry may or may not exist depending on where the
 *                    throw landed; IDs reflect whatever was persisted.
 */
final readonly class IngestRowResult {

  public function __construct(
    public string $status,
    public ?int $materialSuppliersId,
    public ?int $materialPriceHistoryId,
    public string $message,
    public ?float $deltaPercent = NULL,
  ) {}

}
