<?php

/**
 * Place the newer work_order bundles onto the correct CREW-SPECIFIC
 * wo_complete_info sign-off field (field_work_order), so a crew can sign off
 * these WO types from their crew form.
 *
 * The generic wo_complete_info.complete field already accepts all WO bundles
 * (fixed separately); this covers the crew-facing sign-off forms, which group
 * WO bundles by the crew that does the work:
 *   - backflow_testing -> irrigation_crew (backflow is irrigation work; the
 *     bundle already holds every sprinkler_* bundle)
 *   - winter_pruning   -> clean_up_crew   (pairs with summer_pruning, already
 *     in clean_up_crew)
 *
 * exterior_lighting / landscape_lighting are intentionally NOT mapped here —
 * there is no lighting crew bundle and it is unclear which crew signs off
 * lighting; those still sign off via the generic `complete` form until the
 * office confirms the crew.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/fix_crew_complete_info_target_bundles.php
 */

$map = [
  'irrigation_crew' => ['backflow_testing'],
  'clean_up_crew'   => ['winter_pruning'],
];

$valid = \Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order');
$storage = \Drupal::entityTypeManager()->getStorage('field_config');
$out = [];

foreach ($map as $crew => $add) {
  $fc = $storage->load("wo_complete_info.$crew.field_work_order");
  if (!$fc) {
    $out[] = "SKIP $crew — field not found";
    continue;
  }
  $settings = $fc->getSettings();
  $target = $settings['handler_settings']['target_bundles'] ?? [];
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
    $out[] = "wo_complete_info.$crew.field_work_order: added " . implode(', ', $added) . " (now " . count($target) . ")";
  }
  else {
    $out[] = "wo_complete_info.$crew.field_work_order: already complete (" . count($target) . ")";
  }
}

print implode("\n", $out) . "\nDONE.\n";
