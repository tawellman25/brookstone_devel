<?php

/**
 * @file
 * Break the single snow "Per Push Rate" into depth-tiered plow rates on
 * contracts.snow_removal, matching the customer-facing pricing tiers:
 *   0–2", 2–4", 4–6", 6"+  (decimal(10,2), $ prefix — same as field_per_push_rate).
 *
 * Each rate is for one complete plowing of the contracted area at that depth.
 * The legacy field_per_push_rate is KEPT (still used by wo_snow_removal billing
 * until the tiered-billing phase wires depth → tier). Idempotent; ECK/field
 * configs skip cim, so this script is the deploy path. Places the fields on the
 * default + admin contract form displays and the default view display.
 *
 * Run: ddev drush php:script web/scripts/setup_snow_tiered_plow_rates.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$tiers = [
  'field_plow_rate_0_2' => 'Plow Rate — 0–2"',
  'field_plow_rate_2_4' => 'Plow Rate — 2–4"',
  'field_plow_rate_4_6' => 'Plow Rate — 4–6"',
  'field_plow_rate_6_plus' => 'Plow Rate — 6"+',
];

$weight = 10;
foreach ($tiers as $field_name => $label) {
  if (!FieldStorageConfig::loadByName('contracts', $field_name)) {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'contracts',
      'type' => 'decimal',
      'settings' => ['precision' => 10, 'scale' => 2],
      'cardinality' => 1,
    ])->save();
    print "storage $field_name created\n";
  }
  if (!FieldConfig::loadByName('contracts', 'snow_removal', $field_name)) {
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'contracts',
      'bundle' => 'snow_removal',
      'label' => $label,
      'description' => 'Rate for one complete plowing of the contracted service area at this snow depth.',
      'settings' => ['min' => NULL, 'max' => NULL, 'prefix' => '$', 'suffix' => ''],
    ])->save();
    print "instance $field_name created\n";
  }

  foreach (['default', 'admin'] as $fd_id) {
    $fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("contracts.snow_removal.$fd_id");
    if ($fd && !$fd->getComponent($field_name)) {
      $fd->setComponent($field_name, ['type' => 'number', 'weight' => $weight, 'settings' => ['placeholder' => '']])->save();
    }
  }
  $vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('contracts.snow_removal.default');
  if ($vd && !$vd->getComponent($field_name)) {
    $vd->setComponent($field_name, ['type' => 'number_decimal', 'weight' => $weight, 'label' => 'inline'])->save();
  }
  $weight++;
}

print "\nUUIDs (for sync YAMLs):\n";
foreach (array_keys($tiers) as $field_name) {
  $s = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load("contracts.$field_name");
  $i = \Drupal::entityTypeManager()->getStorage('field_config')->load("contracts.snow_removal.$field_name");
  print "  $field_name  storage=" . ($s ? $s->uuid() : '?') . "  instance=" . ($i ? $i->uuid() : '?') . "\n";
}
print "DONE\n";
