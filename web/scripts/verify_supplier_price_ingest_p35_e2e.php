<?php

declare(strict_types=1);

/**
 * Phase 3.5 — one-off end-to-end sanity:
 *
 *   parse → match → approve → stub commit
 *
 * Not part of the standing verify suite — kept for the Phase 3.5
 * commit review, can be removed in a later cleanup pass. Exits clean
 * on success; throws on any state-machine violation.
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\File\FileSystemInterface;

$etm      = \Drupal::entityTypeManager();
$parser   = \Drupal::service('supplier_price_ingest.parser');
$matcher  = \Drupal::service('supplier_price_ingest.matcher');
$stub     = \Drupal::service('supplier_price_ingest.stub_committer');
$fileRepo = \Drupal::service('file.repository');
$fs       = \Drupal::service('file_system');

$uploadDir = 'public://supplier_ingest';
$fs->prepareDirectory($uploadDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

$cleanup = ['rows' => [], 'batches' => [], 'configs' => [], 'suppliers' => [], 'mfrs' => [], 'files' => []];

try {
  $mfr = $etm->getStorage('manufacturer')->create(['type' => 'manufacturer', 'field_name' => 'PHASE35-TestMfr', 'uid' => 1]);
  $mfr->save();
  $cleanup['mfrs'][] = (int) $mfr->id();

  $sup = $etm->getStorage('supplier')->create(['type' => 'supplier', 'field_supplier_name' => 'PHASE35-TestSup', 'uid' => 1]);
  $sup->save();
  $cleanup['suppliers'][] = (int) $sup->id();

  $cfg = $etm->getStorage('supplier_ingest_config')->create([
    'type' => 'config',
    'title' => 'PHASE35-Cfg',
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
    'field_bundle_policy' => json_encode(['irrigation' => 'matched_only', 'pvc' => 'discovery']),
  ]);
  $cfg->save();
  $cleanup['configs'][] = (int) $cfg->id();

  $csv = "SKU,Mfr#,Brand,Desc,Price,UOM\nA1,P35-1,PHASE35-TestMfr,Item 1,1.00,each\nA2,P35-2,PHASE35-TestMfr,Item 2,2.00,each\n";
  $f = $fileRepo->writeData($csv, "$uploadDir/spi_p35_e2e.csv", FileSystemInterface::EXISTS_REPLACE);
  $f->setPermanent();
  $f->save();
  $cleanup['files'][] = (int) $f->id();

  $batch = $etm->getStorage('supplier_price_ingest_batch')->create([
    'type' => 'batch',
    'title' => 'PHASE35-E2E',
    'uid' => 1,
    'field_supplier' => $sup->id(),
    'field_source_file' => $f->id(),
    'field_source_filename' => 'spi_p35_e2e.csv',
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
  echo "post-match status: " . $batch->get('field_status')->value . "\n";

  // Simulate approve-form submit: two-hop status + stub commit.
  $batch->set('field_status', 'awaiting_approval');
  $batch->save();
  $batch->set('field_status', 'approved');
  $batch->set('field_committed_by', 1);
  $batch->set('field_committed_on', gmdate('Y-m-d\TH:i:s'));
  $batch->save();
  $stub->commit($batch, \Drupal\user\Entity\User::load(1));
  $batch = $etm->getStorage('supplier_price_ingest_batch')->load($batch->id());
  echo "post-stub-commit status: " . $batch->get('field_status')->value . "\n";

  $rows = $etm->getStorage('supplier_price_ingest_row')->loadByProperties(['field_batch' => $batch->id()]);
  foreach ($rows as $r) {
    $cleanup['rows'][] = (int) $r->id();
    echo sprintf(
      "  row %d  tier=%s  row_status=%s\n",
      $r->id(),
      (string) $r->get('field_match_tier')->value,
      (string) $r->get('field_row_status')->value,
    );
  }

  if ((string) $batch->get('field_status')->value !== 'committed') {
    throw new \RuntimeException('Expected batch status committed; got ' . $batch->get('field_status')->value);
  }
  echo "PASS — e2e flow reached committed state.\n";
}
finally {
  echo "\nCleanup:\n";
  foreach ($cleanup['rows'] as $id)      { $e = $etm->getStorage('supplier_price_ingest_row')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['batches'] as $id)   { $e = $etm->getStorage('supplier_price_ingest_batch')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['configs'] as $id)   { $e = $etm->getStorage('supplier_ingest_config')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['suppliers'] as $id) { $e = $etm->getStorage('supplier')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['mfrs'] as $id)      { $e = $etm->getStorage('manufacturer')->load($id); if ($e) { $e->delete(); } }
  foreach ($cleanup['files'] as $id)     { $f = $etm->getStorage('file')->load($id); if ($f) { $f->delete(); } }
  echo "  done.\n";
}
