<?php

/**
 * Allow the newer work_order bundles to be referenced from a WO status update.
 *
 * The `field_status_of_wo` entity-reference field on `wo_status_updates.update`
 * was configured before four real WO bundles existed, so its target_bundles
 * allowlist omitted them — meaning those WOs could not be referenced on the
 * status-update add form (autocomplete missed them, and a prefilled target_id
 * failed validation on save). Backflow test WOs were the report of record.
 *
 * This adds any missing real WO bundles to the allowlist so every WO type can
 * receive status updates. `estimate` is intentionally left as-is (already in
 * the list; legacy/phasing out).
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/fix_status_update_wo_target_bundles.php
 */

$out = [];

$fc = \Drupal::entityTypeManager()->getStorage('field_config')
  ->load('wo_status_updates.update.field_status_of_wo');
if (!$fc) {
  print "ERROR: field_config wo_status_updates.update.field_status_of_wo not found\n";
  return;
}

// The four bundles that post-date the field's original configuration.
$add = ['backflow_testing', 'exterior_lighting', 'landscape_lighting', 'winter_pruning'];

// Only add bundles that actually exist as work_order bundles.
$valid = \Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order');

$settings = $fc->getSettings();
$target = $settings['handler_settings']['target_bundles'] ?? [];
$added = [];
foreach ($add as $b) {
  if (!isset($valid[$b])) {
    $out[] = "SKIP $b — not a work_order bundle in this env";
    continue;
  }
  if (!isset($target[$b])) {
    $target[$b] = $b;
    $added[] = $b;
  }
}

if (!$added) {
  print "No change — all target bundles already present.\n";
  return;
}

$settings['handler_settings']['target_bundles'] = $target;
$fc->set('settings', $settings);
$fc->save();

$out[] = 'Added to target_bundles: ' . implode(', ', $added);
print implode("\n", $out) . "\nDONE.\n";
