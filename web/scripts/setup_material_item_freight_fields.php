<?php

/**
 * Add optional "Add Freight" charge to work-order material line items.
 *
 * Two fields on wo_material_list_item / items:
 *   - field_add_freight (boolean, default OFF) — the toggle.
 *   - field_freight     (decimal 10,2, min 0)  — total freight for the shipment,
 *     entered ONCE per order; recovered at cost (never marked up).
 *
 * Freight math lives in the two subtotal modules (wo_material_item_subtotal,
 * wo_material_list_management); the amount is hidden until the box is checked
 * (wo_material_list_form form_alter). Policy: recover shipping at cost on
 * special-order parts — enter freight once per shipment; never mark it up,
 * never eat it.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/setup_material_item_freight_fields.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'wo_material_list_item';
$BUNDLE = 'items';
$out = [];

// ---------------------------------------------------------------------------
// Field storages.
$storages = [
  'field_add_freight' => ['type' => 'boolean', 'settings' => []],
  'field_freight' => ['type' => 'decimal', 'settings' => ['precision' => 10, 'scale' => 2]],
];
foreach ($storages as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'type' => $def['type'],
      'cardinality' => 1,
      'settings' => $def['settings'],
    ])->save();
    $out[] = "storage $name created";
  }
  else {
    $out[] = "storage $name exists";
  }
}

// ---------------------------------------------------------------------------
// Field instances.
$instances = [
  'field_add_freight' => [
    'label' => 'Add Freight',
    'description' => 'Check to add shipping/freight to this line at cost (no markup). Use once per shipment, on the special-order part it shipped with.',
    'required' => FALSE,
    'settings' => [],
    'default_value' => [['value' => 0]],
  ],
  'field_freight' => [
    'label' => 'Freight',
    'description' => 'Total freight for this shipment — enter ONCE per order, not on every line. Added to the customer price at cost (no markup).',
    'required' => FALSE,
    'settings' => ['min' => 0],
    'default_value' => [],
  ],
];
foreach ($instances as $name => $def) {
  $id = "$ENTITY.$BUNDLE.$name";
  if (!FieldConfig::load($id)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => $def['label'],
      'description' => $def['description'],
      'required' => $def['required'],
      'settings' => $def['settings'],
      'default_value' => $def['default_value'],
    ])->save();
    $out[] = "instance $name created";
  }
  else {
    $out[] = "instance $name exists";
  }
}

// ---------------------------------------------------------------------------
// Form displays: add both widgets to every item entry form, placed just after
// the cost field. field_freight is hidden until field_add_freight is checked
// (that #states lives in wo_material_list_form_form_alter).
$fdStorage = \Drupal::entityTypeManager()->getStorage('entity_form_display');
$formDisplays = $fdStorage->loadByProperties([
  'targetEntityType' => $ENTITY,
  'bundle' => $BUNDLE,
]);
foreach ($formDisplays as $fd) {
  // Anchor weight after the cost/quantity fields.
  $anchor = $fd->getComponent('field_material_cost') ?? $fd->getComponent('field_quantity');
  $base = ($anchor && isset($anchor['weight'])) ? (int) $anchor['weight'] : 20;
  if (!$fd->getComponent('field_add_freight')) {
    $fd->setComponent('field_add_freight', [
      'type' => 'boolean_checkbox',
      'weight' => $base + 1,
      'settings' => ['display_label' => TRUE],
      'region' => 'content',
    ]);
  }
  if (!$fd->getComponent('field_freight')) {
    $fd->setComponent('field_freight', [
      'type' => 'number',
      'weight' => $base + 2,
      'settings' => ['placeholder' => ''],
      'region' => 'content',
    ]);
  }
  $fd->save();
  $out[] = 'form display ' . $fd->getMode() . ' updated';
}

// ---------------------------------------------------------------------------
// View display: surface both on the default view display (freight visible only
// when the toggle is on, via the boolean/number formatters).
$vd = \Drupal::service('entity_display.repository')->getViewDisplay($ENTITY, $BUNDLE, 'default');
$vanchor = $vd->getComponent('field_subtotal') ?? $vd->getComponent('field_material_cost');
$vbase = ($vanchor && isset($vanchor['weight'])) ? (int) $vanchor['weight'] : 20;
if (!$vd->getComponent('field_add_freight')) {
  $vd->setComponent('field_add_freight', ['type' => 'boolean', 'label' => 'inline', 'weight' => $vbase + 1, 'region' => 'content']);
}
if (!$vd->getComponent('field_freight')) {
  $vd->setComponent('field_freight', ['type' => 'number_decimal', 'label' => 'inline', 'weight' => $vbase + 2, 'region' => 'content']);
}
$vd->save();
$out[] = 'view display default updated';

print implode("\n", $out) . "\nDONE.\n";
