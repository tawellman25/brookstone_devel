<?php

declare(strict_types=1);

namespace Drupal\supplier_price_ingest\Commands;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;

/**
 * Drush commands — backfill material.field_manufacturer_item_number from title.
 *
 * Three-phase workflow: propose → review → apply. The propose phase
 * writes proposals to a CSV at /tmp; nothing touches the database
 * until apply is invoked deliberately by the operator.
 *
 * Motivation (2026-05-25 catalog audit): Rain Bird materials have only
 * ~36% mfr-item-number fill, but the SKU is sitting in the title for
 * most empty entries — "15 ft. 15SST Plastic Side Strip Nozzle" has
 * the SKU 15SST right there. Same for Hunter ("PRO-SPRAY PROS-12"
 * → "PROS-12"). Extracting these via regex and offering them up for
 * human review gets us back to high Tier-1 match rates in the
 * supplier-price-ingest pipeline without an apprentice-week of manual
 * catalog cleanup.
 *
 * Five extraction patterns, applied in order — first match wins:
 *
 *   A  — size prefix + SKU         (HIGH confidence)
 *        "15 ft. 15SST Plastic..." → "15SST"
 *   B  — Pop-Up + SKU              (HIGH)
 *        "4 in. Pop-Up 1804 Spray..." → "1804"
 *   C  — PRO-SPRAY + SKU           (HIGH)
 *        "Hunter 12 in. PRO-SPRAY PROS-12" → "PROS-12"
 *   D  — SKU at title start        (MEDIUM)
 *        "PSU-04-15A 4 in. Pop-Up..." → "PSU-04-15A"
 *   E  — after manufacturer name   (HIGH)
 *        "Rain Bird PGP-04 Adjustable..." → "PGP-04"
 *
 * Patterns with multiple matches at different confidence levels → LOW
 * with both candidates listed in the notes column for human disambiguation.
 * No match → SKIP, never written to DB.
 */
final class MfrBackfillCommands extends DrushCommands {

  /**
   * Extraction-pattern definitions. Order matters — first match wins.
   * Each entry: regex (PHP PCRE), example, confidence tier name.
   */
  private const PATTERNS = [
    'A_size_prefix' => [
      'regex' => '/^\d+(?:\s*ft\.?|\s*in\.?)\s+([A-Z0-9][A-Z0-9\-+]*?)\s/',
      'confidence' => 'HIGH',
      'description' => 'size prefix + SKU',
    ],
    'B_popup' => [
      'regex' => '/Pop-Up\s+([A-Z0-9][A-Z0-9\-+]*?)\s/i',
      'confidence' => 'HIGH',
      'description' => 'Pop-Up + SKU',
    ],
    'C_prospray' => [
      'regex' => '/PRO-SPRAY\s+([A-Z0-9][A-Z0-9\-+]*)/i',
      'confidence' => 'HIGH',
      'description' => 'PRO-SPRAY + SKU',
    ],
    'D_title_start' => [
      'regex' => '/^([A-Z]{2,5}-?\d+[A-Z0-9\-]*?)\s/',
      'confidence' => 'MEDIUM',
      'description' => 'SKU at title start (no size/brand prefix)',
    ],
    'E_mfr_prefix' => [
      'regex' => '/^(?:Rain Bird|Hunter)\s+([A-Z0-9][A-Z0-9\-+]*?)\s/i',
      'confidence' => 'HIGH',
      'description' => 'after manufacturer name',
    ],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Propose mfr-item-number extractions for a given manufacturer.
   *
   * Reads target materials, runs the extraction patterns against each
   * title, writes one row per candidate to a CSV at /tmp. The DB is
   * not touched.
   *
   * @command bos:mfr-backfill:propose
   * @aliases bos-mfr-backfill-propose
   * @param int $manufacturer_id  Manufacturer entity id (Rain Bird=61, Hunter=63).
   * @option limit Maximum number of candidate materials to process. Default: no limit.
   * @usage drush bos:mfr-backfill:propose 61
   *   Propose extractions for every empty-mfr-item Rain Bird material.
   * @usage drush bos:mfr-backfill:propose 61 --limit=50
   *   First 50 candidates only — useful for spot-checking patterns.
   */
  public function propose(int $manufacturer_id, array $options = ['limit' => 0]): int {
    $mfr = $this->entityTypeManager->getStorage('manufacturer')->load($manufacturer_id);
    if (!$mfr) {
      $this->output()->writeln("<error>Manufacturer #{$manufacturer_id} not found.</error>");
      return 1;
    }
    $mfrLabel = (string) $mfr->label();
    $this->output()->writeln("Manufacturer: {$mfrLabel} (#{$manufacturer_id})");

    $candidates = $this->loadEmptyMfrItemMaterials($manufacturer_id, (int) ($options['limit'] ?? 0));
    if (!$candidates) {
      $this->output()->writeln('No candidate materials found (every material with this manufacturer already has field_manufacturer_item_number populated).');
      return 0;
    }
    $this->output()->writeln(sprintf('Loaded %d candidate materials.', count($candidates)));

    $proposals = [];
    $counts = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'SKIP' => 0];

    foreach ($candidates as $material) {
      $proposal = $this->extractFromMaterial($material);
      $proposals[] = $proposal;
      $counts[$proposal['confidence_tier']]++;
    }

    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($mfrLabel)) ?? 'mfr';
    $csvPath = sprintf('/tmp/mfr_backfill_proposals_%s_%s.csv', trim($slug, '_'), date('Ymd_His'));
    $this->writeCsv($csvPath, $proposals);

