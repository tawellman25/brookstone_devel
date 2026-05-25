<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Matching;

use Drupal\Core\Entity\EntityInterface;

/**
 * Phase 3.4 — Tier 3 fuzzy match scorer.
 *
 * Pluggable: future tuning replaces individual signals without touching the
 * matcher orchestration. The class exposes a single `score()` entry point
 * that returns a ScoreBreakdown so the matcher can both route by total and
 * write a per-signal audit string into field_resolution_notes.
 *
 * Signal weights (per Phase 2 §3.3 Tier 3, with Phase 3.4 tuning):
 *
 *   description : 50  (weighted Jaccard on normalized tokens, +10 substring)
 *   uom         : 10  (exact = +10, mismatch = -5, missing = 0)
 *   size        : 25  (extracted size token sets; partial = +10, miss = -10)
 *   mfr         : 15  (exact = +15 post-suffix-strip, substring = +10)
 *
 * Missing signals contribute 0 — we do NOT rescale to 100. Rescaling would
 * inflate weak matches and bias reviewers toward accepting them.
 *
 * The class is stateless beyond cached lookup tables (size-normalization
 * regex, suffix list). Safe to share across rows in a batch.
 */
class FuzzyScorer {

  /**
   * Stopwords removed before Jaccard. Intentionally tiny — short tokens
   * carry signal in hardgoods catalogs ("EA", "PVC", "NPT").
   */
  private const STOPWORDS = [
    'the', 'a', 'an', 'with', 'for', 'of', 'in', 'to', 'and', 'or',
  ];

  /**
   * Manufacturer-name suffixes stripped before comparison.
   * Lowercase, no trailing punctuation — punctuation is stripped first.
   */
  private const MFR_SUFFIXES = [
    'incorporated', 'industries', 'manufacturing', 'corporation',
    'company', 'corp', 'inc', 'mfg', 'co',
  ];

  /**
   * Common decimal-to-fraction equivalences applied during normalization.
   * Limited set — arbitrary decimals stay decimal.
   */
  private const DECIMAL_FRACTIONS = [
    '0.125' => '1/8',
    '0.25'  => '1/4',
    '0.375' => '3/8',
    '0.5'   => '1/2',
    '0.625' => '5/8',
    '0.75'  => '3/4',
    '0.875' => '7/8',
  ];

  /**
   * UOM alias map for cross-vocabulary equivalence between the row's
   * `field_cost_uom` (lowercase: each/case/box/bag/roll) and the material's
   * `field_unit_of_measure` (uppercase abbreviations: EA/LF/M/C/TON/...).
   *
   * Both sides normalize to a canonical lowercase key. Anything unmapped
   * compares verbatim (so future-added UOM values still produce useful
   * exact-match signal).
   */
  private const UOM_CANONICAL = [
    'each' => 'each',
    'ea'   => 'each',
    'case' => 'case',
    'c'    => 'case',
    'box'  => 'box',
    'bag'  => 'bag',
    'roll' => 'roll',
    'lf'   => 'linear_foot',
    'ton'  => 'ton',
    'yd'   => 'cubic_yard',
    'lb'   => 'pound',
    'oz'   => 'ounce',
    'm'    => 'thousand',
    'tsp'  => 'teaspoon',
  ];

  /**
   * Score one row against one candidate material.
   *
   * Returns a ScoreBreakdown — `->total` is the 0..100 score used for
   * threshold routing; the full breakdown supplies the resolution-notes
   * audit string.
   */
  public function score(EntityInterface $row, EntityInterface $candidate): ScoreBreakdown {
    $rowDesc = (string) ($row->get('field_description')->value ?? '');
    $matDesc = (string) $candidate->label();

    $rowNorm = $this->normalizeDescription($rowDesc);
    $matNorm = $this->normalizeDescription($matDesc);

    $description  = $this->scoreDescription($rowNorm, $matNorm);
    $uom          = $this->scoreUom($row, $candidate);
    $size         = $this->scoreSize($rowNorm, $matNorm);
    $manufacturer = $this->scoreManufacturer($row, $candidate);

    $total = $description + $uom + $size + $manufacturer;
    // Floor at 0 — anti-signals can push a candidate's total negative,
    // but a negative confidence value is meaningless to the reviewer
    // and the downstream routing treats 0 as "no signal."
    if ($total < 0.0) {
      $total = 0.0;
    }
    if ($total > ScoreBreakdown::TOTAL_MAX) {
      $total = ScoreBreakdown::TOTAL_MAX;
    }

    return new ScoreBreakdown(
      total: $total,
      description: $description,
      uom: $uom,
      size: $size,
      manufacturer: $manufacturer,
    );
  }

