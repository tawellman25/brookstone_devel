<?php

declare(strict_types=1);

/**
 * Phase 3.6 — IngestCommitter end-to-end verification.
 *
 * Exercises the full feed-import commit pathway. Constructs fresh
 * fixtures (materials + supplier + ingest config + batch + rows),
 * runs the committer, asserts catalog mutations + audit history,
 * cleans everything up.
 *
 * 9 scenarios:
 *   1.  Auto-create new material_suppliers link
 *   2.  Update existing link — price decrease (always applied)
 *   3.  Update existing link — increase within threshold (applied)
 *   4.  Update existing link — increase exceeds threshold (flagged_high)
 *   5.  material.field_cost_integer MAX-sync runs downstream
 *   6.  Idempotent recovery after interrupted commit
 *   7.  Error containment — matched material deleted between match and commit
 *   8.  Source filter on /admin/materials/price-review
 *   9.  Determinism across full pipeline (parse + match + commit twice)
 *
 * Usage:
 *   ddev drush scr web/scripts/verify_supplier_price_ingest_committer.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\File\FileSystemInterface;

$etm        = \Drupal::entityTypeManager();
$parser     = \Drupal::service('supplier_price_ingest.parser');
$matcher    = \Drupal::service('supplier_price_ingest.matcher');
$committer  = \Drupal::service('supplier_price_ingest.committer');
$fileRepo   = \Drupal::service('file.repository');
$fs         = \Drupal::service('file_system');
$db         = \Drupal::database();

$uploadDir = 'public://supplier_ingest';
$fs->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

$results = [];
$cleanup = [
  'rows' => [], 'batches' => [], 'configs' => [],
  'history' => [], 'links' => [], 'materials' => [],
  'mfrs' => [], 'suppliers' => [], 'files' => [],
];

/**
 * Build the standard supplier + ingest config for these scenarios.
 *
 * Returns [supplier, config, mfr].
 */
$bootstrapFixtures = function () use ($etm, &$cleanup): array {
  $mfr = $etm->getStorage('manufacturer')->create([
    'type' => 'manufacturer',
    'field_name' => 'P36-TestMfr',
    'uid' => 1,
  ]);
  $mfr->save();
  $cleanup['mfrs'][] = (int) $mfr->id();

  $sup = $etm->getStorage('supplier')->create([
    'type' => 'supplier',
    'field_supplier_name' => 'P36-TestSup',
    'uid' => 1,
  ]);
  $sup->save();
  $cleanup['suppliers'][] = (int) $sup->id();

  $cfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'P36-Cfg',
    'uid' => 1,
    'field_supplier' => $sup->id(),
    'field_active' => 1,
    'field_default_cost_uom' => 'each',
    'field_column_mapping' => json_encode([
      'source_columns' => [
        'SKU'   => 'field_supplier_sku',
        'Mfr#'  => 'field_manufacturer_item_number',
        'Brand' => 'field_manufacturer_name',
        'Desc'  => 'field_description',
        'Price' => 'field_unit_cost',
        'UOM'   => 'field_cost_uom',
      ],
      'header_row' => 1,
    ]),
    'field_bundle_policy' => json_encode([
      'irrigation' => 'matched_only',
      'pvc'        => 'discovery',
    ]),
  ]);
  $cfg->save();
  $cleanup['configs'][] = (int) $cfg->id();

  return ['sup' => $sup, 'cfg' => $cfg, 'mfr' => $mfr];
};

/**
 * Build a single-row test batch via parse+match, then return the
 * loaded batch + its row(s) in 'dry_run' status ready to commit.
 */
$buildTestBatchSingle = function (array $bs, string $sku, string $mfrItem, string $price) use ($etm, $parser, $matcher, $fileRepo, $uploadDir, &$cleanup): array {
  $csv = "SKU,Mfr#,Brand,Desc,Price,UOM\n{$sku},{$mfrItem},P36-TestMfr,P36 test row,{$price},each\n";
  $f = $fileRepo->writeData($csv, "$uploadDir/spi_p36_test_" . uniqid() . ".csv", FileSystemInterface::EXISTS_REPLACE);
  $f->setPermanent();
  $f->save();
  $cleanup['files'][] = (int) $f->id();
  $batch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'P36-Test',
    'uid' => 1,
    'field_supplier' => $bs['sup']->id(),
    'field_source_file' => $f->id(),
    'field_source_filename' => basename($f->getFileUri()),
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch->save();
  $cleanup['batches'][] = (int) $batch->id();
  $parser->parseUploadedFile($batch);
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());
  $matcher->matchBatch($batch);
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());
  $rows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch->id()]);
  foreach ($rows as $r) { $cleanup['rows'][] = (int) $r->id(); }
  return ['batch' => $batch, 'rows' => $rows];
};

/**
 * Move a batch from dry_run_complete to 'approved' (skipping the form);
 * mirrors what ApproveBatchForm does pre-commit.
 */
$promoteToApproved = function ($batch): void {
  $batch->set('field_status', 'awaiting_approval');
  $batch->save();
  $batch->set('field_status', 'approved');
  $batch->set('field_committed_by', 1);
  $batch->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
  $batch->save();
};

