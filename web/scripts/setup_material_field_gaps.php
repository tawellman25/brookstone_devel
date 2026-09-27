<?php

declare(strict_types=1);

/**
 * Close the material-bundle field gaps from the 2026-09-26 field audit + fix the
 * UOM "Ounce" typo. Idempotent; run per env.
 *
 *   Ounce : field_unit_of_measure OZ label "Per Once" -> "Per Ounce".
 *   A     : field_container_size (new string) -> annuals, plants, shrubs, trees
 *           (sale container/pot size: #1, #5, #15, B&B, 1 gal, 4" pot).
 *   B     : field_installed_price (reused) -> annuals, plants, shrubs
 *           (trees already had it; nursery stock we plant now carries an
 *           installed price too).
 *   C     : mulch was typed by field_rock_type (rock_types vocab) — wrong for
 *           mulch. New mulch_types vocab + field_mulch_type -> mulch, seeded,
 *           and field_rock_type removed from mulch (0 mulch records → no data).
 *   E     : field_unit_of_measure (reused) -> annuals, plants, shrubs, trees,
 *           pumps, default EA (they sell "each").
 *
 * (Gap D — the mixed title/field_name label model — is deliberately NOT touched
 * here; it needs a catalog-wide label decision, not a blind field add.)
 *
 *   drush php:script web/scripts/setup_material_field_gaps.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$ENTITY = 'material';
$vdRepo = \Drupal::service('entity_display.repository');

/** Add an instance from a reused storage, cloning label+settings from an exemplar bundle. */
$addReusedInstance = function (string $field, string $bundle, string $exemplarBundle, ?string $label = NULL) use ($ENTITY): void {
  if (FieldConfig::loadByName($ENTITY, $bundle, $field)) {
    print "  = $field already on $bundle\n";
    return;
  }
  $ex = FieldConfig::loadByName($ENTITY, $exemplarBundle, $field);
  FieldConfig::create([
    'field_name' => $field,
    'entity_type' => $ENTITY,
    'bundle' => $bundle,
    'label' => $label ?? ($ex ? $ex->getLabel() : $field),
    'required' => FALSE,
    'settings' => $ex ? $ex->getSettings() : [],
  ])->save();
  print "  + $field -> $bundle\n";
};

$setForm = function (string $bundle, string $field, array $component) use ($ENTITY, $vdRepo): void {
  $fd = $vdRepo->getFormDisplay($ENTITY, $bundle, 'default');
  if (!$fd->getComponent($field)) {
    $fd->setComponent($field, $component)->save();
  }
};
$setView = function (string $bundle, string $field, array $component) use ($ENTITY, $vdRepo): void {
  $vd = $vdRepo->getViewDisplay($ENTITY, $bundle, 'default');
  if (!$vd->getComponent($field)) {
    $vd->setComponent($field, $component)->save();
  }
};

// --- Ounce typo -------------------------------------------------------------
$uom = FieldStorageConfig::loadByName($ENTITY, 'field_unit_of_measure');
if ($uom) {
  $vals = $uom->getSetting('allowed_values');
  if (($vals['OZ'] ?? '') !== 'Per Ounce') {
    $vals['OZ'] = 'Per Ounce';
    $uom->setSetting('allowed_values', $vals)->save();
    print "Ounce: OZ label -> 'Per Ounce'\n";
  }
  else {
    print "Ounce: already 'Per Ounce'\n";
  }
}

// --- A: field_container_size (new string) -----------------------------------
print "A) container size:\n";
if (!FieldStorageConfig::loadByName($ENTITY, 'field_container_size')) {
  FieldStorageConfig::create([
    'field_name' => 'field_container_size',
    'entity_type' => $ENTITY,
    'type' => 'string',
    'settings' => ['max_length' => 60],
    'cardinality' => 1,
  ])->save();
  print "  + storage field_container_size\n";
}
foreach (['annuals', 'plants', 'shrubs', 'trees'] as $b) {
  if (!FieldConfig::loadByName($ENTITY, $b, 'field_container_size')) {
    FieldConfig::create([
      'field_name' => 'field_container_size',
      'entity_type' => $ENTITY,
      'bundle' => $b,
      'label' => 'Container Size',
      'required' => FALSE,
      'description' => 'The size the plant is sold in — e.g. #1, #5, #15, B&B, 1 gal, 4" pot.',
    ])->save();
    print "  + field_container_size -> $b\n";
  }
  else {
    print "  = field_container_size already on $b\n";
  }
  $setForm($b, 'field_container_size', ['type' => 'string_textfield', 'weight' => 14, 'settings' => ['size' => 60, 'placeholder' => '']]);
  $setView($b, 'field_container_size', ['type' => 'string', 'label' => 'inline', 'weight' => 11, 'settings' => ['link_to_entity' => FALSE]]);
}

