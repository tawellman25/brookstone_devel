<?php

declare(strict_types=1);

/**
 * 1:many destination follow-up — update SiteOne supplier_ingest_config
 * so the supplier_item_number CSV column populates BOTH
 * field_supplier_sku AND field_manufacturer_item_number on each row.
 *
 * Why: SiteOne's catalog re-uses the manufacturer item number as their
 * own SKU (often prefixed with "R" for Rain Bird nozzles). The Phase
 * 3.10 strip_prefix transformation handles "R15H" → "15H" for Tier 1
 * lookups against field_supplier_sku, but only the SKU side of the
 * match is populated unless we also write supplier_item_number to
 * field_manufacturer_item_number. Without this, Tier 1 manufacturer-#
 * lookups can't run and we leave matches on the table.
 *
 * Idempotent: if the config already has the array shape for
 * supplier_item_number, prints "no-op" and exits 0.
 *
 * Usage:
 *   ddev drush scr web/scripts/update_siteone_config_1many.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

const SITEONE_TARGET_HEADER = 'supplier_item_number';
const SITEONE_TARGET_FIELDS = [
  'field_supplier_sku',
  'field_manufacturer_item_number',
];

$etm = \Drupal::entityTypeManager();
$siteOneIds = $etm->getStorage('supplier')->getQuery()
  ->accessCheck(FALSE)
  ->condition('title', 'SiteOne', 'CONTAINS')
  ->execute();
if (!$siteOneIds) {
  echo "No SiteOne supplier found — skipping. (Live env: supplier should exist.)\n";
  exit(0);
}

$updated = 0;
$alreadySet = 0;
$skipped = 0;
foreach ($siteOneIds as $sid) {
  $configs = $etm->getStorage('supplier_ingest_config')->loadByProperties(['field_supplier' => $sid]);
  if (!$configs) {
    echo "Supplier #{$sid} has no supplier_ingest_config — skip.\n";
    $skipped++;
    continue;
  }
  $config = reset($configs);
  $raw = (string) ($config->get('field_column_mapping')->value ?? '');
  if ($raw === '') {
    echo "Config #{$config->id()} (supplier #{$sid}) has empty field_column_mapping — skip; configure base mapping first.\n";
    $skipped++;
    continue;
  }
  $decoded = json_decode($raw, TRUE);
  if (!is_array($decoded) || !isset($decoded['source_columns']) || !is_array($decoded['source_columns'])) {
    echo "Config #{$config->id()} (supplier #{$sid}) has invalid JSON shape — skip.\n";
    $skipped++;
    continue;
  }

  $current = $decoded['source_columns'][SITEONE_TARGET_HEADER] ?? NULL;
  if (is_array($current) && count(array_diff(SITEONE_TARGET_FIELDS, $current)) === 0 && count(array_diff($current, SITEONE_TARGET_FIELDS)) === 0) {
    echo "Config #{$config->id()} (supplier #{$sid}) already has 1:many array for '" . SITEONE_TARGET_HEADER . "' — no-op.\n";
    $alreadySet++;
    continue;
  }

  $decoded['source_columns'][SITEONE_TARGET_HEADER] = SITEONE_TARGET_FIELDS;

  $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  $config->set('field_column_mapping', $encoded);
  $config->save();
  echo "Config #{$config->id()} (supplier #{$sid}) updated to 1:many for '" . SITEONE_TARGET_HEADER . "':\n";
  echo $encoded . "\n";
  $updated++;
}

echo "\nResult: updated={$updated}, already_set={$alreadySet}, skipped={$skipped}\n";
