<?php

/**
 * @file
 * Non-billable punches — idempotent setup (job-clock guardrails item 6).
 *
 *   ddev drush php:script web/scripts/wo_non_billable_setup.php -- --dry-run
 *   ddev drush php:script web/scripts/wo_non_billable_setup.php
 *
 * Steps (each checks before it writes; no data backfill — the flag defaults
 * to off, so no completed work order changes until a person ticks a box):
 *   1. wo_time_clock.entry fields: field_non_billable (bool),
 *      field_non_billable_reason (list), field_non_billable_by (user),
 *      field_non_billable_at (datetime) — from config/sync via the field API
 *      (BOS silent-skip workaround, working_with_claude.md).
 *   2. work_order.field_non_billable_time (decimal) on the 35 real bundles
 *      (never the legacy `estimate` bundle) — written at completion by
 *      wo_shared_work_order_presave(); Total Time becomes the billable sum.
 *   3. Entry form display: Non-billable checkbox + reason select right after
 *      Time-limit override; the two stamps stay off the form.
 *   4. Billing views: a "Non-billable" hours column cloned from the
 *      field_total_time handler, placed right after it, on every billing view
 *      that shows Total Time (table `columns`/`info` kept in step).
 *   5. Verify.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\views\Entity\View;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };

const NB_ENTRY_FIELDS = ['field_non_billable', 'field_non_billable_reason', 'field_non_billable_by', 'field_non_billable_at'];
const NB_WO_FIELD = 'field_non_billable_time';
const NB_BILLING_VIEWS = [
  'admin_billing', 'admin_clean_up_crew_billing', 'admin_weed_spray_billing', 'admin_pre_emergent_billing',
  'admin_property_estimating', 'admin_office_work_orders_mow_crew_billing', 'admin_work_order_administration',
  'admin_snow_removal_billing', 'wo_admin_billing_info',
];

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}
$ensure_storage = static function (string $et, string $field) use ($sync, $dry, $out): void {
  if (FieldStorageConfig::loadByName($et, $field)) {
    $out("   storage $et.$field exists");
    return;
  }
  $data = Yaml::parseFile("$sync/field.storage.$et.$field.yml");
  unset($data['_core'], $data['uuid']);
  // Exported YAML carries settings in CONFIG shape (list_string allowed_values
  // as a value/label sequence); the entity API wants the in-memory shape
  // (value => label). cim converts on load — a direct create() does not, and
  // save() would then re-export a nested label and fail the schema check.
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['type']);
  if (!empty($def['class']) && method_exists($def['class'], 'storageSettingsFromConfigData')) {
    $data['settings'] = $def['class']::storageSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldStorageConfig::create($data)->save();
  }
  $out("   storage $et.$field CREATED");
};
$ensure_instance = static function (string $et, string $bundle, string $field) use ($sync, $dry, $out): bool {
  if (FieldConfig::loadByName($et, $bundle, $field)) {
    return FALSE;
  }
  $file = "$sync/field.field.$et.$bundle.$field.yml";
  if (!is_file($file)) {
    $out("   !! missing $file — skipped");
    return FALSE;
  }
  $data = Yaml::parseFile($file);
  unset($data['_core'], $data['uuid']);
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['field_type']);
  if (!empty($def['class']) && method_exists($def['class'], 'fieldSettingsFromConfigData')) {
    $data['settings'] = $def['class']::fieldSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldConfig::create($data)->save();
  }
  return TRUE;
};

// ── 1. entry fields ─────────────────────────────────────────────────────
$out('== 1. wo_time_clock.entry fields ==');
foreach (NB_ENTRY_FIELDS as $f) {
  $ensure_storage('wo_time_clock', $f);
  $out('   instance entry.' . $f . ($ensure_instance('wo_time_clock', 'entry', $f) ? ' CREATED' : ' exists'));
}

// ── 2. WO field on every real bundle ───────────────────────────────────
$out('== 2. work_order.' . NB_WO_FIELD . ' ==');
$ensure_storage('work_order', NB_WO_FIELD);
$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order'));
$created = 0; $existing = 0;
foreach ($bundles as $b) {
  if ($b === 'estimate') {
    continue;
  }
  if ($ensure_instance('work_order', $b, NB_WO_FIELD)) {
    $created++;
  }
  else {
    $existing++;
  }
}
$out("   instances: $created created, $existing existed (legacy estimate skipped) across " . (count($bundles) - 1) . ' bundles');

// ── 3. entry form display ──────────────────────────────────────────────
$out('== 3. entry form display ==');
$display = EntityFormDisplay::load('wo_time_clock.entry.default');
if (!$display) {
  $out('   !! wo_time_clock.entry.default form display missing — skipped');
}
else {
  $changed = FALSE;
  $anchor = $display->getComponent('field_time_limit_override');
  $w = $anchor ? (int) $anchor['weight'] : 4;
  if (!$display->getComponent('field_non_billable')) {
    $display->setComponent('field_non_billable', ['type' => 'boolean_checkbox', 'weight' => $w, 'region' => 'content', 'settings' => ['display_label' => TRUE], 'third_party_settings' => []]);
    $out("   field_non_billable ADDED (boolean_checkbox @ $w)");
    $changed = TRUE;
  }
  else {
    $out('   field_non_billable already on the form');
  }
  if (!$display->getComponent('field_non_billable_reason')) {
    $display->setComponent('field_non_billable_reason', ['type' => 'options_select', 'weight' => $w, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
    $out("   field_non_billable_reason ADDED (options_select @ $w)");
    $changed = TRUE;
  }
  else {
    $out('   field_non_billable_reason already on the form');
  }
  foreach (['field_non_billable_by', 'field_non_billable_at'] as $f) {
    if ($display->getComponent($f)) {
      $display->removeComponent($f);
      $out("   $f removed from the form (stamped in code)");
      $changed = TRUE;
    }
  }
  if ($changed && !$dry) {
    $display->save();
  }
}

// ── 4. billing views ───────────────────────────────────────────────────
$out('== 4. billing views: Non-billable column after Total Time ==');
foreach (NB_BILLING_VIEWS as $vid) {
  $view = View::load($vid);
  if (!$view) {
    $out("   !! view $vid not found — skipped");
    continue;
  }
  $display_cfg = &$view->getDisplay('default');
  $fields = &$display_cfg['display_options']['fields'];
  if (!is_array($fields) || !isset($fields['field_total_time'])) {
    $out("   $vid: no field_total_time on default — skipped");
    unset($display_cfg, $fields);
    continue;
  }
  if (isset($fields[NB_WO_FIELD])) {
    $out("   $vid: column already present");
    unset($display_cfg, $fields);
    continue;
  }
  $tpl = $fields['field_total_time'];
  $new = $tpl;
  $new['id'] = NB_WO_FIELD;
  $new['table'] = 'work_order__' . NB_WO_FIELD;
  $new['field'] = NB_WO_FIELD;
  $new['entity_field'] = NB_WO_FIELD;
  $new['label'] = 'Non-billable';
  $new['exclude'] = FALSE;
  $new['hide_empty'] = TRUE;
  $new['empty_zero'] = TRUE;
  $new['type'] = 'number_decimal';
  $new['settings'] = ['thousand_separator' => '', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => FALSE];
  unset($new['alter']['alter_text'], $new['alter']['text']);
  // Insert right after field_total_time, preserving order.
  $rebuilt = [];
  foreach ($fields as $k => $v) {
    $rebuilt[$k] = $v;
    if ($k === 'field_total_time') {
      $rebuilt[NB_WO_FIELD] = $new;
    }
  }
  $fields = $rebuilt;
  // Table style bookkeeping.
  $style = &$display_cfg['display_options']['style'];
  if (isset($style['options']['columns']) && is_array($style['options']['columns'])) {
    $cols = [];
    foreach ($style['options']['columns'] as $k => $v) {
      $cols[$k] = $v;
      if ($k === 'field_total_time') {
        $cols[NB_WO_FIELD] = NB_WO_FIELD;
      }
    }
    if (!isset($cols[NB_WO_FIELD])) {
      $cols[NB_WO_FIELD] = NB_WO_FIELD;
    }
    $style['options']['columns'] = $cols;
  }
  if (isset($style['options']['info']['field_total_time']) && is_array($style['options']['info'])) {
    $info = [];
    foreach ($style['options']['info'] as $k => $v) {
      $info[$k] = $v;
      if ($k === 'field_total_time') {
        $info[NB_WO_FIELD] = $v;
      }
    }
    $style['options']['info'] = $info;
  }
  unset($style, $display_cfg, $fields);
  $view->addCacheableDependency(NULL);
  if (!$dry) {
    $view->save();
  }
  $out("   $vid: Non-billable column ADDED after Total Time (label '" . $tpl['label'] . "' → 'Non-billable')");
}

// ── 5. verify ───────────────────────────────────────────────────────────
$out('== 5. verify ==');
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('wo_time_clock', 'entry');
$have = array_values(array_filter(NB_ENTRY_FIELDS, static fn($f) => isset($defs[$f])));
$out('   entry fields present: ' . (implode(', ', $have) ?: 'NONE'));
$n = 0;
foreach ($bundles as $b) {
  if ($b !== 'estimate' && FieldConfig::loadByName('work_order', $b, NB_WO_FIELD)) {
    $n++;
  }
}
$out("   work_order bundles with " . NB_WO_FIELD . ": $n");
$display = EntityFormDisplay::load('wo_time_clock.entry.default');
$out('   entry form: non_billable ' . ($display && $display->getComponent('field_non_billable') ? 'on' : 'OFF') . ', reason ' . ($display && $display->getComponent('field_non_billable_reason') ? 'on' : 'OFF') . ', by/at ' . (($display && ($display->getComponent('field_non_billable_by') || $display->getComponent('field_non_billable_at'))) ? 'ON (wrong)' : 'off'));
$ok = 0;
foreach (NB_BILLING_VIEWS as $vid) {
  $view = View::load($vid);
  $f = $view ? ($view->getDisplay('default')['display_options']['fields'] ?? []) : [];
  if (isset($f[NB_WO_FIELD])) {
    $ok++;
    // Handler sanity: init the display and its handlers (no execution).
    if (!$dry) {
      try {
        $exec = $view->getExecutable();
        $exec->setDisplay('default');
        $exec->initHandlers();
      }
      catch (\Throwable $e) {
        $out("   !! $vid handlers failed to init: " . $e->getMessage());
      }
    }
  }
}
$out("   views carrying the column: $ok / " . count(NB_BILLING_VIEWS));
$out('done');
