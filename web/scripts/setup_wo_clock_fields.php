<?php

/**
 * @file
 * Idempotent setup for the four Phase A wo_clock GPS/distance fields on
 * wo_time_clock:entry. Uses the direct entity-API workaround (the cim
 * silent-skip bug — see drupal_bos_gotchas.md), then configures displays:
 * hidden on the default form, visible on the default view (weight 30-33).
 *
 * Run: ddev drush php:script web/scripts/setup_wo_clock_fields.php
 * Prints the per-environment UUIDs to patch into the sync YAMLs.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$fields = [
  [
    'name' => 'field_clock_in_location',
    'type' => 'geofield',
    'settings' => ['backend' => 'geofield_backend_default'],
    'label' => 'Clock-In Location',
    'weight' => 30,
    'view_formatter' => 'geofield_default',
  ],
  [
    'name' => 'field_clock_out_location',
    'type' => 'geofield',
    'settings' => ['backend' => 'geofield_backend_default'],
    'label' => 'Clock-Out Location',
    'weight' => 31,
    'view_formatter' => 'geofield_default',
  ],
  // NOTE: spec names field_clock_in/out_distance_from_property exceed Drupal's
  // 32-char machine-name limit; shortened to _distance_ft (labels unchanged).
  [
    'name' => 'field_clock_in_distance_ft',
    'type' => 'decimal',
    'settings' => ['precision' => 10, 'scale' => 2],
    'label' => 'Clock-In Distance from Property (ft)',
    'weight' => 32,
    'view_formatter' => 'number_decimal',
  ],
  [
    'name' => 'field_clock_out_distance_ft',
    'type' => 'decimal',
    'settings' => ['precision' => 10, 'scale' => 2],
    'label' => 'Clock-Out Distance from Property (ft)',
    'weight' => 33,
    'view_formatter' => 'number_decimal',
  ],
];

$etm = \Drupal::entityTypeManager();
$formDisplay = $etm->getStorage('entity_form_display')->load('wo_time_clock.entry.default');
$viewDisplay = $etm->getStorage('entity_view_display')->load('wo_time_clock.entry.default');

foreach ($fields as $f) {
  $storage = FieldStorageConfig::loadByName('wo_time_clock', $f['name']);
  if (!$storage) {
    $storage = FieldStorageConfig::create([
      'field_name' => $f['name'],
      'entity_type' => 'wo_time_clock',
      'type' => $f['type'],
      'cardinality' => 1,
      'settings' => $f['settings'],
    ]);
    $storage->save();
    print "created storage {$f['name']}\n";
  }
  else {
    print "storage {$f['name']} already exists\n";
  }

  $instance = FieldConfig::loadByName('wo_time_clock', 'entry', $f['name']);
  if (!$instance) {
    $instance = FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'entry',
      'label' => $f['label'],
      'required' => FALSE,
    ]);
    $instance->save();
    print "created instance {$f['name']} — uuid={$instance->uuid()}\n";
  }
  else {
    print "instance {$f['name']} already exists — uuid={$instance->uuid()}\n";
  }

  // Hidden on the default form display (matches field_total_time / audit fields).
  if ($formDisplay) {
    $formDisplay->removeComponent($f['name']);
  }
  // Visible on the default view display, grouped after existing fields.
  if ($viewDisplay) {
    $viewDisplay->setComponent($f['name'], [
      'type' => $f['view_formatter'],
      'weight' => $f['weight'],
      'label' => 'inline',
      'region' => 'content',
      'settings' => [],
    ]);
  }
}

if ($formDisplay) {
  $formDisplay->save();
  print "form display saved (4 fields hidden)\n";
}
if ($viewDisplay) {
  $viewDisplay->save();
  print "view display saved (4 fields visible, weight 30-33)\n";
}

print "\nDONE. Patch these instance UUIDs into the sync YAMLs after cex.\n";
