<?php

/**
 * @file
 * Make HOA properties first-class: allow the `hoa` bundle on EVERY entity
 * reference field that targets the `properties` entity type and is currently
 * restricted to `property` only. The `hoa` bundle is a superset of `property`,
 * so this is widening-only — no existing record is affected.
 *
 * Covers contracts, estimate_request, service_request, ownership_record, media
 * (photos/videos), and all property_* detail entities (the ones wo_* modules
 * read/write on WO completion). work_order was already done separately and is
 * skipped here (already hoa + property).
 *
 * Idempotent; run per env:
 *   ddev drush php:script web/scripts/setup_allow_hoa_on_property_refs.php   (dev)
 *   drush php:script web/scripts/setup_allow_hoa_on_property_refs.php        (live)
 */

$map = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('entity_reference');
$fcStorage = \Drupal::entityTypeManager()->getStorage('field_config');
$changed = 0;
$skipped = 0;

foreach ($map as $entity_type => $fields) {
  foreach ($fields as $field_name => $info) {
    foreach ($info['bundles'] as $bundle) {
      $fc = $fcStorage->load("{$entity_type}.{$bundle}.{$field_name}");
      if (!$fc || $fc->getSetting('target_type') !== 'properties') {
        continue;
      }
      $settings = $fc->getSetting('handler_settings') ?: [];
      $target = $settings['target_bundles'] ?? [];
      // Only touch instances restricted to exactly property-only. (Unrestricted
      // instances already allow hoa; instances already including hoa are done.)
      if (array_keys($target) !== ['property']) {
        if (isset($target['hoa'])) {
          $skipped++;
        }
        continue;
      }
      $target['hoa'] = 'hoa';
      ksort($target);
      $settings['target_bundles'] = $target;
      $fc->setSetting('handler_settings', $settings);
      $fc->save();
      $changed++;
      echo "• {$entity_type}.{$bundle}.{$field_name} → " . implode(' + ', array_keys($target)) . "\n";
    }
  }
}
echo "Done. changed={$changed}, already-had-hoa={$skipped}\n";
