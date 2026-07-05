<?php

/**
 * Add a dedicated lighting labor rate to the business_setting config page:
 *   field_lighting_technician_rate  (decimal, $)  — hourly rate
 *   field_lighting_tech_minimum     (decimal, fraction-of-hour) — minimum billable time
 * Modeled on the sprinkler equivalents. Left EMPTY for the office to set after a
 * competitive-rate analysis. Idempotent. Run: drush php:script <this>.
 * (config_pages fields cim-skip like ECK, so this uses the entity API.)
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$defs = [
  'field_lighting_technician_rate' => [
    'label' => 'Lighting Technician',
    'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '$', 'suffix' => ''],
    'weight' => 47,
  ],
  'field_lighting_tech_minimum' => [
    'label' => 'Lighting Technician Minimum',
    'settings' => ['min' => 0.01, 'max' => 1, 'prefix' => '', 'suffix' => 'of an hour'],
    'weight' => 48,
  ],
];

$edr = \Drupal::service('entity_display.repository');
$fd = $edr->getFormDisplay('config_pages', 'business_setting');

foreach ($defs as $name => $cfg) {
  if (!FieldStorageConfig::loadByName('config_pages', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'config_pages',
      'type' => 'decimal',
      'settings' => ['precision' => 10, 'scale' => 2],
      'cardinality' => 1,
    ])->save();
    print "created storage $name\n";
  }
  if (!FieldConfig::loadByName('config_pages', 'business_setting', $name)) {
    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName('config_pages', $name),
      'bundle' => 'business_setting',
      'label' => $cfg['label'],
      'required' => FALSE,
      'settings' => $cfg['settings'],
    ])->save();
    print "created field $name (\"" . $cfg['label'] . "\")\n";
  }
  else {
    print "field $name already present\n";
  }
  $fd->setComponent($name, ['type' => 'number', 'weight' => $cfg['weight'], 'region' => 'content', 'settings' => ['placeholder' => '']]);
}
$fd->save();
print "form display updated (fields editable on the Business Settings page)\n";
