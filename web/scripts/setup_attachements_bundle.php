<?php

/**
 * Build out the equipment.attachements bundle (currently zero non-base fields).
 * IDEMPOTENT; entity-API only (no cim). All 15 field STORAGES already exist on
 * equipment — this adds field INSTANCES (cloned from heavy_equipment for
 * consistency; field_attaches_to from snow_plows, retargeted) + form/view
 * display + fixes the bundle label to "Attachments" (machine name stays
 * `attachements` — permanent typo).
 *
 *   drush php:script web/scripts/setup_attachements_bundle.php
 */

use Drupal\field\Entity\FieldConfig;

$BUNDLE = 'attachements';

// ── 1. Bundle label → "Attachments" ─────────────────────────────────────────
$cfg = \Drupal::configFactory()->getEditable('eck.eck_type.equipment.attachements');
if ($cfg->get('name') !== 'Attachments') {
  $cfg->set('name', 'Attachments')->save();
  print "bundle label → \"Attachments\"\n";
}
else {
  print "bundle label already \"Attachments\"\n";
}

// ── 2. Field instances (clone, guarded) ─────────────────────────────────────
$clone = function (string $srcBundle, string $field, ?string $label = NULL, ?array $targetBundles = NULL) use ($BUNDLE) {
  if (FieldConfig::loadByName('equipment', $BUNDLE, $field)) {
    print "  instance exists: $field\n";
    return;
  }
  $src = FieldConfig::loadByName('equipment', $srcBundle, $field);
  if (!$src) {
    print "  *** source missing: $srcBundle.$field — SKIP\n";
    return;
  }
  $a = $src->toArray();
  unset($a['uuid'], $a['_core'], $a['id'], $a['dependencies']);
  $a['bundle'] = $BUNDLE;
  if ($label !== NULL) {
    $a['label'] = $label;
  }
  if ($targetBundles !== NULL) {
    $a['settings']['handler_settings']['target_bundles'] = array_combine($targetBundles, $targetBundles);
  }
  FieldConfig::create($a)->save();
  print "  instance created: $field (from $srcBundle)\n";
};

// 14 shared fields cloned from heavy_equipment (keeps handler/settings identical).
foreach ([
  'field_equipment_number', 'field_status', 'field_equipment_type', 'field_equipment_make',
  'field_model', 'field_size', 'field_manufactured_year', 'field_serial_code_number',
  'field_date_purchased', 'field_purchase_price', 'field_depriciated_value',
  'field_public_description', 'field_pictures', 'field_documents',
] as $f) {
  $clone('heavy_equipment', $f);
}
// Parent-machine reference: clone snow_plows', retarget to include small_engine.
$clone('snow_plows', 'field_attaches_to', 'Attaches To', ['heavy_equipment', 'vehicles', 'small_engine']);

// ── 3. Form display (mirror heavy_equipment widgets; sensible order) ─────────
$repo = \Drupal::service('entity_display.repository');
$heavyForm = $repo->getFormDisplay('equipment', 'heavy_equipment');
$form = $repo->getFormDisplay('equipment', $BUNDLE);
$formOrder = [
  'field_equipment_number', 'field_status', 'field_equipment_type', 'field_attaches_to',
  'field_equipment_make', 'field_model', 'field_size', 'field_manufactured_year',
  'field_serial_code_number', 'field_date_purchased', 'field_purchase_price', 'field_depriciated_value',
  'field_public_description', 'field_pictures', 'field_documents',
];
foreach ($formOrder as $i => $f) {
  if ($f === 'field_attaches_to') {
    $form->setComponent($f, ['type' => 'entity_reference_autocomplete', 'weight' => $i, 'region' => 'content', 'settings' => ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => ''], 'third_party_settings' => []]);
    continue;
  }
  $hc = $heavyForm->getComponent($f);
  if ($hc) {
    $hc['weight'] = $i;
    $form->setComponent($f, $hc);
  }
  else {
    $form->setComponent($f, ['type' => 'string_textfield', 'weight' => $i, 'region' => 'content']);
  }
}
$form->save();
print "  form display configured (" . count($formOrder) . " fields)\n";

// ── 4. Default view display (mirror heavy where present; add type + attaches_to) ─
$heavyView = \Drupal\Core\Entity\Entity\EntityViewDisplay::load('equipment.heavy_equipment.default');
$view = $repo->getViewDisplay('equipment', $BUNDLE);
$viewSpec = [
  'field_pictures' => ['colorbox', 0],
  'field_status' => ['entity_reference_label', 1],
  'field_equipment_type' => ['entity_reference_label', 2],
  'field_attaches_to' => ['entity_reference_label', 3],
  'field_equipment_make' => ['string', 4],
  'field_model' => ['string', 5],
  'field_size' => ['string', 6],
  'field_manufactured_year' => ['number_integer', 7],
  'field_serial_code_number' => ['string', 8],
  'field_public_description' => ['text_default', 9],
  'field_documents' => ['file_default', 10],
];
foreach ($viewSpec as $f => [$type, $w]) {
  $hc = $heavyView ? $heavyView->getComponent($f) : NULL;
  if ($hc) {
    $hc['weight'] = $w;
    $view->setComponent($f, $hc);
  }
  else {
    $view->setComponent($f, ['type' => $type, 'label' => 'inline', 'weight' => $w, 'region' => 'content', 'settings' => []]);
  }
}
$view->save();
print "  default view display configured\n";

print "DONE — attachements bundle built.\n";
