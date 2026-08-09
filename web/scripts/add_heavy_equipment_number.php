<?php

/**
 * Add the existing field_equipment_number ("Asset ID") to the heavy_equipment
 * bundle + its edit form — the per-unit number field the mowers/chain saws
 * already use (e.g. "CS2-X"). Lets the office designate each skid-steer (SS1,
 * SS2, …) instead of relying on the internal entity id. Idempotent; run per env.
 *
 *   drush php:script web/scripts/add_heavy_equipment_number.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$storage = FieldStorageConfig::loadByName('equipment', 'field_equipment_number');
if (!$storage) {
  print "ERROR: field storage equipment.field_equipment_number missing — abort.\n";
  return;
}

if (!FieldConfig::loadByName('equipment', 'heavy_equipment', 'field_equipment_number')) {
  FieldConfig::create([
    'field_name' => 'field_equipment_number',
    'entity_type' => 'equipment',
    'bundle' => 'heavy_equipment',
    'label' => 'Asset ID',
    'required' => FALSE,
    'description' => 'Short unit number for this machine (e.g. SS1). Uniquely identifies it even when two share a model.',
  ])->save();
  print "created field instance equipment.heavy_equipment.field_equipment_number\n";
}
else {
  print "field instance already exists\n";
}

// Put it on the edit form (textfield) near the other identity fields.
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('equipment', 'heavy_equipment');
if (!$fd->getComponent('field_equipment_number')) {
  $fd->setComponent('field_equipment_number', [
    'type' => 'string_textfield',
    'weight' => -8,
    'settings' => ['size' => 20, 'placeholder' => ''],
  ])->save();
  print "added field_equipment_number to the heavy_equipment edit form\n";
}
else {
  print "field already on the edit form\n";
}

print "DONE.\n";
