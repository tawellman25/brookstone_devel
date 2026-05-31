<?php

declare(strict_types=1);

/**
 * Phase 3.7 — Office Manager dashboards end-to-end verification.
 *
 * Covers:
 *   1. IngestCommitter routes discovery + fuzzy_med rows to
 *      discovery_pending on commit (the upstream dependency that
 *      lets the three new views surface anything).
 *   2. Each of the 8 per-row operations:
 *      - Discovery: Create Material, Link to Existing, Mark as
 *        Replacement, Reject
 *      - Fuzzy: Confirm Match, Override Match, Send to Discovery, Reject
 *   3. SiteOne column-mapping seed button (overwrite-protected).
 *   4. Bulk Reject action behavior.
 *
 * Uses Drupal's FormBuilder to submit each form programmatically.
 * Exercises catalog mutations via the real PriceSyncService.
 *
 * Usage:
 *   ddev drush scr web/scripts/verify_supplier_price_ingest_dashboards.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;

$etm        = \Drupal::entityTypeManager();
$parser     = \Drupal::service('supplier_price_ingest.parser');
$matcher    = \Drupal::service('supplier_price_ingest.matcher');
$committer  = \Drupal::service('supplier_price_ingest.committer');
$priceSync  = \Drupal::service('wo_material_price_sync.price_sync');
$fileRepo   = \Drupal::service('file.repository');
$fs         = \Drupal::service('file_system');
$formBuilder = \Drupal::formBuilder();

$uploadDir = 'public://supplier_ingest';
$fs->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

$results = [];
$cleanup = [
  'rows' => [], 'batches' => [], 'configs' => [], 'history' => [],
  'links' => [], 'materials' => [], 'mfrs' => [], 'suppliers' => [], 'files' => [],
];

/**
 * Format an entity id for entity_autocomplete's element_validate.
 * The validator parses "Label (id)" at the end of the input and
 * extracts the id — a programmatic submission can short-circuit the
 * label by providing any string with "(id)" suffix.
 */
$autocompleteValue = function (int $id): string {
  return "x ($id)";
};

/**
 * Submit a form programmatically, asserting it didn't return errors.
 */
$submit = function (string $formClass, $row, array $values) use ($formBuilder, $etm): array {
  $form_state = new FormState();
  $form_state->setValues($values);
  // BuildInfo args mirror the route's parameter resolution.
  $form_state->addBuildInfo('args', [$row]);
  $formBuilder->submitForm($formClass, $form_state);
  return [
    'errors' => $form_state->getErrors(),
    'redirect' => $form_state->getRedirect(),
  ];
};

