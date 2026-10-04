<?php

declare(strict_types=1);

/**
 * Holiday lighting price per linear foot, on Business Settings.
 *
 * __BOS_AI/Entities/business_setting.md calls this config page the "central rate
 * table for all billing calculations" and a "live rate table — changing a value
 * here changes all future WO billing calculations immediately". That is where a
 * published price belongs, and it is where field_winterizing_base_fee already
 * lives and feeds the public /winterize page.
 *
 * NOT State. Despite the module name, config_pages field VALUES are entity data
 * in the database; only the field definitions and displays are in config/sync.
 * So a price changed in production does NOT drift from sync and is NOT reverted
 * by a partial cim — the drift risk that would justify State does not exist
 * here, and State would put one price somewhere none of the other 60 live.
 *
 * Group follows the per-service convention on that form (Snow Removal, Mowing
 * Pricing, Dormant Oil …): a new "Holiday Lighting" group rather than appending
 * to an unrelated fieldset.
 *
 * NOTE field_labor_cost_decorations (27.00) is NOT this. The doc flags all nine
 * field_labor_cost_* fields as "not referenced in any custom module code" —
 * internal cost reference, not a billing rate.
 *
 *   drush php:script web/scripts/setup_holiday_lighting_price.php
 *   BOS_HL_APPLY=1 drush php:script web/scripts/setup_holiday_lighting_price.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$apply = getenv('BOS_HL_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$FIELD = 'field_holiday_light_per_foot';
$GROUP = 'group_holiday_lighting';
$SEED = '8.00';

// Add-ons marketing lists as "quoted separately at the walkthrough", plus the
// job minimum. ALL CREATED EMPTY AND DELIBERATELY UNSEEDED — nobody has given
// me these rates, and a seeded guess becomes a quote somebody honours. The $8
// was confirmed; these are not. The office fills them in on the form.
//
// ⚠ The UNIT in each label is an assumption, not a fact — per tree, each, per
// linear foot. If the office prices any of these differently, the label is the
// thing to change, not the number.
$ADDONS = [
  'field_holiday_tree_wrap'   => ['Holiday Lighting — Tree Wrapping (per tree)', 'Quoted at the walkthrough. Not published on the public pages.'],
  'field_holiday_wreath'      => ['Holiday Lighting — Wreaths (each)', 'Quoted at the walkthrough. Not published on the public pages.'],
  'field_holiday_shrub'       => ['Holiday Lighting — Shrubs (each)', 'Quoted at the walkthrough. Not published on the public pages.'],
  'field_holiday_column_wrap' => ['Holiday Lighting — Wrapped Columns (each)', 'Quoted at the walkthrough. Not published on the public pages.'],
  'field_holiday_walkway'     => ['Holiday Lighting — Walkway Borders (per linear foot)', 'Quoted at the walkthrough. Not published on the public pages.'],
  'field_holiday_min_job'     => ['Holiday Lighting — Job Minimum', 'Minimum seasonal charge. Left empty until confirmed — marketing proposed $750 but it was never confirmed, so nothing is seeded.'],
];

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_HL_APPLY=1 to write)\n\n";
printf("field name length %d (limit 32)\n", strlen($FIELD));
if (strlen($FIELD) > 32) { print "ABORT — field name too long.\n"; return; }

// Mirror an existing money field rather than inventing settings.
$model = $etm->getStorage('field_config')->load('config_pages.business_setting.field_winterizing_base_fee');
if (!$model) { print "ABORT — no field_winterizing_base_fee to mirror.\n"; return; }
printf("mirroring field_winterizing_base_fee (type %s)\n\n", $model->getType());

$storage = $etm->getStorage('field_storage_config')->load('config_pages.' . $FIELD);
if ($storage) { print "✓ storage exists\n"; }
else {
  print "+ creating storage (decimal)\n";
  if ($apply) {
    FieldStorageConfig::create([
      'field_name' => $FIELD, 'entity_type' => 'config_pages',
      'type' => 'decimal', 'cardinality' => 1,
      'settings' => ['precision' => 10, 'scale' => 2],
    ])->save();
  }
}

$inst = $etm->getStorage('field_config')->load('config_pages.business_setting.' . $FIELD);
if ($inst) { print "✓ instance exists\n"; }
else {
  print "+ creating instance on business_setting\n";
  if ($apply) {
    FieldConfig::create([
      'field_name' => $FIELD, 'entity_type' => 'config_pages', 'bundle' => 'business_setting',
      'label' => 'Holiday Lighting — Price Per Linear Foot',
      'description' => 'Published on /holiday-lights and the Holiday Decorations service page, and used for quoting. Changing this updates the public pages immediately.',
      'required' => FALSE,
      'settings' => ['min' => 0, 'prefix' => '$', 'suffix' => ' per linear foot'],
    ])->save();
  }
}

// The add-on rate fields. Same storage shape, no seeding.
foreach ($ADDONS as $name => [$label, $desc]) {
  if (strlen($name) > 32) { print "ABORT — $name exceeds 32 chars.\n"; return; }
  if (!$etm->getStorage('field_storage_config')->load('config_pages.' . $name)) {
    printf("+ storage  %s\n", $name);
    if ($apply) {
      FieldStorageConfig::create([
        'field_name' => $name, 'entity_type' => 'config_pages',
        'type' => 'decimal', 'cardinality' => 1,
        'settings' => ['precision' => 10, 'scale' => 2],
      ])->save();
    }
  }
  if (!$etm->getStorage('field_config')->load('config_pages.business_setting.' . $name)) {
    printf("+ instance %-28s %s\n", $name, $label);
    if ($apply) {
      FieldConfig::create([
        'field_name' => $name, 'entity_type' => 'config_pages', 'bundle' => 'business_setting',
        'label' => $label, 'description' => $desc, 'required' => FALSE,
        'settings' => ['min' => 0, 'prefix' => '$'],
      ])->save();
    }
  }
}

// Form display: new group, field inside it.
$fd = $etm->getStorage('entity_form_display')->load('config_pages.business_setting.default');
if (!$fd) { print "ABORT — no business_setting form display.\n"; return; }
$groups = $fd->getThirdPartySettings('field_group');
if (isset($groups[$GROUP])) { print "✓ Holiday Lighting group exists\n"; }
else {
  print "+ creating \"Holiday Lighting\" group (per-service convention)\n";
  if ($apply) {
    $model_group = $groups['group_snow_removal'] ?? NULL;
    $fd->setThirdPartySetting('field_group', $GROUP, [
      'children' => array_merge([$FIELD], array_keys($ADDONS)),
      'label' => 'Holiday Lighting',
      'region' => $model_group['region'] ?? 'content',
      'parent_name' => $model_group['parent_name'] ?? '',
      'weight' => 99,
      'format_type' => $model_group['format_type'] ?? 'details',
      'format_settings' => $model_group['format_settings'] ?? ['open' => FALSE],
    ]);
  }
}
$w = 1;
foreach (array_merge([$FIELD], array_keys($ADDONS)) as $name) {
  $w++;
  if ($fd->getComponent($name)) { continue; }
  printf("+ form widget %s\n", $name);
  if ($apply) { $fd->setComponent($name, ['type' => 'number', 'weight' => $w, 'region' => 'content']); }
}
if ($apply) { $fd->save(); }

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }

drupal_flush_all_caches();

// Seed, without clobbering a value the office has already set.
$bs = \Drupal::service('config_pages.loader')->load('business_setting');
if (!$bs) { print "ABORT — business_setting page not found.\n"; return; }
if (!$bs->hasField($FIELD)) { print "ABORT — field not on the loaded entity after creation.\n"; return; }
$cur = $bs->get($FIELD)->value;
if ($cur === NULL || $cur === '') {
  $bs->set($FIELD, $SEED)->save();
  printf("\n+ seeded %s = %s\n", $FIELD, $SEED);
}
else { printf("\n✓ already set to %s — not overwritten\n", $cur); }

// Prove the documented read pattern returns it.
$re = \Drupal::service('config_pages.loader')->load('business_setting');
printf("read back via config_pages.loader: %s\n", (float) $re->get($FIELD)->value);
print "\nADD-ONS — created EMPTY on purpose, for the office to fill:\n";
foreach ($ADDONS as $name => [$label,]) {
  $v = $re->hasField($name) && !$re->get($name)->isEmpty() ? $re->get($name)->value : '(empty)';
  printf("  %-28s %-52s %s\n", $name, $label, $v);
}
print "\nNone of these is published. Marketing's copy says add-ons are quoted at\n";
print "the walkthrough, and that stays true — these are for quoting and for the\n";
print "estimating work later.\n";
