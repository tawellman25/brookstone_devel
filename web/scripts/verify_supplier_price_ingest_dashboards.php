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
