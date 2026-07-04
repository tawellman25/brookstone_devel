<?php

/**
 * @file
 * Idempotent setup for field_source on wo_time_clock:entry — the structured
 * origin-attribution marker (Phase A refinement). Entity-API workaround for the
 * cim silent-skip bug (see drupal_bos_gotchas.md). Hidden on form, visible on
 * view at weight 5.
 *
 * Run: ddev drush php:script web/scripts/setup_wo_time_clock_field_source.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$name = 'field_source';
$allowed = [
  'flag' => 'Flag toggle (legacy path)',
  'wo_clock_button' => 'Clock In/Out button (Phase A)',
  'wo_clock_intervention' => 'Intervention close (alert region or modal)',
  'manual' => 'Manually entered (Enter Manually button or admin form)',
  'signoff_reconciliation' => 'Sign-off reconciliation',
  'cleanup_script' => 'Data hygiene cleanup script',
];

$storage = FieldStorageConfig::loadByName('wo_time_clock', $name);
if (!$storage) {
  $storage = FieldStorageConfig::create([
    'field_name' => $name,
    'entity_type' => 'wo_time_clock',
    'type' => 'list_string',
    'cardinality' => 1,
    'settings' => ['allowed_values' => $allowed],
  ]);
  $storage->save();
  print "created storage field_source (list_string)\n";
}
else {
  print "storage field_source already exists\n";
}

$instance = FieldConfig::loadByName('wo_time_clock', 'entry', $name);
if (!$instance) {
  $instance = FieldConfig::create([
    'field_storage' => $storage,
    'bundle' => 'entry',
    'label' => 'Source',
    'required' => FALSE,
    'description' => 'Origin marker for how this time clock entry was created. Populated automatically by the code path that creates or modifies the entry.',
  ]);
  $instance->save();
  print "created instance field_source — uuid={$instance->uuid()}\n";
}
else {
  print "instance field_source already exists — uuid={$instance->uuid()}\n";
}

$etm = \Drupal::entityTypeManager();
$fd = $etm->getStorage('entity_form_display')->load('wo_time_clock.entry.default');
if ($fd) {
  $fd->removeComponent($name);
  $fd->save();
  print "form display: field_source hidden\n";
}
$vd = $etm->getStorage('entity_view_display')->load('wo_time_clock.entry.default');
if ($vd) {
  $vd->setComponent($name, [
    'type' => 'list_default',
    'weight' => 5,
    'label' => 'inline',
    'region' => 'content',
    'settings' => [],
  ]);
  $vd->save();
  print "view display: field_source visible at weight 5\n";
}
print "\nDONE. Patch the instance UUID into the sync YAML after export.\n";
