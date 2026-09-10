<?php

/**
 * @file
 * Allow Work Orders to reference HOA properties.
 *
 * work_order.field_property targets the `properties` entity type but every WO
 * bundle's instance restricts handler target_bundles to `property`, so an `hoa`
 * record can't be selected. This adds `hoa` alongside `property` on all WO
 * bundles (widening only — existing WOs and the property→WO flow are unaffected).
 *
 * Idempotent; run per env:
 *   ddev drush php:script web/scripts/setup_wo_field_property_allow_hoa.php   (dev)
 *   drush php:script web/scripts/setup_wo_field_property_allow_hoa.php        (live)
 */

use Drupal\field\Entity\FieldConfig;

$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order'));
$changed = 0;
$skipped = 0;
foreach ($bundles as $bundle) {
  $fc = FieldConfig::loadByName('work_order', $bundle, 'field_property');
  if (!$fc) {
    continue;
  }
  $settings = $fc->getSetting('handler_settings') ?: [];
  $target = $settings['target_bundles'] ?? [];
  if (isset($target['hoa'])) {
    $skipped++;
    continue;
  }
  // Preserve the existing target(s) (property) and add hoa.
  $target['hoa'] = 'hoa';
  $settings['target_bundles'] = $target;
  // Keep the two bundles in a stable order.
  ksort($settings['target_bundles']);
  $fc->setSetting('handler_settings', $settings);
  $fc->save();
  $changed++;
  echo "• {$bundle}: field_property now targets " . implode(' + ', array_keys($settings['target_bundles'])) . "\n";
}
echo "Done. changed={$changed}, already-had-hoa={$skipped}, total WO bundles=" . count($bundles) . "\n";