  // ─────────────────────────────────────────────────────────────────────
  // Signal 1: description token similarity
  // ─────────────────────────────────────────────────────────────────────

  private function scoreDescription(string $rowNorm, string $matNorm): float {
    if ($rowNorm === '' || $matNorm === '') {
      return 0.0;
    }
    $a = $this->tokenize($rowNorm);
    $b = $this->tokenize($matNorm);
    if ($a === [] || $b === []) {
      return 0.0;
    }
    $setA = array_unique($a);
    $setB = array_unique($b);
    $intersect = count(array_intersect($setA, $setB));
    $union     = count(array_unique(array_merge($setA, $setB)));
    if ($union === 0) {
      return 0.0;
    }
    $jaccard = $intersect / $union;
    $score = $jaccard * ScoreBreakdown::DESC_MAX;

    // Substring bonus: candidate's full normalized title appears in the
    // row's normalized description. Catches verbose rows whose canonical
    // product name is buried in catalog noise. Cap at DESC_MAX.
    if (strlen($matNorm) >= 8 && str_contains($rowNorm, $matNorm)) {
      $score = min($score + 10.0, ScoreBreakdown::DESC_MAX);
    }
    return $score;
  }

  private function tokenize(string $normalized): array {
    $parts = preg_split('/\s+/', $normalized) ?: [];
    $out = [];
    foreach ($parts as $tok) {
      if ($tok === '' || in_array($tok, self::STOPWORDS, TRUE)) {
        continue;
      }
      $out[] = $tok;
    }
    return $out;
  }

  // ─────────────────────────────────────────────────────────────────────
  // Signal 2: UOM
  // ─────────────────────────────────────────────────────────────────────

  private function scoreUom(EntityInterface $row, EntityInterface $candidate): float {
    $rowUom = strtolower(trim((string) ($row->get('field_cost_uom')->value ?? '')));
    $matUom = '';
    if ($candidate->hasField('field_unit_of_measure')) {
      $matUom = strtolower(trim((string) ($candidate->get('field_unit_of_measure')->value ?? '')));
    }
    if ($rowUom === '' || $matUom === '') {
      return 0.0;
    }
    $rowCanon = self::UOM_CANONICAL[$rowUom] ?? $rowUom;
    $matCanon = self::UOM_CANONICAL[$matUom] ?? $matUom;
    if ($rowCanon === $matCanon) {
      return ScoreBreakdown::UOM_MAX;
    }
    // Both have UOM and they don't match — that's a real anti-signal.
    return -5.0;
  }

  // ─────────────────────────────────────────────────────────────────────
  // Signal 3: size
  // ─────────────────────────────────────────────────────────────────────

  private function scoreSize(string $rowNorm, string $matNorm): float {
    $rowSizes = $this->extractSizes($rowNorm);
    $matSizes = $this->extractSizes($matNorm);
    if ($rowSizes === [] || $matSizes === []) {
      return 0.0;
    }
    $intersect = array_intersect($rowSizes, $matSizes);
    $intersectCount = count($intersect);
    if ($intersectCount === 0) {
      // Both have sizes; none agree — strong anti-signal.
      return -10.0;
    }
    if ($intersectCount === count($rowSizes) && $intersectCount === count($matSizes)) {
      return ScoreBreakdown::SIZE_MAX;
    }
    // Partial overlap.
    return 10.0;
  }

  /**
   * Extract size tokens from a normalized description.
   *
   * Catches: 1/2", 3/4 in, 1.5", 12', 25mm, etc. Returns canonical strings
   * like "1/2in", "12ft", "25mm" so two descriptions with the same physical
   * size produce equal tokens regardless of source spelling.
   */
  private function extractSizes(string $normalized): array {
    // Match a number (decimal or fraction) followed by a unit token.
    // Normalization already converted inch/in/inches → " and feet/ft → '
    // and stripped most punctuation while preserving / and ".
    $sizes = [];
    if (preg_match_all(
      '#(\d+(?:\.\d+)?(?:/\d+)?)\s*(\"|\'|mm|cm)#i',
      $normalized,
      $matches,
      PREG_SET_ORDER,
    )) {
      foreach ($matches as $m) {
        $num  = $m[1];
        $unit = strtolower($m[2]);
        $canonUnit = match ($unit) {
          '"'  => 'in',
          "'"  => 'ft',
          'mm' => 'mm',
          'cm' => 'cm',
          default => $unit,
        };
        $sizes[] = $num . $canonUnit;
      }
    }
    return array_values(array_unique($sizes));
  }

