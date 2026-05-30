<?php

declare(strict_types=1);

/**
 * Phase 3.7.5 — extend SiteOne supplier_ingest_config column_mapping to
 * capture the 5 pack-tier columns the scrape already produces but BOS
 * was previously dropping at parse time.
 *
 * The scrape CSV has columns: pack_qty_mid_label, pack_qty_mid,
 * pack_qty_case, pack_family, pack_data_source. The base SiteOne
 * mapping (set up in commit 2f723fc7) covers only the SKU/description/
 * cost/UOM columns. This script adds the 5 pack-tier mappings.
 *
 * Idempotent. If the config already has all 5 mappings, prints no-op.
 *
 * Usage:
 *   ddev drush scr web/scripts/update_siteone_config_pack_tiers.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

const SITEONE_PACK_MAPPINGS = [
  'pack_qty_mid_label' => 'field_pack_qty_mid_label',
  'pack_qty_mid'       => 'field_pack_qty_mid',
  'pack_qty_case'      => 'field_pack_qty_case',
  'pack_family'        => 'field_pack_family',
  'pack_data_source'   => 'field_pack_data_source',
];

$etm = \Drupal::entityTypeManager();
$siteOneIds = $etm->getStorage('supplier')->getQuery()
  ->accessCheck(FALSE)
  ->condition('title', 'SiteOne', 'CONTAINS')
  ->execute();
if (!$siteOneIds) {
  echo "No SiteOne supplier found — skip.\n";
  exit(0);
}

$updated = 0;
$alreadySet = 0;
foreach ($siteOneIds as $sid) {
  $configs = $etm->getStorage('supplier_ingest_config')->loadByProperties(['field_supplier' => $sid]);
  if (!$configs) {
    echo "Supplier #$sid has no supplier_ingest_config — skip.\n";
    continue;
  }
  $config = reset($configs);
  $raw = (string) ($config->get('field_column_mapping')->value ?? '');
  $decoded = json_decode($raw, TRUE);
  if (!is_array($decoded) || !isset($decoded['source_columns']) || !is_array($decoded['source_columns'])) {
    echo "Config #{$config->id()} (supplier #$sid) has invalid mapping JSON — skip.\n";
    continue;
  }

  $diff = FALSE;
  foreach (SITEONE_PACK_MAPPINGS as $csvCol => $bosField) {
    if (($decoded['source_columns'][$csvCol] ?? NULL) !== $bosField) {
      $decoded['source_columns'][$csvCol] = $bosField;
      $diff = TRUE;
    }
  }
  if (!$diff) {
    echo "Config #{$config->id()} (supplier #$sid) already has all 5 pack mappings — no-op.\n";
    $alreadySet++;
    continue;
  }

  $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  $config->set('field_column_mapping', $encoded);
  $config->save();
  echo "Config #{$config->id()} (supplier #$sid) updated with 5 pack-tier mappings:\n";
  echo $encoded . "\n";
  $updated++;
}

echo "\nResult: updated=$updated, already_set=$alreadySet\n";
