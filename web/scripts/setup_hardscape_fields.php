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

// ── Form display: ordered block first, existing fields shifted after ─────────
$repo = \Drupal::service('entity_display.repository');
$form = $repo->getFormDisplay('material', $BUNDLE);
$widgets = [
  'field_name' => 'string_textfield',
  'field_hardscape_type' => 'options_select',
  'field_color' => 'string_textfield',
  'field_finish_texture' => 'string_textfield',
  'field_description' => 'text_textarea_with_summary',
  'field_length_in' => 'number',
  'field_width_in' => 'number',
  'field_thickness_in' => 'number',
  'field_setback' => 'string_textfield',
  'field_units_per_sqft' => 'number',
  'field_units_per_pallet' => 'number',
  'field_weight_each' => 'number',
  'field_size' => 'string_textfield',
  'field_carton_quantity' => 'string_textfield',
  'field_unit_of_measure' => 'options_select',
  'field_application' => 'options_buttons',
];
$order = array_keys($widgets);
foreach ($order as $i => $name) {
  $comp = $form->getComponent($name);
  if ($comp === NULL) {
    // New field — set widget + position.
    $form->setComponent($name, ['type' => $widgets[$name], 'weight' => $i, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  }
  else {
    // Existing field — keep its widget/settings, just reposition.
    $comp['weight'] = $i;
    $form->setComponent($name, $comp);
  }
}
// Shift every other material field after the ordered block (stable/idempotent).
$others = [];
foreach ($form->getComponents() as $name => $comp) {
  if (in_array($name, $order, TRUE) || strpos($name, 'field_') !== 0) {
    continue;
  }
  $others[$name] = $comp['weight'] ?? 0;
}
asort($others);
$w = 100;
foreach (array_keys($others) as $name) {
  $comp = $form->getComponent($name);
  $comp['weight'] = $w++;
  $form->setComponent($name, $comp);
}
$form->save();
print "  form display order applied (16 ordered fields; " . count($others) . " others shifted after)\n";

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
