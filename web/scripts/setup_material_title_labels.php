<?php

declare(strict_types=1);

/**
 * Gap D — composed material titles (Name + Size), entered separately, auto-built
 * and hidden on the form; Name hidden on display so the composed Title is the
 * heading. Uses auto_entitylabel (already in use on irrigation/decorative_rock).
 *
 * Per-family Title pattern:
 *   parts/plumbing : [field_size] [field_name]          (matches existing titles)
 *   pumps          : [field_pump_size] [field_name]
 *   nursery        : [field_name] [field_container_size]
 *   bulk           : [field_name] - [type]
 *   sod            : [field_sod_variety]
 *
 * This script (idempotent, run per env):
 *   1. Adds field_name to the nursery bundles that lack it (annuals/shrubs/trees).
 *   2. Writes the auto_entitylabel config (status=1 => auto-generate + hide the
 *      Title field on the form) per bundle.
 *   3. Removes field_name from the default VIEW display (Title heading carries it).
 *
 * Run AFTER this: regenerate_material_titles.php — it does the data-safe backfill
 * (fill each bundle's name field from the current title where empty, so nothing
 * that lived only in a manual title is lost) and re-saves so titles recompose.
 * status=1 + preserve_titles=false => saving rewrites title.
 *
 *   drush php:script web/scripts/setup_material_title_labels.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'material';
$vdRepo = \Drupal::service('entity_display.repository');

// bundle => Title pattern.
$PATTERNS = [
  // parts / plumbing
  'brass' => '[material:field_size] [material:field_name]',
  'copper' => '[material:field_size] [material:field_name]',
  'pvc' => '[material:field_size] [material:field_name]',
  'galv' => '[material:field_size] [material:field_name]',
  'poly' => '[material:field_size] [material:field_name]',
  'irrigation' => '[material:field_size] [material:field_name]',
  'electric' => '[material:field_size] [material:field_name]',
  'landscape' => '[material:field_size] [material:field_name]',
  'misc' => '[material:field_size] [material:field_name]',
  'xmas' => '[material:field_size] [material:field_name]',
  'backflow' => '[material:field_size] [material:field_name]',
  'supplies' => '[material:field_size] [material:field_name]',
  'pavers' => '[material:field_size] [material:field_name]',
  // pumps — field_pump_size is a coded list, but the token renders its LABEL
  // ("3/4 HP"), so title = "{HP} {name}". fix_pumps_size_title.php moves the HP
  // out of the name into field_pump_size for existing records.
  'pumps' => '[material:field_pump_size] [material:field_name]',
  // nursery
  'plants' => '[material:field_name] [material:field_container_size]',
  'shrubs' => '[material:field_name] [material:field_container_size]',
  'trees' => '[material:field_name] [material:field_container_size]',
  'annuals' => '[material:field_name] [material:field_container_size]',
  // bulk
  'decorative_rock' => '[material:field_name] - [material:field_rock_type]',
  'mulch' => '[material:field_name] - [material:field_mulch_type]',
  'bulk_material' => '[material:field_name] - [material:field_bulk_material_type]',
  // sod
  'sod' => '[material:field_sod_variety]',
];

// 1) field_name on nursery bundles that lack it + backfill from title.
print "1) field_name on nursery:\n";
foreach (['annuals', 'shrubs', 'trees'] as $b) {
  if (!FieldConfig::loadByName($ENTITY, $b, 'field_name')) {
    FieldConfig::create([
      'field_name' => 'field_name',
      'entity_type' => $ENTITY,
      'bundle' => $b,
      'label' => 'Name',
      'required' => FALSE,
    ])->save();
    print "  + field_name -> $b\n";
  }
  else {
    print "  = field_name already on $b\n";
  }
  $fd = $vdRepo->getFormDisplay($ENTITY, $b, 'default');
  if (!$fd->getComponent('field_name')) {
    $fd->setComponent('field_name', ['type' => 'string_textfield', 'weight' => -5, 'settings' => ['size' => 60, 'placeholder' => '']])->save();
  }
}

// 2) auto_entitylabel config per bundle (status=1 => auto + hide title field).
print "2) auto_entitylabel patterns:\n";
foreach ($PATTERNS as $bundle => $pattern) {
  $cfg = \Drupal::configFactory()->getEditable("auto_entitylabel.settings.material.$bundle");
  $cfg->setData([
    'status' => 1,
    'pattern' => $pattern,
    'escape' => FALSE,
    'preserve_titles' => FALSE,
    'save' => FALSE,
    'chunk' => 50,
    'dependencies' => ['config' => ["eck.eck_type.material.$bundle"]],
    'new_content_behavior' => 0,
  ])->save();
  print "  $bundle => $pattern\n";
}

// 3) hide field_name on the default VIEW display (Title heading carries it).
print "3) hide field_name on default view display:\n";
foreach (array_keys($PATTERNS) as $bundle) {
  $vd = $vdRepo->getViewDisplay($ENTITY, $bundle, 'default');
  if ($vd->getComponent('field_name')) {
    $vd->removeComponent('field_name')->save();
    print "  - field_name hidden on $bundle\n";
  }
}
print "DONE.\n";
