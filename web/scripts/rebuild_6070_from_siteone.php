<?php

/**
 * Rebuild WO material list 6070 from a SiteOne invoice CSV.
 *
 * Clears the list and re-creates it from the SiteOne invoice: correct material,
 * quantity, and the REAL "Your Price" as cost; stamps the SiteOne SKU and the
 * SiteOne supplier (#18 Pioneer - SiteOne) on each line; and "learns" each SKU
 * onto the material_suppliers catalog so future SiteOne imports auto-match by SKU.
 *
 * Matching = OVERRIDES (hand-verified corrections) -> crosswalk (dev export) ->
 * error. Refuses to run if any row is unresolved.
 *
 * Env: NEW_CSV=/home/brookstoneadmin/siteone_6070.csv
 *      CROSSWALK_CSV=/home/brookstoneadmin/crosswalk_6068.csv
 *      REBUILD_APPLY=1   (default is dry-run)
 */

$LIST_ID = 6070;
$SUPPLIER_ID = 18; // Pioneer - SiteOne.
$newCsv = getenv('NEW_CSV') ?: '/home/brookstoneadmin/siteone_6070.csv';
$xwalkCsv = getenv('CROSSWALK_CSV') ?: '/home/brookstoneadmin/crosswalk_6068.csv';
$apply = (bool) getenv('REBUILD_APPLY');
// SKUs to leave out of the rebuild (office adds/verifies them manually).
$SKIP = array_filter(array_map('strtoupper', array_map('trim',
  explode(',', (string) getenv('REBUILD_SKIP')))));

// Hand-verified corrections (SiteOne SKU -> BOS material id) that OVERRIDE the
// crosswalk. Elbows: 417=45deg, 406=90deg; 412-007 is a street elbow, not a tee.
$OVERRIDES = [
  '535-200K' => 25747,          // 1 in. Brass Tee FIPT
  'FLEX08001000100' => 25097,   // 1 in. x 100 ft. HDPE poly pipe
  '417-010' => 24591,           // 1 in. 45 deg elbow
  '417-012' => 24592,           // 1-1/4 in. 45 deg elbow
  '417-015' => 24593,           // 1-1/2 in. 45 deg elbow
  '406-010' => 24532,           // 1 in. 90 deg elbow
  '406-012' => 24533,           // 1-1/4 in. 90 deg elbow
  '406-015' => 24534,           // 1-1/2 in. 90 deg elbow
  '412-007' => 24571,           // 3/4 in. 90 deg street elbow MIPT x FIPT
];

$etm = \Drupal::entityTypeManager();
$matStorage = $etm->getStorage('material');
$itemStorage = $etm->getStorage('wo_material_list_item');
$importer = \Drupal::service('wo_material_list_management.import');

// Crosswalk from the dev export.
$xwalk = [];
$h = fopen($xwalkCsv, 'r'); $first = TRUE;
while (($c = fgetcsv($h)) !== FALSE) {
  if ($first) { $first = FALSE; continue; }
  $id = (int) trim((string) ($c[0] ?? ''));
  $sku = strtoupper(trim((string) ($c[2] ?? '')));
  if ($id && $sku !== '') { $xwalk[$sku] = $id; }
}
fclose($h);

// Parse SiteOne invoice.
$rows = [];
$h = fopen($newCsv, 'r'); $first = TRUE;
while (($c = fgetcsv($h)) !== FALSE) {
  if ($first) { $first = FALSE; continue; }
  $sku = trim((string) ($c[2] ?? ''));
  if ($sku === '') { continue; }
  $rows[] = [
    'sku' => $sku,
    'desc' => trim((string) ($c[3] ?? '')),
    'price' => preg_replace('/[^0-9.]/', '', (string) ($c[5] ?? '')),
    'qty' => (int) preg_replace('/[^0-9]/', '', (string) ($c[6] ?? '')),
  ];
}
fclose($h);

// Resolve every row.
$plan = []; $unresolved = [];
foreach ($rows as $r) {
  $skuU = strtoupper($r['sku']);
  $mid = $OVERRIDES[$skuU] ?? $xwalk[$skuU] ?? NULL;
  $src = isset($OVERRIDES[$skuU]) ? 'override' : (isset($xwalk[$skuU]) ? 'crosswalk' : 'NONE');
  $label = $mid ? (($m = $matStorage->load($mid)) ? $m->label() : '(missing!)') : '';
  if (!$mid || $label === '(missing!)') { $unresolved[] = $r['sku']; }
  $plan[] = $r + ['mid' => $mid, 'src' => $src, 'label' => $label];
}

echo "== Rebuild plan for list $LIST_ID (supplier #$SUPPLIER_ID) ==\n";
$total = 0.0; $skipped = 0;
foreach ($plan as $p) {
  $skip = in_array(strtoupper($p['sku']), $SKIP, TRUE);
  $line = $p['qty'] * (float) $p['price'];
  if ($skip) { $skipped++; } else { $total += $line; }
  printf("  [%-9s] %-16s x%-4d @%-7s = %-9.2f -> #%-6s %s\n",
    $skip ? 'SKIP' : $p['src'], $p['sku'], $p['qty'], $p['price'], $line, $p['mid'] ?: '???', substr($p['label'], 0, 44));
}
printf("\nRows: %d | overrides: %d | skipped: %d | unresolved: %d\n",
  count($plan), count($OVERRIDES), $skipped, count($unresolved));
printf("Cost-basis total (excl. skipped): $%.2f | with 30%% markup: $%.2f\n", $total, $total * 1.3);

if ($unresolved) {
  echo "REFUSING: unresolved SKUs: " . implode(', ', $unresolved) . "\n";
  return;
}

if (!$apply) {
  echo "\nDRY RUN — set REBUILD_APPLY=1 to clear list $LIST_ID and rebuild.\n";
  return;
}

// APPLY: clear then rebuild via the importer's public import() (handles cost,
// SKU, supplier stamp, and SKU "learn" onto material_suppliers).
$existing = $itemStorage->getQuery()->accessCheck(FALSE)->condition('field_list_id', $LIST_ID)->execute();
printf("\nDeleting %d existing line(s)...\n", count($existing));
$itemStorage->delete($itemStorage->loadMultiple($existing));

$importRows = [];
foreach ($plan as $p) {
  if (in_array(strtoupper($p['sku']), $SKIP, TRUE)) {
    printf("  Skipping %s (manual add).\n", $p['sku']);
    continue;
  }
  $importRows[] = [
    'material_id' => $p['mid'],
    'quantity' => $p['qty'],
    'unit_cost' => $p['price'],
    'supplier_item_number' => $p['sku'],
    'include' => TRUE,
  ];
}
$res = $importer->import($LIST_ID, $importRows, $SUPPLIER_ID, TRUE);
printf("Import result: created=%d merged=%d skipped=%d links_created=%d links_updated=%d\n",
  $res['created'], $res['merged'], $res['skipped'], $res['links_created'], $res['links_updated']);

// Verify subtotals.
$ids = $itemStorage->getQuery()->accessCheck(FALSE)->condition('field_list_id', $LIST_ID)->execute();
$sub = 0.0; $subM = 0.0;
foreach ($itemStorage->loadMultiple($ids) as $it) {
  $sub += (float) $it->get('field_subtotal')->value;
  $subM += (float) $it->get('field_subtotal_w_markup')->value;
}
printf("New list: %d lines | Σ subtotal = $%.2f | Σ w/markup = $%.2f\n", count($ids), $sub, $subM);
