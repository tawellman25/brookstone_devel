<?php

declare(strict_types=1);

/**
 * Phase 3.2 — IngestParser + presave-validation round-trip verification.
 *
 * Mirrors the 8-step manual test from the Phase 3.2 spec, programmatic
 * end-to-end. Cleans up all created entities on exit (success or fail).
 *
 * Steps:
 *   1.  CREATE supplier_ingest_config (CPS - Grand Junction)
 *   2.  ASSERT uniqueness violation when creating a second config for
 *       the same supplier
 *   3.  ASSERT presave error on malformed JSON in field_column_mapping
 *   4.  ASSERT presave error when column_mapping targets unknown field
 *   5.  PARSE a small valid CSV (3 rows, 6 columns)
 *   6.  VERIFY batch + rows created, raw_data round-trips
 *   7.  PARSE a broken CSV (mixed unparseable + valid rows) — parser
 *       must not crash, errored rows flagged with field_match_tier='error'
 *   8.  PARSE an XLSX — same content as CSV — same outcomes
 *
 * Usage:
 *   ddev drush scr web/scripts/verify_supplier_price_ingest_parser.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\File\FileSystemInterface;
use Drupal\supplier_price_ingest\Service\IngestParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$results = [];
$cleanup = [
  'configs' => [],
  'batches' => [],
  'rows' => [],
  'files' => [],
  // Step 11 (Phase 3.2 form-DI regression guard) needs a fixture
  // supplier of its own.
  'suppliers' => [],
];

$etm = \Drupal::entityTypeManager();
$parser = \Drupal::service('supplier_price_ingest.parser');
$fileRepo = \Drupal::service('file.repository');
$fileSystem = \Drupal::service('file_system');
$uploadDir = 'public://supplier_ingest';
$fileSystem->prepareDirectory(
  $uploadDir,
  FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
);

// Pick the first supplier in the DB to test with.
$sids = \Drupal::entityQuery('supplier')->accessCheck(FALSE)->range(0, 1)->execute();
if (!$sids) {
  echo "ABORT: no supplier entities in DB.\n";
  exit(1);
}
$supplierId = (int) reset($sids);
$supplier = $etm->getStorage('supplier')->load($supplierId);
echo "Using supplier id=$supplierId (" . $supplier->label() . ")\n\n";

// If a config already exists for this supplier (from prior runs), clear it.
foreach ($etm->getStorage('supplier_ingest_config')->loadByProperties(['field_supplier' => $supplierId]) as $stale) {
  $stale->delete();
  echo "Cleared stale config id=" . $stale->id() . "\n";
}

$validColumnMapping = json_encode([
  'source_columns' => [
    'Item Number' => 'field_supplier_sku',
    'Mfr Part #'  => 'field_manufacturer_item_number',
    'Brand'       => 'field_manufacturer_name',
    'Description' => 'field_description',
    'Price'       => 'field_unit_cost',
    'UOM'         => 'field_cost_uom',
    'Pack Qty'    => 'field_pack_quantity',
  ],
  'header_row' => 1,
  'skip_rows_until_header' => FALSE,
  'case_sensitive_headers' => FALSE,
  'trim_whitespace' => TRUE,
]);

try {
  // ── Step 1: create config ─────────────────────────────────────────
  echo "=== Step 1: create supplier_ingest_config ===\n";
  $cfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'TEST CONFIG — phase 3.2 verify',
    'uid' => 1,
    'field_supplier' => $supplierId,
    'field_active' => 1,
    'field_default_cost_uom' => 'each',
    'field_fuzzy_threshold_high' => '90.00',
    'field_fuzzy_threshold_med' => '70.00',
    'field_column_mapping' => $validColumnMapping,
    'field_bundle_policy' => json_encode(['irrigation' => 'matched_only', 'pvc' => 'matched_only']),
  ]);
  $cfg->save();
  $cleanup['configs'][] = (int) $cfg->id();
  $results['step1_create_config'] = 'PASS — id=' . $cfg->id();
  echo "  PASS — config id=" . $cfg->id() . "\n";

  // ── Step 2: uniqueness violation ──────────────────────────────────
  echo "\n=== Step 2: second config for same supplier should fail ===\n";
  try {
    $cfg2 = $etm->getStorage('supplier_ingest_config')->create([
      'type' => 'config',
      'title' => 'TEST DUP — should fail',
      'uid' => 1,
      'field_supplier' => $supplierId,
      'field_column_mapping' => $validColumnMapping,
    ]);
    $cfg2->save();
    // If save succeeded, that's a FAIL.
    $cleanup['configs'][] = (int) $cfg2->id();
    $results['step2_uniqueness'] = 'FAIL — duplicate config saved';
    echo "  FAIL — duplicate config saved (id=" . $cfg2->id() . ")\n";
  }
  catch (\Drupal\Core\Entity\EntityStorageException $e) {
    if (stripos($e->getMessage(), 'already exists') !== FALSE) {
      $results['step2_uniqueness'] = 'PASS — exception with expected message';
      echo "  PASS — blocked with: " . $e->getMessage() . "\n";
    }
    else {
      $results['step2_uniqueness'] = 'FAIL — wrong exception: ' . $e->getMessage();
      echo "  FAIL — wrong message\n";
    }
  }

  // ── Step 3: malformed JSON in column_mapping ──────────────────────
  echo "\n=== Step 3: malformed column_mapping JSON ===\n";
  try {
    $cfg->set('field_column_mapping', 'not { valid json');
    $cfg->save();
    $results['step3_malformed_json'] = 'FAIL — malformed JSON saved';
    echo "  FAIL — saved\n";
  }
  catch (\Drupal\Core\Entity\EntityStorageException $e) {
    if (stripos($e->getMessage(), 'not valid JSON') !== FALSE || stripos($e->getMessage(), 'JSON') !== FALSE) {
      $results['step3_malformed_json'] = 'PASS — exception thrown';
      echo "  PASS — " . $e->getMessage() . "\n";
    }
    else {
      $results['step3_malformed_json'] = 'FAIL — wrong exception';
      echo "  FAIL — " . $e->getMessage() . "\n";
    }
  }

  // Restore valid mapping for the next step.
  $cfg = $etm->getStorage('supplier_ingest_config')->load(end($cleanup['configs']));
  $cfg->set('field_column_mapping', $validColumnMapping);
  $cfg->save();

  // ── Step 4: column_mapping with non-existent BOS field ────────────
  echo "\n=== Step 4: column_mapping targets non-existent BOS field ===\n";
  $badMapping = json_encode([
    'source_columns' => [
      'Item Number' => 'field_supplier_sku',
      'Price' => 'field_unit_cost',
      'Bogus' => 'field_completely_made_up',
    ],
  ]);
  try {
    $cfg->set('field_column_mapping', $badMapping);
    $cfg->save();
    $results['step4_bad_field'] = 'FAIL — bad mapping saved';
    echo "  FAIL\n";
  }
  catch (\Drupal\Core\Entity\EntityStorageException $e) {
    if (stripos($e->getMessage(), 'field_completely_made_up') !== FALSE) {
      $results['step4_bad_field'] = 'PASS — exception thrown';
      echo "  PASS — " . $e->getMessage() . "\n";
    }
    else {
      $results['step4_bad_field'] = 'FAIL — wrong exception: ' . $e->getMessage();
      echo "  FAIL — wrong exception\n";
    }
  }
  // Restore
  $cfg->set('field_column_mapping', $validColumnMapping);
  $cfg->save();

  // ── Step 5+6: parse a small valid CSV, verify rows ────────────────
  echo "\n=== Step 5+6: parse valid CSV, verify rows ===\n";
  $csv = "Item Number,Mfr Part #,Brand,Description,Price,UOM,Pack Qty\n"
       . "SKU-001,HUNTER-001,Hunter,Hunter PGP-04 Rotor,\$12.95,each,1\n"
       . "SKU-002,RB-002,Rain Bird,Rain Bird 5004 Plus PRS,15.50,each,1\n"
       . "SKU-003,SPEARS-003,Spears,1\" PVC Schedule 40 Tee,2.45,each,10\n";
  $csvPath = '/tmp/spi_test_valid.csv';
  file_put_contents($csvPath, $csv);
  $csvFile = $fileRepo->writeData($csv, 'public://supplier_ingest/spi_test_valid.csv', FileSystemInterface::EXISTS_REPLACE);
  $csvFile->setPermanent();
  $csvFile->save();
  $cleanup['files'][] = (int) $csvFile->id();

  $batch1 = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — valid CSV',
    'uid' => 1,
    'field_supplier' => $supplierId,
    'field_source_file' => $csvFile->id(),
    'field_source_filename' => 'spi_test_valid.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch1->save();
  $cleanup['batches'][] = (int) $batch1->id();

  $result1 = $parser->parseUploadedFile($batch1);
  echo "  parser: " . $result1->summary() . "\n";

  $rows = $etm->getStorage('supplier_price_ingest_row')
    ->loadByProperties(['field_batch' => $batch1->id()]);
  foreach ($rows as $r) $cleanup['rows'][] = (int) $r->id();

  $checks = [
    'rows_created=3' => $result1->rowsCreated === 3,
    'rows_skipped=0' => $result1->rowsSkipped === 0,
    'rows_errored=0' => $result1->rowsErrored === 0,
    'entities_persisted=3' => count($rows) === 3,
  ];
  // Round-trip raw_data on first row
  $first = reset($rows);
  $raw = json_decode($first->get('field_raw_data')->value, TRUE);
  $checks['raw_data_roundtrip'] = isset($raw['Item Number']) && $raw['Item Number'] === 'SKU-001';
  // Cost normalization (strip $)
  $checks['cost_strip_dollar'] = (float) $first->get('field_unit_cost')->value === 12.95;
  foreach ($checks as $k => $v) {
    echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
  }
  $allPass = !in_array(FALSE, $checks, TRUE);
  $results['step5_6_valid_csv'] = $allPass ? 'PASS' : 'FAIL';

  // ── Step 7: broken CSV, no crash, errored rows flagged ────────────
  echo "\n=== Step 7: parse broken CSV — must not crash ===\n";
  $brokenCsv = "Item Number,Mfr Part #,Brand,Description,Price,UOM,Pack Qty\n"
             . "SKU-100,FOO-001,FooBrand,Valid row,5.00,each,1\n"
             . ",,,Description only no price,,,\n"                    // skipped: no cost
             . "SKU-101,FOO-002,FooBrand,Bad price,not-a-number,each,1\n"  // errored: bad cost
             . "SKU-102,FOO-003,FooBrand,Bad UOM,7.50,gallon,1\n"     // errored: unmapped UOM (no default in this config — wait, default IS 'each')
             . "SKU-103,FOO-004,FooBrand,Another valid,9.99,each,1\n";

  $brokenFile = $fileRepo->writeData($brokenCsv, 'public://supplier_ingest/spi_test_broken.csv', FileSystemInterface::EXISTS_REPLACE);
  $brokenFile->setPermanent();
  $brokenFile->save();
  $cleanup['files'][] = (int) $brokenFile->id();

  $batch2 = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — broken CSV',
    'uid' => 1,
    'field_supplier' => $supplierId,
    'field_source_file' => $brokenFile->id(),
    'field_source_filename' => 'spi_test_broken.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch2->save();
  $cleanup['batches'][] = (int) $batch2->id();

  try {
    $result2 = $parser->parseUploadedFile($batch2);
    echo "  parser: " . $result2->summary() . "\n";
    $rows2 = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch2->id()]);
    foreach ($rows2 as $r) $cleanup['rows'][] = (int) $r->id();
    $erroredEntities = array_filter($rows2, fn($r) => ($r->get('field_match_tier')->value ?? '') === 'error');
    // Expected behavior (Phase 3.3 UOM strictness):
    //   - SKU-100 valid → created (clean)
    //   - empty SKU/no-price row → skipped (no entity created)
    //   - SKU-101 bad price → errored (entity created, field_match_tier='error')
    //   - SKU-102 "gallon" UOM (non-empty, unmapped) → errored. Phase 3.3
    //     changed this from silent fall-back to strict-error so unrecognized
    //     UOMs surface for human review.
    //   - SKU-103 valid → created (clean)
    // ParseResult: created and errored are mutually exclusive return
    // statuses. So: 2 created, 2 errored, 1 skipped. Total entities = 4.
    $checks7 = [
      'no_crash' => TRUE,
      'created_2' => $result2->rowsCreated === 2,
      'skipped_1' => $result2->rowsSkipped === 1,
      'errored_2' => $result2->rowsErrored === 2,
      'errored_rows_marked_2' => count($erroredEntities) === 2,
    ];
    foreach ($checks7 as $k => $v) echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
    $results['step7_broken_csv'] = !in_array(FALSE, $checks7, TRUE) ? 'PASS' : 'FAIL';
  }
  catch (\Throwable $e) {
    $results['step7_broken_csv'] = 'FAIL — parser crashed: ' . $e->getMessage();
    echo "  FAIL — parser crashed\n";
  }

  // ── Step 8: XLSX same content as valid CSV ────────────────────────
  echo "\n=== Step 8: parse XLSX — same shape as CSV ===\n";
  $ss = new Spreadsheet();
  $sh = $ss->getActiveSheet();
  $sh->fromArray([
    ['Item Number', 'Mfr Part #', 'Brand', 'Description', 'Price', 'UOM', 'Pack Qty'],
    ['XL-001', 'HUNTER-001', 'Hunter', 'XLSX test 1', 12.95, 'each', 1],
    ['XL-002', 'RB-002',    'Rain Bird', 'XLSX test 2', 15.50, 'each', 1],
  ], NULL, 'A1');
  $xlsxPath = '/tmp/spi_test_valid.xlsx';
  (new Xlsx($ss))->save($xlsxPath);
  $xlsxData = file_get_contents($xlsxPath);
  $xlsxFile = $fileRepo->writeData($xlsxData, 'public://supplier_ingest/spi_test_valid.xlsx', FileSystemInterface::EXISTS_REPLACE);
  $xlsxFile->setPermanent();
  $xlsxFile->save();
  $cleanup['files'][] = (int) $xlsxFile->id();

  $batch3 = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — valid XLSX',
    'uid' => 1,
    'field_supplier' => $supplierId,
    'field_source_file' => $xlsxFile->id(),
    'field_source_filename' => 'spi_test_valid.xlsx',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch3->save();
  $cleanup['batches'][] = (int) $batch3->id();

  $result3 = $parser->parseUploadedFile($batch3);
  echo "  parser: " . $result3->summary() . "\n";
  $rows3 = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch3->id()]);
  foreach ($rows3 as $r) $cleanup['rows'][] = (int) $r->id();
  $checks8 = [
    'no_crash' => TRUE,
    'created_2' => $result3->rowsCreated === 2,
    'errored_0' => $result3->rowsErrored === 0,
  ];
  foreach ($checks8 as $k => $v) echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
  $results['step8_xlsx'] = !in_array(FALSE, $checks8, TRUE) ? 'PASS' : 'FAIL';

  // ── Step 9: unmapped UOM error contains original value + allowed list ──
  echo "\n=== Step 9: non-empty unmapped UOM errors with helpful note ===\n";
  $uomCsv = "Item Number,Mfr Part #,Brand,Description,Price,UOM,Pack Qty\n"
          . "SKU-200,TEST-200,TestMfr,Has weird UOM,5.00,FurlongPerFortnight,1\n"
          . "SKU-201,TEST-201,TestMfr,Empty UOM falls back to default,7.00,,1\n";
  $uomFile = $fileRepo->writeData($uomCsv, 'public://supplier_ingest/spi_test_uom_strict.csv', FileSystemInterface::EXISTS_REPLACE);
  $uomFile->setPermanent();
  $uomFile->save();
  $cleanup['files'][] = (int) $uomFile->id();

  $batch4 = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — UOM strict',
    'uid' => 1,
    'field_supplier' => $supplierId,
    'field_source_file' => $uomFile->id(),
    'field_source_filename' => 'spi_test_uom_strict.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch4->save();
  $cleanup['batches'][] = (int) $batch4->id();

  $result4 = $parser->parseUploadedFile($batch4);
  echo "  parser: " . $result4->summary() . "\n";
  $rows4 = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch4->id()]);
  foreach ($rows4 as $r) $cleanup['rows'][] = (int) $r->id();

  // Locate the FurlongPerFortnight row and verify the note.
  $weirdRow = NULL;
  $emptyRow = NULL;
  foreach ($rows4 as $r) {
    if ($r->get('field_supplier_sku')->value === 'SKU-200') $weirdRow = $r;
    if ($r->get('field_supplier_sku')->value === 'SKU-201') $emptyRow = $r;
  }
  $note = $weirdRow ? (string) $weirdRow->get('field_resolution_notes')->value : '';
  $checks9 = [
    'weird_row_errored' => $weirdRow && ($weirdRow->get('field_match_tier')->value === 'error'),
    'note_has_original_value' => str_contains($note, 'FurlongPerFortnight'),
    'note_has_allowed_list' => str_contains($note, 'each') && str_contains($note, 'roll'),
    'empty_uom_fell_back_to_default' => $emptyRow && ($emptyRow->get('field_cost_uom')->value === 'each'),
    'empty_uom_not_errored' => $emptyRow && ($emptyRow->get('field_match_tier')->value !== 'error'),
  ];
  foreach ($checks9 as $k => $v) echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
  $results['step9_unmapped_uom_errors'] = !in_array(FALSE, $checks9, TRUE) ? 'PASS' : 'FAIL';

  // ── Step 10: admin page smoke-test ───────────────────────────────
  // Catches "route registered but page throws" bugs — the kind that
  // would otherwise ship undetected until an office user clicks the
  // link. Render each admin page via subrequest as uid 1 and assert
  // 200 OK. Add new pages here as the module surfaces new admin URLs.
  echo "\n=== Step 10: admin pages render cleanly (subrequest, uid 1) ===\n";
  \Drupal::currentUser()->setAccount(\Drupal\user\Entity\User::load(1));
  $httpKernel = \Drupal::service('http_kernel');

  // Phase 3.5 — per-batch URLs need a real batch ID. Promote $batch1
  // (parsed CSV from Step 5/6) to dry_run_complete by running the
  // matcher on it, so the Approve form's validation can render the
  // confirm prompt (Approve is gated to dry_run_complete batches).
  $smokeBatchId = (int) $batch1->id();
  try {
    $smokeBatch = $etm->getStorage('supplier_price_ingest_batch')->load($smokeBatchId);
    if ($smokeBatch && (string) $smokeBatch->get('field_status')->value === 'pending_dry_run') {
      \Drupal::service('supplier_price_ingest.matcher')->matchBatch($smokeBatch);
    }
  }
  catch (\Throwable $e) {
    echo "  WARN — failed to promote batch1 for smoke test: " . $e->getMessage() . "\n";
  }

  // Phase 3.7 — also need an ingest row id for the per-row operation
  // URLs. Pick any row from the smoke batch (they exist regardless of
  // matcher outcome). Operations don't require the row to be in a
  // specific status to RENDER — validation gates submit, not display —
  // so any row works for a 200-OK smoke check.
  $smokeRowId = 0;
  try {
    $smokeRowIds = $etm->getStorage('supplier_price_ingest_row')->getQuery()
      ->accessCheck(FALSE)
      ->condition('field_batch', $smokeBatchId)
      ->sort('id', 'ASC')
      ->range(0, 1)
      ->execute();
    $smokeRowId = $smokeRowIds ? (int) reset($smokeRowIds) : 0;
  }
  catch (\Throwable $e) {
    echo "  WARN — failed to resolve smoke row id: " . $e->getMessage() . "\n";
  }

  $pages = [
    '/admin/materials/supplier-ingest/upload'        => ['label' => 'Upload Catalog form', 'expect_ct' => NULL],
    '/admin/materials/supplier-ingest/configs'       => ['label' => 'Supplier Configs list (Views)', 'expect_ct' => NULL],
    '/admin/materials/supplier-ingest/configs/add'   => ['label' => 'Add Supplier Ingest Config', 'expect_ct' => NULL],
    '/admin/materials/supplier-ingest/batches/add'   => ['label' => 'Add Supplier Price Ingest Batch (preemptive)', 'expect_ct' => NULL],
    '/admin/materials/supplier-ingest/rows/add'      => ['label' => 'Add Supplier Price Ingest Row (preemptive)', 'expect_ct' => NULL],
    // Phase 3.5 — batch detail + approve/reject confirm forms + CSV export.
    "/admin/materials/supplier-ingest/batch/$smokeBatchId"            => ['label' => 'Batch Detail (dry-run report)', 'expect_ct' => NULL],
    "/admin/materials/supplier-ingest/batch/$smokeBatchId/approve"    => ['label' => 'Approve Batch confirm form', 'expect_ct' => NULL],
    "/admin/materials/supplier-ingest/batch/$smokeBatchId/reject"     => ['label' => 'Reject Batch confirm form', 'expect_ct' => NULL],
    "/admin/materials/supplier-ingest/batch/$smokeBatchId/export.csv" => ['label' => 'Batch CSV export', 'expect_ct' => 'text/csv'],
    // Phase 3.7 — three new Office Manager dashboards.
    '/admin/materials/supplier-ingest/batches'                       => ['label' => 'Batch Manager (Views)', 'expect_ct' => NULL],
    '/admin/materials/supplier-ingest/discovery'                     => ['label' => 'Discovery Queue (Views)', 'expect_ct' => NULL],
    '/admin/materials/supplier-ingest/fuzzy-review'                  => ['label' => 'Fuzzy Match Review (Views)', 'expect_ct' => NULL],
  ];
  // Phase 3.7 — 8 per-row operation URLs (skipped if no smoke row).
  if ($smokeRowId > 0) {
    $pages += [
      "/admin/materials/supplier-ingest/discovery/$smokeRowId/create-material"   => ['label' => 'Discovery — Create Material', 'expect_ct' => NULL],
      "/admin/materials/supplier-ingest/discovery/$smokeRowId/link-existing"     => ['label' => 'Discovery — Link to Existing', 'expect_ct' => NULL],
      "/admin/materials/supplier-ingest/discovery/$smokeRowId/mark-replacement"  => ['label' => 'Discovery — Mark as Replacement', 'expect_ct' => NULL],
      "/admin/materials/supplier-ingest/discovery/$smokeRowId/reject"            => ['label' => 'Discovery — Reject Row', 'expect_ct' => NULL],
      "/admin/materials/supplier-ingest/fuzzy-review/$smokeRowId/confirm"        => ['label' => 'Fuzzy Review — Confirm Match', 'expect_ct' => NULL],
      "/admin/materials/supplier-ingest/fuzzy-review/$smokeRowId/override"       => ['label' => 'Fuzzy Review — Override Match', 'expect_ct' => NULL],
      "/admin/materials/supplier-ingest/fuzzy-review/$smokeRowId/send-to-discovery" => ['label' => 'Fuzzy Review — Send to Discovery', 'expect_ct' => NULL],
      "/admin/materials/supplier-ingest/fuzzy-review/$smokeRowId/reject"         => ['label' => 'Fuzzy Review — Reject Row', 'expect_ct' => NULL],
    ];
  }
  $checks10 = [];
  foreach ($pages as $path => $meta) {
    $label = $meta['label'];
    $expectCt = $meta['expect_ct'];
    try {
      $subRequest = \Symfony\Component\HttpFoundation\Request::create($path, 'GET');
      $response = $httpKernel->handle($subRequest, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
      $code = $response->getStatusCode();
      $ctOk = TRUE;
      $ct = (string) $response->headers->get('Content-Type');
      if ($expectCt !== NULL) {
        $ctOk = str_contains($ct, $expectCt);
      }
      $pass = ($code === 200) && $ctOk;
      $checks10["$path → 200"] = $pass;
      echo sprintf(
        "  %s — %s (HTTP %d%s)\n",
        $pass ? 'PASS' : 'FAIL',
        $label,
        $code,
        $expectCt !== NULL ? sprintf(', Content-Type: %s', $ct ?: '(none)') : '',
      );
    }
    catch (\Throwable $e) {
      $checks10["$path → 200"] = FALSE;
      echo sprintf("  FAIL — %s EXCEPTION: %s\n", $label, $e->getMessage());
    }
  }
  $results['step10_admin_pages_smoke'] = !in_array(FALSE, $checks10, TRUE) ? 'PASS' : 'FAIL';

  // ── Step 11: BatchUploadForm — full form-submit lifecycle ────────
  // Catches form-level DI failures that service-level tests miss.
  // Specifically: when Drupal serializes a form between the AJAX
  // managed_file upload step and the final submit (which it does for
  // any form with managed_file), the `DependencySerializationTrait`
  // uses `get_object_vars($this)` from inside the trait's __sleep().
  // PRIVATE properties of the using class are invisible in trait
  // scope — so `private readonly` promoted properties get omitted
  // from serialization entirely, leaving them uninitialized after
  // unserialize. PHP then throws "must not be accessed before
  // initialization" the first time submit code touches them.
  //
  // Fix shipped 2026-05-25: all 10 forms changed from
  // `private readonly` → `protected` promoted properties. This step
  // is the regression guard.
  echo "\n=== Step 11: BatchUploadForm survives serialize/wakeup + submit ===\n";
  $checks11 = [];
  try {
    // Build a fixture supplier + active config so the form's
    // supplier dropdown has an option to submit against.
    $sup = $etm->getStorage('supplier')->create([
      'type' => 'supplier',
      'field_supplier_name' => 'P32-UploadFormTest-Sup',
      'uid' => 1,
    ]);
    $sup->save();
    $cleanup['suppliers'][] = (int) $sup->id();

    $cfg = $etm->getStorage('supplier_ingest_config')->create([
      'type' => 'config',
      'title' => 'P32-UploadFormTest-Cfg',
      'uid' => 1,
      'field_supplier' => $sup->id(),
      'field_active' => 1,
      'field_default_cost_uom' => 'each',
      'field_column_mapping' => json_encode([
        'source_columns' => [
          'SKU' => 'field_supplier_sku',
          'Mfr#' => 'field_manufacturer_item_number',
          'Brand' => 'field_manufacturer_name',
          'Desc' => 'field_description',
          'Price' => 'field_unit_cost',
          'UOM' => 'field_cost_uom',
        ],
        'header_row' => 1,
      ]),
    ]);
    $cfg->save();
    $cleanup['configs'][] = (int) $cfg->id();

    // Stage the CSV as a managed file (the form's managed_file
    // widget would have done this during the AJAX upload step).
    $stepCsv = "SKU,Mfr#,Brand,Desc,Price,UOM\nstep11-1,,,Step 11 upload-form regression row,1.00,each\n";
    $stepFile = $fileRepo->writeData(
      $stepCsv,
      "$uploadDir/spi_step11_upload.csv",
      \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE,
    );
    $stepFile->save(); // intentionally NOT setPermanent — form's submit handler promotes it
    $cleanup['files'][] = (int) $stepFile->id();

    // 1. Construct the form via class_resolver (mirrors what Drupal
    //    does on the first form-build request).
    $formClass = 'Drupal\\supplier_price_ingest\\Form\\BatchUploadForm';
    $form = \Drupal::service('class_resolver')->getInstanceFromDefinition($formClass);

    // 2. Round-trip through serialize/wakeup — mirrors what Drupal
    //    does between the AJAX managed_file upload and the final
    //    submit (form_state cache).
    $form = unserialize(serialize($form));

    // 3. Confirm all typed properties are still initialized post-wakeup.
    $reflect = new ReflectionClass($form);
    $uninit = [];
    foreach ($reflect->getProperties() as $p) {
      if ($p->isStatic() || str_starts_with($p->getName(), '_')) {
        continue;
      }
      if ($p->hasType() && $p->getType() && !$p->getType()->allowsNull()
          && !$p->isInitialized($form) && !$p->hasDefaultValue()) {
        $uninit[] = $p->getName();
      }
    }
    $checks11['post_wakeup_no_uninit_props'] = $uninit === [];
    if ($uninit) {
      echo "  uninit props after wakeup: " . implode(', ', $uninit) . "\n";
    }

    // 4. Call submitForm() DIRECTLY on the deserialized form
    //    instance. We bypass FormBuilder::submitForm() here because
    //    that path runs managed_file's element validator, which
    //    rejects programmatic submission (it expects to have
    //    processed a real upload pipeline). The bug we're guarding
    //    against fires inside submitForm() at the first property
    //    access — line ~118, $this->entityTypeManager->getStorage().
    //    If DI is broken, the throw happens before any business
    //    logic; the test just needs to prove submitForm() can read
    //    its own injected properties post-wakeup.
    $form_array = [];
    $form_state = new \Drupal\Core\Form\FormState();
    $form_state->setValues([
      'supplier' => (string) $sup->id(),
      'source_file' => [(string) $stepFile->id()],
      'notes' => 'Step 11 — verifier-generated upload',
    ]);
    $form->submitForm($form_array, $form_state);

    // 5. Confirm a batch was created (submitForm reads the file via
    //    $this->entityTypeManager, then creates the batch via the
    //    same path — both touch the previously-uninitialized
    //    property; both have to work for this assertion to pass).
    $createdBatches = $etm->getStorage('supplier_price_ingest_batch')
      ->loadByProperties(['field_supplier' => $sup->id()]);
    foreach ($createdBatches as $b) {
      $cleanup['batches'][] = (int) $b->id();
      foreach ($etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $b->id()]) as $r) {
        $cleanup['rows'][] = (int) $r->id();
      }
    }
    $checks11['batch_created'] = count($createdBatches) >= 1;

    foreach ($checks11 as $k => $v) {
      echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n";
    }
  }
  catch (\Throwable $e) {
    echo "  FAIL — exception during form submit: " . $e->getMessage() . "\n";
    echo "    class: " . get_class($e) . "\n";
    $checks11['no_exception'] = FALSE;
  }
  $results['step11_upload_form_full_lifecycle'] = (!empty($checks11) && !in_array(FALSE, $checks11, TRUE)) ? 'PASS' : 'FAIL';
}
finally {
  // Cleanup
  echo "\n=== Cleanup ===\n";
  foreach ($cleanup['rows'] as $id) {
    $e = $etm->getStorage('supplier_price_ingest_row')->load($id);
    if ($e) $e->delete();
  }
  foreach ($cleanup['batches'] as $id) {
    $e = $etm->getStorage('supplier_price_ingest_batch')->load($id);
    if ($e) $e->delete();
  }
  foreach ($cleanup['configs'] as $id) {
    $e = $etm->getStorage('supplier_ingest_config')->load($id);
    if ($e) $e->delete();
  }
  // Suppliers must come AFTER configs (config has a required reference
  // to the supplier).
  foreach ($cleanup['suppliers'] as $id) {
    $e = $etm->getStorage('supplier')->load($id);
    if ($e) $e->delete();
  }
  foreach ($cleanup['files'] as $id) {
    $f = $etm->getStorage('file')->load($id);
    if ($f) $f->delete();
  }
  echo "  done.\n";
}

echo "\n========== SUMMARY ==========\n";
$overall = 'PASS';
foreach ($results as $k => $v) {
  printf("  %-32s %s\n", $k, $v);
  if (!str_starts_with($v, 'PASS')) $overall = 'FAIL';
}
echo "----------------------------\n";
echo "  OVERALL                          $overall\n";
echo "=============================\n";
