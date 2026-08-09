<?php

/**
 * Gate 1 — Hardscape (pavers) bundle structural build. IDEMPOTENT; run per env.
 *
 * Creates: hardscape_types vocabulary; 11 new fields on material.pavers; the
 * form-display order + widgets; the two new fields on the default view display;
 * and relabels field_name → "Name". Entity-API only (no cim — field-instance
 * configs silently skip on cim; this is the BOS idiom, per setup_wo_profit_fields
 * / setup_property_photo_media). Terms + backfill are SEPARATE scripts.
 *
 *   drush php:script web/scripts/setup_hardscape_fields.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Vocabulary;

$BUNDLE = 'pavers';

// ── Vocabulary ──────────────────────────────────────────────────────────────
if (!Vocabulary::load('hardscape_types')) {
  Vocabulary::create(['vid' => 'hardscape_types', 'name' => 'Hardscape Types'])->save();
  print "created vocabulary hardscape_types\n";
}
else {
  print "vocabulary hardscape_types exists\n";
}

// ── Field helpers (guarded / idempotent) ────────────────────────────────────
$ensureStorage = function (string $name, string $type, array $settings = [], int $card = 1) {
  if (!FieldStorageConfig::loadByName('material', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'material',
      'type' => $type,
      'settings' => $settings,
      'cardinality' => $card,
    ])->save();
    print "  storage created: $name ($type)\n";
  }
  else {
    print "  storage exists: $name\n";
  }
};
$ensureInstance = function (string $name, string $label, array $settings = []) use ($BUNDLE) {
  if (!FieldConfig::loadByName('material', $BUNDLE, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'material',
      'bundle' => $BUNDLE,
      'label' => $label,
      'required' => FALSE,
      'settings' => $settings,
    ])->save();
    print "  instance created: $name\n";
  }
  else {
    print "  instance exists: $name\n";
  }
};

// ── Tier 1 ──────────────────────────────────────────────────────────────────
$ensureStorage('field_hardscape_type', 'entity_reference', ['target_type' => 'taxonomy_term'], 1);
$ensureInstance('field_hardscape_type', 'Hardscape Type', [
  'handler' => 'default:taxonomy_term',
  'handler_settings' => ['target_bundles' => ['hardscape_types' => 'hardscape_types'], 'auto_create' => FALSE],
]);
$ensureStorage('field_color', 'string', [], 1);
$ensureInstance('field_color', 'Color');

// ── Tier 2 ──────────────────────────────────────────────────────────────────
$ensureStorage('field_length_in', 'decimal', ['precision' => 6, 'scale' => 2], 1);
$ensureInstance('field_length_in', 'Length (in)');
$ensureStorage('field_width_in', 'decimal', ['precision' => 6, 'scale' => 2], 1);
$ensureInstance('field_width_in', 'Width (in)');
$ensureStorage('field_thickness_in', 'decimal', ['precision' => 6, 'scale' => 2], 1);
$ensureInstance('field_thickness_in', 'Thickness (in)');
$ensureStorage('field_units_per_sqft', 'decimal', ['precision' => 8, 'scale' => 4], 1);
$ensureInstance('field_units_per_sqft', 'Units per Sq Ft');
$ensureStorage('field_units_per_pallet', 'integer', [], 1);
$ensureInstance('field_units_per_pallet', 'Units per Pallet');
$ensureStorage('field_weight_each', 'decimal', ['precision' => 8, 'scale' => 2], 1);
$ensureInstance('field_weight_each', 'Weight Each (lb)');

// ── Tier 3 ──────────────────────────────────────────────────────────────────
$ensureStorage('field_finish_texture', 'string', [], 1);
$ensureInstance('field_finish_texture', 'Finish / Texture');
$ensureStorage('field_setback', 'string', [], 1);
$ensureInstance('field_setback', 'Setback / Batter');
$ensureStorage('field_application', 'list_string', [
  'allowed_values' => [
    'patio' => 'Patio', 'driveway' => 'Driveway', 'pool_deck' => 'Pool Deck',
    'walkway' => 'Walkway', 'freestanding_wall' => 'Freestanding Wall',
    'retaining_wall' => 'Retaining Wall', 'edging' => 'Edging', 'steps' => 'Steps',
  ],
], -1);
$ensureInstance('field_application', 'Application');

// ── Relabel field_name → "Name" (load-modify-save, guarded) ─────────────────
$fn = FieldConfig::loadByName('material', $BUNDLE, 'field_name');
if ($fn && $fn->getLabel() !== 'Name') {
  $fn->setLabel('Name')->save();
  print "  relabeled field_name → \"Name\"\n";
}
else {
  print "  field_name label already \"Name\" (or missing)\n";
}

// ── Form display: group-aware layout ────────────────────────────────────────
// Loose (ungrouped) fields render on top by weight; field_group sections render
// below (group weights 30+). Pack fields move INTO the Manufacturer group
// (packaging is manufacturer-dictated). Description stays in Public Information.
$repo = \Drupal::service('entity_display.repository');
$form = $repo->getFormDisplay('material', $BUNDLE);

// Widget for the NEW fields (existing fields keep their current widget).
$newWidgets = [
  'field_hardscape_type' => 'options_select',
  'field_color' => 'string_textfield',
  'field_finish_texture' => 'string_textfield',
  'field_length_in' => 'number',
  'field_width_in' => 'number',
  'field_thickness_in' => 'number',
  'field_setback' => 'string_textfield',
  'field_units_per_sqft' => 'number',
  'field_units_per_pallet' => 'number',
  'field_weight_each' => 'number',
  'field_application' => 'options_buttons',
];

// Loose (top-level, ungrouped) fields in display order.
$loose = [
  'field_name', 'field_hardscape_type', 'field_color', 'field_finish_texture',
  'field_length_in', 'field_width_in', 'field_thickness_in', 'field_setback',
  'field_units_per_sqft', 'field_units_per_pallet', 'field_weight_each',
  'field_size', 'field_carton_quantity', 'field_unit_of_measure', 'field_application',
  'field_cost_integer', 'field_installed_price', 'field_price_updated', 'field_discontinued',
  'field_supplier', 'field_replaced_by', 'field_material_tags', 'field_instructional_video',
];
foreach ($loose as $i => $name) {
  $comp = $form->getComponent($name);
  if ($comp === NULL) {
    $form->setComponent($name, ['type' => $newWidgets[$name] ?? 'string_textfield', 'weight' => $i, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  }
  else {
    $comp['weight'] = $i;
    $form->setComponent($name, $comp);
  }
}

// Reweight the field_group sections to sit below the loose fields.
$groupWeights = [
  'group_suppliers' => 30,
  'group_manufacturer_information' => 31,
  'group_public_information' => 32,
  'group_supporting_images' => 33,
  'group_office_admin' => 34,
];
foreach ($groupWeights as $gid => $gw) {
  $g = $form->getThirdPartySetting('field_group', $gid);
  if ($g) {
    $g['weight'] = $gw;
    $form->setThirdPartySetting('field_group', $gid, $g);
  }
}

// Move the 5 pack fields INTO the Manufacturer group (idempotent).
$packFields = ['field_pack_data_source', 'field_pack_family', 'field_pack_qty_mid_label', 'field_pack_qty_mid', 'field_pack_qty_case'];
$mfg = $form->getThirdPartySetting('field_group', 'group_manufacturer_information');
if ($mfg) {
  // Drop pack fields from any OTHER group first (idempotency / no dupes).
  foreach (array_keys($form->getThirdPartySettings('field_group')) as $gid) {
    if ($gid === 'group_manufacturer_information') {
      continue;
    }
    $g = $form->getThirdPartySetting('field_group', $gid);
    $g['children'] = array_values(array_diff($g['children'] ?? [], $packFields));
    $form->setThirdPartySetting('field_group', $gid, $g);
  }
  foreach ($packFields as $pf) {
    if (!in_array($pf, $mfg['children'], TRUE)) {
      $mfg['children'][] = $pf;
    }
  }
  $form->setThirdPartySetting('field_group', 'group_manufacturer_information', $mfg);
}
// Order within the Manufacturer group (children sort by component weight).
$mfgOrder = [
  'field_manufacturer' => 0, 'field_manufacturer_item_number' => 1, 'field_manufacturer_website_item' => 2,
  'field_documentation' => 3, 'field_safety_data_sheet' => 4,
  'field_pack_data_source' => 5, 'field_pack_family' => 6, 'field_pack_qty_mid_label' => 7,
  'field_pack_qty_mid' => 8, 'field_pack_qty_case' => 9,
];
foreach ($mfgOrder as $name => $mw) {
  $comp = $form->getComponent($name);
  if ($comp !== NULL) {
    $comp['weight'] = $mw;
    $form->setComponent($name, $comp);
  }
}

$form->save();
print "  form display: loose fields on top, groups below, pack fields → Manufacturer group\n";

// ── Default view display: add the two catalog fields ────────────────────────
$view = $repo->getViewDisplay('material', $BUNDLE);
if (!$view->getComponent('field_hardscape_type')) {
  $view->setComponent('field_hardscape_type', ['type' => 'entity_reference_label', 'label' => 'inline', 'weight' => -1, 'region' => 'content', 'settings' => ['link' => FALSE]]);
  print "  view display: added field_hardscape_type\n";
}
if (!$view->getComponent('field_color')) {
  $view->setComponent('field_color', ['type' => 'string', 'label' => 'inline', 'weight' => 0, 'region' => 'content', 'settings' => []]);
  print "  view display: added field_color\n";
}
$view->save();

print "DONE — hardscape structural setup complete.\n";