    $this->output()->writeln('');
    $this->output()->writeln(sprintf('Total candidates: %d', count($proposals)));
    $this->output()->writeln(sprintf(
      'HIGH: %d | MEDIUM: %d | LOW: %d | SKIP: %d',
      $counts['HIGH'], $counts['MEDIUM'], $counts['LOW'], $counts['SKIP'],
    ));
    $this->output()->writeln("Proposals written to: {$csvPath}");
    return 0;
  }

  /**
   * Human-readable summary of a proposals CSV — for Todd's spot-check.
   *
   * Shows first 30 HIGH, first 30 MEDIUM, all LOW, first 20 SKIP.
   *
   * @command bos:mfr-backfill:review
   * @aliases bos-mfr-backfill-review
   * @param string $csv_path  Path to proposals CSV produced by :propose.
   * @usage drush bos:mfr-backfill:review /tmp/mfr_backfill_proposals_rain_bird_20260525_143012.csv
   */
  public function review(string $csv_path): int {
    if (!is_readable($csv_path)) {
      $this->output()->writeln("<error>CSV not readable: {$csv_path}</error>");
      return 1;
    }
    $rows = $this->readCsv($csv_path);
    if (!$rows) {
      $this->output()->writeln("<error>CSV has no rows: {$csv_path}</error>");
      return 1;
    }
    $byTier = ['HIGH' => [], 'MEDIUM' => [], 'LOW' => [], 'SKIP' => []];
    foreach ($rows as $row) {
      $tier = $row['confidence_tier'] ?? 'SKIP';
      $byTier[$tier][] = $row;
    }

    $this->output()->writeln(sprintf('=== Review: %s ===', basename($csv_path)));
    $this->output()->writeln(sprintf(
      'HIGH: %d | MEDIUM: %d | LOW: %d | SKIP: %d',
      count($byTier['HIGH']), count($byTier['MEDIUM']),
      count($byTier['LOW']), count($byTier['SKIP']),
    ));
    $this->output()->writeln('');

    $this->printSection('HIGH-confidence (first 30)', array_slice($byTier['HIGH'], 0, 30));
    $this->printSection('MEDIUM-confidence (first 30)', array_slice($byTier['MEDIUM'], 0, 30));
    $this->printSection('LOW-confidence (all)', $byTier['LOW']);
    $this->printSection('SKIP samples (first 20) — patterns missed these', array_slice($byTier['SKIP'], 0, 20));

    $this->output()->writeln('');
    $this->output()->writeln('To apply HIGH-only:');
    $this->output()->writeln("  drush bos:mfr-backfill:apply {$csv_path}");
    $this->output()->writeln('To apply HIGH + MEDIUM:');
    $this->output()->writeln("  drush bos:mfr-backfill:apply {$csv_path} --confidence=HIGH,MEDIUM");
    $this->output()->writeln('Dry-run first to see what would change:');
    $this->output()->writeln("  drush bos:mfr-backfill:apply {$csv_path} --dry-run");
    return 0;
  }

  /**
   * Apply approved extractions to material.field_manufacturer_item_number.
   *
   * Defensive: re-loads each material and skips it if the field has
   * been populated since proposing (concurrent edit). Every applied
   * change is logged to /tmp/mfr_backfill_applied_*.log.
   *
   * @command bos:mfr-backfill:apply
   * @aliases bos-mfr-backfill-apply
   * @param string $csv_path  Path to proposals CSV.
   * @option confidence Comma-separated confidence tiers to apply. Default: HIGH only.
   * @option dry-run Print what would be applied; do not write.
   * @usage drush bos:mfr-backfill:apply /tmp/mfr_backfill_proposals_rain_bird_20260525_143012.csv
   *   Apply only HIGH-confidence rows.
   * @usage drush bos:mfr-backfill:apply /tmp/mfr_backfill_proposals_rain_bird_20260525_143012.csv --confidence=HIGH,MEDIUM
   *   Apply HIGH + MEDIUM after spot-check.
   * @usage drush bos:mfr-backfill:apply /tmp/mfr_backfill_proposals_rain_bird_20260525_143012.csv --dry-run
   *   See what would change without writing.
   */
  public function apply(string $csv_path, array $options = ['confidence' => 'HIGH', 'dry-run' => FALSE]): int {
    if (!is_readable($csv_path)) {
      $this->output()->writeln("<error>CSV not readable: {$csv_path}</error>");
      return 1;
    }
    $allowedTiers = array_map('trim', explode(',', strtoupper((string) $options['confidence'])));
    $allowedTiers = array_filter($allowedTiers, fn ($t) => in_array($t, ['HIGH', 'MEDIUM', 'LOW'], TRUE));
    if (!$allowedTiers) {
      $this->output()->writeln('<error>--confidence must be one or more of HIGH, MEDIUM, LOW.</error>');
      return 1;
    }
    $dryRun = (bool) $options['dry-run'];

    $rows = $this->readCsv($csv_path);
    $targets = array_filter($rows, fn ($r) => in_array(($r['confidence_tier'] ?? ''), $allowedTiers, TRUE));
    if (!$targets) {
      $this->output()->writeln(sprintf('No rows in confidence tiers [%s].', implode(', ', $allowedTiers)));
      return 0;
    }

    $this->output()->writeln(sprintf(
      '%s %d rows in tiers [%s] from %s',
      $dryRun ? 'DRY RUN —' : 'Applying',
      count($targets),
      implode(', ', $allowedTiers),
      basename($csv_path),
    ));

    $matStorage = $this->entityTypeManager->getStorage('material');
    $applied = 0;
    $skippedNowPopulated = 0;
    $errors = 0;
    $logLines = [];

    foreach ($targets as $row) {
      $materialId = (int) ($row['material_id'] ?? 0);
      $extracted = trim((string) ($row['extracted_sku'] ?? ''));
      if ($materialId <= 0 || $extracted === '') {
        $errors++;
        $logLines[] = sprintf('[ERROR] row missing material_id or extracted_sku: %s', json_encode($row));
        continue;
      }
      $matStorage->resetCache([$materialId]);
      $material = $matStorage->load($materialId);
      if (!$material) {
        $errors++;
        $logLines[] = sprintf('[ERROR] material #%d not loadable', $materialId);
        continue;
      }
      // Defensive: re-check empty status before writing.
      $existing = trim((string) ($material->get('field_manufacturer_item_number')->value ?? ''));
      if ($existing !== '') {
        $skippedNowPopulated++;
        $logLines[] = sprintf('[SKIP-now-populated] material #%d already has "%s"', $materialId, $existing);
        continue;
      }
      $beforeJson = sprintf('material #%d title="%s" before=""', $materialId, str_replace('"', '\\"', $material->label()));
      if ($dryRun) {
        $logLines[] = sprintf('[DRY-RUN] %s -> "%s" (tier=%s)', $beforeJson, $extracted, $row['confidence_tier']);
        $applied++;
        continue;
      }
      try {
        $material->set('field_manufacturer_item_number', $extracted);
        $material->save();
        $logLines[] = sprintf('[APPLIED] %s -> "%s" (tier=%s)', $beforeJson, $extracted, $row['confidence_tier']);
        $applied++;
      }
      catch (\Throwable $e) {
        $errors++;
        $logLines[] = sprintf('[ERROR] material #%d save failed: %s', $materialId, $e->getMessage());
      }
    }

    // Write log.
    $logPath = sprintf(
      '/tmp/mfr_backfill_applied_%s_%s.log',
      preg_replace('/[^a-z0-9]+/', '_', strtolower(pathinfo($csv_path, PATHINFO_FILENAME))) ?? 'apply',
      date('Ymd_His'),
    );
    file_put_contents($logPath, implode("\n", $logLines) . "\n");

    $this->output()->writeln('');
    $this->output()->writeln(sprintf(
      '%s: %d | Skipped (now populated): %d | Errors: %d',
      $dryRun ? 'Would apply' : 'Applied',
      $applied, $skippedNowPopulated, $errors,
    ));
    $this->output()->writeln("Log written to: {$logPath}");
    return $errors > 0 ? 2 : 0;
  }

  // ────────────────────────────────────────────────────────────────────
  // Internals
  // ────────────────────────────────────────────────────────────────────

  /**
   * Load materials for a manufacturer whose field_manufacturer_item_number
   * is empty or whitespace-only.
   *
   * Two-stage: (a) entity query for all materials referencing the
   * manufacturer; (b) PHP-filter to drop ones with non-whitespace
   * content. The entityQuery `notExists` alone misses rows where the
   * field was explicitly set to '' or '   '.
   *
   * @return EntityInterface[]
   */
  private function loadEmptyMfrItemMaterials(int $manufacturer_id, int $limit): array {
    $matStorage = $this->entityTypeManager->getStorage('material');
    $q = $matStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_manufacturer', $manufacturer_id)
      ->sort('id', 'ASC');
    if ($limit > 0) {
      // Over-fetch to give the filter room — we may discard rows that
      // turn out to have non-whitespace content. 3x overage is generous.
      $q->range(0, $limit * 3);
    }
    $ids = array_values($q->execute());
    if (!$ids) {
      return [];
    }

    $kept = [];
    foreach (array_chunk($ids, 100) as $chunk) {
      foreach ($matStorage->loadMultiple($chunk) as $material) {
        if (!$material->hasField('field_manufacturer_item_number')) {
          continue;
        }
        $current = trim((string) ($material->get('field_manufacturer_item_number')->value ?? ''));
        if ($current !== '') {
          continue;
        }
        $kept[] = $material;
        if ($limit > 0 && count($kept) >= $limit) {
          return $kept;
        }
      }
      $matStorage->resetCache($chunk);
    }
    return $kept;
  }

  /**
   * Run all extraction patterns against a material's title. Returns a
   * proposal row in the shape :propose writes to CSV.
   */
  private function extractFromMaterial(EntityInterface $material): array {
    $title = (string) ($material->label() ?? '');
    $matches = [];
    foreach (self::PATTERNS as $name => $def) {
      if (preg_match($def['regex'], $title, $m)) {
        $matches[] = [
          'pattern' => $name,
          'sku' => trim($m[1] ?? ''),
          'confidence' => $def['confidence'],
        ];
      }
    }

    if (!$matches) {
      return [
        'material_id' => (int) $material->id(),
        'current_title' => $title,
        'current_mfr_item' => '',
        'extracted_sku' => '',
        'extraction_method' => '',
        'confidence_tier' => 'SKIP',
        'notes' => 'no extraction pattern matched',
      ];
    }

    // Single match — straight win.
    if (count($matches) === 1) {
      $m = $matches[0];
      return [
        'material_id' => (int) $material->id(),
        'current_title' => $title,
        'current_mfr_item' => '',
        'extracted_sku' => $m['sku'],
        'extraction_method' => $m['pattern'],
        'confidence_tier' => $m['confidence'],
        'notes' => '',
      ];
    }

    // Multiple matches — collapse identical extractions; flag divergent.
    $uniqueSkus = array_values(array_unique(array_column($matches, 'sku')));
    if (count($uniqueSkus) === 1) {
      // All patterns agreed on the SKU — promote to HIGH if any matcher
      // was HIGH, otherwise inherit the highest tier seen.
      $tiers = array_column($matches, 'confidence');
      $tier = in_array('HIGH', $tiers, TRUE) ? 'HIGH' : (in_array('MEDIUM', $tiers, TRUE) ? 'MEDIUM' : 'LOW');
      return [
        'material_id' => (int) $material->id(),
        'current_title' => $title,
        'current_mfr_item' => '',
        'extracted_sku' => $uniqueSkus[0],
        'extraction_method' => implode('+', array_column($matches, 'pattern')),
        'confidence_tier' => $tier,
        'notes' => sprintf('%d patterns agreed', count($matches)),
      ];
    }

    // Divergent — LOW, list all candidates.
    $candidates = [];
    foreach ($matches as $m) {
      $candidates[] = "{$m['pattern']}={$m['sku']}";
    }
    return [
      'material_id' => (int) $material->id(),
      'current_title' => $title,
      'current_mfr_item' => '',
      'extracted_sku' => $matches[0]['sku'],
      'extraction_method' => $matches[0]['pattern'] . ' (first of ' . count($matches) . ')',
      'confidence_tier' => 'LOW',
      'notes' => 'multiple patterns matched divergent SKUs: ' . implode(', ', $candidates),
    ];
  }

  /**
   * Write proposals to CSV with a stable column order.
   */
  private function writeCsv(string $path, array $rows): void {
    $columns = [
      'material_id', 'current_title', 'current_mfr_item',
      'extracted_sku', 'extraction_method', 'confidence_tier', 'notes',
    ];
    $fh = fopen($path, 'wb');
    if ($fh === FALSE) {
      throw new \RuntimeException("Cannot open {$path} for writing.");
    }
    fputcsv($fh, $columns);
    foreach ($rows as $row) {
      $line = [];
      foreach ($columns as $col) {
        $line[] = (string) ($row[$col] ?? '');
      }
      fputcsv($fh, $line);
    }
    fclose($fh);
  }

  /**
   * Read a proposals CSV into associative-array rows.
   */
  private function readCsv(string $path): array {
    $fh = fopen($path, 'rb');
    if ($fh === FALSE) {
      return [];
    }
    $headers = fgetcsv($fh);
    if (!$headers) {
      fclose($fh);
      return [];
    }
    $out = [];
    while (($cells = fgetcsv($fh)) !== FALSE) {
      $out[] = array_combine($headers, array_pad($cells, count($headers), ''));
    }
    fclose($fh);
    return $out;
  }

  /**
   * Print a labeled section of review rows.
   */
  private function printSection(string $title, array $rows): void {
    $this->output()->writeln("--- {$title} ({$this->countLabel($rows)}) ---");
    if (!$rows) {
      $this->output()->writeln('  (none)');
      $this->output()->writeln('');
      return;
    }
    foreach ($rows as $row) {
      $tier = $row['confidence_tier'] ?? '';
      $mid = (int) ($row['material_id'] ?? 0);
      $sku = (string) ($row['extracted_sku'] ?? '');
      $title2 = (string) ($row['current_title'] ?? '');
      $notes = (string) ($row['notes'] ?? '');
      if ($tier === 'SKIP') {
        $this->output()->writeln(sprintf('  [SKIP] material %d (no pattern matched)', $mid));
        $this->output()->writeln(sprintf('         title: "%s"', $title2));
      }
      else {
        $this->output()->writeln(sprintf('  [%s] material %d: extract "%s"', $tier, $mid, $sku));
        $this->output()->writeln(sprintf('         from title: "%s"', $title2));
        if ($notes !== '') {
          $this->output()->writeln(sprintf('         notes: %s', $notes));
        }
      }
    }
    $this->output()->writeln('');
  }

  private function countLabel(array $rows): string {
    return count($rows) === 1 ? '1 row' : (count($rows) . ' rows');
  }

}
