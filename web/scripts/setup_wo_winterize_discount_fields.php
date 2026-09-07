<?php

/**
 * @file
 * Add discount fields to work_order.sprinkler_winterizing so the applied
 * discount + its reason are recorded on the WO (auditable, invoice-visible).
 *
 *   field_winterize_discount        decimal — dollars subtracted (single largest)
 *   field_winterize_discount_reason string  — which discount won
 *
 * ECK/field configs silent-skip on cim → this script IS the deploy path.
 * Idempotent. Run: drush php:script web/scripts/setup_wo_winterize_discount_fields.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$ENTITY = 'work_order';
$BUNDLE = 'sprinkler_winterizing';
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

$ensure('field_winterize_discount', 'decimal', ['precision' => 10, 'scale' => 2], 'Winterizing Discount',
  'Auto-applied at completion — the single largest qualifying discount (contract/auto-list, 4+ services, new-customer 4+ homes, or HOA). Already reflected in the WO total.');
$ensure('field_winterize_discount_reason', 'string', ['max_length' => 255], 'Winterizing Discount Reason',
  'Which discount was applied (for the office / invoice).');
$ensure('field_new_customer_discount', 'boolean', [], 'New Customer 4+ Homes Discount',
  'Check when this is a new customer signing up 4 or more homes (applies the one-time discount to THIS work order only). Office-selected.');

// Form display (read-mostly — office can see/override).
$fd = EntityFormDisplay::load("$ENTITY.$BUNDLE.default");
if ($fd) {
  if (!$fd->getComponent('field_winterize_discount')) {
    $fd->setComponent('field_winterize_discount', ['type' => 'number', 'weight' => 60, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  }
  if (!$fd->getComponent('field_winterize_discount_reason')) {
    $fd->setComponent('field_winterize_discount_reason', ['type' => 'string_textfield', 'weight' => 61, 'region' => 'content', 'settings' => ['size' => 40], 'third_party_settings' => []]);
  }
  if (!$fd->getComponent('field_new_customer_discount')) {
    $fd->setComponent('field_new_customer_discount', ['type' => 'boolean_checkbox', 'weight' => 59, 'region' => 'content', 'settings' => ['display_label' => TRUE], 'third_party_settings' => []]);
  }
  $fd->save();
  $out[] = 'form: added discount + reason + new-customer checkbox';
}

// View display.
$vd = EntityViewDisplay::load("$ENTITY.$BUNDLE.default");
if ($vd) {
  if (!$vd->getComponent('field_winterize_discount')) {
    $vd->setComponent('field_winterize_discount', ['type' => 'number_decimal', 'label' => 'inline', 'weight' => 60, 'region' => 'content', 'settings' => ['prefix_suffix' => TRUE], 'third_party_settings' => []]);
  }
  if (!$vd->getComponent('field_winterize_discount_reason')) {
    $vd->setComponent('field_winterize_discount_reason', ['type' => 'string', 'label' => 'inline', 'weight' => 61, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  }
  $vd->save();
  $out[] = 'view: added discount + reason';
}

print implode("\n", $out) . "\nDONE.\n";
