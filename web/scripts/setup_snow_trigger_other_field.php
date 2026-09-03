<?php

/**
 * @file
 * Add field_snow_trigger_other (string) to contracts.snow_removal — the custom
 * depth/description captured when Snow Trigger = "Other". Shown on the contract
 * form only when Other is selected (#states, contract_snow_form_alter), and as
 * a fill-in line on the agreement PDF. Idempotent.
 * Run: ddev drush php:script web/scripts/setup_snow_trigger_other_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

if (!FieldStorageConfig::loadByName('contracts', 'field_snow_trigger_other')) {
  FieldStorageConfig::create([
    'field_name' => 'field_snow_trigger_other',
    'entity_type' => 'contracts',
    'type' => 'string',
    'settings' => ['max_length' => 255],
    'cardinality' => 1,
  ])->save();
  print "storage created\n";
}
if (!FieldConfig::loadByName('contracts', 'snow_removal', 'field_snow_trigger_other')) {
  FieldConfig::create([
    'field_name' => 'field_snow_trigger_other',
    'entity_type' => 'contracts',
    'bundle' => 'snow_removal',
    'label' => 'Other trigger — specify',
    'description' => 'The custom trigger depth/description when Snow Trigger is set to "Other".',
    'settings' => [],
  ])->save();
  print "instance created\n";
}
foreach (['default', 'admin'] as $fd_id) {
  $fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("contracts.snow_removal.$fd_id");
  if ($fd && !$fd->getComponent('field_snow_trigger_other')) {
    $fd->setComponent('field_snow_trigger_other', ['type' => 'string_textfield', 'weight' => 33, 'settings' => ['size' => 40]])->save();
  }
}
$vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('contracts.snow_removal.default');
if ($vd && !$vd->getComponent('field_snow_trigger_other')) {
  $vd->setComponent('field_snow_trigger_other', ['type' => 'string', 'weight' => 33, 'label' => 'inline'])->save();
}
print "DONE\n";
