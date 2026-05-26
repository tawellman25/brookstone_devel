<?php

declare(strict_types=1);

/**
 * Phase 3.3 — IngestMatcher end-to-end verification.
 *
 * Sibling to verify_supplier_price_ingest_schema.php (Phase 3.1) and
 * verify_supplier_price_ingest_parser.php (Phase 3.2). Tests Tier 1,
 * Tier 2, discontinued handling (retarget + orphan), bundle policy
 * enforcement, discovery routing, ambiguity handling, and the
 * supplier-do_not_use short-circuit.
 *
 * Creates and cleans up test fixtures (test supplier, test materials,
 * test manufacturer, test material_suppliers link, test
 * supplier_ingest_config, batches, rows, files).
 *
 * Usage:
 *   ddev drush scr web/scripts/verify_supplier_price_ingest_matcher.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\File\FileSystemInterface;

$etm        = \Drupal::entityTypeManager();
$parser     = \Drupal::service('supplier_price_ingest.parser');
$matcher    = \Drupal::service('supplier_price_ingest.matcher');
$fileRepo   = \Drupal::service('file.repository');
$fileSystem = \Drupal::service('file_system');

$uploadDir = 'public://supplier_ingest';
$fileSystem->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

$cleanup = [
  'configs' => [], 'batches' => [], 'rows' => [], 'files' => [],
  'materials' => [], 'links' => [], 'mfrs' => [], 'suppliers' => [],
];

// ── Pre-flight: find a supplier we can clone field_supplier_status on ──
// Use the first existing supplier as a "do_not_use" test target, but
// flip status only on a NEW test supplier we create, not on a real one.

$results = [];

try {
  // ── Fixture: test manufacturer ──────────────────────────────────
  // AEL overrides title with [manufacturer:field_name], so set field_name.
  $testMfr = $etm->getStorage('manufacturer')->create([
    'type' => 'manufacturer',
    'field_name' => 'TestMfr-Phase3.3',
    'uid' => 1,
  ]);
  $testMfr->save();
  $cleanup['mfrs'][] = (int) $testMfr->id();
  echo "Test manufacturer: id=" . $testMfr->id() . " label=" . $testMfr->label() . "\n";

  // ── Fixture: test supplier (the "main" supplier of the batch) ──
  // AEL overrides title with [supplier:field_supplier_name].
  $testSupplier = $etm->getStorage('supplier')->create([
    'type' => 'supplier',
    'field_supplier_name' => 'TestSupplier-Phase3.3',
    'uid' => 1,
  ]);
  $testSupplier->save();
  $cleanup['suppliers'][] = (int) $testSupplier->id();

  // ── Fixture: test supplier marked do_not_use ──────────────────
  $dnuSupplier = $etm->getStorage('supplier')->create([
    'type' => 'supplier',
    'field_supplier_name' => 'TestSupplier-DNU-Phase3.3',
    'uid' => 1,
    'field_supplier_status' => 'do_not_use',
  ]);
  $dnuSupplier->save();
  $cleanup['suppliers'][] = (int) $dnuSupplier->id();

  // ── Fixture: test materials ────────────────────────────────────
  // M1, M2: clean Tier 1 hits (irrigation bundle, both mapped to testMfr)
  $m1 = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M1',
    'uid' => 1,
    'field_manufacturer' => $testMfr->id(),
    'field_manufacturer_item_number' => 'P33-M1',
  ]);
  $m1->save();
  $cleanup['materials'][] = (int) $m1->id();

  $m2 = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M2',
    'uid' => 1,
    'field_manufacturer' => $testMfr->id(),
    'field_manufacturer_item_number' => 'P33-M2',
  ]);
  $m2->save();
  $cleanup['materials'][] = (int) $m2->id();

  // M3: discontinued with replacement (M3R)
  $m3r = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M3-Replacement',
    'uid' => 1,
    'field_manufacturer' => $testMfr->id(),
    'field_manufacturer_item_number' => 'P33-M3R',
  ]);
  $m3r->save();
  $cleanup['materials'][] = (int) $m3r->id();

  $m3 = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M3-Discontinued',
    'uid' => 1,
    'field_manufacturer' => $testMfr->id(),
    'field_manufacturer_item_number' => 'P33-M3',
    'field_discontinued' => 1,
    'field_replaced_by' => $m3r->id(),
  ]);
  $m3->save();
  $cleanup['materials'][] = (int) $m3->id();

  // M4: discontinued WITHOUT replacement
  $m4 = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M4-Orphan',
    'uid' => 1,
    'field_manufacturer' => $testMfr->id(),
    'field_manufacturer_item_number' => 'P33-M4',
    'field_discontinued' => 1,
  ]);
  $m4->save();
  $cleanup['materials'][] = (int) $m4->id();

  // M5a + M5b: same mfr + item # for ambiguity
  $m5a = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M5a',
    'uid' => 1,
    'field_manufacturer' => $testMfr->id(),
    'field_manufacturer_item_number' => 'P33-M5-AMBIG',
  ]);
  $m5a->save();
  $cleanup['materials'][] = (int) $m5a->id();
  $m5b = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M5b',
    'uid' => 1,
    'field_manufacturer' => $testMfr->id(),
    'field_manufacturer_item_number' => 'P33-M5-AMBIG',
  ]);
  $m5b->save();
  $cleanup['materials'][] = (int) $m5b->id();

  // M6: Tier 2 target (no mfr+item# match, only a material_suppliers SKU)
  $m6 = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-M6-Tier2',
    'uid' => 1,
    // No mfr / no mfr item #, so Tier 1 can never reach it.
  ]);
  $m6->save();
  $cleanup['materials'][] = (int) $m6->id();

  $link6 = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier',
    'title' => 'TestLink-Phase3.3',
    'uid' => 1,
    'field_supplier' => $testSupplier->id(),
    'field_material' => $m6->id(),
    'field_supplier_item_number' => 'T2-SKU-001',
    'field_supplier_unit_cost' => '11.11',
  ]);
  $link6->save();
  $cleanup['links'][] = (int) $link6->id();

  // M7: plants-bundle (excluded by default policy) for excluded-bundle test
  $m7 = $etm->getStorage('material')->create([
    'type' => 'plants',
    'title' => 'TestPlant-M7',
    'uid' => 1,
    // plants bundle doesn't carry field_manufacturer; can't use mfr+item#.
    // Will use a Tier 2 link instead so the matcher sees it and then
    // bundle policy excludes it.
  ]);
  $m7->save();
  $cleanup['materials'][] = (int) $m7->id();

  $link7 = $etm->getStorage('material_suppliers')->create([
    'type' => 'supplier',
    'title' => 'TestLink-Phase3.3-Plants',
    'uid' => 1,
    'field_supplier' => $testSupplier->id(),
    'field_material' => $m7->id(),
    'field_supplier_item_number' => 'T2-SKU-PLANT',
    'field_supplier_unit_cost' => '5.50',
  ]);
  $link7->save();
  $cleanup['links'][] = (int) $link7->id();

  // ── Fixture: supplier_ingest_config for testSupplier ────────────
  $cfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'TestConfig-Phase3.3',
    'uid' => 1,
    'field_supplier' => $testSupplier->id(),
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
      'plants'     => 'excluded',
    ]),
  ]);
  $cfg->save();
  $cleanup['configs'][] = (int) $cfg->id();

  // do_not_use supplier config — minimal, just so the upload form
  // could find it. Tests #8 uses this.
  $dnuCfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'TestConfig-DNU',
    'uid' => 1,
    'field_supplier' => $dnuSupplier->id(),
    'field_active' => 1,
    'field_column_mapping' => json_encode([
      'source_columns' => [
        'SKU' => 'field_supplier_sku',
        'Mfr#' => 'field_manufacturer_item_number',
        'Brand' => 'field_manufacturer_name',
        'Price' => 'field_unit_cost',
      ],
    ]),
  ]);
  $dnuCfg->save();
  $cleanup['configs'][] = (int) $dnuCfg->id();

  // ── Build the test CSV ──────────────────────────────────────────
  // Columns: SKU, Mfr#, Brand, Desc, Price, UOM
  $csv = "SKU,Mfr#,Brand,Desc,Price,UOM\n"
    // Step 1: two clean Tier 1
    . "anything-1,P33-M1,TestMfr-Phase3.3,Sprinkler one,5.00,each\n"
    . "anything-2,P33-M2,TestMfr-Phase3.3,Sprinkler two,6.00,each\n"
    // Step 2: Tier 1 hit on discontinued WITH replacement
    . "anything-3,P33-M3,TestMfr-Phase3.3,Old rotor,7.00,each\n"
    // Step 3: Tier 1 hit on discontinued WITHOUT replacement (orphan)
    . "anything-4,P33-M4,TestMfr-Phase3.3,Obsolete part,8.00,each\n"
    // Step 4: Tier 1 ambiguous
    . "anything-5,P33-M5-AMBIG,TestMfr-Phase3.3,Ambig item,9.00,each\n"
    // Step 5: Tier 2 hit — no mfr+item#, matches via supplier_sku
    . "T2-SKU-001,,,T2 lookup,10.00,each\n"
    // Step 6: discovery x2 — no mfr+item#, no Tier 2 SKU
    . "anything-7,P33-NEVER,TestMfr-Phase3.3,Brand new item,11.00,each\n"
    . "anything-8,P33-NEVER-2,UnknownBrand,No mfr in DB,12.00,each\n"
    // Step 7: excluded bundle — Tier 2 match against plants material
    . "T2-SKU-PLANT,,,Plants row should be excluded,13.00,each\n";

  $csvFile = $fileRepo->writeData($csv, "$uploadDir/spi_test_matcher.csv", FileSystemInterface::EXISTS_REPLACE);
  $csvFile->setPermanent();
  $csvFile->save();
  $cleanup['files'][] = (int) $csvFile->id();

  // ── Step 1: upload + parse + auto-match (testSupplier) ──────────
  echo "\n=== Steps 1–7 (single batch): parse + match against TestSupplier ===\n";
  $batch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — matcher 3.3',
    'uid' => 1,
    'field_supplier' => $testSupplier->id(),
    'field_source_file' => $csvFile->id(),
    'field_source_filename' => 'spi_test_matcher.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch->save();
  $cleanup['batches'][] = (int) $batch->id();

  $parseResult = $parser->parseUploadedFile($batch);
  echo "  parser: " . $parseResult->summary() . "\n";
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());

  $matchResult = $matcher->matchBatch($batch);
  echo "  matcher: " . $matchResult->summary() . "\n";

  // Reload batch + rows
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());
  $rows = $etm->getStorage('supplier_price_ingest_row')
    ->loadByProperties(['field_batch' => $batch->id()]);
  foreach ($rows as $r) $cleanup['rows'][] = (int) $r->id();

  // Build map by supplier_sku / mfr_item# for assertions
  $byMfrItem = [];
  $bySku = [];
  foreach ($rows as $r) {
    $mfr = $r->get('field_manufacturer_item_number')->value;
    if ($mfr) $byMfrItem[$mfr] = $r;
    $sku = $r->get('field_supplier_sku')->value;
    if ($sku) $bySku[$sku] = $r;
  }

  // ── Step 1: two clean Tier 1 ────────────────────────────────────
  $checks = [];
  foreach (['P33-M1' => $m1->id(), 'P33-M2' => $m2->id()] as $item => $expectedMatId) {
    $r = $byMfrItem[$item] ?? NULL;
    $checks["step1_$item _tier1"]        = $r && ($r->get('field_match_tier')->value === 'tier_1_mfr');
    $checks["step1_$item _confidence"]   = $r && ((int) $r->get('field_match_confidence')->value === 100);
    $checks["step1_$item _matched_mat"]  = $r && ((int) $r->get('field_matched_material')->target_id === (int) $expectedMatId);
  }

  // ── Step 2: Tier 1 → discontinued WITH replacement ──────────────
  $r3 = $byMfrItem['P33-M3'] ?? NULL;
  $checks['step2_tier_set']             = $r3 && ($r3->get('field_match_tier')->value === 'tier_1_mfr');
  $checks['step2_confidence_95']        = $r3 && ((int) $r3->get('field_match_confidence')->value === 95);
  $checks['step2_retargeted_to_m3r']    = $r3 && ((int) $r3->get('field_matched_material')->target_id === (int) $m3r->id());
  $checks['step2_note_mentions_replace'] = $r3 && (stripos((string) $r3->get('field_resolution_notes')->value, 'retargeted to replacement') !== FALSE);

  // ── Step 3: Tier 1 → discontinued ORPHAN ────────────────────────
  $r4 = $byMfrItem['P33-M4'] ?? NULL;
  $checks['step3_skipped_discontinued'] = $r4 && ($r4->get('field_match_tier')->value === 'skipped_discontinued');
  $checks['step3_no_matched_material']  = $r4 && $r4->get('field_matched_material')->isEmpty();
  $checks['step3_note_mentions_no_replacement'] = $r4 && (stripos((string) $r4->get('field_resolution_notes')->value, 'no replacement') !== FALSE);

  // ── Step 4: Tier 1 ambiguous ────────────────────────────────────
  $r5 = $byMfrItem['P33-M5-AMBIG'] ?? NULL;
  $checks['step4_routed_to_fuzzy_med']  = $r5 && ($r5->get('field_match_tier')->value === 'tier_3_fuzzy_med');
  $checks['step4_confidence_50']        = $r5 && ((int) $r5->get('field_match_confidence')->value === 50);
  $checks['step4_picks_lowest_id_first'] = $r5 && ((int) $r5->get('field_matched_material')->target_id === (int) $m5a->id());
  $checks['step4_note_mentions_ambig']  = $r5 && (stripos((string) $r5->get('field_resolution_notes')->value, 'ambiguous') !== FALSE);

  // ── Step 5: Tier 2 hit ──────────────────────────────────────────
  $r6 = $bySku['T2-SKU-001'] ?? NULL;
  $checks['step5_tier_2']               = $r6 && ($r6->get('field_match_tier')->value === 'tier_2_supplier_sku');
  $checks['step5_confidence_100']       = $r6 && ((int) $r6->get('field_match_confidence')->value === 100);
  $checks['step5_matched_m6']           = $r6 && ((int) $r6->get('field_matched_material')->target_id === (int) $m6->id());
  $checks['step5_existing_link']        = $r6 && ((int) $r6->get('field_existing_link')->target_id === (int) $link6->id());

  // ── Step 6: discovery x2 ────────────────────────────────────────
  $rDisc1 = $byMfrItem['P33-NEVER'] ?? NULL;
  $rDisc2 = $byMfrItem['P33-NEVER-2'] ?? NULL;
  $checks['step6_disc1_discovery']      = $rDisc1 && ($rDisc1->get('field_match_tier')->value === 'discovery');
  $checks['step6_disc1_conf_0']         = $rDisc1 && ((int) $rDisc1->get('field_match_confidence')->value === 0);
  $checks['step6_disc2_discovery']      = $rDisc2 && ($rDisc2->get('field_match_tier')->value === 'discovery');

  // ── Step 7: excluded bundle ─────────────────────────────────────
  $rExc = $bySku['T2-SKU-PLANT'] ?? NULL;
  $checks['step7_skipped_excluded']     = $rExc && ($rExc->get('field_match_tier')->value === 'skipped_excluded_bundle');
  $checks['step7_no_matched_material']  = $rExc && $rExc->get('field_matched_material')->isEmpty();

  // ── Batch rollups ───────────────────────────────────────────────
  $batchCounts = [
    'tier1' => (int) $batch->get('field_row_count_tier1')->value,
    'tier2' => (int) $batch->get('field_row_count_tier2')->value,
    'tier3_med' => (int) $batch->get('field_row_count_tier3_med')->value,
    'discovery' => (int) $batch->get('field_row_count_discovery')->value,
    'skipped' => (int) $batch->get('field_row_count_skipped')->value,
    'total' => (int) $batch->get('field_row_count_total')->value,
  ];
  echo "  batch rollups: " . json_encode($batchCounts) . "\n";
  $checks['rollup_tier1_3']             = $batchCounts['tier1'] === 3; // M1, M2, M3 (retargeted, still tier_1_mfr)
  $checks['rollup_tier2_1']             = $batchCounts['tier2'] === 1; // T2-SKU-001
  $checks['rollup_tier3_med_1']         = $batchCounts['tier3_med'] === 1; // ambiguous
  $checks['rollup_discovery_2']         = $batchCounts['discovery'] === 2;
  $checks['rollup_skipped_2']           = $batchCounts['skipped'] === 2; // orphan + excluded

  $checks['batch_status_dry_run_complete'] = (string) $batch->get('field_status')->value === 'dry_run_complete';

  foreach ($checks as $k => $v) echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
  $results['steps_1_7'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ── Step 8: do_not_use supplier short-circuit ──────────────────
  echo "\n=== Step 8: supplier do_not_use → all rows skipped ===\n";
  $dnuCsv = "SKU,Mfr#,Brand,Price\nrow1,P33-M1,TestMfr-Phase3.3,5.00\nrow2,P33-M2,TestMfr-Phase3.3,6.00\n";
  $dnuFile = $fileRepo->writeData($dnuCsv, "$uploadDir/spi_test_dnu.csv", FileSystemInterface::EXISTS_REPLACE);
  $dnuFile->setPermanent();
  $dnuFile->save();
  $cleanup['files'][] = (int) $dnuFile->id();

  $dnuBatch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — DNU supplier',
    'uid' => 1,
    'field_supplier' => $dnuSupplier->id(),
    'field_source_file' => $dnuFile->id(),
    'field_source_filename' => 'spi_test_dnu.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $dnuBatch->save();
  $cleanup['batches'][] = (int) $dnuBatch->id();

  $parser->parseUploadedFile($dnuBatch);
  $dnuBatch = $etm->getStorage('supplier_price_ingest_batch')->load($dnuBatch->id());
  $dnuResult = $matcher->matchBatch($dnuBatch);
  echo "  matcher: " . $dnuResult->summary() . "\n";

  $dnuRows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $dnuBatch->id()]);
  foreach ($dnuRows as $r) $cleanup['rows'][] = (int) $r->id();
  $allDnu = TRUE;
  foreach ($dnuRows as $r) {
    if (($r->get('field_match_tier')->value ?? '') !== 'skipped_do_not_use') $allDnu = FALSE;
  }
  $dnuBatch = $etm->getStorage('supplier_price_ingest_batch')->load($dnuBatch->id());
  $checks8 = [
    'all_rows_skipped_dnu' => $allDnu && count($dnuRows) === 2,
    'batch_dry_run_complete' => (string) $dnuBatch->get('field_status')->value === 'dry_run_complete',
  ];
  foreach ($checks8 as $k => $v) echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
  $results['step_8_dnu'] = !in_array(FALSE, $checks8, TRUE) ? 'PASS' : 'FAIL';

  // ── Step 9: determinism — same input twice → identical outcomes ─
  // Regression guard against the range()-without-sort() class of bug
  // surfaced by Phase 3.4 verification (see range_audit_2026-05-25.md).
  // Re-parse the same CSV against a fresh batch, run the matcher,
  // compare every row's match_tier / match_confidence / matched_material
  // against the first batch's outcome.
  echo "\n=== Step 9: determinism — re-run produces identical outcomes ===\n";
  $batch2 = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — determinism re-run',
    'uid' => 1,
    'field_supplier' => $testSupplier->id(),
    'field_source_file' => $csvFile->id(),
    'field_source_filename' => 'spi_test_matcher.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch2->save();
  $cleanup['batches'][] = (int) $batch2->id();

  $parser->parseUploadedFile($batch2);
  $batch2 = $etm->getStorage('supplier_price_ingest_batch')->load($batch2->id());
  $matcher->matchBatch($batch2);

  $rows2 = $etm->getStorage('supplier_price_ingest_row')
    ->loadByProperties(['field_batch' => $batch2->id()]);
  foreach ($rows2 as $r) $cleanup['rows'][] = (int) $r->id();

  // Build per-row keys (supplier_sku or mfr_item_number, whichever
  // identifies the row) so we can compare batch1 vs batch2 row-by-row.
  $keyFn = function ($r): string {
    $sku = (string) ($r->get('field_supplier_sku')->value ?? '');
    $mfr = (string) ($r->get('field_manufacturer_item_number')->value ?? '');
    $desc = (string) ($r->get('field_description')->value ?? '');
    return $sku !== '' ? "sku:$sku" : ($mfr !== '' ? "mfr:$mfr" : "desc:$desc");
  };
  $signature = function ($r): array {
    return [
      'tier'       => (string) ($r->get('field_match_tier')->value ?? ''),
      'confidence' => (string) ($r->get('field_match_confidence')->value ?? ''),
      'matched'   => (string) ($r->get('field_matched_material')->target_id ?? ''),
    ];
  };

  $b1Signatures = [];
  foreach ($rows as $r) $b1Signatures[$keyFn($r)] = $signature($r);
  $b2Signatures = [];
  foreach ($rows2 as $r) $b2Signatures[$keyFn($r)] = $signature($r);

  $mismatches = [];
  $compared = 0;
  foreach ($b1Signatures as $key => $sig1) {
    $sig2 = $b2Signatures[$key] ?? NULL;
    if ($sig2 === NULL) {
      $mismatches[] = "$key — missing in re-run";
      continue;
    }
    $compared++;
    if ($sig1 !== $sig2) {
      $mismatches[] = sprintf(
        '%s — run1=%s, run2=%s',
        $key,
        json_encode($sig1),
        json_encode($sig2),
      );
    }
  }
  echo "  compared $compared rows; mismatches: " . count($mismatches) . "\n";
  foreach ($mismatches as $m) echo "    $m\n";
  $checks9 = [
    'compared_at_least_5_rows' => $compared >= 5,
    'no_mismatches'            => count($mismatches) === 0,
  ];
  foreach ($checks9 as $k => $v) echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
  $results['step_9_determinism'] = !in_array(FALSE, $checks9, TRUE) ? 'PASS' : 'FAIL';

  // ── Step 10: Phase 3.10 — SKU normalization + supplier transformations ──
  // Three scenarios from the Phase 3.10 spec:
  //   10a. Hyphen normalization match (row "1806PRS" → BOS "1806-PRS")
  //   10b. Supplier-specific prefix strip + normalization (strip_prefix=["R"];
  //        row "R15H" → BOS "15H")
  //   10c. Transformation with no match (row "Z99Q" — no prefix to strip,
  //        no matching material — falls through cleanly)
  //
  // Uses a dedicated supplier + config + fixture materials so it can't
  // interfere with the earlier steps' fixtures.
  echo "\n=== Step 10: Phase 3.10 SKU normalization + supplier transformations ===\n";

  // Fresh fixtures.
  $normMfr = $etm->getStorage('manufacturer')->create([
    'type' => 'manufacturer',
    'field_name' => 'TestMfr-P310',
    'uid' => 1,
  ]);
  $normMfr->save();
  $cleanup['mfrs'][] = (int) $normMfr->id();

  $normSup = $etm->getStorage('supplier')->create([
    'type' => 'supplier',
    'field_supplier_name' => 'TestSupplier-P310',
    'uid' => 1,
  ]);
  $normSup->save();
  $cleanup['suppliers'][] = (int) $normSup->id();

  // BOS materials: stored in BOS-native form
  //   1806-PRS (with hyphen, BOS conventional spelling)
  //   15H       (bare manufacturer-native form)
  $m_1806 = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-1806-PRS',
    'uid' => 1,
    'field_manufacturer' => $normMfr->id(),
    'field_manufacturer_item_number' => '1806-PRS',
  ]);
  $m_1806->save();
  $cleanup['materials'][] = (int) $m_1806->id();

  $m_15H = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-15H',
    'uid' => 1,
    'field_manufacturer' => $normMfr->id(),
    'field_manufacturer_item_number' => '15H',
  ]);
  $m_15H->save();
  $cleanup['materials'][] = (int) $m_15H->id();

  // Config with strip_prefix=["R"].
  $normCfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'TestConfig-P310',
    'uid' => 1,
    'field_supplier' => $normSup->id(),
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
    'field_bundle_policy' => json_encode(['irrigation' => 'matched_only']),
    'field_sku_transformations' => json_encode(['strip_prefix' => ['R'], 'strip_suffix' => []]),
  ]);
  $normCfg->save();
  $cleanup['configs'][] = (int) $normCfg->id();

  // CSV with three rows:
  //   row-A: mfr-item "1806PRS"  → should match 1806-PRS via normalization
  //   row-B: mfr-item "R15H"     → strip R → "15H" → match 15H via transformation
  //   row-C: mfr-item "Z99Q"     → no match anywhere → falls through
  $normCsv = "SKU,Mfr#,Brand,Desc,Price,UOM\n"
    . "anything-a,1806PRS,TestMfr-P310,row A hyphen-norm,1.00,each\n"
    . "anything-b,R15H,TestMfr-P310,row B prefix strip,2.00,each\n"
    . "anything-c,Z99Q,TestMfr-P310,row C no match,3.00,each\n";

  $normFile = $fileRepo->writeData($normCsv, "$uploadDir/spi_test_p310_norm.csv", FileSystemInterface::EXISTS_REPLACE);
  $normFile->setPermanent();
  $normFile->save();
  $cleanup['files'][] = (int) $normFile->id();

  $normBatch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — Phase 3.10 SKU norm',
    'uid' => 1,
    'field_supplier' => $normSup->id(),
    'field_source_file' => $normFile->id(),
    'field_source_filename' => 'spi_test_p310_norm.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $normBatch->save();
  $cleanup['batches'][] = (int) $normBatch->id();

  $parser->parseUploadedFile($normBatch);
  $normBatch = $etm->getStorage('supplier_price_ingest_batch')->load($normBatch->id());
  $normResult = $matcher->matchBatch($normBatch);
  echo "  matcher: " . $normResult->summary() . "\n";

  $normRows = $etm->getStorage('supplier_price_ingest_row')
    ->loadByProperties(['field_batch' => $normBatch->id()]);
  foreach ($normRows as $r) {
    $cleanup['rows'][] = (int) $r->id();
  }
  $normBySku = [];
  foreach ($normRows as $r) {
    $sku = (string) ($r->get('field_supplier_sku')->value ?? '');
    if ($sku !== '') {
      $normBySku[$sku] = $r;
    }
  }

  // 10a — hyphen normalization
  $rA = $normBySku['anything-a'] ?? NULL;
  $checks10a = [
    'tier_1_hit'         => $rA && (string) $rA->get('field_match_tier')->value === 'tier_1_mfr',
    'matched_material'   => $rA && (int) $rA->get('field_matched_material')->target_id === (int) $m_1806->id(),
    'confidence_100'     => $rA && (int) $rA->get('field_match_confidence')->value === 100,
    'note_has_normalization' => $rA && stripos((string) $rA->get('field_resolution_notes')->value, 'normalization') !== FALSE,
    'note_no_transformation' => $rA && stripos((string) $rA->get('field_resolution_notes')->value, 'transformation') === FALSE,
  ];
  echo "  -- 10a: hyphen normalization (row 1806PRS → BOS 1806-PRS) --\n";
  foreach ($checks10a as $k => $v) echo "    " . ($v ? 'PASS' : 'FAIL') . " — $k\n";

  // 10b — prefix strip + normalization
  $rB = $normBySku['anything-b'] ?? NULL;
  $checks10b = [
    'tier_1_hit'           => $rB && (string) $rB->get('field_match_tier')->value === 'tier_1_mfr',
    'matched_material'     => $rB && (int) $rB->get('field_matched_material')->target_id === (int) $m_15H->id(),
    'confidence_100'       => $rB && (int) $rB->get('field_match_confidence')->value === 100,
    'note_has_transformation' => $rB && stripos((string) $rB->get('field_resolution_notes')->value, 'transformation') !== FALSE,
    'note_mentions_strip'  => $rB && stripos((string) $rB->get('field_resolution_notes')->value, "stripped to '15H'") !== FALSE,
  ];
  echo "  -- 10b: prefix strip (row R15H → strip R → BOS 15H) --\n";
  foreach ($checks10b as $k => $v) echo "    " . ($v ? 'PASS' : 'FAIL') . " — $k\n";

  // 10c — no match; falls through. The fixture supplier's policy lists
  // only irrigation→matched_only, so an unmatched row goes to
  // skipped_excluded_bundle (no discovery-enabled bundles). Either way:
  // not tier_1_mfr, not tier_2_supplier_sku.
  $rC = $normBySku['anything-c'] ?? NULL;
  $checks10c = [
    'not_tier1' => $rC && (string) $rC->get('field_match_tier')->value !== 'tier_1_mfr',
    'not_tier2' => $rC && (string) $rC->get('field_match_tier')->value !== 'tier_2_supplier_sku',
    'no_matched_material' => $rC && $rC->get('field_matched_material')->isEmpty(),
  ];
  echo "  -- 10c: no transformation match (row Z99Q falls through) --\n";
  foreach ($checks10c as $k => $v) echo "    " . ($v ? 'PASS' : 'FAIL') . " — $k\n";

  $all10 = array_merge($checks10a, $checks10b, $checks10c);
  $results['step_10_sku_norm_and_transform'] = !in_array(FALSE, $all10, TRUE) ? 'PASS' : 'FAIL';

  // ── Step 11: end-to-end SiteOne R15H path (1:many destination
  //              + strip_prefix transformation, no Mfr# column) ─────
  // The SiteOne CSV does NOT have a separate manufacturer item-number
  // column — the supplier_item_number column is re-used. The 1:many
  // destination shape ("supplier_item_number": ["field_supplier_sku",
  // "field_manufacturer_item_number"]) closes that gap: same source
  // cell → both BOS row fields. Combined with strip_prefix=["R"],
  // a raw "R15H" cell must Tier-1 match a BOS material whose
  // field_manufacturer_item_number is "15H".
  echo "\n=== Step 11: 1:many destination + strip_prefix (no Mfr# column) ===\n";

  $e2eMfr = $etm->getStorage('manufacturer')->create([
    'type' => 'manufacturer',
    'field_name' => 'TestMfr-E2E',
    'uid' => 1,
  ]);
  $e2eMfr->save();
  $cleanup['mfrs'][] = (int) $e2eMfr->id();

  $e2eSup = $etm->getStorage('supplier')->create([
    'type' => 'supplier',
    'field_supplier_name' => 'TestSupplier-E2E',
    'uid' => 1,
  ]);
  $e2eSup->save();
  $cleanup['suppliers'][] = (int) $e2eSup->id();

  $e2eMat = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'title' => 'TestMaterial-15H-E2E',
    'uid' => 1,
    'field_manufacturer' => $e2eMfr->id(),
    'field_manufacturer_item_number' => '15H',
  ]);
  $e2eMat->save();
  $cleanup['materials'][] = (int) $e2eMat->id();

  $e2eCfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'TestConfig-E2E',
    'uid' => 1,
    'field_supplier' => $e2eSup->id(),
    'field_active' => 1,
    'field_default_cost_uom' => 'each',
    'field_column_mapping' => json_encode([
      'source_columns' => [
        // SiteOne-style: one CSV column → both SKU and Mfr#.
        'supplier_item_number' => ['field_supplier_sku', 'field_manufacturer_item_number'],
        'product_name'         => 'field_description',
        'manufacturer_inferred' => 'field_manufacturer_name',
        'your_price'           => 'field_unit_cost',
        'cost_uom'             => 'field_cost_uom',
      ],
      'header_row' => 1,
    ]),
    'field_bundle_policy' => json_encode(['irrigation' => 'matched_only']),
    'field_sku_transformations' => json_encode(['strip_prefix' => ['R'], 'strip_suffix' => []]),
  ]);
  $e2eCfg->save();
  $cleanup['configs'][] = (int) $e2eCfg->id();

  $e2eCsv = "supplier_item_number,product_name,manufacturer_inferred,your_price,cost_uom\n"
    . "R15H,Rain Bird R15H nozzle,TestMfr-E2E,2.95,each\n";
  $e2eFile = $fileRepo->writeData($e2eCsv, "$uploadDir/spi_test_e2e_siteone.csv", FileSystemInterface::EXISTS_REPLACE);
  $e2eFile->setPermanent();
  $e2eFile->save();
  $cleanup['files'][] = (int) $e2eFile->id();

  $e2eBatch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — E2E SiteOne 1:many',
    'uid' => 1,
    'field_supplier' => $e2eSup->id(),
    'field_source_file' => $e2eFile->id(),
    'field_source_filename' => 'spi_test_e2e_siteone.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $e2eBatch->save();
  $cleanup['batches'][] = (int) $e2eBatch->id();

  $parser->parseUploadedFile($e2eBatch);
  $e2eBatch = $etm->getStorage('supplier_price_ingest_batch')->load($e2eBatch->id());
  $e2eResult = $matcher->matchBatch($e2eBatch);
  echo "  matcher: " . $e2eResult->summary() . "\n";

  $e2eRows = $etm->getStorage('supplier_price_ingest_row')
    ->loadByProperties(['field_batch' => $e2eBatch->id()]);
  foreach ($e2eRows as $r) $cleanup['rows'][] = (int) $r->id();
  $row11 = reset($e2eRows);

  $checks11 = [];
  if ($row11) {
    $sku = trim((string) ($row11->get('field_supplier_sku')->value ?? ''));
    $mfr = trim((string) ($row11->get('field_manufacturer_item_number')->value ?? ''));
    $checks11['parser_populated_sku']          = ($sku === 'R15H');
    $checks11['parser_populated_mfr']          = ($mfr === 'R15H');
    $checks11['tier_1_hit']                    = (string) $row11->get('field_match_tier')->value === 'tier_1_mfr';
    $checks11['matched_expected_material']     = (int) $row11->get('field_matched_material')->target_id === (int) $e2eMat->id();
    $checks11['confidence_100']                = (int) $row11->get('field_match_confidence')->value === 100;
    $notes = (string) $row11->get('field_resolution_notes')->value;
    $checks11['note_mentions_transformation']  = stripos($notes, 'transformation') !== FALSE;
    $checks11['note_mentions_strip']           = stripos($notes, "stripped to '15H'") !== FALSE;
  }
  else {
    $checks11['row_parsed'] = FALSE;
  }
  foreach ($checks11 as $k => $v) echo "  " . ($v ? 'PASS' : 'FAIL') . " — $k\n";
  $results['step_11_e2e_siteone_1many_plus_strip'] = !in_array(FALSE, $checks11, TRUE) ? 'PASS' : 'FAIL';
}
finally {
  echo "\n=== Cleanup ===\n";
  // Order matters: rows + batches before configs (rows reference batches);
  // links before materials; materials/mfrs/suppliers can go last.
  foreach ($cleanup['rows'] as $id)      { $e = $etm->getStorage('supplier_price_ingest_row')->load($id); if ($e) $e->delete(); }
  foreach ($cleanup['batches'] as $id)   { $e = $etm->getStorage('supplier_price_ingest_batch')->load($id); if ($e) $e->delete(); }
  foreach ($cleanup['configs'] as $id)   { $e = $etm->getStorage('supplier_ingest_config')->load($id); if ($e) $e->delete(); }
  foreach ($cleanup['links'] as $id)     { $e = $etm->getStorage('material_suppliers')->load($id); if ($e) $e->delete(); }
  foreach ($cleanup['materials'] as $id) { $e = $etm->getStorage('material')->load($id); if ($e) $e->delete(); }
  foreach ($cleanup['mfrs'] as $id)      { $e = $etm->getStorage('manufacturer')->load($id); if ($e) $e->delete(); }
  foreach ($cleanup['suppliers'] as $id) { $e = $etm->getStorage('supplier')->load($id); if ($e) $e->delete(); }
  foreach ($cleanup['files'] as $id)     { $f = $etm->getStorage('file')->load($id); if ($f) $f->delete(); }
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
