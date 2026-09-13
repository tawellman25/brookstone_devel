<?php

/**
 * A. Backflow TEST GAUGES as company-owned equipment.
 *
 * New `equipment` bundle `test_gauges`, reusing the shared equipment field
 * storages (make/model/serial/number/status/type/pictures/etc.) + a new
 * `field_assigned_to` (→ user; soft assignment — the company still owns it).
 * Calibration is tracked with the existing `equipment_maintenance_event` system:
 * this adds a "calibration" option to `field_event_type`. equipment_labels
 * auto-titles the bundle (no auto_entitylabel pattern needed).
 *
 * Idempotent; entity-API only (no cim). Run per env.
 *   drush php:script web/scripts/setup_test_gauges_bundle.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$etm = \Drupal::entityTypeManager();
$ENTITY = 'equipment';
$BUNDLE = 'test_gauges';
$out = [];

// 1. Bundle.
$bundleStorage = $etm->getStorage($etm->getDefinition($ENTITY)->getBundleEntityType());
if (!$bundleStorage->load($BUNDLE)) {
  $bundleStorage->create([
    'type' => $BUNDLE,
    'name' => 'Test Gauges',
    'description' => 'Backflow test gauges and calibrated instruments — company-owned, soft-assigned to a tester. Calibration tracked via equipment maintenance events.',
  ])->save();
  $out[] = "created bundle equipment.$BUNDLE";
}

// 2. field_assigned_to (new storage on equipment + instance on test_gauges).
if (!FieldStorageConfig::loadByName('equipment', 'field_assigned_to')) {
  FieldStorageConfig::create([
    'field_name' => 'field_assigned_to', 'entity_type' => 'equipment',
    'type' => 'entity_reference', 'cardinality' => 1,
    'settings' => ['target_type' => 'user'],
  ])->save();
  $out[] = 'storage field_assigned_to';
}
if (!FieldConfig::loadByName('equipment', $BUNDLE, 'field_assigned_to')) {
  FieldConfig::create([
    'field_name' => 'field_assigned_to', 'entity_type' => 'equipment', 'bundle' => $BUNDLE,
    'label' => 'Assigned To (holder)',
    'description' => 'The tester currently holding this gauge. Soft assignment — the company still owns it.',
    'settings' => ['handler' => 'default:user', 'handler_settings' => []],
  ])->save();
  $out[] = 'instance field_assigned_to';
}

// 3. Clone the reused equipment fields from heavy_equipment (identical handlers).
$clone = function (string $field, string $src = 'heavy_equipment') use ($BUNDLE, &$out) {
  if (FieldConfig::loadByName('equipment', $BUNDLE, $field)) { return; }
  $s = FieldConfig::loadByName('equipment', $src, $field);
  if (!$s) { $out[] = "  *** source missing: $src.$field"; return; }
  $a = $s->toArray();
  unset($a['uuid'], $a['_core'], $a['id'], $a['dependencies']);
  $a['bundle'] = $BUNDLE;
  FieldConfig::create($a)->save();
  $out[] = "instance $field";
};
foreach ([
  'field_equipment_number', 'field_status', 'field_equipment_type', 'field_equipment_make',
  'field_model', 'field_serial_code_number', 'field_manufactured_year',
  'field_date_purchased', 'field_purchase_price', 'field_pictures', 'field_documents',
] as $f) {
  $clone($f);
}

// 4. Add "calibration" to equipment_maintenance_event.field_event_type.
$evt = FieldStorageConfig::loadByName('equipment_maintenance_event', 'field_event_type');
if ($evt) {
  // getSettings() normalizes allowed_values to a value=>label MAP.
  $settings = $evt->getSettings();
  $vals = $settings['allowed_values'] ?? [];
  if (!array_key_exists('calibration', $vals)) {
    $vals['calibration'] = 'Calibration';
    $settings['allowed_values'] = $vals;
    $evt->setSettings($settings)->save();
    $out[] = 'event_type += calibration';
  }
}

// 5. Form + view displays (mirror heavy_equipment widgets/order for shared fields).
$repo = \Drupal::service('entity_display.repository');
$form = $repo->getFormDisplay('equipment', $BUNDLE);
$order = ['field_equipment_number', 'field_status', 'field_equipment_type', 'field_assigned_to',
  'field_equipment_make', 'field_model', 'field_serial_code_number', 'field_manufactured_year',
  'field_date_purchased', 'field_purchase_price', 'field_pictures', 'field_documents'];
$w = 1;
foreach ($order as $f) {
  if (!FieldConfig::loadByName('equipment', $BUNDLE, $f)) { continue; }
  $widget = match (TRUE) {
    in_array($f, ['field_status', 'field_equipment_type', 'field_assigned_to'], TRUE) => 'entity_reference_autocomplete',
    $f === 'field_pictures' => 'image_image',
    $f === 'field_documents' => 'file_generic',
    $f === 'field_date_purchased' => 'datetime_default',
    in_array($f, ['field_purchase_price', 'field_manufactured_year'], TRUE) => 'number',
    default => 'string_textfield',
  };
  $form->setComponent($f, ['type' => $widget, 'weight' => $w++, 'region' => 'content']);
}
$form->save();
$out[] = 'form display set';

$view = $repo->getViewDisplay('equipment', $BUNDLE);
$w = 1;
foreach ($order as $f) {
  if (!FieldConfig::loadByName('equipment', $BUNDLE, $f)) { continue; }
  $view->setComponent($f, ['label' => 'inline', 'weight' => $w++, 'region' => 'content']);
}
$view->save();
$out[] = 'view display set';

print implode("\n", $out) . "\nDONE.\n";
