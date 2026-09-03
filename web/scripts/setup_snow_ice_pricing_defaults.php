<?php

/**
 * @file
 * Snow ice-control pricing defaults:
 *  - Add field_default_salt_per_lb to business_setting (per-pound salt default;
 *    the existing per-bag field_salt_rate is left for WO billing). Seed 0.85
 *    (= the current $42.50 / 50-lb bag) if empty — office adjusts.
 *  - Mag default reuses the existing business_setting field_mag_chloride_rate.
 *  - Relabel the contract per-customer rate fields with their units.
 * Idempotent. Run: ddev drush php:script web/scripts/setup_snow_ice_pricing_defaults.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

// 1. business_setting: Default Salt Rate (per lb).
if (!FieldStorageConfig::loadByName('config_pages', 'field_default_salt_per_lb')) {
  FieldStorageConfig::create([
    'field_name' => 'field_default_salt_per_lb',
    'entity_type' => 'config_pages',
    'type' => 'decimal',
    'settings' => ['precision' => 10, 'scale' => 2],
    'cardinality' => 1,
  ])->save();
  print "storage field_default_salt_per_lb\n";
}
if (!FieldConfig::loadByName('config_pages', 'business_setting', 'field_default_salt_per_lb')) {
  FieldConfig::create([
    'field_name' => 'field_default_salt_per_lb',
    'entity_type' => 'config_pages',
    'bundle' => 'business_setting',
    'label' => 'Default Salt Rate (per lb)',
    'description' => 'Default per-pound salt price for snow contracts. A snow contract\'s per-customer Salt Rate prefills from this and can be overridden.',
    'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '$', 'suffix' => ''],
  ])->save();
  print "instance field_default_salt_per_lb\n";
}
$fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('config_pages.business_setting.default');
if ($fd && !$fd->getComponent('field_default_salt_per_lb')) {
  $fd->setComponent('field_default_salt_per_lb', ['type' => 'number', 'weight' => 50])->save();
  print "placed on business_setting form\n";
}
// Seed the default value if empty.
$bs = \Drupal::service('config_pages.loader')->load('business_setting');
if ($bs && $bs->hasField('field_default_salt_per_lb') && $bs->get('field_default_salt_per_lb')->isEmpty()) {
  $bs->set('field_default_salt_per_lb', '0.85');
  $bs->save();
  print "seeded default salt/lb = 0.85\n";
}

// 2. Relabel contract per-customer rate fields with units.
$salt = FieldConfig::loadByName('contracts', 'snow_removal', 'field_salt_rate');
if ($salt) {
  $salt->set('label', 'Salt Rate (per lb)')->save();
  print "relabeled contract field_salt_rate\n";
}
$mag = FieldConfig::loadByName('contracts', 'snow_removal', 'field_mag_rate');
if ($mag) {
  $mag->set('label', 'Mag Chloride Rate (per gal)')->save();
  print "relabeled contract field_mag_rate\n";
}
print "DONE\n";
