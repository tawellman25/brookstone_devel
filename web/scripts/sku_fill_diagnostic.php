<?php

declare(strict_types=1);

/**
 * Read-only SKU-bridge fill-rate diagnostic for material_suppliers.
 *
 * Quantifies how many material_suppliers link rows have
 * field_supplier_item_number populated, per supplier and per material
 * bundle, to size the apprentice's SKU-bootstrap work before turning
 * on automated supplier-feed pricing matching.
 *
 * Output:
 *   - Markdown report saved to __BOS_AI/Reports/sku_fill_report_YYYY-MM-DD.md
 *   - Same content also printed to stdout
 *   - Absolute path printed at the end
 *
 * Re-runnable weekly: each run uses the current date as the filename;
 * previous runs are not overwritten.
 *
 * Usage:
 *   ddev drush scr web/scripts/sku_fill_diagnostic.php
 *
 *   # On live (after explicit user confirmation):
 *   ssh brookstone "cd /home/brookstoneadmin/brookstone && \
 *     ./vendor/bin/drush scr web/scripts/sku_fill_diagnostic.php"
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

// ── Environment detection ──────────────────────────────────────────
$env = (getenv('IS_DDEV_PROJECT') || getenv('DDEV_HOSTNAME') || strpos(gethostname(), 'web') !== FALSE)
  ? 'ddev'
  : 'live';
$generated = date('Y-m-d H:i');

// ── Output sink: in-memory buffer + final file save ────────────────
$out = [];
$w = function (string $line = '') use (&$out): void {
  $out[] = $line;
};

// ── Helpers ─────────────────────────────────────────────────────────
$pct = function (int $num, int $den): string {
  if ($den === 0) return 'n/a';
  return number_format(($num / $den) * 100, 1) . '%';
};
$isFilled = function ($value): bool {
  return is_string($value) && trim($value) !== '';
};

// ── Load all material_suppliers entities ───────────────────────────
$storage = \Drupal::entityTypeManager()->getStorage('material_suppliers');
$ids = \Drupal::entityQuery('material_suppliers')->accessCheck(FALSE)->execute();
$total = count($ids);

if ($total === 0) {
  echo "No material_suppliers rows. Aborting.\n";
  exit(0);
}

// ── Bulk-prefetch all referenced suppliers + materials ─────────────
// Iterate in batches so we don't blow memory on very large catalogs.
$by_supplier = []; // supplier_id => ['total'=>n,'filled'=>n,'by_bundle'=>[bundle=>['t','f']]]
$by_bundle   = []; // bundle => ['total','filled']
$supplier_ids_seen = [];
$material_ids_seen = [];
$bundle_supplier_empty = []; // "bundle|supplier_id" => count of empty SKU rows

$batch_size = 500;
$batches = array_chunk(array_values($ids), $batch_size);
foreach ($batches as $batch) {
  $rows = $storage->loadMultiple($batch);
  foreach ($rows as $row) {
    $sup_id = (int) ($row->get('field_supplier')->target_id ?? 0);
    $mat_id = (int) ($row->get('field_material')->target_id ?? 0);
    $sku    = $row->get('field_supplier_item_number')->value ?? NULL;
    $filled = $isFilled($sku);

    if ($sup_id > 0) $supplier_ids_seen[$sup_id] = TRUE;
    if ($mat_id > 0) $material_ids_seen[$mat_id] = TRUE;

    // Aggregate per supplier.
    if (!isset($by_supplier[$sup_id])) {
      $by_supplier[$sup_id] = ['total' => 0, 'filled' => 0, 'by_bundle' => []];
    }
    $by_supplier[$sup_id]['total']++;
    if ($filled) $by_supplier[$sup_id]['filled']++;
  }
}

// Need material bundle lookups for per-bundle aggregation. Bulk-load materials.
$mat_storage = \Drupal::entityTypeManager()->getStorage('material');
$mat_bundle = []; // material_id => bundle
$mat_ids = array_keys($material_ids_seen);
foreach (array_chunk($mat_ids, 500) as $batch) {
  $mats = $mat_storage->loadMultiple($batch);
  foreach ($mats as $m) {
    $mat_bundle[(int) $m->id()] = $m->bundle();
  }
}

// Second pass: now that we have bundles, populate per-bundle aggs.
foreach ($batches as $batch) {
  $rows = $storage->loadMultiple($batch);
  foreach ($rows as $row) {
    $sup_id = (int) ($row->get('field_supplier')->target_id ?? 0);
    $mat_id = (int) ($row->get('field_material')->target_id ?? 0);
    $sku    = $row->get('field_supplier_item_number')->value ?? NULL;
    $filled = $isFilled($sku);
    $bundle = $mat_bundle[$mat_id] ?? '(missing material)';

    if (!isset($by_bundle[$bundle])) {
      $by_bundle[$bundle] = ['total' => 0, 'filled' => 0];
    }
    $by_bundle[$bundle]['total']++;
    if ($filled) $by_bundle[$bundle]['filled']++;

    if (!isset($by_supplier[$sup_id]['by_bundle'][$bundle])) {
      $by_supplier[$sup_id]['by_bundle'][$bundle] = ['total' => 0, 'filled' => 0];
    }
    $by_supplier[$sup_id]['by_bundle'][$bundle]['total']++;
    if ($filled) $by_supplier[$sup_id]['by_bundle'][$bundle]['filled']++;

    if (!$filled) {
      $key = $bundle . '|' . $sup_id;
      $bundle_supplier_empty[$key] = ($bundle_supplier_empty[$key] ?? 0) + 1;
    }
  }
}

// ── Load supplier entities for titles / status ─────────────────────
$sup_storage = \Drupal::entityTypeManager()->getStorage('supplier');
$sup_data = []; // sup_id => ['title','name','status']
foreach (array_chunk(array_keys($supplier_ids_seen), 500) as $batch) {
  $sups = $sup_storage->loadMultiple($batch);
  foreach ($sups as $s) {
    $sup_data[(int) $s->id()] = [
      'title'  => (string) $s->label(),
      'name'   => $s->hasField('field_supplier_name') ? trim($s->get('field_supplier_name')->value ?? '') : '',
      'status' => $s->hasField('field_supplier_status') ? trim($s->get('field_supplier_status')->value ?? '') : '',
    ];
  }
}

// ── Identify priority suppliers ────────────────────────────────────
$priority_specs = [
  'SiteOne'      => ['siteone'],
  'Denver Brass' => ['denver brass'],
  'CPS/Heritage' => ['cps', 'heritage'],
];
$priority_matches = []; // friendly_name => [sup_ids]
foreach ($priority_specs as $friendly => $needles) {
  $priority_matches[$friendly] = [];
  foreach ($sup_data as $sid => $d) {
    $hay = strtolower($d['title'] . '|' . $d['name']);
    foreach ($needles as $n) {
      if (str_contains($hay, $n)) {
        $priority_matches[$friendly][] = $sid;
        continue 2;
      }
    }
  }
}

// Filled tally for headline.
$total_filled = 0;
foreach ($by_supplier as $agg) $total_filled += $agg['filled'];
$total_empty = $total - $total_filled;

// ── Render markdown ─────────────────────────────────────────────────
$w("# SKU-Bridge Fill Rate Diagnostic");
$w();
$w("```");
$w("GENERATED: $generated");
$w("ENVIRONMENT: $env");
$w("TOTAL material_suppliers ROWS: $total");
$w("TOTAL UNIQUE SUPPLIERS: " . count($supplier_ids_seen));
$w("TOTAL UNIQUE MATERIALS LINKED: " . count($material_ids_seen));
$w("OVERALL SKU FILL RATE: " . $pct($total_filled, $total) . " ($total_filled filled / $total_empty empty)");
$w("PRIORITY SUPPLIER FILL RATES:");
foreach ($priority_specs as $friendly => $_) {
  $sids = $priority_matches[$friendly];
  if (!$sids) {
    $w("  $friendly: NO MATCH");
    continue;
  }
  $t = 0; $f = 0;
  foreach ($sids as $sid) {
    $t += $by_supplier[$sid]['total'] ?? 0;
    $f += $by_supplier[$sid]['filled'] ?? 0;
  }
  $w(sprintf("  %-14s %s (%d filled / %d empty)", $friendly . ':', $pct($f, $t), $f, $t - $f));
}
$w("```");
$w();

// Section 2 — Per-Supplier SKU Fill Summary
$w("## Section 2 — Per-Supplier SKU Fill Summary");
$w();
$w("| Supplier (title) | Supplier ID | Total Links | SKU Filled | SKU Empty | Fill % | Status |");
$w("|---|---:|---:|---:|---:|---:|---|");

// Sort suppliers by total DESC.
$sup_rows = [];
foreach ($by_supplier as $sid => $agg) {
  $sup_rows[] = [
    'sid'    => $sid,
    'title'  => $sup_data[$sid]['title'] ?? "(supplier $sid missing)",
    'status' => $sup_data[$sid]['status'] ?? '',
    'total'  => $agg['total'],
    'filled' => $agg['filled'],
    'empty'  => $agg['total'] - $agg['filled'],
  ];
}
usort($sup_rows, fn($a, $b) => $b['total'] <=> $a['total']);
foreach ($sup_rows as $r) {
  $title = str_replace('|', '\\|', $r['title']);
  $status = $r['status'] !== '' ? str_replace('|', '\\|', $r['status']) : '(none)';
  $w(sprintf(
    "| %s | %d | %d | %d | %d | %s | %s |",
    $title, $r['sid'], $r['total'], $r['filled'], $r['empty'],
    $pct($r['filled'], $r['total']), $status
  ));
}
$w();

// Section 3 — Per-Bundle SKU Fill Summary
$w("## Section 3 — Per-Bundle SKU Fill Summary");
$w();
$w("| Material Bundle | Total Links | SKU Filled | SKU Empty | Fill % |");
$w("|---|---:|---:|---:|---:|");

$bundle_rows = [];
foreach ($by_bundle as $b => $agg) {
  $bundle_rows[] = [
    'bundle' => $b,
    'total'  => $agg['total'],
    'filled' => $agg['filled'],
    'empty'  => $agg['total'] - $agg['filled'],
  ];
}
usort($bundle_rows, fn($a, $b) => $b['total'] <=> $a['total']);
foreach ($bundle_rows as $r) {
  $w(sprintf(
    "| `%s` | %d | %d | %d | %s |",
    $r['bundle'], $r['total'], $r['filled'], $r['empty'],
    $pct($r['filled'], $r['total'])
  ));
}
$w();

// Section 4 — Priority Supplier Deep Dive
$w("## Section 4 — Priority Supplier Deep Dive");
$w();
foreach ($priority_specs as $friendly => $needles) {
  $sids = $priority_matches[$friendly];
  $w("### $friendly");
  $w();
  if (!$sids) {
    $w("**NO MATCH** for needles: " . implode(', ', $needles));
    $w();
    continue;
  }
  // Aggregate across all matched supplier IDs.
  $total_p = 0; $filled_p = 0;
  $by_bundle_p = [];
  foreach ($sids as $sid) {
    $agg = $by_supplier[$sid];
    $total_p  += $agg['total'];
    $filled_p += $agg['filled'];
    foreach ($agg['by_bundle'] as $b => $bagg) {
      if (!isset($by_bundle_p[$b])) {
        $by_bundle_p[$b] = ['total' => 0, 'filled' => 0];
      }
      $by_bundle_p[$b]['total']  += $bagg['total'];
      $by_bundle_p[$b]['filled'] += $bagg['filled'];
    }
  }
  $empty_p = $total_p - $filled_p;
  $w("```");
  $w("Matched supplier ID(s): " . implode(', ', $sids));
  foreach ($sids as $sid) {
    $w("  -> id $sid : " . ($sup_data[$sid]['title'] ?? '(missing)'));
  }
  $w("Total material_suppliers rows: $total_p");
  $w("Overall fill rate: " . $pct($filled_p, $total_p) . " ($filled_p filled / $empty_p empty)");
  $w();
  $w("By target material bundle:");
  uasort($by_bundle_p, fn($a, $b) => $b['total'] <=> $a['total']);
  foreach ($by_bundle_p as $b => $bagg) {
    $bf = $bagg['filled'];
    $bt = $bagg['total'];
    $be = $bt - $bf;
    $w(sprintf(
      "  %-22s %4d rows | %4d filled (%5s) | %4d empty",
      $b . ':', $bt, $bf, $pct($bf, $bt), $be
    ));
  }
  $w("```");
  $w();
}

// Fallback: if any priority supplier had NO match, also dump top 10 supplier titles.
$any_missing = FALSE;
foreach ($priority_matches as $sids) {
  if (!$sids) { $any_missing = TRUE; break; }
}
if ($any_missing) {
  $w("### Priority supplier match failures — top 10 supplier titles in system (for manual identification)");
  $w();
  $top10 = array_slice($sup_rows, 0, 10);
  foreach ($top10 as $r) {
    $w(sprintf("- `%d` — %s (links: %d)", $r['sid'], $r['title'], $r['total']));
  }
  $w();
}

// Section 5 — Top 20 Bootstrap Targets
$w("## Section 5 — Top 20 Bootstrap Targets");
$w();
$w("The 20 (material bundle, supplier) combinations with the most empty-SKU rows. Highest-leverage cleanup targets.");
$w();
$w("| Bundle | Supplier | Empty SKU Count |");
$w("|---|---|---:|");

arsort($bundle_supplier_empty);
$top = array_slice($bundle_supplier_empty, 0, 20, TRUE);
foreach ($top as $key => $cnt) {
  [$bundle, $sid] = explode('|', $key, 2);
  $sup_title = $sup_data[(int) $sid]['title'] ?? "(supplier $sid missing)";
  $sup_title = str_replace('|', '\\|', $sup_title);
  $w(sprintf("| `%s` | %s | %d |", $bundle, $sup_title, $cnt));
}
$w();

// Notes section
$w("## Notes");
$w();
$w("- SKU is counted as filled iff `field_supplier_item_number` is a non-empty trimmed string. NULL, empty, or whitespace-only all count as empty.");
$w("- Section 2 \"Status\" column reads `supplier.field_supplier_status` (supplier-level), NOT `material_suppliers.field_supplier_status_override`.");
$w("- A material_suppliers row whose `field_material` points at a deleted/missing material is reported under bundle `(missing material)`.");
$w("- Priority supplier matching is by case-insensitive substring on `supplier.title` and `supplier.field_supplier_name`.");
$w();

// ── Write file ──────────────────────────────────────────────────────
$content = implode("\n", $out) . "\n";
$date = date('Y-m-d');
$rel_path = "__BOS_AI/Reports/sku_fill_report_$date.md";
$abs_path = DRUPAL_ROOT . '/../' . $rel_path;
$abs_path = realpath(dirname($abs_path)) . '/' . basename($abs_path);

file_put_contents($abs_path, $content);

// Print full report to stdout.
echo $content;
echo "\n---\nReport saved to:\n  $abs_path\n";