  // ─────────────────────────────────────────────────────────────────────
  // Signal 4: manufacturer
  // ─────────────────────────────────────────────────────────────────────

  private function scoreManufacturer(EntityInterface $row, EntityInterface $candidate): float {
    $rowMfr = trim((string) ($row->get('field_manufacturer_name')->value ?? ''));
    if ($rowMfr === '') {
      return 0.0;
    }
    if (!$candidate->hasField('field_manufacturer')) {
      return 0.0;
    }
    $mfrEntity = $candidate->get('field_manufacturer')->entity;
    if (!$mfrEntity) {
      return 0.0;
    }
    $matMfr = (string) $mfrEntity->label();
    if ($matMfr === '') {
      return 0.0;
    }
    $a = $this->normalizeManufacturer($rowMfr);
    $b = $this->normalizeManufacturer($matMfr);
    if ($a === '' || $b === '') {
      return 0.0;
    }
    if ($a === $b) {
      return ScoreBreakdown::MFR_MAX;
    }
    if ($a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a))) {
      return 10.0;
    }
    return 0.0;
  }

  private function normalizeManufacturer(string $name): string {
    $s = strtolower(trim($name));
    // Strip punctuation that's noise around suffixes (commas, periods).
    $s = preg_replace('/[.,]/', ' ', $s) ?? $s;
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    if ($s === '') {
      return '';
    }
    // Strip trailing suffixes iteratively (catches "Foo Inc. Co").
    foreach (self::MFR_SUFFIXES as $sfx) {
      // Loop until no more trailing matches — handles "Foo Inc Mfg".
      while (preg_match('/^(.*?)(?:\s+' . preg_quote($sfx, '/') . ')$/i', $s, $m)) {
        $s = trim($m[1]);
        if ($s === '') {
          break 2;
        }
      }
    }
    return $s;
  }

  // ─────────────────────────────────────────────────────────────────────
  // Normalization (applied to descriptions before tokenize / size-extract)
  // ─────────────────────────────────────────────────────────────────────

  /**
   * Bring a description string into the form both signals consume.
   *
   * Steps applied in order:
   *   1. Lowercase + collapse whitespace.
   *   2. Strip punctuation EXCEPT " ' / - .
   *   3. Replace inch/inches/in (as standalone word) with "
   *      and feet/ft (as standalone word) with '.
   *   4. Convert known decimal-to-fraction equivalences (0.5 → 1/2, etc.).
   *   5. Collapse spaces between a size and its unit: "1/2 \"" → "1/2\""
   *      and "3 /4 in" → "3/4\"".
   */
  public function normalizeDescription(string $raw): string {
    $s = strtolower($raw);
    // Strip punctuation we don't want — replace with space. Keep "', /, -, ., and digits.
    $s = preg_replace('/[^a-z0-9"\'\/\-.\s]+/u', ' ', $s) ?? $s;
    // Pre-collapse whitespace so word boundaries are clean.
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = trim($s);
    if ($s === '') {
      return '';
    }
    // inch/inches/in → "  (word-boundary).
    $s = preg_replace('/\b(?:inches|inch|in)\b/', '"', $s) ?? $s;
    // feet/ft → '
    $s = preg_replace('/\b(?:feet|ft)\b/', "'", $s) ?? $s;
    // Collapse "3 /4" → "3/4" and "1/2 \"" → "1/2\"" / "3 mm" → "3mm".
    $s = preg_replace('/(\d)\s*\/\s*(\d)/', '$1/$2', $s) ?? $s;
    $s = preg_replace('/(\d(?:\.\d+)?(?:\/\d+)?)\s+(\"|\'|mm|cm)/', '$1$2', $s) ?? $s;
    // Decimal → fraction for the common ones.
    foreach (self::DECIMAL_FRACTIONS as $dec => $frac) {
      $s = preg_replace('/\b' . preg_quote($dec, '/') . '\b/', $frac, $s) ?? $s;
    }
    // Final whitespace collapse.
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
  }

}