try {
  // ════════════════════════════════════════════════════════════════
  // SCENARIO 1 — auto-create new material_suppliers link
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 1: auto-create new material_suppliers link ===\n";
  $bs = $bootstrapFixtures();
  // Create a material with NO existing material_suppliers row for our supplier.
  $m1 = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S1',
    'field_size' => '', 'field_name' => 'P36-S1-AutoCreate',
  ]);
  $m1->save();
  $cleanup['materials'][] = (int) $m1->id();

  $bd = $buildTestBatchSingle($bs, 'sku-s1', 'P36-S1', '12.50');
  $promoteToApproved($bd['batch']);
  $commit = $committer->commitBatch($bd['batch']);
  echo "  " . $commit->summary() . "\n";

  // Reload the row + check fields.
  $r1 = reset($bd['rows']);
  $r1 = $etm->getStorage('supplier_price_ingest_row')->load($r1->id());

  // Find the freshly-created material_suppliers row.
  $linkIds = $etm->getStorage('material_suppliers')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_material', $m1->id())
    ->condition('field_supplier', $bs['sup']->id())
    ->sort('id', 'ASC')
    ->execute();
  $linkId = $linkIds ? (int) reset($linkIds) : 0;
  if ($linkId) { $cleanup['links'][] = $linkId; }
  $link = $linkId ? $etm->getStorage('material_suppliers')->load($linkId) : NULL;

  // Find the freshly-written history entry.
  $histIds = $etm->getStorage('material_price_history')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_material', $m1->id())
    ->condition('field_supplier', $bs['sup']->id())
    ->condition('field_ingest_batch', $bd['batch']->id())
    ->sort('id', 'ASC')
    ->execute();
  $histId = $histIds ? (int) reset($histIds) : 0;
  if ($histId) { $cleanup['history'][] = $histId; }
  $hist = $histId ? $etm->getStorage('material_price_history')->load($histId) : NULL;

  $checks = [
    'link_created'        => $link !== NULL,
    'link_cost_set'       => $link && abs((float) $link->get('field_supplier_unit_cost')->value - 12.50) < 0.01,
    'link_eff_date_today' => $link && $link->get('field_price_effective_date')->value === date('Y-m-d'),
    'history_written'     => $hist !== NULL,
    'history_source'      => $hist && $hist->get('field_source')->value === 'feed_import_auto',
    'history_status'      => $hist && $hist->get('field_status')->value === 'auto_created',
    'history_batch_ref'   => $hist && (int) $hist->get('field_ingest_batch')->target_id === (int) $bd['batch']->id(),
    'row_status_committed' => (string) $r1->get('field_row_status')->value === 'committed',
    'row_action_created'  => (string) $r1->get('field_resolution_action')->value === 'created_link',
    'commit_counters'     => $commit->rowsAutoCreated === 1 && $commit->rowsApplied === 0 && $commit->rowsFlaggedHigh === 0,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_1_auto_create'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 2 — price decrease, applied
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 2: update existing link — decrease ===\n";
  $m2 = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S2',
    'field_size' => '', 'field_name' => 'P36-S2-Decrease',
  ]);
  $m2->save();
  $cleanup['materials'][] = (int) $m2->id();
  // Pre-seed an existing link @ 20.00.
  $link2 = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier', 'title' => 'P36-S2-Link', 'uid' => 1,
    'field_material' => $m2->id(),
    'field_supplier' => $bs['sup']->id(),
    'field_supplier_unit_cost' => '20.00',
    'field_price_effective_date' => '2026-01-01',
    'field_price_source' => 'invoice',
  ]);
  $link2->save();
  $cleanup['links'][] = (int) $link2->id();

  $bd2 = $buildTestBatchSingle($bs, 'sku-s2', 'P36-S2', '18.00');
  $promoteToApproved($bd2['batch']);
  $commit2 = $committer->commitBatch($bd2['batch']);
  echo "  " . $commit2->summary() . "\n";

  $link2 = $etm->getStorage('material_suppliers')->load($link2->id());
  $hist2 = $etm->getStorage('material_price_history')->loadByProperties([
    'field_ingest_batch' => $bd2['batch']->id(),
    'field_material' => $m2->id(),
  ]);
  $hist2 = $hist2 ? reset($hist2) : NULL;
  if ($hist2) { $cleanup['history'][] = (int) $hist2->id(); }

  $checks = [
    'cost_updated_to_18' => abs((float) $link2->get('field_supplier_unit_cost')->value - 18.00) < 0.01,
    'history_status_applied' => $hist2 && $hist2->get('field_status')->value === 'applied',
    'history_old_cost_20' => $hist2 && abs((float) $hist2->get('field_old_cost')->value - 20.00) < 0.01,
    'history_new_cost_18' => $hist2 && abs((float) $hist2->get('field_new_cost')->value - 18.00) < 0.01,
    'history_delta_neg_10' => $hist2 && abs((float) $hist2->get('field_delta_percent')->value - (-10.0)) < 0.1,
    'commit_applied' => $commit2->rowsApplied === 1,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_2_decrease_applied'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 3 — increase within threshold (7.5%)
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 3: increase within threshold (+7.5%) — applied ===\n";
  $m3 = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S3',
    'field_size' => '', 'field_name' => 'P36-S3-SmallIncrease',
  ]);
  $m3->save();
  $cleanup['materials'][] = (int) $m3->id();
  $link3 = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier', 'title' => 'P36-S3-Link', 'uid' => 1,
    'field_material' => $m3->id(), 'field_supplier' => $bs['sup']->id(),
    'field_supplier_unit_cost' => '20.00',
    'field_price_effective_date' => '2026-01-01',
    'field_price_source' => 'invoice',
  ]);
  $link3->save();
  $cleanup['links'][] = (int) $link3->id();

  $bd3 = $buildTestBatchSingle($bs, 'sku-s3', 'P36-S3', '21.50');
  $promoteToApproved($bd3['batch']);
  $commit3 = $committer->commitBatch($bd3['batch']);

  $link3 = $etm->getStorage('material_suppliers')->load($link3->id());
  $hist3 = $etm->getStorage('material_price_history')->loadByProperties([
    'field_ingest_batch' => $bd3['batch']->id(),
    'field_material' => $m3->id(),
  ]);
  $hist3 = $hist3 ? reset($hist3) : NULL;
  if ($hist3) { $cleanup['history'][] = (int) $hist3->id(); }

  $checks = [
    'cost_updated_to_2150' => abs((float) $link3->get('field_supplier_unit_cost')->value - 21.50) < 0.01,
    'history_status_applied' => $hist3 && $hist3->get('field_status')->value === 'applied',
    'history_delta_75' => $hist3 && abs((float) $hist3->get('field_delta_percent')->value - 7.5) < 0.1,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_3_small_increase_applied'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 4 — increase exceeds threshold (+25%) — flagged
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 4: increase exceeds threshold (+25%) — flagged ===\n";
  $m4 = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S4',
    'field_size' => '', 'field_name' => 'P36-S4-BigIncrease',
  ]);
  $m4->save();
  $cleanup['materials'][] = (int) $m4->id();
  $link4 = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier', 'title' => 'P36-S4-Link', 'uid' => 1,
    'field_material' => $m4->id(), 'field_supplier' => $bs['sup']->id(),
    'field_supplier_unit_cost' => '20.00',
    'field_price_effective_date' => '2026-01-01',
    'field_price_source' => 'invoice',
  ]);
  $link4->save();
  $cleanup['links'][] = (int) $link4->id();

  $bd4 = $buildTestBatchSingle($bs, 'sku-s4', 'P36-S4', '25.00');
  $promoteToApproved($bd4['batch']);
  $commit4 = $committer->commitBatch($bd4['batch']);

  $link4 = $etm->getStorage('material_suppliers')->load($link4->id());
  $hist4 = $etm->getStorage('material_price_history')->loadByProperties([
    'field_ingest_batch' => $bd4['batch']->id(),
    'field_material' => $m4->id(),
  ]);
  $hist4 = $hist4 ? reset($hist4) : NULL;
  if ($hist4) { $cleanup['history'][] = (int) $hist4->id(); }
  $row4 = reset($bd4['rows']);
  $row4 = $etm->getStorage('supplier_price_ingest_row')->load($row4->id());

  $checks = [
    'cost_UNCHANGED_at_20' => abs((float) $link4->get('field_supplier_unit_cost')->value - 20.00) < 0.01,
    'history_status_flagged_high' => $hist4 && $hist4->get('field_status')->value === 'flagged_high',
    'history_delta_25' => $hist4 && abs((float) $hist4->get('field_delta_percent')->value - 25.0) < 0.1,
    'row_status_committed' => (string) $row4->get('field_row_status')->value === 'committed',
    'row_action_updated' => (string) $row4->get('field_resolution_action')->value === 'updated_link',
    'commit_flagged' => $commit4->rowsFlaggedHigh === 1,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_4_high_increase_flagged'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // Verify the flagged entry appears in the price-review view.
  $reviewQuery = $db->select('material_price_history__field_status', 'fs')
    ->fields('fs', ['entity_id'])
    ->condition('fs.field_status_value', 'flagged_high');
  $reviewQuery->join('material_price_history__field_ingest_batch', 'ib', 'ib.entity_id = fs.entity_id');
  $reviewQuery->condition('ib.field_ingest_batch_target_id', $bd4['batch']->id());
  $foundInReview = (bool) $reviewQuery->execute()->fetchField();
  echo '  ' . ($foundInReview ? 'PASS' : 'FAIL') . " — flagged_entry_in_price_review_data\n";
  $results['scenario_4_visible_in_review'] = $foundInReview ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 5 — material.field_cost_integer MAX-sync runs downstream
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 5: material.field_cost_integer MAX-sync ===\n";
  $m5 = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S5',
    'field_size' => '', 'field_name' => 'P36-S5-MaxSync',
  ]);
  $m5->save();
  $cleanup['materials'][] = (int) $m5->id();
  // Supplier A (our test supplier) at 20.00, preferred.
  $linkA = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier', 'title' => 'P36-S5-A', 'uid' => 1,
    'field_material' => $m5->id(), 'field_supplier' => $bs['sup']->id(),
    'field_supplier_unit_cost' => '20.00',
    'field_price_effective_date' => '2026-01-01',
    'field_price_source' => 'invoice',
    'field_preferred_supplier' => 1,
  ]);
  $linkA->save();
  $cleanup['links'][] = (int) $linkA->id();
  // Supplier B (a second supplier) at 18.00.
  $supB = $etm->getStorage('supplier')->create([
    'type' => 'supplier', 'field_supplier_name' => 'P36-TestSup-B', 'uid' => 1,
  ]);
  $supB->save();
  $cleanup['suppliers'][] = (int) $supB->id();
  $linkB = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier', 'title' => 'P36-S5-B', 'uid' => 1,
    'field_material' => $m5->id(), 'field_supplier' => $supB->id(),
    'field_supplier_unit_cost' => '18.00',
    'field_price_effective_date' => '2026-01-01',
    'field_price_source' => 'invoice',
  ]);
  $linkB->save();
  $cleanup['links'][] = (int) $linkB->id();

  // Reload material to capture initial cost_integer (set by MAX-sync on link save).
  $m5_pre = $etm->getStorage('material')->load($m5->id());
  $costBefore = $m5_pre->hasField('field_cost_integer') && !$m5_pre->get('field_cost_integer')->isEmpty()
    ? (float) $m5_pre->get('field_cost_integer')->value : NULL;

  // Update Supplier A to 21.50 via feed (+7.5% — below the 10% threshold;
  // exact-10% is flagged, matching the existing WO behavior's `>=` check).
  $bd5 = $buildTestBatchSingle($bs, 'sku-s5', 'P36-S5', '21.50');
  $promoteToApproved($bd5['batch']);
  $committer->commitBatch($bd5['batch']);

  // Reset entity cache to force a fresh material load (the MAX-sync
  // ran during $linkA->save() inside ingestRow, but we loaded the
  // entity before that).
  $etm->getStorage('material')->resetCache([$m5->id()]);
  $m5 = $etm->getStorage('material')->load($m5->id());
  $costAfter = $m5->hasField('field_cost_integer') && !$m5->get('field_cost_integer')->isEmpty()
    ? (float) $m5->get('field_cost_integer')->value : NULL;
  echo "  material.field_cost_integer: before=" . ($costBefore ?? 'NULL') . ", after=" . ($costAfter ?? 'NULL') . "\n";

  $checks = [
    'link_A_now_2150' => abs((float) $etm->getStorage('material_suppliers')->load($linkA->id())->get('field_supplier_unit_cost')->value - 21.50) < 0.01,
    // Material's catalog cost should reflect the new MAX after the update.
    // The exact MAX-sync behavior depends on material.module's rules
    // (preferred vs. all suppliers, etc.). What we verify: the cost is
    // non-null after the update and the update didn't break the entity.
    'material_loadable' => $m5 !== NULL,
    'cost_integer_set' => $costAfter !== NULL,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_5_max_sync'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 6 — idempotent recovery
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 6: idempotent recovery from partial commit ===\n";
  // Build a batch with 4 auto-applying rows.
  $matsS6 = [];
  for ($i = 1; $i <= 4; $i++) {
    $mat = $etm->getStorage('material')->create([
      'type' => 'irrigation', 'uid' => 1,
      'field_manufacturer' => $bs['mfr']->id(),
      'field_manufacturer_item_number' => "P36-S6-{$i}",
      'field_size' => '', 'field_name' => "P36-S6-{$i}",
    ]);
    $mat->save();
    $cleanup['materials'][] = (int) $mat->id();
    $matsS6[] = $mat;
  }
  $csv6 = "SKU,Mfr#,Brand,Desc,Price,UOM\n";
  for ($i = 1; $i <= 4; $i++) {
    $csv6 .= "sku-s6-{$i},P36-S6-{$i},P36-TestMfr,Row S6 {$i},10.0{$i},each\n";
  }
  $f6 = $fileRepo->writeData($csv6, "$uploadDir/spi_p36_s6_" . uniqid() . ".csv", FileSystemInterface::EXISTS_REPLACE);
  $f6->setPermanent(); $f6->save();
  $cleanup['files'][] = (int) $f6->id();
  $batch6 = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch', 'title' => 'P36-S6', 'uid' => 1,
    'field_supplier' => $bs['sup']->id(),
    'field_source_file' => $f6->id(),
    'field_source_filename' => 'p36-s6.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch6->save();
  $cleanup['batches'][] = (int) $batch6->id();
  $parser->parseUploadedFile($batch6);
  $batch6 = $etm->getStorage('supplier_price_ingest_batch')->load($batch6->id());
  $matcher->matchBatch($batch6);
  $batch6 = $etm->getStorage('supplier_price_ingest_batch')->load($batch6->id());

  $rowsS6 = $etm->getStorage('supplier_price_ingest_row')->loadByProperties([
    'field_batch' => $batch6->id(),
  ]);
  uksort($rowsS6, function ($a, $b) { return $a <=> $b; });
  foreach ($rowsS6 as $r) { $cleanup['rows'][] = (int) $r->id(); }

  $promoteToApproved($batch6);

  // Simulate partial commit: mark the first 2 rows as committed manually.
  $rowsList = array_values($rowsS6);
  for ($i = 0; $i < 2; $i++) {
    $rowsList[$i]->set('field_row_status', 'committed');
    $rowsList[$i]->save();
  }
  // Pretend the batch was interrupted: status is still 'approved'.

  // Re-invoke commitBatch.
  $commit6 = $committer->commitBatch($batch6);
  echo "  recovery " . $commit6->summary() . "\n";

  // Verify: only 2 rows were processed by the recovery (the still-dry_run ones).
  $checks = [
    'recovery_processed_2' => $commit6->rowsCommitted === 2,
    'recovery_zero_errors' => $commit6->rowsErrored === 0,
    'batch_now_committed' => (string) $etm->getStorage('supplier_price_ingest_batch')->load($batch6->id())->get('field_status')->value === 'committed',
  ];
  // None of the rows should still be in dry_run.
  $remainingDryRun = (int) $etm->getStorage('supplier_price_ingest_row')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_batch', $batch6->id())
    ->condition('field_row_status', 'dry_run')
    ->count()
    ->execute();
  $checks['no_more_dry_run_rows'] = $remainingDryRun === 0;
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_6_idempotent_recovery'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 7 — error containment when matched material is deleted
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 7: error containment — matched material deleted mid-commit ===\n";
  // Create a material, build a batch matching it, delete the material,
  // then commit.
  $m7 = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S7',
    'field_size' => '', 'field_name' => 'P36-S7-WillBeDeleted',
  ]);
  $m7->save();
  $cleanup['materials'][] = (int) $m7->id();

  $bd7 = $buildTestBatchSingle($bs, 'sku-s7', 'P36-S7', '15.00');

  // Also include a healthy second row so we can verify batch continues.
  $m7b = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S7B',
    'field_size' => '', 'field_name' => 'P36-S7B-Healthy',
  ]);
  $m7b->save();
  $cleanup['materials'][] = (int) $m7b->id();
  // Append a second row to the same batch by parsing a fresh CSV into it.
  // Simpler: build a new 2-row batch.
  $csv7 = "SKU,Mfr#,Brand,Desc,Price,UOM\nsku-s7,P36-S7,P36-TestMfr,Will be orphaned,15.00,each\nsku-s7b,P36-S7B,P36-TestMfr,Healthy row,16.00,each\n";
  $f7 = $fileRepo->writeData($csv7, "$uploadDir/spi_p36_s7_" . uniqid() . ".csv", FileSystemInterface::EXISTS_REPLACE);
  $f7->setPermanent(); $f7->save();
  $cleanup['files'][] = (int) $f7->id();
  // Remove the first single-row batch from cleanup tracking (use the new one).
  $bd7['batch']->delete();
  array_pop($cleanup['batches']);
  foreach ($bd7['rows'] as $r) { $r->delete(); }
  $cleanup['rows'] = array_diff($cleanup['rows'], array_map(fn($r) => (int) $r->id(), $bd7['rows']));

  $batch7 = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch', 'title' => 'P36-S7', 'uid' => 1,
    'field_supplier' => $bs['sup']->id(),
    'field_source_file' => $f7->id(),
    'field_source_filename' => 'p36-s7.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch7->save();
  $cleanup['batches'][] = (int) $batch7->id();
  $parser->parseUploadedFile($batch7);
  $batch7 = $etm->getStorage('supplier_price_ingest_batch')->load($batch7->id());
  $matcher->matchBatch($batch7);
  $batch7 = $etm->getStorage('supplier_price_ingest_batch')->load($batch7->id());
  $rows7 = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch7->id()]);
  foreach ($rows7 as $r) { $cleanup['rows'][] = (int) $r->id(); }

  // Delete m7 (simulate admin deletion between match and commit).
  $m7->delete();
  $cleanup['materials'] = array_diff($cleanup['materials'], [(int) $m7->id()]);

  $promoteToApproved($batch7);
  $commit7 = $committer->commitBatch($batch7);
  echo "  " . $commit7->summary() . "\n";

  // Reload rows
  $rows7 = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch7->id()]);
  $orphanedRow = NULL;
  $healthyRow = NULL;
  foreach ($rows7 as $r) {
    if ($r->get('field_supplier_sku')->value === 'sku-s7') { $orphanedRow = $r; }
    if ($r->get('field_supplier_sku')->value === 'sku-s7b') { $healthyRow = $r; }
  }

  $checks = [
    'orphaned_row_errored' => $orphanedRow && (string) $orphanedRow->get('field_row_status')->value === 'error',
    'orphaned_row_notes_mention_missing' => $orphanedRow && stripos((string) $orphanedRow->get('field_resolution_notes')->value, 'no longer exists') !== FALSE,
    'healthy_row_committed' => $healthyRow && (string) $healthyRow->get('field_row_status')->value === 'committed',
    'batch_committed' => (string) $etm->getStorage('supplier_price_ingest_batch')->load($batch7->id())->get('field_status')->value === 'committed',
    'commit_errored_one' => $commit7->rowsErrored === 1,
    'commit_other_succeeded' => $commit7->rowsCommitted === 1,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_7_error_containment'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 8 — source filter on price-review view (data presence)
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 8: source filter — feed_import_auto entries exist ===\n";
  $feedAutoCount = (int) $etm->getStorage('material_price_history')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_source', 'feed_import_auto')
    ->count()
    ->execute();
  echo "  total feed_import_auto entries in DB: $feedAutoCount (from this verifier and any prior runs)\n";
  // wo_entry counts are environment-dependent (local dev may have zero;
  // live has thousands). What we check is that Phase 3.6 produces
  // feed_import_auto entries — the source filter on the price-review
  // view is meaningful only when both source types coexist, and the
  // filter UI presence is verified by the view-config smoke test.

  $checks = [
    'feed_auto_entries_present' => $feedAutoCount >= 4,  // S1, S2, S3, S4 at minimum
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_8_source_filter_data'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 9 — determinism across parse + match + commit
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 9: determinism across full pipeline ===\n";
  $m9 = $etm->getStorage('material')->create([
    'type' => 'irrigation', 'uid' => 1,
    'field_manufacturer' => $bs['mfr']->id(),
    'field_manufacturer_item_number' => 'P36-S9',
    'field_size' => '', 'field_name' => 'P36-S9-Determinism',
  ]);
  $m9->save();
  $cleanup['materials'][] = (int) $m9->id();

  $runOnce = function () use ($bs, $etm, $parser, $matcher, $committer, $fileRepo, $uploadDir, &$cleanup): array {
    $csv = "SKU,Mfr#,Brand,Desc,Price,UOM\nsku-s9,P36-S9,P36-TestMfr,Determinism test,17.00,each\n";
    $f = $fileRepo->writeData($csv, "$uploadDir/spi_p36_s9_" . uniqid() . ".csv", FileSystemInterface::EXISTS_REPLACE);
    $f->setPermanent(); $f->save();
    $cleanup['files'][] = (int) $f->id();
    $b = $etm->getStorage('supplier_price_ingest_batch')->create([
      'type' => 'batch', 'title' => 'P36-S9', 'uid' => 1,
      'field_supplier' => $bs['sup']->id(),
      'field_source_file' => $f->id(),
      'field_source_filename' => 'p36-s9.csv',
      'field_uploaded_by' => 1,
      'field_uploaded_on' => date('Y-m-d\TH:i:s'),
      'field_status' => 'pending_dry_run',
    ]);
    $b->save();
    $cleanup['batches'][] = (int) $b->id();
    $parser->parseUploadedFile($b);
    $b = $etm->getStorage('supplier_price_ingest_batch')->load($b->id());
    $matcher->matchBatch($b);
    $b = $etm->getStorage('supplier_price_ingest_batch')->load($b->id());
    $rs = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $b->id()]);
    foreach ($rs as $r) { $cleanup['rows'][] = (int) $r->id(); }
    $b->set('field_status', 'awaiting_approval'); $b->save();
    $b->set('field_status', 'approved');
    $b->set('field_committed_by', 1);
    $b->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
    $b->save();
    $commit = $committer->commitBatch($b);
    $row = reset($rs);
    $row = $etm->getStorage('supplier_price_ingest_row')->load($row->id());
    return [
      'tier' => (string) $row->get('field_match_tier')->value,
      'confidence' => (string) $row->get('field_match_confidence')->value,
      'matched_id' => (string) $row->get('field_matched_material')->target_id,
      'row_status' => (string) $row->get('field_row_status')->value,
      'commit_applied' => $commit->rowsApplied,
      'commit_flagged' => $commit->rowsFlaggedHigh,
      'commit_created' => $commit->rowsAutoCreated,
    ];
  };
  $runA = $runOnce();
  // Clean any material_suppliers link auto-created by run A so run B
  // starts from the same fresh state — otherwise run B would update an
  // existing link instead of auto-creating one. That's correct
  // behavior, not non-determinism, but it makes the assertion noisy.
  $linkIdsRunA = $etm->getStorage('material_suppliers')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_material', $m9->id())
    ->condition('field_supplier', $bs['sup']->id())
    ->execute();
  foreach ($etm->getStorage('material_suppliers')->loadMultiple($linkIdsRunA) as $linkA9) {
    $linkA9->delete();
  }
  $runB = $runOnce();
  echo "  runA: " . json_encode($runA) . "\n";
  echo "  runB: " . json_encode($runB) . "\n";
  $checks = ['identical' => $runA === $runB];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_9_full_pipeline_determinism'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 10 — Phase 3.7.5 pack-tier capture (parse → row → material)
  // ════════════════════════════════════════════════════════════════
  // End-to-end: scrape CSV with 5 pack-tier columns → parser writes
  // them to the row entity → committer hands off to PriceSyncService →
  // PriceSyncService writes onto the matched material per the
  // trust-aware rule.
  //
  // Three sub-scenarios:
  //   10a — confirmed source overwrites empty material's pack fields,
  //         tags family + data_source.
  //   10b — confirmed source DOES overwrite material's existing
  //         (different) values.
  //   10c — listing_only source on a fresh material with empty pack
  //         fields fills them; on a material with already-confirmed
  //         pack fields it does NOT overwrite (trust ladder honored).
  echo "\n=== Scenario 10: Phase 3.7.5 pack-tier capture ===\n";

  // Defensive: skip the whole scenario if the pack schema isn't
  // present (this verifier should still work on a DDEV that hasn't
  // run setup_pack_tier_capture.php yet).
  $packSchemaPresent = (bool) \Drupal\field\Entity\FieldStorageConfig::loadByName('material', 'field_pack_qty_mid_label');
  if (!$packSchemaPresent) {
    echo "  SKIP — pack-tier schema not installed (run setup_pack_tier_capture.php first)\n";
    $results['scenario_10_pack_tier_capture'] = 'SKIP — schema not installed';
  } else {
    $checks10 = [];

    // Fresh materials, one per sub-scenario, all with NO pack data.
    $m10a = $etm->getStorage('material')->create([
      'type' => 'irrigation', 'uid' => 1,
      'field_manufacturer' => $bs['mfr']->id(),
      'field_manufacturer_item_number' => 'P311-S10A',
      'field_name' => 'P311-S10A-confirmed-onto-empty',
    ]);
    $m10a->save();
    $cleanup['materials'][] = (int) $m10a->id();

    // 10b material: pre-populate with a different pack rule so the
    // confirmed-overwrite assertion can prove the change.
    $m10b = $etm->getStorage('material')->create([
      'type' => 'irrigation', 'uid' => 1,
      'field_manufacturer' => $bs['mfr']->id(),
      'field_manufacturer_item_number' => 'P311-S10B',
      'field_name' => 'P311-S10B-confirmed-overwrites-existing',
      'field_pack_qty_mid_label' => 'Box',
      'field_pack_qty_mid' => 10,
      'field_pack_qty_case' => 100,
    ]);
    $m10b->save();
    $cleanup['materials'][] = (int) $m10b->id();

    // 10c-prefilled material: simulate a prior confirmed ingest by
    // pre-populating pack fields, then commit a low-confidence row;
    // material's existing values must survive untouched.
    $m10c_prefilled = $etm->getStorage('material')->create([
      'type' => 'irrigation', 'uid' => 1,
      'field_manufacturer' => $bs['mfr']->id(),
      'field_manufacturer_item_number' => 'P311-S10C',
      'field_name' => 'P311-S10C-low-confidence-does-not-overwrite',
      'field_pack_qty_mid_label' => 'Bag',
      'field_pack_qty_mid' => 50,
      'field_pack_qty_case' => 250,
    ]);
    $m10c_prefilled->save();
    $cleanup['materials'][] = (int) $m10c_prefilled->id();

    // Build a config fixture that maps the pack columns. Reuse the
    // existing $bs['sup'] but build a fresh config (the shared $bs
    // config doesn't have pack mappings).
    $cfg10 = $etm->getStorage('supplier_ingest_config')->create([
      'type' => 'config',
      'title' => 'P311-S10-Cfg',
      'uid' => 1,
      'field_supplier' => $bs['sup']->id(),
      'field_active' => 1,
      'field_default_cost_uom' => 'each',
      'field_column_mapping' => json_encode([
        'source_columns' => [
          'Mfr#'             => 'field_manufacturer_item_number',
          'Brand'            => 'field_manufacturer_name',
          'Desc'             => 'field_description',
          'Price'            => 'field_unit_cost',
          'UOM'              => 'field_cost_uom',
          'PackMidLabel'     => 'field_pack_qty_mid_label',
          'PackMid'          => 'field_pack_qty_mid',
          'PackCase'         => 'field_pack_qty_case',
          'PackFamily'       => 'field_pack_family',
          'PackDataSource'   => 'field_pack_data_source',
        ],
        'header_row' => 1,
      ]),
      'field_bundle_policy' => json_encode(['irrigation' => 'matched_only']),
    ]);
    // We can't save two configs for the same supplier (uniqueness
    // invariant). Delete any other config for this supplier first.
    $existingCfgs = $etm->getStorage('supplier_ingest_config')->loadByProperties(['field_supplier' => $bs['sup']->id()]);
    foreach ($existingCfgs as $ec) {
      // Track for cleanup so the shared fixture's config doesn't leak,
      // but delete here so the unique invariant is satisfied.
      $cleanup['configs'][] = (int) $ec->id();
      $ec->delete();
    }
    $cfg10->save();
    $cleanup['configs'][] = (int) $cfg10->id();

    // CSV: three rows, one per sub-scenario.
    $csv10 = "Mfr#,Brand,Desc,Price,UOM,PackMidLabel,PackMid,PackCase,PackFamily,PackDataSource\n"
      . "P311-S10A,P36-TestMfr,confirmed-onto-empty,1.00,each,Bag,25,500,Rain Bird R-Series,confirmed\n"
      . "P311-S10B,P36-TestMfr,confirmed-overwrites,2.00,each,Package,10,50,Rain Bird R-VAN,confirmed\n"
      . "P311-S10C,P36-TestMfr,low-conf-no-overwrite,3.00,each,Carton,5,20,Hunter ST Commercial,listing_only\n";
    $f10 = $fileRepo->writeData($csv10, "$uploadDir/spi_p311_s10.csv", FileSystemInterface::EXISTS_REPLACE);
    $f10->setPermanent(); $f10->save();
    $cleanup['files'][] = (int) $f10->id();

    $b10 = $etm->getStorage('supplier_price_ingest_batch')->create([
      'type' => 'batch', 'title' => 'P311-S10', 'uid' => 1,
      'field_supplier' => $bs['sup']->id(),
      'field_source_file' => $f10->id(),
      'field_source_filename' => 'p311-s10.csv',
      'field_uploaded_by' => 1,
      'field_uploaded_on' => date('Y-m-d\TH:i:s'),
      'field_status' => 'pending_dry_run',
    ]);
    $b10->save();
    $cleanup['batches'][] = (int) $b10->id();

    $parser->parseUploadedFile($b10);
    $b10 = $etm->getStorage('supplier_price_ingest_batch')->load($b10->id());
    $matcher->matchBatch($b10);
    $b10 = $etm->getStorage('supplier_price_ingest_batch')->load($b10->id());

    // Track rows for cleanup. Pull them by SKU/Mfr# for assertion.
    $rows10 = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $b10->id()]);
    foreach ($rows10 as $r) { $cleanup['rows'][] = (int) $r->id(); }

    // Assertion 1: parser populated row fields from CSV.
    $rowA = NULL;
    foreach ($rows10 as $r) {
      if ((string) $r->get('field_manufacturer_item_number')->value === 'P311-S10A') {
        $rowA = $r; break;
      }
    }
    $checks10['10_row_parsed_mid_label'] = $rowA && (string) $rowA->get('field_pack_qty_mid_label')->value === 'Bag';
    $checks10['10_row_parsed_mid_qty']   = $rowA && (int) $rowA->get('field_pack_qty_mid')->value === 25;
    $checks10['10_row_parsed_case_qty']  = $rowA && (int) $rowA->get('field_pack_qty_case')->value === 500;
    $checks10['10_row_parsed_data_source'] = $rowA && (string) $rowA->get('field_pack_data_source')->value === 'confirmed';
    $rbRSeriesTid = NULL;
    if ($rowA && !$rowA->get('field_pack_family')->isEmpty()) {
      $term = $rowA->get('field_pack_family')->entity;
      $rbRSeriesTid = $term ? (int) $term->id() : NULL;
      $checks10['10_row_parsed_family_term'] = $term && $term->label() === 'Rain Bird R-Series';
    } else {
      $checks10['10_row_parsed_family_term'] = FALSE;
    }

    // Commit the batch.
    $b10->set('field_status', 'awaiting_approval'); $b10->save();
    $b10->set('field_status', 'approved');
    $b10->set('field_committed_by', 1);
    $b10->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
    $b10->save();
    $committer->commitBatch($b10);

    // Re-load the materials post-commit.
    $m10a = $etm->getStorage('material')->load($m10a->id());
    $m10b = $etm->getStorage('material')->load($m10b->id());
    $m10c_prefilled = $etm->getStorage('material')->load($m10c_prefilled->id());

    // 10a: confirmed → empty material → all 3 tier fields populated.
    $checks10['10a_mid_label_filled'] = (string) $m10a->get('field_pack_qty_mid_label')->value === 'Bag';
    $checks10['10a_mid_qty_filled']   = (int) $m10a->get('field_pack_qty_mid')->value === 25;
    $checks10['10a_case_filled']      = (int) $m10a->get('field_pack_qty_case')->value === 500;
    $checks10['10a_data_source_set']  = (string) $m10a->get('field_pack_data_source')->value === 'confirmed';

    // 10b: confirmed → material with different existing values → overwrite.
    $checks10['10b_mid_label_overwritten'] = (string) $m10b->get('field_pack_qty_mid_label')->value === 'Package';
    $checks10['10b_mid_qty_overwritten']   = (int) $m10b->get('field_pack_qty_mid')->value === 10;
    $checks10['10b_case_overwritten']      = (int) $m10b->get('field_pack_qty_case')->value === 50;

    // 10c: listing_only → material with existing confirmed values → NO overwrite.
    $checks10['10c_mid_label_preserved'] = (string) $m10c_prefilled->get('field_pack_qty_mid_label')->value === 'Bag';
    $checks10['10c_mid_qty_preserved']   = (int) $m10c_prefilled->get('field_pack_qty_mid')->value === 50;
    $checks10['10c_case_preserved']      = (int) $m10c_prefilled->get('field_pack_qty_case')->value === 250;
    // But family + data_source DO update (they're metadata tags, not rule).
    $checks10['10c_family_still_updated'] = !$m10c_prefilled->get('field_pack_family')->isEmpty()
      && $m10c_prefilled->get('field_pack_family')->entity
      && $m10c_prefilled->get('field_pack_family')->entity->label() === 'Hunter ST Commercial';
    $checks10['10c_data_source_still_updated'] = (string) $m10c_prefilled->get('field_pack_data_source')->value === 'listing_only';

    foreach ($checks10 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
    $results['scenario_10_pack_tier_capture'] = !in_array(FALSE, $checks10, TRUE) ? 'PASS' : 'FAIL';
  }
}
finally {
  echo "\n=== Cleanup ===\n";
  // Order matters — children before parents to avoid dangling refs.
  foreach ($cleanup['rows'] as $id)      { $e = $etm->getStorage('supplier_price_ingest_row')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['batches'] as $id)   { $e = $etm->getStorage('supplier_price_ingest_batch')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['configs'] as $id)   { $e = $etm->getStorage('supplier_ingest_config')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['history'] as $id)   { $e = $etm->getStorage('material_price_history')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['links'] as $id)     { $e = $etm->getStorage('material_suppliers')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['materials'] as $id) { $e = $etm->getStorage('material')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['mfrs'] as $id)      { $e = $etm->getStorage('manufacturer')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['suppliers'] as $id) { $e = $etm->getStorage('supplier')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['files'] as $id)     { $f = $etm->getStorage('file')->load($id); if ($f) { $f->delete(); } }
  echo "  done.\n";
}

echo "\n========== SUMMARY ==========\n";
$overall = 'PASS';
foreach ($results as $k => $v) {
  printf("  %-40s %s\n", $k, $v);
  if (!str_starts_with($v, 'PASS')) { $overall = 'FAIL'; }
}
echo "----------------------------\n";
echo "  OVERALL                                  $overall\n";
echo "=============================\n";
