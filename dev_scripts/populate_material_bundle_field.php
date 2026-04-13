<?php

/**
 * Drush PHP script: Populate field_material_bundle on material_types terms.
 *
 * Sets the bundle machine name on each material_types taxonomy term so the
 * EVA view can filter materials by bundle on the term page.
 *
 * Prerequisites: deploy with --cim first so field_material_bundle exists.
 *
 * Usage (on live server, in Drupal root):
 *   drush php:script populate_material_bundle_field.php
 *
 * Safe to run multiple times — overwrites the field value each time.
 */

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

// TID => bundle machine name mapping.
$mapping = [
  1426 => 'annuals',         // Annuals
  1774 => 'backflow',        // Backflow
  1312 => 'pavers',          // Blocks and Pavers
  1314 => 'brass',           // Brass
  1315 => 'xmas',            // Christmas Lights
  1316 => 'copper',          // Copper
  1317 => 'electric',        // Electric
  1318 => 'galv',            // Galvanized Pipe and Fittings
  1319 => 'irrigation',      // Irrigation
  1320 => 'landscape',       // Landscape Materials
  1326 => 'misc',            // Miscellaneous Materials
  1773 => 'mulch',           // Mulch
  1324 => 'poly',            // Poly
  1323 => 'pumps',           // Pumps
  1313 => 'pvc',             // PVC
  1403 => 'decorative_rock', // Rock
  1325 => 'shrubs',          // Shrubs
  1322 => 'sod',             // Sod
  1321 => 'plants',          // Plants (parent)
  1433 => 'trees',           // Trees (parent)
];

$updated = 0;
$errors = 0;

foreach ($mapping as $tid => $bundle) {
  $term = $term_storage->load($tid);
  if (!$term) {
    echo "WARNING: TID $tid not found — skipped.\n";
    $errors++;
    continue;
  }

  if (!$term->hasField('field_material_bundle')) {
    echo "ERROR: field_material_bundle does not exist. Run drush cim -y first.\n";
    return;
  }

  $term->set('field_material_bundle', $bundle);
  $term->save();
  echo "Set TID $tid ({$term->label()}) => $bundle\n";
  $updated++;
}

echo "\nDone. Updated: $updated, Errors: $errors\n";
