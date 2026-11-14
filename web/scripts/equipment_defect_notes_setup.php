<?php

/**
 * @file
 * equipment_defect.standard.field_defect_notes — idempotent setup.
 *
 *   drush php:script web/scripts/equipment_defect_notes_setup.php -- --dry-run
 *   drush php:script web/scripts/equipment_defect_notes_setup.php
 *
 * Steps (each checks before it writes):
 *   1. Field storage + instance from config/sync, created through the entity
 *      API (an existing instance has its allowed_formats converged to the YAML),
 *      API with the YAML's uuid KEPT (strip only _core) — so active ends up
 *      identical to sync and a later partial cim has nothing to do (gotchas:
 *      "A UUID mismatch makes cim DELETE + CREATE"). This is also the BOS
 *      silent-skip mitigation: the instance exists before any cim.
 *   2. Form display: text_textarea (8 rows) directly below field_defect_summary;
 *      everything after the summary shifts down one weight.
 *   3. View display: text_default, inline label, same position.
 *   4. Verify + print the definition.
 * No data touched: existing defects simply have an empty field.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };

const DN_ET = 'equipment_defect';
const DN_BUNDLE = 'standard';
const DN_FIELD = 'field_defect_notes';
const DN_AFTER = 'field_defect_summary';

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}

// ── 1. storage + instance ───────────────────────────────────────────────
$out('== 1. field storage + instance ==');
if (FieldStorageConfig::loadByName(DN_ET, DN_FIELD)) {
  $out('   storage ' . DN_ET . '.' . DN_FIELD . ' exists');
}
else {
  $data = Yaml::parseFile("$sync/field.storage." . DN_ET . '.' . DN_FIELD . '.yml');
  unset($data['_core']);
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['type']);
  if (!empty($def['class']) && method_exists($def['class'], 'storageSettingsFromConfigData')) {
    $data['settings'] = $def['class']::storageSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldStorageConfig::create($data)->save();
  }
  $out('   storage ' . DN_ET . '.' . DN_FIELD . ' CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}
if ($inst = FieldConfig::loadByName(DN_ET, DN_BUNDLE, DN_FIELD)) {
  // Converge the one setting that has changed since the first build (2026-11-13:
  // basic_html + full_html → full_html only, Todd's call) from the sync YAML.
  $want = array_values(Yaml::parseFile("$sync/field.field." . DN_ET . '.' . DN_BUNDLE . '.' . DN_FIELD . '.yml')['settings']['allowed_formats'] ?? []);
  $have = array_values($inst->getSetting('allowed_formats') ?? []);
  if ($want !== $have) {
    $inst->setSetting('allowed_formats', $want);
    if (!$dry) {
      $inst->save();
    }
    $out('   instance ' . DN_ET . '.' . DN_BUNDLE . '.' . DN_FIELD . ' exists — allowed_formats UPDATED ' . json_encode($have) . ' → ' . json_encode($want));
  }
  else {
    $out('   instance ' . DN_ET . '.' . DN_BUNDLE . '.' . DN_FIELD . ' exists (allowed_formats ' . json_encode($have) . ')');
  }
}
else {
  $data = Yaml::parseFile("$sync/field.field." . DN_ET . '.' . DN_BUNDLE . '.' . DN_FIELD . '.yml');
  unset($data['_core']);
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['field_type']);
  if (!empty($def['class']) && method_exists($def['class'], 'fieldSettingsFromConfigData')) {
    $data['settings'] = $def['class']::fieldSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldConfig::create($data)->save();
  }
  $out('   instance ' . DN_ET . '.' . DN_BUNDLE . '.' . DN_FIELD . ' CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}

// ── 2 + 3. displays ─────────────────────────────────────────────────────
$place = static function ($display, string $kind, array $component) use ($out, $dry): void {
  if (!$display) {
    $out("   !! $kind display " . DN_ET . '.' . DN_BUNDLE . '.default missing — skipped (bootstrap it first)');
    return;
  }
  if ($display->getComponent(DN_FIELD)) {
    $out("   $kind display: " . DN_FIELD . ' already placed (weight ' . $display->getComponent(DN_FIELD)['weight'] . ')');
    return;
  }
  $anchor = $display->getComponent(DN_AFTER);
  if (!$anchor) {
    $out("   !! $kind display has no " . DN_AFTER . ' — skipped');
    return;
  }
  $w = (int) $anchor['weight'];
  foreach ($display->getComponents() as $name => $c) {
    if ($name !== DN_AFTER && isset($c['weight']) && (int) $c['weight'] > $w) {
      $c['weight'] = (int) $c['weight'] + 1;
      $display->setComponent($name, $c);
    }
  }
  $component['weight'] = $w + 1;
  $component['region'] = 'content';
  $display->setComponent(DN_FIELD, $component);
  if (!$dry) {
    $display->save();
  }
  $out("   $kind display: " . DN_FIELD . ' PLACED at weight ' . ($w + 1) . ' (right after ' . DN_AFTER . ' @ ' . $w . '; later fields shifted +1)');
};
$out('== 2. form display ==');
$place(EntityFormDisplay::load(DN_ET . '.' . DN_BUNDLE . '.default'), 'form', [
  'type' => 'text_textarea',
  'settings' => ['rows' => 8, 'placeholder' => ''],
  'third_party_settings' => [],
]);
$out('== 3. view display ==');
$place(EntityViewDisplay::load(DN_ET . '.' . DN_BUNDLE . '.default'), 'view', [
  'type' => 'text_default',
  'label' => 'inline',
  'settings' => [],
  'third_party_settings' => [],
]);

// ── 4. verify ───────────────────────────────────────────────────────────
$out('== 4. verify ==');
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions(DN_ET, DN_BUNDLE);
if (isset($defs[DN_FIELD])) {
  $d = $defs[DN_FIELD];
  $out(sprintf('   %s: type=%s cardinality=%d required=%s label="%s" allowed_formats=%s', DN_FIELD, $d->getType(), $d->getFieldStorageDefinition()->getCardinality(), $d->isRequired() ? 'yes' : 'no', $d->getLabel(), json_encode(array_values($d->getSetting('allowed_formats') ?? []))));
  $out('   description: ' . $d->getDescription());
}
else {
  $out('   ' . DN_FIELD . ($dry ? ' (would exist after a real run)' : ' MISSING'));
}
foreach (['form' => EntityFormDisplay::class, 'view' => EntityViewDisplay::class] as $kind => $class) {
  $disp = $class::load(DN_ET . '.' . DN_BUNDLE . '.default');
  $c = $disp ? $disp->getComponent(DN_FIELD) : NULL;
  $a = $disp ? $disp->getComponent(DN_AFTER) : NULL;
  $out("   $kind: " . ($c ? DN_FIELD . ' ' . $c['type'] . ' @' . $c['weight'] . ' (summary @' . ($a['weight'] ?? '?') . ')' : DN_FIELD . ' not on display'));
}
$out('done');
