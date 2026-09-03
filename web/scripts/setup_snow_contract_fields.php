<?php

/**
 * @file
 * Phase 1 field build-out for the Snow Removal Service Agreement
 * (contracts.snow_removal). Idempotent; ECK/field configs skip cim, so this is
 * the deploy path. Places each field on the default + admin contract form
 * displays and the default view display.
 *
 * Fields (pricing tiers + ice-treatment flag were added earlier):
 *   field_snow_contract_number       string  (auto SNOW-{year}-{id}, set by module)
 *   field_snow_service_method        list    Automatic / On-Call
 *   field_snow_trigger               decimal inches that trigger a visit
 *   field_snow_ice_authorized boolean customer authorizes ice control
 *   field_salt_rate                  decimal $ ice-control (salt) pricing
 *   field_mag_rate                   decimal $ ice-control (mag) pricing
 *   field_shovel_rate                decimal $ sidewalk/shovel pricing
 *   field_snow_property_instructions text_long property-specific instructions
 *   field_snow_template_version      string  document/template version
 *
 * Run: ddev drush php:script web/scripts/setup_snow_contract_fields.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

// [storage settings, storage type, instance label, instance settings, form widget, view formatter]
$defs = [
  'field_snow_contract_number' => [
    'type' => 'string', 'storage' => ['max_length' => 64],
    'label' => 'Snow Contract #', 'settings' => [],
    'widget' => ['type' => 'string_textfield', 'settings' => ['size' => 20]],
    'formatter' => ['type' => 'string'],
    'desc' => 'Auto-assigned (SNOW-{year}-{id}). Identifies this agreement for the QR code + scanning.',
  ],
  'field_snow_service_method' => [
    'type' => 'list_string', 'storage' => ['allowed_values' => ['automatic' => 'Automatic', 'on_call' => 'On-Call']],
    'label' => 'Service Method', 'settings' => [],
    'widget' => ['type' => 'options_select'],
    'formatter' => ['type' => 'list_default'],
    'desc' => 'Automatic = we service on trigger; On-Call = only when the customer calls.',
  ],
  'field_snow_trigger' => [
    'type' => 'decimal', 'storage' => ['precision' => 4, 'scale' => 2],
    'label' => 'Snow Trigger (inches)', 'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '', 'suffix' => '"'],
    'widget' => ['type' => 'number'],
    'formatter' => ['type' => 'number_decimal'],
    'desc' => 'Accumulation depth that triggers an automatic service visit.',
  ],
  'field_snow_ice_authorized' => [
    'type' => 'boolean', 'storage' => [],
    'label' => 'Ice Control Authorized', 'settings' => ['on_label' => 'Authorized', 'off_label' => 'Not authorized'],
    'widget' => ['type' => 'boolean_checkbox', 'settings' => ['display_label' => TRUE]],
    'formatter' => ['type' => 'boolean'],
    'desc' => 'Customer authorizes ice-control (salt / mag) application and billing. (Operational completion gate is field_requires_ice_treatment.)',
  ],
  'field_salt_rate' => [
    'type' => 'decimal', 'storage' => ['precision' => 10, 'scale' => 2],
    'label' => 'Salt Rate', 'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '$', 'suffix' => ''],
    'widget' => ['type' => 'number'], 'formatter' => ['type' => 'number_decimal'],
    'desc' => 'Ice-control pricing — salt.',
  ],
  'field_mag_rate' => [
    'type' => 'decimal', 'storage' => ['precision' => 10, 'scale' => 2],
    'label' => 'Mag Chloride Rate', 'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '$', 'suffix' => ''],
    'widget' => ['type' => 'number'], 'formatter' => ['type' => 'number_decimal'],
    'desc' => 'Ice-control pricing — mag chloride.',
  ],
  'field_shovel_rate' => [
    'type' => 'decimal', 'storage' => ['precision' => 10, 'scale' => 2],
    'label' => 'Sidewalk / Shovel Rate', 'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '$', 'suffix' => ''],
    'widget' => ['type' => 'number'], 'formatter' => ['type' => 'number_decimal'],
    'desc' => 'Sidewalk / hand-shoveling service pricing (pairs with "Shoveling Labor Included").',
  ],
  'field_snow_property_instructions' => [
    'type' => 'text_long', 'storage' => [],
    'label' => 'Property-Specific Instructions', 'settings' => [],
    'widget' => ['type' => 'text_textarea', 'settings' => ['rows' => 4]],
    'formatter' => ['type' => 'text_default'],
    'desc' => 'Property-specific instructions shown on the agreement + to the crew.',
  ],
  'field_snow_template_version' => [
    'type' => 'string', 'storage' => ['max_length' => 32],
    'label' => 'Template Version', 'settings' => [],
    'widget' => ['type' => 'string_textfield', 'settings' => ['size' => 10]],
    'formatter' => ['type' => 'string'],
    'desc' => 'Snow agreement template version this contract was generated against.',
  ],
];

$weight = 30;
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
