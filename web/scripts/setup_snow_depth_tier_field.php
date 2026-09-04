<?php

/**
 * @file
 * Add field_snow_depth_tier to taxonomy_term:snow_levels — a stable machine key
 * the snow billing reads to map a chosen snow level to a contract plow-rate tier
 * (independent of the human-facing term label). Idempotent.
 * Run: ddev drush php:script web/scripts/setup_snow_depth_tier_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$allowed = [
  '0_2' => '0-2"',
  '2_4' => '2-4"',
  '4_6' => '4-6"',
  '6_plus' => '6"+',
  'icy' => 'Icy',
];

if (!FieldStorageConfig::loadByName('taxonomy_term', 'field_snow_depth_tier')) {
  FieldStorageConfig::create([
    'field_name' => 'field_snow_depth_tier',
    'entity_type' => 'taxonomy_term',
    'type' => 'list_string',
    'cardinality' => 1,
    'settings' => ['allowed_values' => $allowed, 'allowed_values_function' => ''],
  ])->save();
  print "storage created\n";
}
if (!FieldConfig::loadByName('taxonomy_term', 'snow_levels', 'field_snow_depth_tier')) {
  FieldConfig::create([
    'field_name' => 'field_snow_depth_tier',
    'entity_type' => 'taxonomy_term',
    'bundle' => 'snow_levels',
    'label' => 'Plow-rate tier',
    'description' => 'Maps this snow level to the contract plow-rate tier used for billing. "Icy" is ice-only (no plow tier).',
    'required' => FALSE,
  ])->save();
  print "instance created\n";
}
$fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('taxonomy_term.snow_levels.default');
if ($fd && !$fd->getComponent('field_snow_depth_tier')) {
  $fd->setComponent('field_snow_depth_tier', ['type' => 'options_select', 'weight' => 5])->save();
  print "form component added\n";
}
print "DONE\n";
