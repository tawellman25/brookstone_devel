<?php

/**
 * @file
 * Create field_requires_ice_treatment (boolean) on contracts.snow_removal.
 *
 * When TRUE, the snow completion validation requires a Salt or Mag amount > 0
 * on an Icy Conditions visit before the WO can be completed (e.g. Walmart).
 * Default FALSE — the office opts in per contract. Idempotent; ECK/field
 * configs skip cim, so this script is the deploy path.
 *
 * Run: ddev drush php:script web/scripts/setup_requires_ice_treatment_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

if (!FieldStorageConfig::loadByName('contracts', 'field_requires_ice_treatment')) {
  FieldStorageConfig::create([
    'field_name' => 'field_requires_ice_treatment',
    'entity_type' => 'contracts',
    'type' => 'boolean',
    'cardinality' => 1,
  ])->save();
  print "Created storage contracts.field_requires_ice_treatment\n";
}
else {
  print "storage exists\n";
}

if (!FieldConfig::loadByName('contracts', 'snow_removal', 'field_requires_ice_treatment')) {
  FieldConfig::create([
    'field_name' => 'field_requires_ice_treatment',
    'entity_type' => 'contracts',
    'bundle' => 'snow_removal',
    'label' => 'Requires ice treatment on Icy visits',
    'description' => 'When checked, an Icy Conditions snow visit for this property cannot be completed until a Salt or Mag Chloride amount is recorded (e.g. Walmart). Leave unchecked for contracts with no ice-treatment requirement.',
    'settings' => ['on_label' => 'Required', 'off_label' => 'Not required'],
  ])->save();
  print "Created instance contracts.snow_removal.field_requires_ice_treatment\n";
}
else {
  print "instance exists\n";
}

// Place the field on the snow_removal contract form + view displays.
$fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('contracts.snow_removal.default');
if ($fd && !$fd->getComponent('field_requires_ice_treatment')) {
  $fd->setComponent('field_requires_ice_treatment', [
    'type' => 'boolean_checkbox',
    'weight' => 20,
    'settings' => ['display_label' => TRUE],
  ])->save();
  print "placed on form display\n";
}
$vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('contracts.snow_removal.default');
if ($vd && !$vd->getComponent('field_requires_ice_treatment')) {
  $vd->setComponent('field_requires_ice_treatment', [
    'type' => 'boolean',
    'weight' => 20,
    'label' => 'inline',
  ])->save();
  print "placed on view display\n";
}

$s = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load('contracts.field_requires_ice_treatment');
$i = \Drupal::entityTypeManager()->getStorage('field_config')->load('contracts.snow_removal.field_requires_ice_treatment');
print 'storage uuid: ' . ($s ? $s->uuid() : 'MISSING') . "\n";
print 'instance uuid: ' . ($i ? $i->uuid() : 'MISSING') . "\n";
print "DONE\n";
