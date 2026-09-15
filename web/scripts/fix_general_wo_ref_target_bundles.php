<?php

/**
 * Add the newer work_order bundles to the GENERAL-PURPOSE "any work order"
 * reference fields so every WO type (backflow_testing, exterior_lighting,
 * landscape_lighting, winter_pruning) can attach photos, videos, notes, time,
 * sign-off, dumping, materials and rentals.
 *
 * These fields predate the four bundles and list the full WO set minus those.
 * Bundle-specific fields (contract_sections/estimate per-service slots,
 * wo_chemicals_used/wo_spraying_conditions spray-only fields, per-bundle
 * tasks_list/complete_info crew fields, etc.) are INTENTIONALLY restricted and
 * are deliberately NOT touched here.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/fix_general_wo_ref_target_bundles.php
 */

$add = ['backflow_testing', 'exterior_lighting', 'landscape_lighting', 'winter_pruning'];

// Explicit allowlist of general "any WO" reference fields (field_config ids).
$fields = [
  'media.wo_images.field_work_order',
  'media.wo_videos.field_work_order',
  'wo_notes.note.field_work_order',
  'wo_time_clock.entry.field_work_order',
  'wo_complete_info.complete.field_work_order',
  'wo_material_dumping.load.field_work_order',
  'wo_material_list.material_list.field_work_order',
  'wo_rental_equipment.equipment_rental.field_rented_for',
];

$valid = \Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order');
$storage = \Drupal::entityTypeManager()->getStorage('field_config');
$out = [];

foreach ($fields as $id) {
  $fc = $storage->load($id);
  if (!$fc) {
    $out[] = "SKIP $id — not found in this env";
    continue;
  }
  // Guard: must actually target work_order.
  if ($fc->getFieldStorageDefinition()->getSetting('target_type') !== 'work_order') {
    $out[] = "SKIP $id — does not target work_order";
    continue;
  }
  $settings = $fc->getSettings();
  $target = $settings['handler_settings']['target_bundles'] ?? [];
  // Guard: only touch broad, general fields (never a single-bundle field).
  if (count($target) < 5) {
    $out[] = "SKIP $id — looks bundle-specific (" . count($target) . " target)";
    continue;
  }
  $added = [];
  foreach ($add as $b) {
    if (isset($valid[$b]) && !isset($target[$b])) {
      $target[$b] = $b;
      $added[] = $b;
    }
  }
  if ($added) {
    $settings['handler_settings']['target_bundles'] = $target;
    $fc->set('settings', $settings)->save();
    $out[] = "$id: added " . implode(', ', $added) . " (now " . count($target) . ")";
  }
  else {
    $out[] = "$id: already complete (" . count($target) . ")";
  }
}

print implode("\n", $out) . "\nDONE.\n";
