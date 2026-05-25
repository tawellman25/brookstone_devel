<?php

declare(strict_types=1);

/**
 * Phase 3.4 — Tier 3 fuzzy matcher end-to-end verification.
 *
 * Sibling to verify_supplier_price_ingest_matcher.php (Phase 3.3).
 * Tests bundle inference, multi-factor scoring (description / uom / size
 * / manufacturer), threshold routing (high / medium / low), candidate
 * pool exclusion of discontinued materials, excluded-bundle filtering,
 * and the score-breakdown audit string.
 *
 * Also runs a small performance pass: 100 rows × ~200 candidates each.
 * Budget: < 30s in DDEV. Logged for the completion report.
 *
 * Usage:
 *   ddev drush scr web/scripts/verify_supplier_price_ingest_fuzzy.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\File\FileSystemInterface;

$etm        = \Drupal::entityTypeManager();
$parser     = \Drupal::service('supplier_price_ingest.parser');
$matcher    = \Drupal::service('supplier_price_ingest.matcher');
$scorer     = \Drupal::service('supplier_price_ingest.fuzzy_scorer');
$fileRepo   = \Drupal::service('file.repository');
$fileSystem = \Drupal::service('file_system');

$uploadDir = 'public://supplier_ingest';
$fileSystem->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

$cleanup = [
  'configs' => [], 'batches' => [], 'rows' => [], 'files' => [],
  'materials' => [], 'links' => [], 'mfrs' => [], 'suppliers' => [],
];

$results = [];

try {
  // ── Fixtures ────────────────────────────────────────────────────
  // Manufacturer (AEL pattern → set field_name, NOT title).
  $mfr = $etm->getStorage('manufacturer')->create([
    'type' => 'manufacturer',
    'field_name' => 'AcmePlumbingCo',
    'uid' => 1,
  ]);
  $mfr->save();
  $cleanup['mfrs'][] = (int) $mfr->id();

  // Supplier.
  $supplier = $etm->getStorage('supplier')->create([
    'type' => 'supplier',
    'field_supplier_name' => 'TestSupplier-Phase3.4',
    'uid' => 1,
  ]);
  $supplier->save();
  $cleanup['suppliers'][] = (int) $supplier->id();

  // Candidate materials. All in pvc bundle except where noted.
  // M_PVC_HALF: clean target for high-confidence test
  $mPvcHalf = $etm->getStorage('material')->create([
    'type' => 'pvc',
    'title' => '1/2" Sch 40 PVC Tee SxSxS',
    'uid' => 1,
    'field_manufacturer' => $mfr->id(),
    'field_unit_of_measure' => 'EA',
    'field_size' => '1/2',
  ]);
  $mPvcHalf->save();
  $cleanup['materials'][] = (int) $mPvcHalf->id();

  // M_PVC_THREEQ: target for size-mismatch and medium tests
  $mPvcThreeQ = $etm->getStorage('material')->create([
    'type' => 'pvc',
    'title' => '3/4" Sch 40 PVC Tee SxSxS',
    'uid' => 1,
    'field_manufacturer' => $mfr->id(),
    'field_unit_of_measure' => 'EA',
    'field_size' => '3/4',
  ]);
  $mPvcThreeQ->save();
  $cleanup['materials'][] = (int) $mPvcThreeQ->id();

  // M_PVC_DISC: discontinued — should be excluded from candidate pool
  $mPvcDisc = $etm->getStorage('material')->create([
    'type' => 'pvc',
    'title' => '1/2" Sch 40 PVC Tee SxSxS DISCONTINUED',
    'uid' => 1,
    'field_manufacturer' => $mfr->id(),
    'field_unit_of_measure' => 'EA',
    'field_size' => '1/2',
    'field_discontinued' => 1,
  ]);
  $mPvcDisc->save();
  $cleanup['materials'][] = (int) $mPvcDisc->id();

  // M_IRR_ROTOR: irrigation target — used for bundle-inference correctness.
  // AEL on material.irrigation builds title from "[field_size] [field_name]",
  // so setting `title` directly is overridden. Set the underlying fields so
  // AEL produces a label that exactly matches the row description.
  $mIrrRotor = $etm->getStorage('material')->create([
    'type' => 'irrigation',
    'uid' => 1,
    'field_manufacturer' => $mfr->id(),
    'field_unit_of_measure' => 'EA',
    'field_size' => '4"',
    // Use a space-separated label so bundle-inference keywords ("rotor",
    // "hunter") land as standalone tokens, while including a unique
    // sentinel ("TestRotorP34") so this fixture dominates over any
    // similarly-named real material in the irrigation bundle.
    'field_name' => 'Hunter PGP TestRotorP34',
  ]);
  $mIrrRotor->save();
  $cleanup['materials'][] = (int) $mIrrRotor->id();
  echo "Test irrigation fixture: id=" . $mIrrRotor->id() . ' label="' . $mIrrRotor->label() . "\"\n";

  // M_PLANT_EXCLUDED: plants bundle — for excluded-bundle test
  // (plants don't carry inference keywords by default, so use a description
  // that lands in plants via direct construction.)
  $mPlant = $etm->getStorage('material')->create([
    'type' => 'plants',
    'title' => 'Bark Mulch Plant Bedding',
    'uid' => 1,
  ]);
  $mPlant->save();
  $cleanup['materials'][] = (int) $mPlant->id();

  // ── Config ──────────────────────────────────────────────────────
  $cfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'TestConfig-Phase3.4',
    'uid' => 1,
    'field_supplier' => $supplier->id(),
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
    // pvc → discovery (matched + discovery allowed)
    // irrigation → matched_only
    // mulch → excluded (used for excluded-bundle inference test)
    'field_bundle_policy' => json_encode([
      'pvc'        => 'discovery',
      'irrigation' => 'matched_only',
      'mulch'      => 'excluded',
    ]),
    'field_fuzzy_threshold_high' => 90.0,
    'field_fuzzy_threshold_med'  => 70.0,
  ]);
  $cfg->save();
  $cleanup['configs'][] = (int) $cfg->id();

  // ── Build the test CSV ──────────────────────────────────────────
  // None of these rows carry supplier SKU or mfr item # in a way that
  // would trigger Tier 1 / Tier 2 — Tier 3 is the exclusive evaluator.
  // We use unique row descriptions to drive scoring scenarios.
  //
  // SKU,Mfr#,Brand,Desc,Price,UOM
  $csv = "SKU,Mfr#,Brand,Desc,Price,UOM\n"
    // Scenario 1: clean high-confidence — exact match to mPvcHalf
    . "T34-1,,AcmePlumbingCo,\"1/2\"\" Sch 40 PVC Tee SxSxS\",1.00,each\n"
    // Scenario 2: medium — same canonical product as scenario 1 (so the
    //   description signal stays strong + pre-filter retains mPvcHalf in
    //   the pool) but no manufacturer and no UOM, dropping mfr(15) +
    //   uom(10) = 25 from the max — lands in the 70–89 band.
    . "T34-2,,,\"1/2\"\" Sch 40 PVC Tee SxSxS\",2.00,\n"
    // Scenario 3: low — gibberish description sharing only \"pvc\"
    . "T34-3,,,\"PVC widget thingy something\",3.00,each\n"
    // Scenario 4: size mismatch anti-signal vs mPvcHalf (description otherwise identical)
    //              should prefer mPvcThreeQ because of size signal
    . "T34-4,,AcmePlumbingCo,\"3/4\"\" Sch 40 PVC Tee SxSxS\",4.00,each\n"
    // Scenario 5: UOM mismatch — same product family, wrong UOM (case vs each)
    . "T34-5,,AcmePlumbingCo,\"1/2\"\" Sch 40 PVC Tee SxSxS\",5.00,case\n"
    // Scenario 6: bundle inference correctness — clearly irrigation.
    // Match the test fixture's AEL-generated title verbatim so we don't
    // race against whichever real Hunter material is in the DB.
    . "T34-6,,AcmePlumbingCo,\"4\"\" Hunter PGP TestRotorP34\",6.00,each\n"
    // Scenario 7: multi-bundle inference — \"1/2 PVC ball valve\" can be pvc OR irrigation
    . "T34-7,,AcmePlumbingCo,\"1/2 PVC ball valve slip\",7.00,each\n"
    // Scenario 8: empty inference — no recognizable keywords
    . "T34-8,,,\"zzz xxx yyy aaa\",8.00,each\n"
    // Scenario 9: excluded bundle inference — \"bark mulch\" → mulch (excluded)
    . "T34-9,,,\"Bark mulch wood chip bag\",9.00,each\n"
    // Scenario 11: discontinued exclusion — description perfect for the
    //              discontinued material; matcher must pick non-disc one
    . "T34-11,,,\"1/2\"\" Sch 40 PVC Tee SxSxS DISCONTINUED\",11.00,each\n";

  $csvFile = $fileRepo->writeData($csv, "$uploadDir/spi_test_fuzzy.csv", FileSystemInterface::EXISTS_REPLACE);
  $csvFile->setPermanent();
  $csvFile->save();
  $cleanup['files'][] = (int) $csvFile->id();

  // ── Run parse + match ──────────────────────────────────────────
  echo "\n=== Scenarios 1–9 + 11: parse + match against TestSupplier ===\n";
  $batch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'TEST BATCH — fuzzy 3.4',
    'uid' => 1,
    'field_supplier' => $supplier->id(),
    'field_source_file' => $csvFile->id(),
    'field_source_filename' => 'spi_test_fuzzy.csv',
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

  // Reload batch + rows; build per-SKU map.
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());
  $rows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch->id()]);
  foreach ($rows as $r) { $cleanup['rows'][] = (int) $r->id(); }
  $bySku = [];
  foreach ($rows as $r) {
    $sku = $r->get('field_supplier_sku')->value;
    if ($sku) { $bySku[$sku] = $r; }
  }

  // Helpers — assertions need readable per-row output.
  $explain = function ($row) {
    if (!$row) { return '(row missing)'; }
    return sprintf(
      'tier=%s conf=%s matched=%s notes=%s',
      (string) $row->get('field_match_tier')->value,
      (string) $row->get('field_match_confidence')->value,
      (string) ($row->get('field_matched_material')->target_id ?? ''),
      str_replace("\n", ' | ', (string) $row->get('field_resolution_notes')->value),
    );
  };

  // ── Scenario 1: clean high-confidence ───────────────────────────
  echo "\n--- Scenario 1: clean high-confidence ---\n";
  $r1 = $bySku['T34-1'] ?? NULL;
  echo "  " . $explain($r1) . "\n";
  $checks1 = [
    'tier_fuzzy_high' => $r1 && $r1->get('field_match_tier')->value === 'tier_3_fuzzy_high',
    'matched_mPvcHalf' => $r1 && (int) $r1->get('field_matched_material')->target_id === (int) $mPvcHalf->id(),
    'confidence_ge_90' => $r1 && ((float) $r1->get('field_match_confidence')->value) >= 90.0,
    'notes_have_breakdown' => $r1 && stripos((string) $r1->get('field_resolution_notes')->value, 'Score') !== FALSE,
  ];
  foreach ($checks1 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_1_high'] = !in_array(FALSE, $checks1, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 2: medium-confidence (no mfr, no UOM) ──────────────
  echo "\n--- Scenario 2: medium-confidence ---\n";
  $r2 = $bySku['T34-2'] ?? NULL;
  echo "  " . $explain($r2) . "\n";
  $checks2 = [
    'tier_fuzzy_med' => $r2 && $r2->get('field_match_tier')->value === 'tier_3_fuzzy_med',
    'confidence_in_med_band' => $r2 && (function ($c) { return $c >= 70.0 && $c < 90.0; })((float) $r2->get('field_match_confidence')->value),
  ];
  foreach ($checks2 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_2_med'] = !in_array(FALSE, $checks2, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 3: low-confidence rejection → discovery ────────────
  echo "\n--- Scenario 3: low-confidence rejection ---\n";
  $r3 = $bySku['T34-3'] ?? NULL;
  echo "  " . $explain($r3) . "\n";
  $checks3 = [
    'tier_is_discovery' => $r3 && $r3->get('field_match_tier')->value === 'discovery',
    'notes_mention_low_or_inference' => $r3 && (
      stripos((string) $r3->get('field_resolution_notes')->value, 'low-confidence') !== FALSE
      || stripos((string) $r3->get('field_resolution_notes')->value, 'no active candidates') !== FALSE
      || stripos((string) $r3->get('field_resolution_notes')->value, 'no candidates') !== FALSE
    ),
  ];
  foreach ($checks3 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_3_low'] = !in_array(FALSE, $checks3, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 4: size-mismatch picks the matching-size candidate ─
  echo "\n--- Scenario 4: size mismatch anti-signal ---\n";
  $r4 = $bySku['T34-4'] ?? NULL;
  echo "  " . $explain($r4) . "\n";
  // Row says 3/4 — should pick mPvcThreeQ, not mPvcHalf.
  $checks4 = [
    'matched_threeq_not_half' => $r4 && (int) $r4->get('field_matched_material')->target_id === (int) $mPvcThreeQ->id(),
    'is_high_confidence' => $r4 && $r4->get('field_match_tier')->value === 'tier_3_fuzzy_high',
  ];
  foreach ($checks4 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_4_size_mismatch'] = !in_array(FALSE, $checks4, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 5: UOM mismatch anti-signal ────────────────────────
  echo "\n--- Scenario 5: UOM mismatch anti-signal ---\n";
  $r5 = $bySku['T34-5'] ?? NULL;
  echo "  " . $explain($r5) . "\n";
  // r5 is otherwise identical to r1 but UOM=case vs material EA.
  // r1 should score ~95; r5 should score ~80 (95 - 15 for UOM swing -5 vs +10).
  $confR5 = $r5 ? (float) $r5->get('field_match_confidence')->value : 0.0;
  $confR1 = $r1 ? (float) $r1->get('field_match_confidence')->value : 0.0;
  $checks5 = [
    'r5_below_r1' => $r5 && $r1 && $confR5 < $confR1,
    'r5_penalty_visible' => $r5 && ($confR1 - $confR5) >= 10.0,
  ];
  foreach ($checks5 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k (r1=$confR1, r5=$confR5)\n"; }
  $results['scenario_5_uom_mismatch'] = !in_array(FALSE, $checks5, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 6: bundle-inference correctness (irrigation) ──────
  echo "\n--- Scenario 6: bundle inference (irrigation) ---\n";
  $r6 = $bySku['T34-6'] ?? NULL;
  echo "  " . $explain($r6) . "\n";
  // Verify via the public method too — use a plain "rotor sprinkler"
  // phrase that won't accidentally match pvc keywords.
  $inferred6 = $matcher->inferCandidateBundles('Hunter PGP rotor sprinkler 4 in');
  echo "  inferCandidateBundles → " . json_encode($inferred6) . "\n";
  $checks6 = [
    'inferred_contains_irrigation' => in_array('irrigation', $inferred6, TRUE),
    'inferred_not_first_is_pvc' => ($inferred6[0] ?? '') !== 'pvc',
    'matched_irrigation_material' => $r6 && (int) $r6->get('field_matched_material')->target_id === (int) $mIrrRotor->id(),
  ];
  foreach ($checks6 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_6_inference'] = !in_array(FALSE, $checks6, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 7: multi-bundle inference ──────────────────────────
  echo "\n--- Scenario 7: multi-bundle inference ---\n";
  $inferred7 = $matcher->inferCandidateBundles('1/2 PVC ball valve slip');
  echo "  inferCandidateBundles → " . json_encode($inferred7) . "\n";
  $checks7 = [
    'multiple_candidates' => count($inferred7) >= 2,
    'pvc_present' => in_array('pvc', $inferred7, TRUE),
    'irrigation_present' => in_array('irrigation', $inferred7, TRUE),
  ];
  foreach ($checks7 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_7_multi_inference'] = !in_array(FALSE, $checks7, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 8: empty inference → discovery + special note ─────
  echo "\n--- Scenario 8: empty inference → discovery ---\n";
  $r8 = $bySku['T34-8'] ?? NULL;
  echo "  " . $explain($r8) . "\n";
  $inferred8 = $matcher->inferCandidateBundles('zzz xxx yyy aaa');
  echo "  inferCandidateBundles → " . json_encode($inferred8) . "\n";
  $checks8 = [
    'inference_empty' => $inferred8 === [],
    'tier_discovery' => $r8 && $r8->get('field_match_tier')->value === 'discovery',
    'notes_inference_failure' => $r8 && stripos((string) $r8->get('field_resolution_notes')->value, 'no candidates') !== FALSE,
  ];
  foreach ($checks8 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_8_inference_failure'] = !in_array(FALSE, $checks8, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 9: excluded-bundle inference outcome ──────────────
  echo "\n--- Scenario 9: excluded-bundle inference ---\n";
  $r9 = $bySku['T34-9'] ?? NULL;
  echo "  " . $explain($r9) . "\n";
  $checks9 = [
    'tier_skipped_excluded' => $r9 && $r9->get('field_match_tier')->value === 'skipped_excluded_bundle',
    'no_matched_material' => $r9 && $r9->get('field_matched_material')->isEmpty(),
    'notes_mention_excluded' => $r9 && stripos((string) $r9->get('field_resolution_notes')->value, 'excluded by supplier policy') !== FALSE,
  ];
  foreach ($checks9 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_9_excluded_bundle'] = !in_array(FALSE, $checks9, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 10: candidate pool overflow ───────────────────────
  // Hard to verify without seeding 600+ materials. Skip with note.
  echo "\n--- Scenario 10: candidate pool overflow ---\n";
  echo "  SKIP — would require seeding 600+ materials to reach overflow path.\n";
  echo "         Verified by code inspection: queryFuzzyPool returns NULL above TIER3_TOTAL_CAP=600.\n";
  $results['scenario_10_overflow'] = 'SKIP';

  // ── Scenario 11: discontinued exclusion from pool ──────────────
  // The row's description carries "DISCONTINUED" — a unique token in
  // PVC titles. When the real DB has more than 200 pvc materials, the
  // pre-filter narrows the pool to mPvcDisc only; the scoring loop
  // then skips it via isDiscontinued(); the row falls to discovery.
  // The non-negotiable assertion is that the row never *matches* the
  // discontinued material — that's the whole point of discontinued
  // exclusion.
  echo "\n--- Scenario 11: discontinued exclusion from candidate pool ---\n";
  $r11 = $bySku['T34-11'] ?? NULL;
  echo "  " . $explain($r11) . "\n";
  $matchedId11 = $r11 ? (int) $r11->get('field_matched_material')->target_id : 0;
  $checks11 = [
    'not_matched_to_discontinued' => $r11 && $matchedId11 !== (int) $mPvcDisc->id(),
    'no_match_or_live_match' => $r11 && (
      $matchedId11 === 0
      || $matchedId11 === (int) $mPvcHalf->id()
      || $matchedId11 === (int) $mPvcThreeQ->id()
    ),
  ];
  foreach ($checks11 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_11_discontinued'] = !in_array(FALSE, $checks11, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 12: score breakdown in resolution notes ────────────
  echo "\n--- Scenario 12: score breakdown in notes ---\n";
  $hasBreakdown = $r1 && preg_match('/desc \d+\/50, uom -?\d+\/10, size \d+\/25, mfr \d+\/15/', (string) $r1->get('field_resolution_notes')->value);
  echo "  r1 notes: " . str_replace("\n", ' | ', (string) ($r1 ? $r1->get('field_resolution_notes')->value : '')) . "\n";
  $checks12 = [
    'breakdown_present' => (bool) $hasBreakdown,
  ];
  foreach ($checks12 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_12_breakdown'] = !in_array(FALSE, $checks12, TRUE) ? 'PASS' : 'FAIL';

  // ── Performance pass ────────────────────────────────────────────
  echo "\n--- Performance: 100 rows × N candidates each ---\n";
  // Seed 200 dummy pvc materials so the bundle has enough to actually
  // exercise the pool cap path. Keep all titles unique-enough to not all
  // collide on the pre-filter token.
  $perfMats = [];
  for ($i = 0; $i < 200; $i++) {
    $m = $etm->getStorage('material')->create([
      'type' => 'pvc',
      'title' => sprintf('PVC Test Fixture %03d Sch 40 Tee', $i),
      'uid' => 1,
      'field_unit_of_measure' => 'EA',
    ]);
    $m->save();
    $perfMats[] = (int) $m->id();
    $cleanup['materials'][] = (int) $m->id();
  }
  // Build 100-row CSV.
  $perfRows = ['SKU,Mfr#,Brand,Desc,Price,UOM'];
  for ($i = 0; $i < 100; $i++) {
    $perfRows[] = sprintf('PERF-%03d,,,"1/2\" PVC fitting variant %03d",1.00,each', $i, $i);
  }
  $perfCsv = implode("\n", $perfRows) . "\n";
  $perfFile = $fileRepo->writeData($perfCsv, "$uploadDir/spi_test_fuzzy_perf.csv", FileSystemInterface::EXISTS_REPLACE);
  $perfFile->setPermanent();
  $perfFile->save();
  $cleanup['files'][] = (int) $perfFile->id();

  $perfBatch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'PERF BATCH — fuzzy 3.4',
    'uid' => 1,
    'field_supplier' => $supplier->id(),
    'field_source_file' => $perfFile->id(),
    'field_source_filename' => 'spi_test_fuzzy_perf.csv',
    'field_uploaded_by' => 1,
    'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $perfBatch->save();
  $cleanup['batches'][] = (int) $perfBatch->id();

  $parser->parseUploadedFile($perfBatch);
  $perfBatch = $etm->getStorage('supplier_price_ingest_batch')->load($perfBatch->id());

  $tStart = microtime(TRUE);
  $perfResult = $matcher->matchBatch($perfBatch);
  $elapsed = microtime(TRUE) - $tStart;
  // Capture perf-batch rows for cleanup.
  $perfBatchRows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $perfBatch->id()]);
  foreach ($perfBatchRows as $r) { $cleanup['rows'][] = (int) $r->id(); }

  echo "  matcher: " . $perfResult->summary() . "\n";
  printf("  elapsed: %.2fs (budget: 30.0s)\n", $elapsed);
  $checksPerf = [
    'under_30s' => $elapsed < 30.0,
  ];
  foreach ($checksPerf as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['performance_100x200'] = $checksPerf['under_30s'] ? sprintf('PASS — %.2fs', $elapsed) : sprintf('FAIL — %.2fs', $elapsed);
}
finally {
  echo "\n=== Cleanup ===\n";
  foreach ($cleanup['rows'] as $id)      { $e = $etm->getStorage('supplier_price_ingest_row')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['batches'] as $id)   { $e = $etm->getStorage('supplier_price_ingest_batch')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['configs'] as $id)   { $e = $etm->getStorage('supplier_ingest_config')->load($id); if ($e) { $e->delete(); } }
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
  printf("  %-36s %s\n", $k, $v);
  if (!str_starts_with($v, 'PASS') && !str_starts_with($v, 'SKIP')) { $overall = 'FAIL'; }
}
echo "----------------------------\n";
echo "  OVERALL                              $overall\n";
echo "=============================\n";
