<?php

/**
 * One-off repair for the 2026-08-28 material-list import round-trip bug.
 *
 * The exported CSV header (identifier, material_name, supplier_item_number,
 * quantity, unit_cost) tripped the old parser's \b-boundary detection, so every
 * imported line's unit cost was read from the supplier_item_number column
 * (e.g. "429-010" -> $429,010). Materials and quantities imported correctly;
 * only field_material_cost is wrong.
 *
 * This script reads the same CSV, finds the wo_material_list_item rows that hold
 * the garbage cost (cost == the SKU stripped to digits), and resets each line's
 * field_material_cost to the correct CSV value, re-saving so subtotals recompute.
 *
 * Usage (dry-run):   drush scr web/scripts/fix_import_costs.php
 * Apply:             FIX_IMPORT_APPLY=1 drush scr web/scripts/fix_import_costs.php
 * CSV path override:  FIX_IMPORT_CSV=/home/brookstoneadmin/import_fix.csv
 */

$csv = getenv('FIX_IMPORT_CSV') ?: '/home/brookstoneadmin/import_fix.csv';
$apply = (bool) getenv('FIX_IMPORT_APPLY');

if (!is_readable($csv)) {
  echo "CSV not readable: $csv\n";
  return;
}

// Parse the export CSV: col0 material id, col2 supplier sku, col4 unit cost.
$map = [];
$h = fopen($csv, 'r');
$first = TRUE;
while (($c = fgetcsv($h)) !== FALSE) {
  if ($first) { $first = FALSE; continue; }
  $mid = (int) trim((string) ($c[0] ?? ''));
  if ($mid <= 0) { continue; }
  $sku = trim((string) ($c[2] ?? ''));
  $correct = trim((string) ($c[4] ?? ''));
  $garbage = preg_replace('/[^0-9.]/', '', $sku);
  $map[$mid] = ['sku' => $sku, 'correct' => $correct, 'garbage' => $garbage];
}
fclose($h);
printf("CSV rows: %d\n", count($map));

$storage = \Drupal::entityTypeManager()->getStorage('wo_material_list_item');
$ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('field_parts_used', array_keys($map), 'IN')
  ->execute();

$byList = [];
foreach ($storage->loadMultiple($ids) as $item) {
  $mid = (int) $item->get('field_parts_used')->target_id;
  $listId = (int) $item->get('field_list_id')->target_id;
  $cur = (string) $item->get('field_material_cost')->value;
  $info = $map[$mid];
  $isGarbage = $info['garbage'] !== '' && (float) $cur == (float) $info['garbage'] && (float) $cur != (float) $info['correct'];
  $byList[$listId][] = [
    'item' => $item, 'mid' => $mid, 'cur' => $cur,
    'correct' => $info['correct'], 'garbage' => $info['garbage'], 'bad' => $isGarbage,
  ];
}

echo "\nLine items referencing these materials, grouped by list:\n";
$fixed = 0;
foreach ($byList as $listId => $lines) {
  $bad = array_filter($lines, fn($l) => $l['bad']);
  printf("\n  List %d — %d lines, %d with garbage cost:\n", $listId, count($lines), count($bad));
  foreach ($lines as $l) {
    $tag = $l['bad'] ? 'FIX ' : '    ';
    printf("    %s mid=%-7d cur=%-10s correct=%-8s (sku garbage=%s)\n",
      $tag, $l['mid'], $l['cur'], $l['correct'], $l['garbage']);
    if ($l['bad'] && $apply) {
      $l['item']->set('field_material_cost', $l['correct']);
      $l['item']->save();
      $fixed++;
    }
  }
}

echo "\n" . ($apply ? "APPLIED — fixed $fixed line(s).\n" : "DRY RUN — set FIX_IMPORT_APPLY=1 to apply.\n");