// --- B: installed price on nursery ------------------------------------------
print "B) installed price:\n";
foreach (['annuals', 'plants', 'shrubs'] as $b) {
  $addReusedInstance('field_installed_price', $b, 'trees');
  $setForm($b, 'field_installed_price', ['type' => 'number', 'weight' => 5, 'settings' => ['placeholder' => '']]);
  $setView($b, 'field_installed_price', ['type' => 'number_decimal', 'label' => 'above', 'weight' => 17, 'settings' => ['thousand_separator' => '', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE]]);
}

// --- E: UOM on nursery + pumps (default EA) ---------------------------------
print "E) unit of measure:\n";
foreach (['annuals', 'plants', 'shrubs', 'trees', 'pumps'] as $b) {
  if (!FieldConfig::loadByName($ENTITY, $b, 'field_unit_of_measure')) {
    $ex = FieldConfig::loadByName($ENTITY, 'brass', 'field_unit_of_measure');
    FieldConfig::create([
      'field_name' => 'field_unit_of_measure',
      'entity_type' => $ENTITY,
      'bundle' => $b,
      'label' => $ex ? $ex->getLabel() : 'Unit of Measure',
      'required' => FALSE,
      'settings' => $ex ? $ex->getSettings() : [],
      'default_value' => [['value' => 'EA']],
    ])->save();
    print "  + field_unit_of_measure -> $b (default EA)\n";
  }
  else {
    print "  = field_unit_of_measure already on $b\n";
  }
  $setForm($b, 'field_unit_of_measure', ['type' => 'options_select', 'weight' => 26, 'settings' => []]);
  $setView($b, 'field_unit_of_measure', ['type' => 'list_default', 'label' => 'above', 'weight' => 18, 'settings' => []]);
}

// --- C: mulch type ----------------------------------------------------------
print "C) mulch type:\n";
if (!Vocabulary::load('mulch_types')) {
  Vocabulary::create(['vid' => 'mulch_types', 'name' => 'Mulch Types', 'description' => 'Kinds of mulch (bark, cedar, dyed, etc.).'])->save();
  print "  + vocabulary mulch_types\n";
}
$seed = ['Wood / Bark Mulch', 'Cedar Mulch', 'Redwood Mulch', 'Pine Bark', 'Dyed Brown', 'Dyed Black', 'Dyed Red', 'Playground Chips', 'Rubber Mulch', 'Straw', 'Compost Mulch'];
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
foreach ($seed as $name) {
  $exist = $termStorage->loadByProperties(['vid' => 'mulch_types', 'name' => $name]);
  if (!$exist) {
    Term::create(['vid' => 'mulch_types', 'name' => $name])->save();
    print "    + term '$name'\n";
  }
}
if (!FieldStorageConfig::loadByName($ENTITY, 'field_mulch_type')) {
  FieldStorageConfig::create([
    'field_name' => 'field_mulch_type',
    'entity_type' => $ENTITY,
    'type' => 'entity_reference',
    'settings' => ['target_type' => 'taxonomy_term'],
    'cardinality' => 1,
  ])->save();
  print "  + storage field_mulch_type\n";
}
if (!FieldConfig::loadByName($ENTITY, 'mulch', 'field_mulch_type')) {
  FieldConfig::create([
    'field_name' => 'field_mulch_type',
    'entity_type' => $ENTITY,
    'bundle' => 'mulch',
    'label' => 'Mulch Type',
    'required' => FALSE,
    'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['mulch_types' => 'mulch_types']]],
  ])->save();
  print "  + field_mulch_type -> mulch\n";
}
$setForm('mulch', 'field_mulch_type', ['type' => 'entity_reference_autocomplete', 'weight' => 1, 'settings' => ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => '']]);
$setView('mulch', 'field_mulch_type', ['type' => 'entity_reference_label', 'label' => 'inline', 'weight' => 1, 'settings' => ['link' => TRUE]]);

// remove the wrong rock_type from mulch (0 mulch records → no data loss)
if (FieldConfig::loadByName($ENTITY, 'mulch', 'field_rock_type')) {
  $vdRepo->getFormDisplay($ENTITY, 'mulch', 'default')->removeComponent('field_rock_type')->save();
  $vdRepo->getViewDisplay($ENTITY, 'mulch', 'default')->removeComponent('field_rock_type')->save();
  FieldConfig::loadByName($ENTITY, 'mulch', 'field_rock_type')->delete();
  print "  - removed field_rock_type from mulch\n";
}
else {
  print "  = field_rock_type already absent from mulch\n";
}

print "DONE.\n";
