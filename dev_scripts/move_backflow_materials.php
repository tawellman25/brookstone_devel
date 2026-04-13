<?php

/**
 * Drush PHP script: Move Febco backflow materials from irrigation to backflow bundle.
 *
 * Prerequisites: deploy with --cim first so the backflow bundle exists.
 *
 * Usage (on live server, in Drupal root):
 *   drush php:script move_backflow_materials.php
 *
 * Safe to run multiple times — only moves materials still in irrigation bundle.
 * Entity IDs are unchanged.
 */

$storage = \Drupal::entityTypeManager()->getStorage('material');
$database = \Drupal::database();

// Verify backflow bundle exists.
$bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');
if (!isset($bundles['backflow'])) {
  echo "ERROR: Backflow bundle does not exist. Run drush cim -y first.\n";
  return;
}

// Find all Febco manufacturer materials in irrigation bundle.
$mfr_ids = $database->select('material_field_data', 'm')
  ->fields('m', ['id'])
  ->condition('m.type', 'irrigation');
$mfr_ids->join('material__field_manufacturer', 'mfr', 'mfr.entity_id = m.id AND mfr.deleted = 0');
$mfr_ids->join('manufacturer_field_data', 'mfd', 'mfd.id = mfr.field_manufacturer_target_id');
$mfr_ids->condition('mfd.title', '%Febco%', 'LIKE');
$mfr_result = $mfr_ids->execute()->fetchCol();

// Also find by title patterns (in case manufacturer not set).
$title_ids = $database->select('material_field_data', 'm')
  ->fields('m', ['id'])
  ->condition('m.type', 'irrigation');
$or = $database->condition('OR')
  ->condition('m.title', '%765 PVB%', 'LIKE')
  ->condition('m.title', '%765 Pressure%', 'LIKE')
  ->condition('m.title', '%825Y%', 'LIKE')
  ->condition('m.title', '%Febco%', 'LIKE')
  ->condition('m.title', '%1st Check Spring%', 'LIKE');
$title_ids->condition($or);
$title_result = $title_ids->execute()->fetchCol();

$all_ids = array_unique(array_merge($mfr_result, $title_result));

if (empty($all_ids)) {
  echo "No materials to move — they may have already been moved.\n";
  return;
}

echo "Found " . count($all_ids) . " materials to move from irrigation to backflow.\n";

$moved = 0;
$errors = 0;

foreach ($storage->loadMultiple($all_ids) as $entity) {
  try {
    $old_title = $entity->label();
    $entity->set('type', 'backflow');
    $entity->save();
    echo "  Moved ID {$entity->id()}: $old_title\n";
    $moved++;
  }
  catch (\Throwable $e) {
    echo "  ERROR ID {$entity->id()}: {$e->getMessage()}\n";
    $errors++;
  }
}

echo "\nDone. Moved: $moved, Errors: $errors\n";
