<?php

declare(strict_types=1);

/**
 * Read-only manufacturer-item-number fill-rate diagnostic for the
 * material catalog.
 *
 * Measures how densely populated field_manufacturer_item_number and
 * field_manufacturer are across material bundles. Used to size the
 * supplier-pricing ingest pipeline's match-key strategy: manufacturer
 * item numbers are stable across suppliers (a Hunter PGP-04 is the
 * same part everywhere), so they're a stronger bridge candidate than
 * per-supplier SKUs.
 *
 * Sections:
 *   1. Headline numbers
 *   2. Per-bundle field presence (which bundles HAVE the fields)
 *   3. Per-bundle fill rate (for bundles that have them)
 *   4. Priority bundle deep dive (irrigation, pvc, galv, brass)
 *   5. Combined bridge coverage — % matchable via ANY bridge field
 *   6. Anomaly notes — dangling refs, garbage values, low-fill flags
 *
 * Saves to __BOS_AI/Reports/manufacturer_fill_report_YYYY-MM-DD.md
 * (per-day filename; does not overwrite previous runs).
 *
 * Usage:
 *   ddev drush scr web/scripts/manufacturer_fill_diagnostic.php
 *
 *   # On live (after explicit user confirmation):
 *   ssh brookstone "cd /home/brookstoneadmin/brookstone && \
 *     ./vendor/bin/drush scr web/scripts/manufacturer_fill_diagnostic.php"
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

// ── Environment + timestamp ────────────────────────────────────────
$env = (getenv('IS_DDEV_PROJECT') || getenv('DDEV_HOSTNAME') || strpos(gethostname(), 'web') !== FALSE)
  ? 'ddev'
  : 'live';
$generated = date('Y-m-d H:i');

// ── Output sink ────────────────────────────────────────────────────
$out = [];
$w = function (string $line = '') use (&$out): void { $out[] = $line; };

// ── Helpers ─────────────────────────────────────────────────────────
$pct = function (int $num, int $den): string {
  if ($den === 0) return 'n/a';
  return number_format(($num / $den) * 100, 1) . '%';
};
$isFilledString = function ($value): bool {
  return is_string($value) && trim($value) !== '';
};
$isGarbage = function ($value): bool {
  if (!is_string($value)) return FALSE;
  $t = trim($value);
  if ($t === '') return FALSE; // empty is empty, not garbage
  // Pure punctuation/whitespace/dash etc:
  if (preg_match('/^[\s\.\-_\/\\\\]+$/u', $t)) return TRUE;
  // Common "no value" sentinels:
  if (preg_match('/^(n\/?a|none|tbd|unknown|\?|x)$/i', $t)) return TRUE;
  return FALSE;
};

// ── Bundle discovery + field presence ──────────────────────────────
$bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');
$bundles = array_keys($bundle_info);
sort($bundles);

$field_manager = \Drupal::service('entity_field.manager');
$has_mfr_item = []; // bundle => bool
$has_mfr_ref  = []; // bundle => bool
$has_supplier_item = []; // bundle => bool
foreach ($bundles as $b) {
  $defs = $field_manager->getFieldDefinitions('material', $b);
  $has_mfr_item[$b]      = isset($defs['field_manufacturer_item_number']);
  $has_mfr_ref[$b]       = isset($defs['field_manufacturer']);
  $has_supplier_item[$b] = isset($defs['field_supplier_item_number']);
}

// ── Load all materials, aggregating per bundle ─────────────────────
$storage = \Drupal::entityTypeManager()->getStorage('material');
$ids = \Drupal::entityQuery('material')->accessCheck(FALSE)->execute();
$total = count($ids);

// Per-bundle aggregations:
$agg = [];
foreach ($bundles as $b) {
  $agg[$b] = [
    'total'        => 0,
    'mfr_item_filled'  => 0,
    'mfr_item_empty'   => 0,
    'mfr_item_garbage' => 0,
    'mfr_ref_filled'   => 0,
    'mfr_ref_empty'    => 0,
    'mfr_ref_dangling' => 0,
    'supp_item_filled' => 0,
    'both_mfr_filled'  => 0, // item# + ref both populated
    'any_bridge'       => 0, // item# OR supp_item# OR ref
    'none_bridge'      => 0,
    'mfr_ref_target_counts' => [], // target_id => count (resolve to title later)
    'garbage_examples' => [],
  ];
}

// Bulk-load manufacturer titles. Defer to a single pass — collect
// target_ids first, then load.
$all_mfr_target_ids = [];

$batch_size = 500;
foreach (array_chunk(array_values($ids), $batch_size) as $batch) {
  $rows = $storage->loadMultiple($batch);
  foreach ($rows as $m) {
    $b = $m->bundle();
    if (!isset($agg[$b])) continue;
    $agg[$b]['total']++;

    // Mfr item #
    $mi_val = NULL;
    $mi_filled = FALSE;
    if ($has_mfr_item[$b]) {
      $mi_val = $m->get('field_manufacturer_item_number')->value ?? NULL;
      if ($isFilledString($mi_val)) {
        $mi_filled = TRUE;
        $agg[$b]['mfr_item_filled']++;
      }
      else {
        $agg[$b]['mfr_item_empty']++;
      }
      if ($isGarbage($mi_val)) {
        $agg[$b]['mfr_item_garbage']++;
        if (count($agg[$b]['garbage_examples']) < 10) {
          $agg[$b]['garbage_examples'][] = [
            'id'    => (int) $m->id(),
            'value' => (string) $mi_val,
          ];
        }
      }
    }

    // Mfr ref
    $mr_tid = NULL;
    $mr_filled = FALSE;
    if ($has_mfr_ref[$b]) {
      $mr_tid = $m->get('field_manufacturer')->target_id ?? NULL;
      if ($mr_tid) {
        $mr_filled = TRUE;
        $agg[$b]['mfr_ref_filled']++;
        $agg[$b]['mfr_ref_target_counts'][$mr_tid] = ($agg[$b]['mfr_ref_target_counts'][$mr_tid] ?? 0) + 1;
        $all_mfr_target_ids[$mr_tid] = TRUE;
      }
      else {
        $agg[$b]['mfr_ref_empty']++;
      }
    }

    // Both
    if ($mi_filled && $mr_filled) {
      $agg[$b]['both_mfr_filled']++;
    }

    // Material's own field_supplier_item_number (universal — not on material_suppliers).
    $si_filled = FALSE;
    if ($has_supplier_item[$b]) {
      $si_val = $m->get('field_supplier_item_number')->value ?? NULL;
      if ($isFilledString($si_val)) {
        $si_filled = TRUE;
        $agg[$b]['supp_item_filled']++;
      }
    }

    // Any bridge / none bridge
    if ($mi_filled || $mr_filled || $si_filled) {
      $agg[$b]['any_bridge']++;
    }
    else {
      $agg[$b]['none_bridge']++;
    }
  }
}

// Resolve manufacturer titles + detect dangling refs.
$mfr_storage = \Drupal::entityTypeManager()->getStorage('manufacturer');
$mfr_titles = []; // target_id => title|NULL (NULL = dangling)
foreach (array_chunk(array_keys($all_mfr_target_ids), 500) as $batch) {
  $mfrs = $mfr_storage->loadMultiple($batch);
  foreach ($batch as $tid) {
    $mfr_titles[$tid] = isset($mfrs[$tid]) ? (string) $mfrs[$tid]->label() : NULL;
  }
}
// Tally dangling refs per bundle.
foreach ($bundles as $b) {
  foreach ($agg[$b]['mfr_ref_target_counts'] as $tid => $cnt) {
    if (($mfr_titles[$tid] ?? NULL) === NULL) {
      $agg[$b]['mfr_ref_dangling'] += $cnt;
    }
  }
}

// ── Totals across all bundles ──────────────────────────────────────
$totals = [
  'materials_with_mfr_item_field' => 0,
  'materials_with_mfr_ref_field'  => 0,
  'mfr_item_filled' => 0,
  'mfr_ref_filled'  => 0,
  'both_filled'     => 0,
];
foreach ($bundles as $b) {
  if ($has_mfr_item[$b]) $totals['materials_with_mfr_item_field'] += $agg[$b]['total'];
  if ($has_mfr_ref[$b])  $totals['materials_with_mfr_ref_field']  += $agg[$b]['total'];
  $totals['mfr_item_filled'] += $agg[$b]['mfr_item_filled'];
  $totals['mfr_ref_filled']  += $agg[$b]['mfr_ref_filled'];
  $totals['both_filled']     += $agg[$b]['both_mfr_filled'];
}

// ── Render markdown ─────────────────────────────────────────────────
$w("# Manufacturer Item Number Fill Rate Diagnostic");
$w();
$w("```");
$w("GENERATED: $generated");
$w("ENVIRONMENT: $env");
$w("TOTAL MATERIAL ENTITIES (all bundles): $total");
$w("TOTAL MATERIALS WITH field_manufacturer_item_number FIELD: {$totals['materials_with_mfr_item_field']}");
$w("TOTAL MATERIALS WITH field_manufacturer FIELD: {$totals['materials_with_mfr_ref_field']}");
$w("MFR ITEM # FILL RATE (of materials with the field): " . $pct($totals['mfr_item_filled'], $totals['materials_with_mfr_item_field']));
$w("MFR REFERENCE FILL RATE (of materials with the field): " . $pct($totals['mfr_ref_filled'], $totals['materials_with_mfr_ref_field']));
$bf_denom = max($total, 1);
$w("BOTH FILLED (mfr item # AND mfr reference): {$totals['both_filled']} (" . $pct($totals['both_filled'], $bf_denom) . " of catalog)");
$w("```");
$w();

// Section 2
$w("## Section 2 — Per-Bundle Field Presence");
$w();
$w("| Bundle | Total Entries | Has field_manufacturer_item_number? | Has field_manufacturer? |");
$w("|---|---:|:---:|:---:|");

$bundle_sorted_by_total = $bundles;
usort($bundle_sorted_by_total, fn($a, $b) => $agg[$b]['total'] <=> $agg[$a]['total']);
foreach ($bundle_sorted_by_total as $b) {
  $w(sprintf(
    "| `%s` | %d | %s | %s |",
    $b, $agg[$b]['total'],
    $has_mfr_item[$b] ? '✓' : '✗',
    $has_mfr_ref[$b]  ? '✓' : '✗'
  ));
}
$w();

// Section 3
$w("## Section 3 — Per-Bundle Fill Rate (bundles that have field_manufacturer_item_number)");
$w();
$w("| Bundle | Total Entries | Mfr Item # Filled | Mfr Item # Empty | Fill % | Mfr Ref Filled | Mfr Ref Empty | Mfr Ref Fill % |");
$w("|---|---:|---:|---:|---:|---:|---:|---:|");

foreach ($bundle_sorted_by_total as $b) {
  if (!$has_mfr_item[$b]) continue;
  $a = $agg[$b];
  $mre_denom = $has_mfr_ref[$b] ? $a['total'] : 0;
  $w(sprintf(
    "| `%s` | %d | %d | %d | %s | %s | %s | %s |",
    $b,
    $a['total'],
    $a['mfr_item_filled'],
    $a['mfr_item_empty'],
    $pct($a['mfr_item_filled'], $a['total']),
    $has_mfr_ref[$b] ? (string) $a['mfr_ref_filled'] : '—',
    $has_mfr_ref[$b] ? (string) $a['mfr_ref_empty']  : '—',
    $has_mfr_ref[$b] ? $pct($a['mfr_ref_filled'], $a['total']) : '—'
  ));
}
$w();

// Section 4 — Priority Bundle Deep Dive
$priority_bundles = ['irrigation', 'pvc', 'galv', 'brass'];
$w("## Section 4 — Priority Bundle Deep Dive");
$w();
foreach ($priority_bundles as $b) {
  $w("### $b");
  $w();
  if (!isset($agg[$b])) {
    $w("(bundle not found)");
    $w();
    continue;
  }
  $a = $agg[$b];
  $tot = $a['total'];
  $either = $a['mfr_item_filled'] + $a['mfr_ref_filled'] - $a['both_mfr_filled'];
  $neither = $tot - $either;
  $w("```");
  $w("Total entries: $tot");
  $w(sprintf("field_manufacturer_item_number filled: %d (%s)", $a['mfr_item_filled'], $pct($a['mfr_item_filled'], $tot)));
  $w(sprintf("field_manufacturer reference filled:   %d (%s)", $a['mfr_ref_filled'], $pct($a['mfr_ref_filled'], $tot)));
  $w(sprintf("BOTH filled:                           %d (%s)", $a['both_mfr_filled'], $pct($a['both_mfr_filled'], $tot)));
  $w(sprintf("EITHER filled:                         %d (%s)", $either, $pct($either, $tot)));
  $w(sprintf("NEITHER filled:                        %d (%s)", $neither, $pct($neither, $tot)));
  $w();
  $w("Top 10 manufacturers by entry count (from filled mfr references):");
  if (!$a['mfr_ref_target_counts']) {
    $w("  (no manufacturer refs)");
  }
  else {
    arsort($a['mfr_ref_target_counts']);
    $top = array_slice($a['mfr_ref_target_counts'], 0, 10, TRUE);
    foreach ($top as $tid => $cnt) {
      $title = $mfr_titles[$tid] ?? '(dangling ref)';
      $w(sprintf("  %-30s %d", $title . ':', $cnt));
    }
  }
  $w("```");
  $w();
}

// Section 5 — Combined Bridge Coverage
$w("## Section 5 — Combined Bridge Coverage");
$w();
$w("Headline architecture metric: percentage of materials with AT LEAST ONE bridge field populated (`field_manufacturer_item_number` OR `field_manufacturer` ref OR `field_supplier_item_number` on the material itself).");
$w();
$w("| Bundle | Total | Mfr Item # | Mfr Ref | Material's Supplier Item # | Any Bridge | None |");
$w("|---|---:|---:|---:|---:|---:|---:|");
foreach ($priority_bundles as $b) {
  if (!isset($agg[$b])) continue;
  $a = $agg[$b];
  $tot = $a['total'];
  $w(sprintf(
    "| `%s` | %d | %d (%s) | %d (%s) | %d (%s) | %d (%s) | %d (%s) |",
    $b, $tot,
    $a['mfr_item_filled'], $pct($a['mfr_item_filled'], $tot),
    $a['mfr_ref_filled'],  $pct($a['mfr_ref_filled'], $tot),
    $a['supp_item_filled'], $pct($a['supp_item_filled'], $tot),
    $a['any_bridge'], $pct($a['any_bridge'], $tot),
    $a['none_bridge'], $pct($a['none_bridge'], $tot)
  ));
}
$w();

// Section 6 — Anomaly Notes
$w("## Section 6 — Anomaly Notes");
$w();

// 6a: dangling refs
$w("### Dangling manufacturer references (target_id points to a non-existent manufacturer entity)");
$w();
$any_dangling = FALSE;
foreach ($bundle_sorted_by_total as $b) {
  if ($agg[$b]['mfr_ref_dangling'] > 0) {
    $w(sprintf("- `%s`: %d material(s) reference a missing manufacturer", $b, $agg[$b]['mfr_ref_dangling']));
    $any_dangling = TRUE;
  }
}
if (!$any_dangling) {
  $w("_(none — all populated manufacturer refs resolve to existing entities)_");
}
$w();

// 6b: garbage values
$w("### Garbage / sentinel values in field_manufacturer_item_number");
$w();
$w("Counts strings that are pure punctuation/whitespace or common 'no-value' sentinels (`n/a`, `none`, `tbd`, `unknown`, `?`, `x` — case-insensitive). These count as filled in the totals above; consider treating them as empty if the ingest pipeline matches on this field.");
$w();
$any_garbage = FALSE;
foreach ($bundle_sorted_by_total as $b) {
  if ($agg[$b]['mfr_item_garbage'] > 0) {
    $any_garbage = TRUE;
    $w(sprintf("**`%s`** — %d garbage value(s). Examples:", $b, $agg[$b]['mfr_item_garbage']));
    foreach ($agg[$b]['garbage_examples'] as $ex) {
      $val = str_replace('|', '\\|', $ex['value']);
      $w(sprintf("- id %d: `%s`", $ex['id'], $val));
    }
    $w();
  }
}
if (!$any_garbage) {
  $w("_(none detected)_");
  $w();
}

// 6c: low-fill bundles (both fields present, <25% fill on mfr item #)
$w("### Bundles with both manufacturer fields present but mfr-item-# fill rate below 25%");
$w();
$w("Suggests the fields were added but the data was never backfilled — these bundles need bootstrap before they can participate in mfr-based matching.");
$w();
$any_low = FALSE;
foreach ($bundle_sorted_by_total as $b) {
  if (!$has_mfr_item[$b] || !$has_mfr_ref[$b]) continue;
  if ($agg[$b]['total'] === 0) continue;
  $fill = ($agg[$b]['mfr_item_filled'] / $agg[$b]['total']) * 100;
  if ($fill < 25) {
    $any_low = TRUE;
    $w(sprintf("- `%s`: %d entries, mfr item # filled on %d (%s)",
      $b, $agg[$b]['total'], $agg[$b]['mfr_item_filled'], $pct($agg[$b]['mfr_item_filled'], $agg[$b]['total'])
    ));
  }
}
if (!$any_low) {
  $w("_(none — every bundle with the fields is at >=25% mfr-item-# fill)_");
}
$w();

// ── Save file ──────────────────────────────────────────────────────
$content = implode("\n", $out) . "\n";
$date = date('Y-m-d');
$rel_path = "__BOS_AI/Reports/manufacturer_fill_report_$date.md";
$abs_path = realpath(DRUPAL_ROOT . '/../__BOS_AI/Reports') . '/manufacturer_fill_report_' . $date . '.md';

file_put_contents($abs_path, $content);

echo $content;
echo "\n---\nReport saved to:\n  $abs_path\n";
