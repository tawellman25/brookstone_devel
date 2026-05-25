<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Matching;

/**
 * Per-candidate fuzzy score breakdown.
 *
 * Holds the four signal contributions alongside the total. The matcher uses
 * `$total` for threshold routing and the full structure to populate
 * `field_resolution_notes` so a reviewer can see *why* a candidate won.
 *
 * Each signal carries its own max. When a signal is unavailable
 * (e.g., row has no UOM), max for that signal stays at the documented
 * weight but the contribution is 0 — we deliberately do NOT rescale,
 * since rescaling would inflate weak matches.
 */
final class ScoreBreakdown {

  public const DESC_MAX = 50.0;
  public const UOM_MAX  = 10.0;
  public const SIZE_MAX = 25.0;
  public const MFR_MAX  = 15.0;
  public const TOTAL_MAX = 100.0;

  public function __construct(
    public readonly float $total,
    public readonly float $description,
    public readonly float $uom,
    public readonly float $size,
    public readonly float $manufacturer,
  ) {}

  /**
   * One-line score breakdown for inclusion in resolution notes.
   *
   * Example:
   *   "Score 92.5 (desc 47/50, uom 10/10, size 25/25, mfr 10/15)"
   */
  public function summary(): string {
    return sprintf(
      'Score %.1f (desc %.0f/%d, uom %.0f/%d, size %.0f/%d, mfr %.0f/%d)',
      $this->total,
      $this->description, (int) self::DESC_MAX,
      $this->uom,         (int) self::UOM_MAX,
      $this->size,        (int) self::SIZE_MAX,
      $this->manufacturer, (int) self::MFR_MAX,
    );
  }

}
