<?php

/**
 * @file
 * equipment_defect.standard.field_defect_photos — idempotent setup.
 *
 *   drush php:script web/scripts/equipment_defect_photos_setup.php -- --dry-run
 *   drush php:script web/scripts/equipment_defect_photos_setup.php
 *
 * Steps (each checks before it writes):
 *   1. Field storage + instance from config/sync, created through the entity
 *      API with the YAML's uuid KEPT (strip only _core) — so active ends up
 *      identical to sync and a later partial cim has nothing to do (gotchas:
 *      "A UUID mismatch makes cim DELETE + CREATE"). This is also the BOS
 *      silent-skip mitigation: the instance exists before any cim.
 *   2. Form display: image_image (thumbnail preview) directly below
 *      field_defect_notes; everything after the notes shifts down one weight.
 *   3. View display: colorbox — medium thumbnails on the page, max_1300x1300 in
 *      the lightbox, one gallery per defect — same position. Fails loudly if
 *      the colorbox module is off (both environments have it).
 *   4. Verify + print the definition.
 * No data touched: existing defects simply have an empty field. No cache
 * rebuild needed: the entity API clears the field-definition caches and the
 * display saves invalidate their own config tags (verify script measures it).
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };

const DP_ET = 'equipment_defect';
const DP_BUNDLE = 'standard';
const DP_FIELD = 'field_defect_photos';
const DP_AFTER = 'field_defect_notes';
const DP_PAGE_STYLE = 'medium';
const DP_LIGHTBOX_STYLE = 'max_1300x1300';

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}

// ── 0. preconditions ────────────────────────────────────────────────────
$out('== 0. preconditions ==');
$mh = \Drupal::moduleHandler();
foreach (['image', 'file', 'colorbox'] as $m) {
  if (!$mh->moduleExists($m)) {
    throw new \RuntimeException("module $m is not enabled — the view display would reference a formatter that does not exist; stop.");
  }
}
$out('   modules image, file, colorbox enabled');
$styles = \Drupal::entityTypeManager()->getStorage('image_style');
foreach ([DP_PAGE_STYLE, DP_LIGHTBOX_STYLE, 'thumbnail'] as $s) {
  if (!$styles->load($s)) {
    throw new \RuntimeException("image style $s missing — stop.");
  }
}
$out('   image styles thumbnail, ' . DP_PAGE_STYLE . ', ' . DP_LIGHTBOX_STYLE . ' present');

// ── 1. storage + instance ───────────────────────────────────────────────
$out('== 1. field storage + instance ==');
if ($fs = FieldStorageConfig::loadByName(DP_ET, DP_FIELD)) {
  $out('   storage ' . DP_ET . '.' . DP_FIELD . ' exists (uuid ' . $fs->uuid() . ')');
}
else {
  $data = Yaml::parseFile("$sync/field.storage." . DP_ET . '.' . DP_FIELD . '.yml');
  unset($data['_core']);
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['type']);
  if (!empty($def['class']) && method_exists($def['class'], 'storageSettingsFromConfigData')) {
    $data['settings'] = $def['class']::storageSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldStorageConfig::create($data)->save();
  }
  $out('   storage ' . DP_ET . '.' . DP_FIELD . ' CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}
if ($inst = FieldConfig::loadByName(DP_ET, DP_BUNDLE, DP_FIELD)) {
  $out('   instance ' . DP_ET . '.' . DP_BUNDLE . '.' . DP_FIELD . ' exists (uuid ' . $inst->uuid() . ', dir ' . $inst->getSetting('file_directory') . ')');
}
else {
  $data = Yaml::parseFile("$sync/field.field." . DP_ET . '.' . DP_BUNDLE . '.' . DP_FIELD . '.yml');
  unset($data['_core']);
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['field_type']);
  if (!empty($def['class']) && method_exists($def['class'], 'fieldSettingsFromConfigData')) {
    $data['settings'] = $def['class']::fieldSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldConfig::create($data)->save();
  }
  $out('   instance ' . DP_ET . '.' . DP_BUNDLE . '.' . DP_FIELD . ' CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}

// ── 2 + 3. displays ─────────────────────────────────────────────────────
$place = static function ($display, string $kind, array $component) use ($out, $dry): void {
  if (!$display) {
    $out("   !! $kind display " . DP_ET . '.' . DP_BUNDLE . '.default missing — skipped (bootstrap it first)');
    return;
  }
  if ($display->getComponent(DP_FIELD)) {
    $out("   $kind display: " . DP_FIELD . ' already placed (weight ' . $display->getComponent(DP_FIELD)['weight'] . ')');
    return;
  }
  $anchor = $display->getComponent(DP_AFTER);
  if (!$anchor) {
    $out("   !! $kind display has no " . DP_AFTER . ' — skipped');
    return;
  }
  $w = (int) $anchor['weight'];
  foreach ($display->getComponents() as $name => $c) {
    if ($name !== DP_AFTER && isset($c['weight']) && (int) $c['weight'] > $w) {
      $c['weight'] = (int) $c['weight'] + 1;
      $display->setComponent($name, $c);
    }
  }
  $component['weight'] = $w + 1;
  $component['region'] = 'content';
  $display->setComponent(DP_FIELD, $component);
  if (!$dry) {
    $display->save();
  }
  $out("   $kind display: " . DP_FIELD . ' PLACED at weight ' . ($w + 1) . ' (right after ' . DP_AFTER . ' @ ' . $w . '; later fields shifted +1)');
};
$out('== 2. form display ==');
$place(EntityFormDisplay::load(DP_ET . '.' . DP_BUNDLE . '.default'), 'form', [
  'type' => 'image_image',
  'settings' => ['progress_indicator' => 'throbber', 'preview_image_style' => 'thumbnail'],
  'third_party_settings' => [],
]);
$out('== 3. view display ==');
$place(EntityViewDisplay::load(DP_ET . '.' . DP_BUNDLE . '.default'), 'view', [
  'type' => 'colorbox',
  'label' => 'above',
  'settings' => [
    'colorbox_node_style' => DP_PAGE_STYLE,
    'colorbox_node_style_first' => '',
    'colorbox_image_style' => DP_LIGHTBOX_STYLE,
    'colorbox_gallery' => 'post',
    'colorbox_gallery_custom' => '',
    'colorbox_caption' => 'auto',
    'colorbox_caption_custom' => '',
  ],
  'third_party_settings' => [],
]);

// ── 4. verify ───────────────────────────────────────────────────────────
$out('== 4. verify ==');
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions(DP_ET, DP_BUNDLE);
if (isset($defs[DP_FIELD])) {
  $d = $defs[DP_FIELD];
  $out(sprintf('   %s: type=%s cardinality=%d required=%s label="%s" dir=%s ext="%s" alt=%s/required=%s', DP_FIELD, $d->getType(), $d->getFieldStorageDefinition()->getCardinality(), $d->isRequired() ? 'yes' : 'no', $d->getLabel(), $d->getSetting('file_directory'), $d->getSetting('file_extensions'), $d->getSetting('alt_field') ? 'on' : 'off', $d->getSetting('alt_field_required') ? 'yes' : 'no'));
  $out('   description: ' . $d->getDescription());
}
else {
  $out('   ' . DP_FIELD . ($dry ? ' (would exist after a real run)' : ' MISSING'));
}
foreach (['form' => EntityFormDisplay::class, 'view' => EntityViewDisplay::class] as $kind => $class) {
  $disp = $class::load(DP_ET . '.' . DP_BUNDLE . '.default');
  $c = $disp ? $disp->getComponent(DP_FIELD) : NULL;
  $a = $disp ? $disp->getComponent(DP_AFTER) : NULL;
  $out("   $kind: " . ($c ? DP_FIELD . ' ' . $c['type'] . ' @' . $c['weight'] . ' (notes @' . ($a['weight'] ?? '?') . ')' : DP_FIELD . ' not on display'));
}
$out('done');
