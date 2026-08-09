<?php

/**
 * Stage 1 of WO cost & profit visibility — field foundation (idempotent).
 *
 *  1. config_pages:business_setting.field_blended_labor_cost (decimal) — the
 *     blended loaded labor cost/hr used for labor-cost math. Seeded to 27.00
 *     (wage + payroll tax + workers' comp + benefits, per owner 2026-08-03).
 *  2. work_order.field_wo_cost + work_order.field_wo_profit (decimal) on every
 *     real WO bundle (NOT the legacy `estimate` bundle) — the stored/frozen
 *     cost + profit, written at completion by the wo_profit module.
 *
 * ECK/config_pages field configs silent-skip on cim, so this entity-API script
 * is the deploy path (run per environment). Run:
 *   drush php:script web/scripts/setup_wo_profit_fields.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$SEED_RATE = '27.00';

/**
 * Helper: ensure a decimal field storage exists.
 */
$ensure_storage = function (string $entity_type, string $field_name) {
  if (!FieldStorageConfig::loadByName($entity_type, $field_name)) {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'decimal',
      'settings' => ['precision' => 12, 'scale' => 2],
      'cardinality' => 1,
    ])->save();
    print "  created storage $entity_type.$field_name\n";
  }
  else {
    print "  storage $entity_type.$field_name exists\n";
  }
};

/**
 * Helper: ensure a field instance exists on a bundle.
 */
$ensure_instance = function (string $entity_type, string $bundle, string $field_name, string $label) {
  if (!FieldConfig::loadByName($entity_type, $bundle, $field_name)) {
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => $label,
      'required' => FALSE,
    ])->save();
    return TRUE;
  }
  return FALSE;
};

// ── 1. business_setting: blended labor cost/hr ──────────────────────────────
print "business_setting.field_blended_labor_cost:\n";
$ensure_storage('config_pages', 'field_blended_labor_cost');
$ensure_instance('config_pages', 'business_setting', 'field_blended_labor_cost', 'Blended labor cost / hour');

// Seed the rate (only if empty — never clobber an office-set value).
$loader = \Drupal::service('config_pages.loader');
$page = $loader->load('business_setting');
if ($page && $page->hasField('field_blended_labor_cost')) {
  if ($page->get('field_blended_labor_cost')->isEmpty()) {
    $page->set('field_blended_labor_cost', $SEED_RATE)->save();
    print "  seeded blended labor cost = $SEED_RATE\n";
  }
  else {
    print "  blended labor cost already set = " . $page->get('field_blended_labor_cost')->value . " (left as-is)\n";
  }
}

// ── 2. work_order: field_wo_cost + field_wo_profit ──────────────────────────
print "work_order.field_wo_cost / field_wo_profit:\n";
$ensure_storage('work_order', 'field_wo_cost');
$ensure_storage('work_order', 'field_wo_profit');

$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order'));
$created = 0;
$skipped_estimate = FALSE;
foreach ($bundles as $bundle) {
  // Legacy bundle being phased out — do not add new fields to it (policy).
  if ($bundle === 'estimate') {
    $skipped_estimate = TRUE;
    continue;
  }
  $a = $ensure_instance('work_order', $bundle, 'field_wo_cost', 'WO cost');
  $b = $ensure_instance('work_order', $bundle, 'field_wo_profit', 'WO profit');
  if ($a || $b) {
    $created++;
  }
}
print "  instances added/ensured on " . (count($bundles) - ($skipped_estimate ? 1 : 0)) . " bundles ($created newly created); skipped legacy 'estimate'\n";

print "DONE — wo_profit field foundation ready.\n";
