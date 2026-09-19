<?php

/**
 * Equipment-type grouping foundation:
 *   A) KIND — sync field_equipment_bundle options to ALL real equipment bundles
 *      (was missing it_equipment + test_gauges), then auto-populate each type
 *      from the bundle its equipment records actually use.
 *   B) DEPARTMENT — add a multi-value department reference (field_equip_departments)
 *      to equipment_types + its form widget (office tags which departments use
 *      each type; a machine can serve several).
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/setup_equipment_type_grouping.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$out = [];

// ---- A) sync field_equipment_bundle allowed values to the real bundles -------
$bundleLabels = [
  'attachements' => 'Attachments',
  'heavy_equipment' => 'Heavy Equipment',
  'it_equipment' => 'IT Equipment',
  'power_tools' => 'Power Tools',
  'small_engine' => 'Small Engine',
  'snow_plows' => 'Snow Plows',
  'sprayers' => 'Sprayers',
  'test_gauges' => 'Test Gauges',
  'trailers' => 'Trailers',
  'vehicles' => 'Vehicles',
];
$storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_equipment_bundle');
if ($storage) {
  $storage->setSetting('allowed_values', $bundleLabels);
  $storage->setSetting('allowed_values_function', '');
  $storage->save();
  $out[] = 'field_equipment_bundle options synced to ' . count($bundleLabels) . ' bundles';
}

// ---- B) department multi-reference field on equipment_types ------------------
$DEPT_FIELD = 'field_equip_departments';
if (!FieldStorageConfig::loadByName('taxonomy_term', $DEPT_FIELD)) {
  FieldStorageConfig::create([
    'field_name' => $DEPT_FIELD,
    'entity_type' => 'taxonomy_term',
    'type' => 'entity_reference',
    'cardinality' => -1,
    'settings' => ['target_type' => 'department'],
  ])->save();
  $out[] = "storage $DEPT_FIELD created (entity_reference -> department, multi)";
}
if (!FieldConfig::loadByName('taxonomy_term', 'equipment_types', $DEPT_FIELD)) {
  FieldConfig::create([
    'field_name' => $DEPT_FIELD,
    'entity_type' => 'taxonomy_term',
    'bundle' => 'equipment_types',
    'label' => 'Departments',
    'description' => 'Which departments use this equipment type. A machine can serve more than one.',
    'settings' => ['handler' => 'default:department', 'handler_settings' => []],
  ])->save();
  $out[] = "field $DEPT_FIELD added to equipment_types";
}
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('taxonomy_term', 'equipment_types', 'default');
if (!$fd->getComponent($DEPT_FIELD)) {
  $fd->setComponent($DEPT_FIELD, ['type' => 'options_buttons', 'weight' => 6, 'region' => 'content'])->save();
  $out[] = "$DEPT_FIELD added to the term form (checkboxes)";
}

// ---- A) populate field_equipment_bundle from equipment records ---------------
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$eqStorage = \Drupal::entityTypeManager()->getStorage('equipment');
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'equipment_types')->execute();
$set = 0;
$unresolved = [];
foreach ($termStorage->loadMultiple($tids) as $term) {
  $eids = \Drupal::entityQuery('equipment')->accessCheck(FALSE)->condition('field_equipment_type', $term->id())->execute();
  if (!$eids) {
    if ($term->get('field_equipment_bundle')->isEmpty()) {
      $unresolved[] = $term->id() . ':' . $term->label();
    }
    continue;
  }
  $tally = [];
  foreach ($eqStorage->loadMultiple($eids) as $eq) {
    $tally[$eq->bundle()] = ($tally[$eq->bundle()] ?? 0) + 1;
  }
  arsort($tally);
  $bundle = array_key_first($tally);
  if (isset($bundleLabels[$bundle]) && $term->get('field_equipment_bundle')->value !== $bundle) {
    $term->set('field_equipment_bundle', $bundle)->save();
    $set++;
  }
}
$out[] = "field_equipment_bundle populated/updated on $set term(s) from equipment records";
$out[] = count($unresolved) . ' type(s) have no equipment records and no bundle set (assign manually): ' . implode(', ', array_slice($unresolved, 0, 40));

print implode("\n", $out) . "\nDONE.\n";
