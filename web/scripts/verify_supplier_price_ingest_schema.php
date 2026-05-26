<?php

declare(strict_types=1);

/**
 * Phase 3.1 verification — round-trip each of the three new entity
 * types: CREATE → LOAD → ASSERT FIELD VALUES → DELETE. PASS/FAIL per
 * entity type plus an overall result.
 *
 * Validates that the schema is functionally usable, not just that the
 * config landed.
 *
 * Usage:
 *   ddev drush scr web/scripts/verify_supplier_price_ingest_schema.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

$etm = \Drupal::entityTypeManager();
$results = [];
$cleanup_ids = [];

// Find any supplier to reference (need at least one for entity_reference
// fields to be valid).
$supplier_ids = \Drupal::entityQuery('supplier')->accessCheck(FALSE)->range(0, 1)->execute();
$test_supplier_id = $supplier_ids ? (int) reset($supplier_ids) : NULL;
if (!$test_supplier_id) {
  echo "ABORT: no supplier entities exist in the DB to reference.\n";
  exit(1);
}
echo "Using supplier id=$test_supplier_id for entity-reference fields\n\n";

// ── 1. supplier_price_ingest_batch ─────────────────────────────────
echo "=== supplier_price_ingest_batch ===\n";
try {
  $batch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type'                   => 'batch',
    'title'                  => 'TEST BATCH — phase 3.1 verify',
    'uid'                    => 1,
    'field_supplier'         => $test_supplier_id,
    'field_source_filename'  => 'test_phase31.csv',
    'field_uploaded_by'      => 1,
    'field_uploaded_on'      => date('Y-m-d\TH:i:s'),
    'field_status'           => 'pending_dry_run',
    'field_row_count_total'  => 42,
  ]);
  $batch->save();
  $batch_id = (int) $batch->id();
  $cleanup_ids['supplier_price_ingest_batch'] = $batch_id;
  echo "  CREATE: id=$batch_id\n";

  $loaded = $etm->getStorage('supplier_price_ingest_batch')->load($batch_id);
  if (!$loaded) throw new \RuntimeException("LOAD returned NULL");
  $checks = [
    'title'                 => $loaded->label() === 'TEST BATCH — phase 3.1 verify',
    'field_supplier'        => (int) $loaded->get('field_supplier')->target_id === $test_supplier_id,
    'field_source_filename' => $loaded->get('field_source_filename')->value === 'test_phase31.csv',
    'field_status'          => $loaded->get('field_status')->value === 'pending_dry_run',
    'field_row_count_total' => (int) $loaded->get('field_row_count_total')->value === 42,
  ];
  foreach ($checks as $name => $ok) {
    echo "  ASSERT $name: " . ($ok ? 'PASS' : 'FAIL') . "\n";
  }
  $all = !in_array(FALSE, $checks, TRUE);
  $results['supplier_price_ingest_batch'] = $all ? 'PASS' : 'FAIL';
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . $e->getMessage() . "\n";
  $results['supplier_price_ingest_batch'] = 'FAIL';
}

// ── 2. supplier_price_ingest_row ────────────────────────────────────
echo "\n=== supplier_price_ingest_row ===\n";
try {
  $row = $etm->getStorage('supplier_price_ingest_row')->create([
    'type'              => 'row',
    'title'             => 'TEST ROW — phase 3.1 verify',
    'uid'               => 1,
    'field_batch'       => $batch_id ?? 0,
    'field_row_number'  => 1,
    'field_raw_data'    => json_encode(['sku' => 'TEST-001', 'price' => '9.99']),
    'field_supplier_sku'=> 'TEST-001',
    'field_unit_cost'   => '9.99',
    'field_cost_uom'    => 'each',
    'field_match_tier'  => 'tier_1_mfr',
    'field_row_status'  => 'dry_run',
  ]);
  $row->save();
  $row_id = (int) $row->id();
  $cleanup_ids['supplier_price_ingest_row'] = $row_id;
  echo "  CREATE: id=$row_id\n";

  $loaded = $etm->getStorage('supplier_price_ingest_row')->load($row_id);
  if (!$loaded) throw new \RuntimeException("LOAD returned NULL");
  $checks = [
    'field_batch'      => (int) $loaded->get('field_batch')->target_id === ($batch_id ?? 0),
    'field_row_number' => (int) $loaded->get('field_row_number')->value === 1,
    'field_raw_data'   => str_contains($loaded->get('field_raw_data')->value, 'TEST-001'),
    'field_cost_uom'   => $loaded->get('field_cost_uom')->value === 'each',
    'field_match_tier' => $loaded->get('field_match_tier')->value === 'tier_1_mfr',
    'field_row_status' => $loaded->get('field_row_status')->value === 'dry_run',
  ];
  foreach ($checks as $name => $ok) {
    echo "  ASSERT $name: " . ($ok ? 'PASS' : 'FAIL') . "\n";
  }
  $all = !in_array(FALSE, $checks, TRUE);
  $results['supplier_price_ingest_row'] = $all ? 'PASS' : 'FAIL';
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . $e->getMessage() . "\n";
  $results['supplier_price_ingest_row'] = 'FAIL';
}

// ── 3. supplier_ingest_config ───────────────────────────────────────
echo "\n=== supplier_ingest_config ===\n";
try {
  $cfg = $etm->getStorage('supplier_ingest_config')->create([
    'type'                 => 'config',
    'title'                => 'TEST CONFIG — phase 3.1 verify',
    'uid'                  => 1,
    'field_supplier'       => $test_supplier_id,
    'field_active'         => 1,
    'field_default_cost_uom' => 'each',
    'field_fuzzy_threshold_high' => '92.50',
    'field_fuzzy_threshold_med'  => '75.00',
    // Schema check only — but the Phase 3.2 presave validator runs on save,
    // so we have to give it a shape it'll accept: source_columns wrapper,
    // ≥ 1 identifier mapped, field_unit_cost mapped.
    'field_column_mapping' => json_encode([
      'source_columns' => ['SKU' => 'field_supplier_sku', 'Price' => 'field_unit_cost'],
      'header_row' => 1,
    ]),
    'field_bundle_policy'  => json_encode(['irrigation' => 'matched_only', 'pvc' => 'both']),
  ]);
  $cfg->save();
  $cfg_id = (int) $cfg->id();
  $cleanup_ids['supplier_ingest_config'] = $cfg_id;
  echo "  CREATE: id=$cfg_id\n";

  $loaded = $etm->getStorage('supplier_ingest_config')->load($cfg_id);
  if (!$loaded) throw new \RuntimeException("LOAD returned NULL");
  $checks = [
    'field_supplier'             => (int) $loaded->get('field_supplier')->target_id === $test_supplier_id,
    'field_active'               => (int) $loaded->get('field_active')->value === 1,
    'field_default_cost_uom'     => $loaded->get('field_default_cost_uom')->value === 'each',
    'field_fuzzy_threshold_high' => (float) $loaded->get('field_fuzzy_threshold_high')->value === 92.5,
    'field_fuzzy_threshold_med'  => (float) $loaded->get('field_fuzzy_threshold_med')->value === 75.0,
    'field_column_mapping'       => str_contains($loaded->get('field_column_mapping')->value, 'field_supplier_sku'),
  ];
  foreach ($checks as $name => $ok) {
    echo "  ASSERT $name: " . ($ok ? 'PASS' : 'FAIL') . "\n";
  }
  $all = !in_array(FALSE, $checks, TRUE);
  $results['supplier_ingest_config'] = $all ? 'PASS' : 'FAIL';
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . $e->getMessage() . "\n";
  $results['supplier_ingest_config'] = 'FAIL';
}

// ── Cleanup ─────────────────────────────────────────────────────────
echo "\n=== Cleanup ===\n";
foreach ($cleanup_ids as $type => $id) {
  try {
    $ent = $etm->getStorage($type)->load($id);
    if ($ent) {
      $ent->delete();
      $still = $etm->getStorage($type)->load($id);
      if ($still) {
        echo "  $type id=$id: DELETE call returned but entity still loadable — FAIL\n";
        $results[$type] = 'FAIL';
      }
      else {
        echo "  $type id=$id: DELETE confirmed\n";
      }
    }
  }
  catch (\Throwable $e) {
    echo "  $type id=$id: DELETE EXCEPTION " . $e->getMessage() . "\n";
    $results[$type] = 'FAIL';
  }
}

// ── Summary ─────────────────────────────────────────────────────────
echo "\n========== SUMMARY ==========\n";
$overall = 'PASS';
foreach ($results as $type => $r) {
  printf("  %-32s %s\n", $type, $r);
  if ($r !== 'PASS') $overall = 'FAIL';
}
echo "----------------------------\n";
echo "  OVERALL                          $overall\n";
echo "=============================\n";
exit($overall === 'PASS' ? 0 : 1);
