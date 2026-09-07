<?php

/**
 * @file
 * Add Neighborhood (text) + HOA-contracted (boolean) to properties.property.
 *
 * field_hoa_contracted is the billing trigger for the winterizing HOA discount
 * (−$35). field_neighborhood is informational (e.g. "Bear Creek"). Bear Creek
 * signups auto-set both (see bos_service_request converter wiring).
 *
 * ECK/field configs silent-skip on cim → this script IS the deploy path.
 * Idempotent. Run: drush php:script web/scripts/setup_property_neighborhood_hoa.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$ENTITY = 'properties';
$BUNDLE = 'property';
$out = [];

$ensure = function (string $name, string $type, array $sset, string $label, string $desc) use (&$out, $ENTITY, $BUNDLE) {
  $storage = FieldStorageConfig::loadByName($ENTITY, $name);
  if (!$storage) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => $ENTITY, 'type' => $type,
      'cardinality' => 1, 'settings' => $sset,
    ])->save();
    $out[] = "created storage $name";
    $storage = FieldStorageConfig::loadByName($ENTITY, $name);
  }
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    FieldConfig::create([
      'field_storage' => $storage, 'bundle' => $BUNDLE,
      'label' => $label, 'description' => $desc, 'required' => FALSE,
    ])->save();
    $out[] = "created instance $name";
  }
};

$ensure('field_neighborhood', 'string', ['max_length' => 100], 'Neighborhood',
  'Optional neighborhood / subdivision name (e.g. "Bear Creek"). Informational; the HOA discount is driven by the checkbox below.');
$ensure('field_hoa_contracted', 'boolean', [], 'HOA Contracted (winterizing discount)',
  'When checked, winterizing WOs for this property automatically apply the HOA Contracted discount ($35 off). Bear Creek signups set this automatically.');

// Form display.
$fd = EntityFormDisplay::load("$ENTITY.$BUNDLE.default");
if ($fd) {
  if (!$fd->getComponent('field_neighborhood')) {
    $fd->setComponent('field_neighborhood', ['type' => 'string_textfield', 'weight' => 20, 'region' => 'content', 'settings' => ['size' => 40, 'placeholder' => ''], 'third_party_settings' => []]);
    $out[] = 'form: added field_neighborhood';
  }
  if (!$fd->getComponent('field_hoa_contracted')) {
    $fd->setComponent('field_hoa_contracted', ['type' => 'boolean_checkbox', 'weight' => 21, 'region' => 'content', 'settings' => ['display_label' => TRUE], 'third_party_settings' => []]);
    $out[] = 'form: added field_hoa_contracted';
  }
  $fd->save();
}

// View display.
$vd = EntityViewDisplay::load("$ENTITY.$BUNDLE.default");
if ($vd) {
  if (!$vd->getComponent('field_neighborhood')) {
    $vd->setComponent('field_neighborhood', ['type' => 'string', 'label' => 'inline', 'weight' => 20, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  }
  if (!$vd->getComponent('field_hoa_contracted')) {
    $vd->setComponent('field_hoa_contracted', ['type' => 'boolean', 'label' => 'inline', 'weight' => 21, 'region' => 'content', 'settings' => ['format' => 'yes-no'], 'third_party_settings' => []]);
  }
  $vd->save();
  $out[] = 'view: added neighborhood + hoa';
}

print implode("\n", $out) . "\nDONE.\n";