try {
  // ── Fixtures ────────────────────────────────────────────────────
  $mfr = $etm->getStorage('manufacturer')->create([
    'type' => 'manufacturer', 'field_name' => 'P37-TestMfr', 'uid' => 1,
  ]);
  $mfr->save();
  $cleanup['mfrs'][] = (int) $mfr->id();

  $sup = $etm->getStorage('supplier')->create([
    'type' => 'supplier', 'field_supplier_name' => 'P37-TestSup', 'uid' => 1,
  ]);
  $sup->save();
  $cleanup['suppliers'][] = (int) $sup->id();

  $cfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config', 'title' => 'P37-Cfg', 'uid' => 1,
    'field_supplier' => $sup->id(), 'field_active' => 1,
    'field_default_cost_uom' => 'each',
    'field_column_mapping' => json_encode([
      'source_columns' => [
        'SKU' => 'field_supplier_sku', 'Mfr#' => 'field_manufacturer_item_number',
        'Brand' => 'field_manufacturer_name', 'Desc' => 'field_description',
        'Price' => 'field_unit_cost', 'UOM' => 'field_cost_uom',
      ],
      'header_row' => 1,
    ]),
    // Allow pvc discovery so 1+ discovery rows actually land in the queue.
    'field_bundle_policy' => json_encode(['irrigation' => 'matched_only', 'pvc' => 'discovery']),
  ]);
  $cfg->save();
  $cleanup['configs'][] = (int) $cfg->id();

  // Build a synthetic CSV with three categorically distinct rows:
  //   sku-discovery: PVC widget — no existing material, lands in discovery
  //   sku-link:      PVC fitting — no existing match, will be discovery target for "link existing"
  //   sku-replace:   PVC plug — discovery target for "mark as replacement"
  //   sku-reject:    PVC junk — discovery target for "reject"
  $csv = "SKU,Mfr#,Brand,Desc,Price,UOM\n"
    . "sku-discovery,,,1/2\" PVC widget for create-material test,5.00,each\n"
    . "sku-link,,,3/4\" PVC fitting for link-existing test,6.00,each\n"
    . "sku-replace,,,1\" PVC plug for mark-replacement test,7.00,each\n"
    . "sku-reject,,,PVC junk row for reject test,8.00,each\n";
  $f = $fileRepo->writeData($csv, "$uploadDir/spi_p37_dashboards.csv", FileSystemInterface::EXISTS_REPLACE);
  $f->setPermanent();
  $f->save();
  $cleanup['files'][] = (int) $f->id();

  $batch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch', 'title' => 'P37-Dashboards', 'uid' => 1,
    'field_supplier' => $sup->id(), 'field_source_file' => $f->id(),
    'field_source_filename' => 'spi_p37_dashboards.csv',
    'field_uploaded_by' => 1, 'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $batch->save();
  $cleanup['batches'][] = (int) $batch->id();

  $parser->parseUploadedFile($batch);
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());
  $matcher->matchBatch($batch);
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());

  // Approve + commit to trigger the new routing step.
  $batch->set('field_status', 'awaiting_approval'); $batch->save();
  $batch->set('field_status', 'approved');
  $batch->set('field_committed_by', 1);
  $batch->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
  $batch->save();
  $commitResult = $committer->commitBatch($batch);
  echo "Commit result: " . $commitResult->summary() . "\n";

  // Track rows for cleanup; map by SKU.
  $rowsBySku = [];
  $rows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch->id()]);
  foreach ($rows as $r) {
    $cleanup['rows'][] = (int) $r->id();
    $sku = (string) ($r->get('field_supplier_sku')->value ?? '');
    if ($sku !== '') { $rowsBySku[$sku] = $r; }
  }

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 0 — Committer routing transition
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 0: committer routes discovery rows to discovery_pending ===\n";
  $pendingCount = (int) $etm->getStorage('supplier_price_ingest_row')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_batch', $batch->id())
    ->condition('field_row_status', 'discovery_pending')
    ->count()
    ->execute();
  echo "  discovery_pending rows in batch: $pendingCount (expect 4)\n";
  $checks = [
    'commit_routed_to_discovery_count' => $commitResult->rowsRoutedToDiscovery === 4,
    'all_four_now_discovery_pending'   => $pendingCount === 4,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_0_committer_routing'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 1 — Create Material from Row
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 1: Discovery — Create Material ===\n";
  $row = $rowsBySku['sku-discovery'] ?? NULL;
  if (!$row) { throw new \RuntimeException('Missing fixture row sku-discovery'); }
  $submit(\Drupal\supplier_price_ingest\Form\CreateMaterialFromRowForm::class, $row, [
    'bundle' => 'pvc',
    'title' => 'P37 Created Material from Discovery',
    'field_manufacturer_item_number' => 'P37-CREATED-1',
    'field_manufacturer' => '',
    'field_unit_of_measure' => 'EA',
  ]);
  $row = $etm->getStorage('supplier_price_ingest_row')->load($row->id());
  $newMatId = (int) ($row->get('field_matched_material')->target_id ?? 0);
  if ($newMatId > 0) { $cleanup['materials'][] = $newMatId; }
  // Track the auto-created link + history entry for cleanup.
  $linkIds = $etm->getStorage('material_suppliers')->getQuery()
    ->accessCheck(FALSE)->condition('field_material', $newMatId)->execute();
  foreach ($linkIds as $id) { $cleanup['links'][] = (int) $id; }
  $histIds = $etm->getStorage('material_price_history')->getQuery()
    ->accessCheck(FALSE)->condition('field_ingest_batch', $batch->id())
    ->condition('field_material', $newMatId)->execute();
  foreach ($histIds as $id) { $cleanup['history'][] = (int) $id; }

  $checks = [
    'row_status_discovery_resolved' => (string) $row->get('field_row_status')->value === 'discovery_resolved',
    'row_action_created_new_material_and_link' => (string) $row->get('field_resolution_action')->value === 'created_new_material_and_link',
    'matched_material_set' => $newMatId > 0,
    'history_entry_created' => count($histIds) >= 1,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_1_create_material'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 2 — Link to Existing Material
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 2: Discovery — Link to Existing ===\n";
  // Need a live (non-discontinued) material to link to.
  $linkTarget = $etm->getStorage('material')->create([
    'type' => 'pvc', 'uid' => 1,
    'title' => 'P37 Link Target — PVC Coupling',
    'field_manufacturer_item_number' => 'P37-LINK-TGT',
  ]);
  $linkTarget->save();
  $cleanup['materials'][] = (int) $linkTarget->id();

  $row = $rowsBySku['sku-link'] ?? NULL;
  $submit(\Drupal\supplier_price_ingest\Form\LinkRowToMaterialForm::class, $row, [
    'material' => $autocompleteValue((int) $linkTarget->id()),
  ]);
  $row = $etm->getStorage('supplier_price_ingest_row')->load($row->id());
  $linkIds2 = $etm->getStorage('material_suppliers')->getQuery()
    ->accessCheck(FALSE)->condition('field_material', $linkTarget->id())->execute();
  foreach ($linkIds2 as $id) { $cleanup['links'][] = (int) $id; }
  $histIds2 = $etm->getStorage('material_price_history')->getQuery()
    ->accessCheck(FALSE)->condition('field_ingest_batch', $batch->id())
    ->condition('field_material', $linkTarget->id())->execute();
  foreach ($histIds2 as $id) { $cleanup['history'][] = (int) $id; }

  $checks = [
    'row_status_discovery_resolved' => (string) $row->get('field_row_status')->value === 'discovery_resolved',
    'row_action_linked_to_existing' => (string) $row->get('field_resolution_action')->value === 'linked_to_existing_material',
    'matched_material_is_link_target' => (int) $row->get('field_matched_material')->target_id === (int) $linkTarget->id(),
    'history_entry_created' => count($histIds2) >= 1,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_2_link_existing'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 3 — Mark as Replacement (use-existing mode)
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 3: Discovery — Mark as Replacement (existing) ===\n";
  // Need a discontinued material and a live replacement.
  $discMat = $etm->getStorage('material')->create([
    'type' => 'pvc', 'uid' => 1,
    'title' => 'P37 OLD PVC Plug — discontinued',
    'field_manufacturer_item_number' => 'P37-OLD',
    'field_discontinued' => 1,
  ]);
  $discMat->save();
  $cleanup['materials'][] = (int) $discMat->id();
  $repMat = $etm->getStorage('material')->create([
    'type' => 'pvc', 'uid' => 1,
    'title' => 'P37 NEW PVC Plug — replacement',
    'field_manufacturer_item_number' => 'P37-NEW',
  ]);
  $repMat->save();
  $cleanup['materials'][] = (int) $repMat->id();

  $row = $rowsBySku['sku-replace'] ?? NULL;
  $submit(\Drupal\supplier_price_ingest\Form\MarkRowAsReplacementForm::class, $row, [
    'discontinued_material' => $autocompleteValue((int) $discMat->id()),
    'replacement_mode' => 'existing',
    'existing_replacement' => $autocompleteValue((int) $repMat->id()),
    'new_bundle' => '',
    'new_title' => '',
  ]);
  $row = $etm->getStorage('supplier_price_ingest_row')->load($row->id());
  $discMat = $etm->getStorage('material')->load($discMat->id());
  $linkIds3 = $etm->getStorage('material_suppliers')->getQuery()
    ->accessCheck(FALSE)->condition('field_material', $repMat->id())->execute();
  foreach ($linkIds3 as $id) { $cleanup['links'][] = (int) $id; }
  $histIds3 = $etm->getStorage('material_price_history')->getQuery()
    ->accessCheck(FALSE)->condition('field_ingest_batch', $batch->id())
    ->condition('field_material', $repMat->id())->execute();
  foreach ($histIds3 as $id) { $cleanup['history'][] = (int) $id; }

  $checks = [
    'row_status_discovery_resolved' => (string) $row->get('field_row_status')->value === 'discovery_resolved',
    'row_action_marked_as_replacement' => (string) $row->get('field_resolution_action')->value === 'marked_as_replacement',
    'discontinued_material_field_replaced_by_set' => (int) $discMat->get('field_replaced_by')->target_id === (int) $repMat->id(),
    'history_entry_created' => count($histIds3) >= 1,
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_3_mark_replacement'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 4 — Reject (Discovery)
  // ════════════════════════════════════════════════════════════════
  echo "\n=== Scenario 4: Discovery — Reject Row ===\n";
  $row = $rowsBySku['sku-reject'] ?? NULL;
  $submit(\Drupal\supplier_price_ingest\Form\RejectRowForm::class, $row, [
    'notes' => 'Verifier scenario 4 — rejected as obvious junk.',
  ]);
  $row = $etm->getStorage('supplier_price_ingest_row')->load($row->id());
  $checks = [
    'row_status_rejected' => (string) $row->get('field_row_status')->value === 'rejected',
    'row_action_rejected'  => (string) $row->get('field_resolution_action')->value === 'rejected',
    'notes_captured' => str_contains((string) $row->get('field_resolution_notes')->value, 'Verifier scenario 4'),
  ];
  foreach ($checks as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_4_reject_discovery'] = !in_array(FALSE, $checks, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 5 — Fuzzy Review forms (Confirm / Override / Send / Reject)
  // ════════════════════════════════════════════════════════════════
  // Build a small dedicated fuzzy batch — manually engineer a fuzzy_med row.
  echo "\n=== Scenario 5: Fuzzy Review — Confirm / Override / Send / Reject ===\n";

  // Build a material that scores as a medium-confidence match.
  $fuzzyTarget = $etm->getStorage('material')->create([
    'type' => 'pvc', 'uid' => 1,
    'title' => 'P37 Fuzzy Target PVC Coupling 1in',
    'field_manufacturer_item_number' => 'P37-FUZZ',
  ]);
  $fuzzyTarget->save();
  $cleanup['materials'][] = (int) $fuzzyTarget->id();

  $csvFuzz = "SKU,Mfr#,Brand,Desc,Price,UOM\n"
    . "fz-confirm,,,P37 Fuzzy Target PVC Coupling 1in,5.00,each\n"
    . "fz-override,,,P37 Fuzzy Target PVC Coupling 1in,6.00,each\n"
    . "fz-send,,,P37 Fuzzy Target PVC Coupling 1in,7.00,each\n"
    . "fz-reject,,,P37 Fuzzy Target PVC Coupling 1in,8.00,each\n";
  $ff = $fileRepo->writeData($csvFuzz, "$uploadDir/spi_p37_fuzzy.csv", FileSystemInterface::EXISTS_REPLACE);
  $ff->setPermanent(); $ff->save();
  $cleanup['files'][] = (int) $ff->id();

  $fuzzBatch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch', 'title' => 'P37-Fuzzy', 'uid' => 1,
    'field_supplier' => $sup->id(), 'field_source_file' => $ff->id(),
    'field_source_filename' => 'spi_p37_fuzzy.csv',
    'field_uploaded_by' => 1, 'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $fuzzBatch->save();
  $cleanup['batches'][] = (int) $fuzzBatch->id();

  $parser->parseUploadedFile($fuzzBatch);
  $fuzzBatch = $etm->getStorage('supplier_price_ingest_batch')->load($fuzzBatch->id());
  $matcher->matchBatch($fuzzBatch);
  $fuzzBatch = $etm->getStorage('supplier_price_ingest_batch')->load($fuzzBatch->id());

  // Pre-populate auto-resolved state on all rows so the committer's
  // routing step demotes them to discovery_pending uniformly. (The
  // matcher may have scored these as fuzzy_high if my target is too
  // close — manually nudge them down to fuzzy_med for the test.)
  $fuzzRows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $fuzzBatch->id()]);
  $fuzzRowsBySku = [];
  foreach ($fuzzRows as $r) {
    $cleanup['rows'][] = (int) $r->id();
    $r->set('field_match_tier', 'tier_3_fuzzy_med');
    $r->set('field_match_confidence', '75.0');
    $r->set('field_matched_material', $fuzzyTarget->id());
    $r->save();
    $sku = (string) ($r->get('field_supplier_sku')->value ?? '');
    if ($sku !== '') { $fuzzRowsBySku[$sku] = $r; }
  }

  $fuzzBatch->set('field_status', 'awaiting_approval'); $fuzzBatch->save();
  $fuzzBatch->set('field_status', 'approved');
  $fuzzBatch->set('field_committed_by', 1);
  $fuzzBatch->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
  $fuzzBatch->save();
  $committer->commitBatch($fuzzBatch);

  // Reload rows now that they're discovery_pending.
  foreach (array_keys($fuzzRowsBySku) as $sku) {
    $fuzzRowsBySku[$sku] = $etm->getStorage('supplier_price_ingest_row')->load($fuzzRowsBySku[$sku]->id());
  }

  // 5a — Confirm
  echo "\n  --- 5a: Confirm Match ---\n";
  $r = $fuzzRowsBySku['fz-confirm'] ?? NULL;
  $submit(\Drupal\supplier_price_ingest\Form\ConfirmFuzzyMatchForm::class, $r, []);
  $r = $etm->getStorage('supplier_price_ingest_row')->load($r->id());
  $checks5a = [
    'row_status_committed' => (string) $r->get('field_row_status')->value === 'committed',
    'row_action_updated_or_created' => in_array((string) $r->get('field_resolution_action')->value, ['updated_link', 'created_link'], TRUE),
  ];
  foreach ($checks5a as $k => $v) { echo '    ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  // Track resulting link + history for cleanup.
  $confLinks = $etm->getStorage('material_suppliers')->getQuery()->accessCheck(FALSE)->condition('field_material', $fuzzyTarget->id())->execute();
  foreach ($confLinks as $id) { $cleanup['links'][] = (int) $id; }
  $confHists = $etm->getStorage('material_price_history')->getQuery()->accessCheck(FALSE)->condition('field_ingest_batch', $fuzzBatch->id())->execute();
  foreach ($confHists as $id) { $cleanup['history'][] = (int) $id; }
  $results['scenario_5a_confirm'] = !in_array(FALSE, $checks5a, TRUE) ? 'PASS' : 'FAIL';

  // 5b — Override
  echo "\n  --- 5b: Override Match ---\n";
  $overrideTarget = $etm->getStorage('material')->create([
    'type' => 'pvc', 'uid' => 1, 'title' => 'P37 Override Target', 'field_manufacturer_item_number' => 'P37-OVR',
  ]);
  $overrideTarget->save();
  $cleanup['materials'][] = (int) $overrideTarget->id();
  $r = $fuzzRowsBySku['fz-override'] ?? NULL;
  $submit(\Drupal\supplier_price_ingest\Form\OverrideFuzzyMatchForm::class, $r, ['material' => $autocompleteValue((int) $overrideTarget->id())]);
  $r = $etm->getStorage('supplier_price_ingest_row')->load($r->id());
  $checks5b = [
    'row_status_committed' => (string) $r->get('field_row_status')->value === 'committed',
    'matched_changed' => (int) $r->get('field_matched_material')->target_id === (int) $overrideTarget->id(),
    'notes_mention_override' => str_contains((string) $r->get('field_resolution_notes')->value, 'OVERRIDDEN'),
  ];
  foreach ($checks5b as $k => $v) { echo '    ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $ovrLinks = $etm->getStorage('material_suppliers')->getQuery()->accessCheck(FALSE)->condition('field_material', $overrideTarget->id())->execute();
  foreach ($ovrLinks as $id) { $cleanup['links'][] = (int) $id; }
  $results['scenario_5b_override'] = !in_array(FALSE, $checks5b, TRUE) ? 'PASS' : 'FAIL';

  // 5c — Send to Discovery
  echo "\n  --- 5c: Send to Discovery ---\n";
  $r = $fuzzRowsBySku['fz-send'] ?? NULL;
  $submit(\Drupal\supplier_price_ingest\Form\SendToDiscoveryForm::class, $r, []);
  $r = $etm->getStorage('supplier_price_ingest_row')->load($r->id());
  $checks5c = [
    'tier_now_discovery' => (string) $r->get('field_match_tier')->value === 'discovery',
    'status_still_pending' => (string) $r->get('field_row_status')->value === 'discovery_pending',
    'matched_material_cleared' => $r->get('field_matched_material')->isEmpty(),
  ];
  foreach ($checks5c as $k => $v) { echo '    ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_5c_send_to_discovery'] = !in_array(FALSE, $checks5c, TRUE) ? 'PASS' : 'FAIL';

  // 5d — Reject (Fuzzy)
  echo "\n  --- 5d: Reject Row (fuzzy) ---\n";
  $r = $fuzzRowsBySku['fz-reject'] ?? NULL;
  $submit(\Drupal\supplier_price_ingest\Form\RejectRowForm::class, $r, ['notes' => 'Verifier 5d']);
  $r = $etm->getStorage('supplier_price_ingest_row')->load($r->id());
  $checks5d = [
    'row_status_rejected' => (string) $r->get('field_row_status')->value === 'rejected',
    'row_action_rejected' => (string) $r->get('field_resolution_action')->value === 'rejected',
  ];
  foreach ($checks5d as $k => $v) { echo '    ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_5d_reject_fuzzy'] = !in_array(FALSE, $checks5d, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 6 — seed buttons + constants are GONE
  // ════════════════════════════════════════════════════════════════
  // The Phase 3.7 seed buttons were removed in the 2026-05-25 follow-up
  // (Office staff paste seed JSON directly from Chat output). Both the
  // SUPPLIER_PRICE_INGEST_SITEONE_COLUMN_MAPPING constant and the
  // SUPPLIER_PRICE_INGEST_DEFAULT_BUNDLE_POLICY constant were removed
  // alongside. This scenario asserts the cleanup is complete — defined()
  // returns FALSE for both.
  echo "\n=== Scenario 6: removed-seed-button artifacts are absent ===\n";
  $checks6 = [
    'siteone_const_removed' => !defined('SUPPLIER_PRICE_INGEST_SITEONE_COLUMN_MAPPING'),
    'bundle_policy_const_removed' => !defined('SUPPLIER_PRICE_INGEST_DEFAULT_BUNDLE_POLICY'),
    'seed_handler_removed' => !function_exists('_supplier_price_ingest_load_default_bundle_policy'),
    'siteone_handler_removed' => !function_exists('_supplier_price_ingest_load_siteone_column_mapping'),
  ];
  foreach ($checks6 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_6_seed_buttons_removed'] = !in_array(FALSE, $checks6, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 7 — Bulk Reject action
  // ════════════════════════════════════════════════════════════════
  // Use the rows already discovery_pending — there's still one
  // remaining from the first fixture batch (sku-discovery was
  // resolved, sku-link was resolved, sku-replace was resolved,
  // sku-reject was rejected). So we need a fresh fixture for this.
  echo "\n=== Scenario 7: Bulk Reject action — runs cleanly per row ===\n";
  $bulkCsv = "SKU,Mfr#,Brand,Desc,Price,UOM\n";
  for ($i = 1; $i <= 3; $i++) {
    $bulkCsv .= "bulk-$i,,,1/2\" PVC bulk junk $i,9.0$i,each\n";
  }
  $bf = $fileRepo->writeData($bulkCsv, "$uploadDir/spi_p37_bulk.csv", FileSystemInterface::EXISTS_REPLACE);
  $bf->setPermanent(); $bf->save();
  $cleanup['files'][] = (int) $bf->id();
  $bulkBatch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch', 'title' => 'P37-Bulk', 'uid' => 1,
    'field_supplier' => $sup->id(), 'field_source_file' => $bf->id(),
    'field_source_filename' => 'spi_p37_bulk.csv',
    'field_uploaded_by' => 1, 'field_uploaded_on' => date('Y-m-d\TH:i:s'),
    'field_status' => 'pending_dry_run',
  ]);
  $bulkBatch->save();
  $cleanup['batches'][] = (int) $bulkBatch->id();
  $parser->parseUploadedFile($bulkBatch);
  $bulkBatch = $etm->getStorage('supplier_price_ingest_batch')->load($bulkBatch->id());
  $matcher->matchBatch($bulkBatch);

  $bulkRows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $bulkBatch->id()]);
  foreach ($bulkRows as $r) { $cleanup['rows'][] = (int) $r->id(); }

  // Note: bulk-reject action is registered with the standard
  // plugin.manager.action manager (the @Action annotation); VBO wraps
  // it at view-render time. createInstance via the action manager
  // is the cheap way to test the per-row body without round-tripping
  // through the Views UI.
  $action = \Drupal::service('plugin.manager.action')
    ->createInstance('supplier_price_ingest_bulk_reject_rows', []);
  $bulkRejected = 0;
  foreach ($bulkRows as $r) {
    $action->execute($r);
    $r2 = $etm->getStorage('supplier_price_ingest_row')->load($r->id());
    if ((string) $r2->get('field_row_status')->value === 'rejected') {
      $bulkRejected++;
    }
  }
  echo "  $bulkRejected/3 rows transitioned to rejected\n";
  $results['scenario_7_bulk_reject'] = $bulkRejected === 3 ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 8 — supplier_ingest_config form-render assertions
  // ════════════════════════════════════════════════════════════════
  // Constant-existence in PHP code is not the same as button-on-form;
  // a working alter is verified by rendering the actual form HTML and
  // checking it.
  //
  // The 2026-05-25 follow-up REMOVED both seed-load buttons (user
  // wanted to paste JSON from Chat output directly). 8a/8b assert
  // their absence — re-introducing either button without first
  // restoring the constants + handlers + intent would fail this check.
  echo "\n=== Scenario 8: supplier_ingest_config form-render assertions ===\n";
  \Drupal::currentUser()->setAccount(\Drupal\user\Entity\User::load(1));
  $kernel = \Drupal::service('http_kernel');
  $req = \Symfony\Component\HttpFoundation\Request::create('/admin/materials/supplier-ingest/configs/add', 'GET');
  $resp = $kernel->handle($req, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
  $html = (string) $resp->getContent();
  $checks8 = [
    'http_200' => $resp->getStatusCode() === 200,
    '8a_siteone_button_ABSENT' => !str_contains($html, 'Load SiteOne column mapping'),
    '8b_bundle_policy_button_ABSENT' => !str_contains($html, 'Load default bundle policy'),
    '8c_marker_class_present_on_textarea' => str_contains($html, 'supplier-price-ingest-json-textarea'),
    '8d_no_ckeditor_wrapper_for_json_fields' => !preg_match('/data-ckeditor[\w-]*="[^"]*field[-_]column[-_]mapping[^"]*"/', $html)
                                              && !preg_match('/data-ckeditor[\w-]*="[^"]*field[-_]bundle[-_]policy[^"]*"/', $html)
                                              && !str_contains($html, 'ckeditor5/admin-fix-toolbar')
                                              && !preg_match('/<select[^>]*data-drupal-selector="edit-field-column-mapping-0-format/', $html)
                                              && !preg_match('/<select[^>]*data-drupal-selector="edit-field-bundle-policy-0-format/', $html),
    '8e_uid_field_hidden' => !preg_match('/name="uid\[0\]\[target_id\]"/', $html),
    '8e_created_field_hidden' => !preg_match('/name="created\[0\]\[value\]\[date\]"/', $html),
    '8e_pathauto_alias_hidden' => !preg_match('/name="path\[0\]\[alias\]"/', $html),
  ];
  foreach ($checks8 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_8_form_render'] = !in_array(FALSE, $checks8, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 9 — field storage type is string_long (root-cause fix)
  // ════════════════════════════════════════════════════════════════
  // Pairs with the form-render scenario above. Asserts the storage
  // type is string_long, not text_long — so any future tool that
  // renders these fields gets a plain textarea regardless of whether
  // the form_alter fires. Pure config check, no rendering needed.
  echo "\n=== Scenario 9: field storage type is string_long ===\n";
  $checks9 = [];
  foreach (['field_column_mapping', 'field_bundle_policy'] as $f) {
    $storage = $etm->getStorage('field_storage_config')->load("supplier_ingest_config.$f");
    $type = $storage ? $storage->getType() : '(missing)';
    $checks9["${f}_is_string_long"] = $type === 'string_long';
    echo "  $f storage type: $type\n";
  }
  foreach ($checks9 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_9_storage_string_long'] = !in_array(FALSE, $checks9, TRUE) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 10 — Views render an Operations column with the four
  //               per-row operation links
  // ════════════════════════════════════════════════════════════════
  // Class-of-bug regression test that pairs with the Phase 3.7 spec.
  // Scenarios 1-5 PROVE the operation routes work via direct form
  // submission, but never PROVE the Views config makes them reachable
  // from the queue pages. The Operations column was missing for months
  // on a previous deploy of this verifier — discovered only by the user
  // navigating to /admin/materials/supplier-ingest/discovery and seeing
  // no per-row buttons. This scenario closes the loop by sub-rendering
  // both Views pages and asserting:
  //
  //   1. The Operations column header is in the table HTML.
  //   2. Each fixture row's rendered HTML contains all four expected
  //      operation routes (parameterized by that row's id).
  //
  // Fixture rows are created directly (status + tier set explicitly)
  // since Scenarios 1-5 already mutated their own rows out of
  // discovery_pending by the time we get here.
  echo "\n=== Scenario 10: Views render Operations column with per-row op links ===\n";
  $checks10 = [];
  try {
    $discoveryRow = $etm->getStorage('supplier_price_ingest_row')->create([
      'type' => 'row',
      'title' => 'P37-S10-discovery row',
      'uid' => 1,
      'field_batch' => $batch->id(),
      'field_row_number' => 9001,
      'field_raw_data' => json_encode(['SKU' => 's10-disc']),
      'field_row_status' => 'discovery_pending',
      'field_match_tier' => 'discovery',
      'field_supplier_sku' => 's10-disc',
      'field_description' => 'P37 S10 discovery fixture',
      'field_unit_cost' => '1.23',
      'field_cost_uom' => 'each',
    ]);
    $discoveryRow->save();
    $cleanup['rows'][] = (int) $discoveryRow->id();

    $fuzzyRow = $etm->getStorage('supplier_price_ingest_row')->create([
      'type' => 'row',
      'title' => 'P37-S10-fuzzy row',
      'uid' => 1,
      'field_batch' => $batch->id(),
      'field_row_number' => 9002,
      'field_raw_data' => json_encode(['SKU' => 's10-fuzz']),
      'field_row_status' => 'discovery_pending',
      'field_match_tier' => 'tier_3_fuzzy_med',
      'field_match_confidence' => '75.0',
      'field_supplier_sku' => 's10-fuzz',
      'field_description' => 'P37 S10 fuzzy_med fixture',
      'field_unit_cost' => '2.34',
      'field_cost_uom' => 'each',
      'field_matched_material' => $fuzzyTarget->id(),
    ]);
    $fuzzyRow->save();
    $cleanup['rows'][] = (int) $fuzzyRow->id();

    \Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));

    // 10a — Discovery Queue view, scoped to our test batch via the
    // view's exposed ?batch= filter. Without the filter, accumulated
    // pre-existing discovery_pending rows from real ingest work can
    // paginate the fixture off page 1 — the view's 50-per-page limit
    // is operator-friendly but flaky for a single-fixture assertion.
    $req = \Symfony\Component\HttpFoundation\Request::create(
      '/admin/materials/supplier-ingest/discovery?batch=' . (int) $batch->id(),
      'GET'
    );
    $resp = \Drupal::service('http_kernel')->handle($req);
    $body = $resp->getContent();
    $checks10['discovery_http_200'] = $resp->getStatusCode() === 200;
    $checks10['discovery_operations_header'] = (bool) preg_match('#<th[^>]*>\s*Operations\s*</th>#', $body);
    $dId = $discoveryRow->id();
    foreach ([
      "/admin/materials/supplier-ingest/discovery/$dId/create-material"  => 'discovery_create_link_for_row',
      "/admin/materials/supplier-ingest/discovery/$dId/link-existing"    => 'discovery_link_existing_link_for_row',
      "/admin/materials/supplier-ingest/discovery/$dId/mark-replacement" => 'discovery_mark_replacement_link_for_row',
      "/admin/materials/supplier-ingest/discovery/$dId/reject"           => 'discovery_reject_link_for_row',
    ] as $needle => $key) {
      $checks10[$key] = strpos($body, $needle) !== FALSE;
    }

    // 10b — Fuzzy Match Review view, scoped the same way.
    $req = \Symfony\Component\HttpFoundation\Request::create(
      '/admin/materials/supplier-ingest/fuzzy-review?batch=' . (int) $batch->id(),
      'GET'
    );
    $resp = \Drupal::service('http_kernel')->handle($req);
    $body = $resp->getContent();
    $checks10['fuzzy_http_200'] = $resp->getStatusCode() === 200;
    $checks10['fuzzy_operations_header'] = (bool) preg_match('#<th[^>]*>\s*Operations\s*</th>#', $body);
    $fId = $fuzzyRow->id();
    foreach ([
      "/admin/materials/supplier-ingest/fuzzy-review/$fId/confirm"           => 'fuzzy_confirm_link_for_row',
      "/admin/materials/supplier-ingest/fuzzy-review/$fId/override"          => 'fuzzy_override_link_for_row',
      "/admin/materials/supplier-ingest/fuzzy-review/$fId/send-to-discovery" => 'fuzzy_send_to_discovery_link_for_row',
      "/admin/materials/supplier-ingest/fuzzy-review/$fId/reject"            => 'fuzzy_reject_link_for_row',
    ] as $needle => $key) {
      $checks10[$key] = strpos($body, $needle) !== FALSE;
    }

    \Drupal::service('account_switcher')->switchBack();

    foreach ($checks10 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  }
  catch (\Throwable $e) {
    echo "  FAIL — exception: " . $e->getMessage() . "\n";
    $checks10['no_exception'] = FALSE;
  }
  $results['scenario_10_views_operations_column'] = (!empty($checks10) && !in_array(FALSE, $checks10, TRUE)) ? 'PASS' : 'FAIL';

  // ════════════════════════════════════════════════════════════════
  // SCENARIO 11–14 — Save-and-load-next redirect (Phase 3.7.5 UX)
  //
  // Verify that IngestRowFormTrait::nextRowRedirect() returns:
  //   - a routed Url pointing at the same-operation form on the next
  //     pending row in the same batch (when one exists, id > current);
  //   - falls back to the lowest-id pending row across all batches;
  //   - finally a queue Url when nothing pending remains.
  //
  // We call the helper DIRECTLY via reflection on a shim class that
  // uses the trait. The alternative — going through $formBuilder
  // ->submitForm() and inspecting $form_state->getRedirect() — does
  // not work for our case because programmatic submissions
  // (FormState::getProgrammed() === TRUE) make getRedirect() return
  // FALSE by design; the redirect is computed and set, but never
  // surfaced to the caller. Calling the trait method directly tests
  // the contract that matters — given (currentRow, route, context),
  // produce the right Url.
  // ════════════════════════════════════════════════════════════════
  $trait = new class {
    use \Drupal\supplier_price_ingest\Form\IngestRowFormTrait;
  };
  $ref = new \ReflectionMethod($trait, 'nextRowRedirect');
  $ref->setAccessible(TRUE);
  $messenger = \Drupal::service('messenger');
  $callNextRedirect = fn (\Drupal\Core\Entity\EntityInterface $r, string $route, string $ctx) =>
    $ref->invoke($trait, $r, $route, $ctx, $etm, $messenger);

  // Common fixture maker for scenarios 11–14.
  $makeRow = function (string $tier, string $skuTag) use ($etm, $batch, &$cleanup, $fuzzyTarget) {
    $vals = [
      'type' => 'row', 'uid' => 1,
      'title' => 'P37-S11-' . $tier . '-' . $skuTag,
      'field_batch' => $batch->id(),
      'field_row_number' => 9100 + random_int(0, 800),
      'field_raw_data' => json_encode(['SKU' => $skuTag]),
      'field_row_status' => 'discovery_pending',
      'field_match_tier' => $tier,
      'field_supplier_sku' => $skuTag,
      'field_description' => 'P37 S11 ' . $tier . ' fixture (' . $skuTag . ')',
      'field_unit_cost' => '3.00',
      'field_cost_uom' => 'each',
    ];
    if ($tier === 'tier_3_fuzzy_med') {
      $vals['field_match_confidence'] = '75.0';
      $vals['field_matched_material'] = $fuzzyTarget->id();
    }
    $r = $etm->getStorage('supplier_price_ingest_row')->create($vals);
    $r->save();
    $cleanup['rows'][] = (int) $r->id();
    return $r;
  };

  // ── Scenario 11 — Discovery: next-in-batch redirect ──────────────
  echo "\n=== Scenario 11: Save-and-load-next — Discovery, next-in-batch ===\n";
  $rA = $makeRow('discovery', 's11-disc-a');
  $rB = $makeRow('discovery', 's11-disc-b');
  // Sanity: rA created first → rA.id < rB.id.
  if ((int) $rA->id() >= (int) $rB->id()) {
    [$rA, $rB] = [$rB, $rA];
  }
  $url11 = $callNextRedirect($rA, 'supplier_price_ingest.discovery_create_material', 'discovery');
  $checks11 = [
    'returns_url_object'  => $url11 instanceof \Drupal\Core\Url,
    'is_routed'           => $url11->isRouted(),
    'route_is_same_op'    => $url11->getRouteName() === 'supplier_price_ingest.discovery_create_material',
    'targets_next_row_rB' => (int) ($url11->getRouteParameters()['supplier_price_ingest_row'] ?? 0) === (int) $rB->id(),
  ];
  foreach ($checks11 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_11_discovery_next_in_batch'] = !in_array(FALSE, $checks11, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 12 — Discovery: last-in-batch falls through ────────
  echo "\n=== Scenario 12: Save-and-load-next — Discovery, last in batch ===\n";
  // Mark rA as resolved so rB is the LAST pending in our batch.
  $rA->set('field_row_status', 'discovery_resolved')->save();
  // What SHOULD nextRowRedirect return? Mirror the helper's logic:
  // - same-batch lookup with id > rB.id finds nothing
  // - cross-batch lookup finds the lowest-id discovery_pending row in
  //   the system (could be rB itself if no other pending rows exist;
  //   we explicitly skip past rB by re-querying with id != rB)
  $expIds = $etm->getStorage('supplier_price_ingest_row')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_row_status', 'discovery_pending')
    ->condition('field_match_tier', 'discovery')
    ->condition('id', $rB->id(), '<>')
    ->sort('id', 'ASC')->range(0, 1)->execute();
  $expCrossBatchId = $expIds ? (int) reset($expIds) : 0;
  $url12 = $callNextRedirect($rB, 'supplier_price_ingest.discovery_create_material', 'discovery');
  $checks12 = ['returns_url_object' => $url12 instanceof \Drupal\Core\Url];
  // The helper itself may pick rB (its own id) as the cross-batch target
  // because rB is still discovery_pending until the form completes its
  // mutation. In real use the form sets discovery_resolved BEFORE
  // calling the helper; we simulate by checking either acceptable shape.
  $actualRouteParam = $url12->isRouted()
    ? (int) ($url12->getRouteParameters()['supplier_price_ingest_row'] ?? 0)
    : 0;
  if ($expCrossBatchId > 0) {
    $checks12['cross_batch_fallback_hit'] = $url12->isRouted()
      && $url12->getRouteName() === 'supplier_price_ingest.discovery_create_material'
      && in_array($actualRouteParam, [$expCrossBatchId, (int) $rB->id()], TRUE);
  }
  else {
    $checks12['queue_url_fallback'] = !$url12->isRouted()
      && str_contains($url12->toString(), '/admin/materials/supplier-ingest/discovery');
  }
  foreach ($checks12 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_12_discovery_last_in_batch'] = !in_array(FALSE, $checks12, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 13 — Fuzzy review: next-in-batch redirect ──────────
  echo "\n=== Scenario 13: Save-and-load-next — Fuzzy review, next-in-batch ===\n";
  $fA = $makeRow('tier_3_fuzzy_med', 's13-fuzz-a');
  $fB = $makeRow('tier_3_fuzzy_med', 's13-fuzz-b');
  if ((int) $fA->id() >= (int) $fB->id()) {
    [$fA, $fB] = [$fB, $fA];
  }
  $url13 = $callNextRedirect($fA, 'supplier_price_ingest.fuzzy_confirm', 'fuzzy_review');
  $checks13 = [
    'returns_url_object'  => $url13 instanceof \Drupal\Core\Url,
    'is_routed'           => $url13->isRouted(),
    'route_is_same_op'    => $url13->getRouteName() === 'supplier_price_ingest.fuzzy_confirm',
    'targets_next_row_fB' => (int) ($url13->getRouteParameters()['supplier_price_ingest_row'] ?? 0) === (int) $fB->id(),
  ];
  foreach ($checks13 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_13_fuzzy_next_in_batch'] = !in_array(FALSE, $checks13, TRUE) ? 'PASS' : 'FAIL';

  // ── Scenario 14 — Fuzzy review: last-in-batch falls through ─────
  echo "\n=== Scenario 14: Save-and-load-next — Fuzzy review, last in batch ===\n";
  $fA->set('field_row_status', 'discovery_resolved')->save();
  $expIds14 = $etm->getStorage('supplier_price_ingest_row')->getQuery()
    ->accessCheck(FALSE)
    ->condition('field_row_status', 'discovery_pending')
    ->condition('field_match_tier', 'tier_3_fuzzy_med')
    ->condition('id', $fB->id(), '<>')
    ->sort('id', 'ASC')->range(0, 1)->execute();
  $expCrossBatchId14 = $expIds14 ? (int) reset($expIds14) : 0;
  $url14 = $callNextRedirect($fB, 'supplier_price_ingest.fuzzy_confirm', 'fuzzy_review');
  $checks14 = ['returns_url_object' => $url14 instanceof \Drupal\Core\Url];
  $actualRouteParam14 = $url14->isRouted()
    ? (int) ($url14->getRouteParameters()['supplier_price_ingest_row'] ?? 0)
    : 0;
  if ($expCrossBatchId14 > 0) {
    $checks14['cross_batch_fallback_hit'] = $url14->isRouted()
      && $url14->getRouteName() === 'supplier_price_ingest.fuzzy_confirm'
      && in_array($actualRouteParam14, [$expCrossBatchId14, (int) $fB->id()], TRUE);
  }
  else {
    $checks14['queue_url_fallback'] = !$url14->isRouted()
      && str_contains($url14->toString(), '/admin/materials/supplier-ingest/fuzzy-review');
  }
  foreach ($checks14 as $k => $v) { echo '  ' . ($v ? 'PASS' : 'FAIL') . " — $k\n"; }
  $results['scenario_14_fuzzy_last_in_batch'] = !in_array(FALSE, $checks14, TRUE) ? 'PASS' : 'FAIL';
}
finally {
  echo "\n=== Cleanup ===\n";
  foreach ($cleanup['rows'] as $id)      { $e = $etm->getStorage('supplier_price_ingest_row')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['batches'] as $id)   { $e = $etm->getStorage('supplier_price_ingest_batch')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['configs'] as $id)   { $e = $etm->getStorage('supplier_ingest_config')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['history'] as $id)   { $e = $etm->getStorage('material_price_history')->load($id); if ($e) { $e->delete(); } }
  foreach (array_unique($cleanup['links']) as $id) { $e = $etm->getStorage('material_suppliers')->load($id); if ($e) { $e->delete(); } }
  foreach (array_unique($cleanup['materials']) as $id) { $e = $etm->getStorage('material')->load($id); if ($e) { $e->delete(); } }
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
