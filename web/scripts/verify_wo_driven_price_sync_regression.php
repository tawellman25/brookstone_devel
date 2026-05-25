<?php

declare(strict_types=1);

/**
 * Phase 3.6 — WO-driven PriceSyncService regression sanity.
 *
 * Confirms that the existing WO-entry price-sync path (PriceSyncService::process)
 * continues to behave identically after Phase 3.6's extensions
 * (new ingestRow() method + DB transaction wrapper + PriceHistoryWriter
 * return-type widening from bool to ?int).
 *
 * Tests by directly invoking PriceSyncService::process() against a
 * fixture wo_material_list_item — avoids constructing a full WO sign-
 * off flow. The internal decision tree it exercises is the same one
 * that fires from hook_entity_insert/update in production.
 *
 * Usage:
 *   ddev drush scr web/scripts/verify_wo_driven_price_sync_regression.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

$etm        = \Drupal::entityTypeManager();
$priceSync  = \Drupal::service('wo_material_price_sync.price_sync');
$db         = \Drupal::database();

$cleanup = ['rows' => [], 'lists' => [], 'wos' => [], 'materials' => [], 'suppliers' => [], 'links' => [], 'history' => []];

$results = [];

try {
  // ── Fixtures ───────────────────────────────────────────────────
  $sup = $etm->getStorage('supplier')->create([
    'type' => 'supplier', 'field_supplier_name' => 'P36-WOReg-Sup', 'uid' => 1,
  ]);
  $sup->save();
  $cleanup['suppliers'][] = (int) $sup->id();

  // Material with an existing material_suppliers link at 30.00.
  $mat = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_size' => '', 'field_name' => 'P36-WOReg-Mat',
    'field_cost_integer' => '30.00',
  ]);
  $mat->save();
  $cleanup['materials'][] = (int) $mat->id();

  $link = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier', 'title' => 'P36-WOReg-Link', 'uid' => 1,
    'field_material' => $mat->id(),
    'field_supplier' => $sup->id(),
    'field_supplier_unit_cost' => '30.00',
    'field_price_effective_date' => '2026-01-01',
    'field_price_source' => 'invoice',
  ]);
  $link->save();
  $cleanup['links'][] = (int) $link->id();

  // WO + list + line item that crew "submitted" with a different cost.
  $wo = $etm->getStorage('work_order')->create([
    'type' => 'sprinkler_repair',  // any non-Complete-locked WO bundle works
    'uid' => 1,
    'field_invoiced' => 0,
  ]);
  $wo->save();
  $cleanup['wos'][] = (int) $wo->id();

  $list = $etm->getStorage('wo_material_list')->create([
    'type' => 'material_list', 'uid' => 1, 'title' => 'P36-WOReg-List',
    'field_work_order' => $wo->id(),
  ]);
  $list->save();
  $cleanup['lists'][] = (int) $list->id();

  // Crew creates the line at catalog cost 30.00 first — no price change,
  // so the insert hook is a quiet no-op (this is the pre-existing
  // behavior: hasPriceChanged sees isNew=FALSE post-save and no
  // $entity->original on insert, so process() short-circuits). Then
  // the crew edits the cost to 27.00 — that's the UPDATE hook firing
  // with $entity->original.cost = 30 and the new cost = 27. That's
  // the production path price-sync actually exercises.
  $row = $etm->getStorage('wo_material_list_item')->create([
    'type' => 'items', 'uid' => 1,
    'field_list_id' => $list->id(),
    'field_parts_used' => $mat->id(),
    'field_material_cost' => '30.00',
    'field_purchased_supplier' => $sup->id(),
    'field_quantity_used' => 1,
  ]);
  $row->save();
  $cleanup['rows'][] = (int) $row->id();

  // Reload and "edit" the cost — fires the update hook.
  $row = $etm->getStorage('wo_material_list_item')->load($row->id());
  $row->set('field_material_cost', '27.00');
  $row->save();

  // Confirm the catalog updated to 27.00.
  $link = $etm->getStorage('material_suppliers')->load($link->id());
  $costAfter = (float) $link->get('field_supplier_unit_cost')->value;

  // Confirm a history entry was written with source='wo_entry'.
  $histIds = $etm->getStorage('material_price_history')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_material', $mat->id())
    ->condition('field_supplier', $sup->id())
    ->condition('field_source', 'wo_entry')
    ->sort('id', 'DESC')
    ->range(0, 1)
    ->execute();
  $histId = $histIds ? (int) reset($histIds) : 0;
  if ($histId) { $cleanup['history'][] = $histId; }
  $hist = $histId ? $etm->getStorage('material_price_history')->load($histId) : NULL;

  $checks = [
    'catalog_updated_to_27' => abs($costAfter - 27.00) < 0.01,
    'history_written' => $hist !== NULL,
    'history_source_wo_entry' => $hist && $hist->get('field_source')->value === 'wo_entry',
    'history_status_applied' => $hist && $hist->get('field_status')->value === 'applied',
    'history_wo_reference_set' => $hist && (int) $hist->get('field_wo_reference')->target_id === (int) $wo->id(),
    'history_NO_ingest_batch_ref' => $hist && $hist->get('field_ingest_batch')->isEmpty(),
    'history_old_cost_30' => $hist && abs((float) $hist->get('field_old_cost')->value - 30.00) < 0.01,
    'history_new_cost_27' => $hist && abs((float) $hist->get('field_new_cost')->value - 27.00) < 0.01,
  ];
  echo "WO-driven price sync regression check:\n";
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['wo_driven_regression'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';
}
finally {
  echo "\nCleanup:\n";
  foreach ($cleanup['rows'] as $id)     { $e = $etm->getStorage('wo_material_list_item')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['lists'] as $id)    { $e = $etm->getStorage('wo_material_list')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['wos'] as $id)      { $e = $etm->getStorage('work_order')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['history'] as $id)  { $e = $etm->getStorage('material_price_history')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['links'] as $id)    { $e = $etm->getStorage('material_suppliers')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['materials'] as $id){ $e = $etm->getStorage('material')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['suppliers'] as $id){ $e = $etm->getStorage('supplier')->load($id); if ($e) { $e->delete(); } }
  echo "  done.\n";
}

echo "\n========== SUMMARY ==========\n";
$overall = 'PASS';
foreach ($results as $k => $v) {
  printf("  %-32s %s\n", $k, $v);
  if (!str_starts_with($v, 'PASS')) { $overall = 'FAIL'; }
}
echo "----------------------------\n";
echo "  OVERALL                          $overall\n";
echo "=============================\n";
