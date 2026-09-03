<?php

/**
 * @file
 * Add a per-visit ice-control material cap to contracts.snow_removal:
 *   field_snow_ice_max_amount (decimal) + field_snow_ice_max_unit (Bags/Pounds/
 *   Gallons). Shown on the agreement as "Max per visit: 10 Bags". Idempotent.
 * Run: ddev drush php:script web/scripts/setup_snow_ice_max_fields.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$defs = [
  'field_snow_ice_max_amount' => [
    'type' => 'decimal', 'storage' => ['precision' => 6, 'scale' => 2],
    'label' => 'Ice-Control Max per Visit — amount', 'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '', 'suffix' => ''],
    'widget' => ['type' => 'number'], 'formatter' => ['type' => 'number_decimal'],
    'desc' => 'Maximum ice-control material applied per visit (with the unit field). Leave blank for no cap.',
  ],
  'field_snow_ice_max_unit' => [
    'type' => 'list_string', 'storage' => ['allowed_values' => ['bags' => 'Bags', 'pounds' => 'Pounds', 'gallons' => 'Gallons']],
    'label' => 'Ice-Control Max per Visit — unit', 'settings' => [],
    'widget' => ['type' => 'options_select'], 'formatter' => ['type' => 'list_default'],
    'desc' => 'Unit for the per-visit ice-control cap.',
  ],
];

$weight = 18;
foreach ($defs as $name => $d) {
  if (!FieldStorageConfig::loadByName('contracts', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => 'contracts',
      'type' => $d['type'], 'settings' => $d['storage'], 'cardinality' => 1,
    ])->save();
    print "storage $name\n";
  }
  if (!FieldConfig::loadByName('contracts', 'snow_removal', $name)) {
    FieldConfig::create([
      'field_name' => $name, 'entity_type' => 'contracts', 'bundle' => 'snow_removal',
      'label' => $d['label'], 'description' => $d['desc'], 'settings' => $d['settings'],
    ])->save();
    print "instance $name\n";
  }
  foreach (['default', 'admin'] as $fd_id) {
    $fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("contracts.snow_removal.$fd_id");
    if ($fd && !$fd->getComponent($name)) {
      $fd->setComponent($name, ['weight' => $weight] + $d['widget'])->save();
    }
  }
  $vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('contracts.snow_removal.default');
  if ($vd && !$vd->getComponent($name)) {
    $vd->setComponent($name, ['weight' => $weight, 'label' => 'inline'] + $d['formatter'])->save();
  }
  $weight++;
}
print "DONE\n";
