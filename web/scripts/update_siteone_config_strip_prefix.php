<?php

declare(strict_types=1);

/**
 * Phase 3.10 — set field_sku_transformations on the SiteOne
 * supplier_ingest_config so the matcher strips SiteOne's "R" prefix
 * from Rain Bird nozzle SKUs (e.g., "R15H" → "15H") before lookup.
 *
 * Idempotent. If the config already has the seed JSON, prints "no-op".
 * If the config doesn't exist (no SiteOne config in DDEV yet), prints
 * a hint and exits clean.
 *
 * Usage:
 *   ddev drush scr web/scripts/update_siteone_config_strip_prefix.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

const SITEONE_TRANSFORMATIONS = [
  'strip_prefix' => ['R'],
  'strip_suffix' => [],
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
foreach ($siteOneIds as $sid) {
  $configs = $etm->getStorage('supplier_ingest_config')->loadByProperties(['field_supplier' => $sid]);
  if (!$configs) {
    echo "Supplier #{$sid} has no supplier_ingest_config — skip.\n";
    continue;
  }
  $config = reset($configs);
  $expected = json_encode(SITEONE_TRANSFORMATIONS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  $current = (string) ($config->get('field_sku_transformations')->value ?? '');
  if (trim($current) === trim($expected)) {
    echo "Config #{$config->id()} (supplier #{$sid}) already has expected SKU transformations — no-op.\n";
    $alreadySet++;
    continue;
  }
  $config->set('field_sku_transformations', $expected);
  $config->save();
  echo "Config #{$config->id()} (supplier #{$sid}) updated:\n";
  echo $expected . "\n";
  $updated++;
}

echo "\nResult: updated={$updated}, already_set={$alreadySet}\n";
