<?php

declare(strict_types=1);

/**
 * Build the audience view-mode displays (admin / teammate / client) for every
 * material bundle, per the BOS audience pattern. Role routing already lives in
 * the material module (office->admin, crew->teammate, client->client, else full).
 *
 * Field tiers (safety-first):
 *   CLIENT   = customer-facing only, via an ALLOWLIST — presentation + specs +
 *              price. NEVER cost, supplier, stock, pack, or marketing/internal.
 *   TEAMMATE = CLIENT + sourcing/operational (tags, stock, lead time, supplier,
 *              manufacturer item #, carton, docs/SDS, video). Still NO cost.
 *   ADMIN    = every field on the bundle (full internal view).
 *
 * field_name is hidden on all tiers (the composed Title is the heading).
 * Component config is copied from the bundle's existing `default` view display
 * where present (keeps your formatters), else synthesized by field type.
 *
 * Dry run (prints the per-bundle field sort, saves nothing):
 *   BOS_DISPLAYS_DRYRUN=1 drush php:script web/scripts/build_material_audience_displays.php
 * Apply:
 *   drush php:script web/scripts/build_material_audience_displays.php
 */

$ENTITY = 'material';
$DRY = (bool) getenv('BOS_DISPLAYS_DRYRUN');

// Ordered customer-safe fields (CLIENT).
$CLIENT = [
  'field_main_image', 'field_manufacturer', 'field_subheader_text', 'field_description',
  // type / category
  'field_backflow_type', 'field_rock_type', 'field_mulch_type', 'field_bulk_material_type', 'field_hardscape_type',
  // plant taxonomy + specs
  'field_plant_family', 'field_plant_genus', 'field_plant_species', 'field_cultivar',
  'field_plant_color', 'field_flower_color', 'field_fall_color', 'field_bloom_time',
  'field_plant_characteristics', 'field_water_use', 'field_care_instructions',
  'field_height', 'field_width', 'field_container_size',
  // dims / specs
  'field_size', 'field_color', 'field_finish_texture', 'field_application', 'field_setback',
  'field_length_in', 'field_width_in', 'field_thickness_in', 'field_units_per_sqft', 'field_units_per_pallet', 'field_weight_each',
  'field_est_wt_per_yard', 'field_yard_per_ton', 'field_coverage_per_pallet', 'field_roll_or_pallet', 'field_sod_variety',
  // pump specs
  'field_pump_size', 'field_pump_model_number', 'field_pump_phase', 'field_pump_volts', 'field_pump_discharge_size', 'field_pump_suction_size',
  // supporting media
  'field_supporting_images',
  // price
  'field_price', 'field_installed_price', 'field_unit_of_measure', 'field_retail_price_disclaimer',
];

// Added for TEAMMATE (sourcing / operational — still no cost).
$TEAMMATE_EXTRA = [
  'field_material_tags',
  'field_quantity_in_stock', 'field_lead_time', 'field_last_restocked_date',
  'field_discontinued', 'field_replaced_by', 'field_price_updated',
  'field_manufacturer_item_number', 'field_manufacturer_website_item',
  'field_supplier', 'field_suppliers', 'field_supplier_item_number', 'field_supplier_website_item_link',
  'field_carton_quantity', 'field_documentation', 'field_safety_data_sheet', 'field_instructional_video',
];

// Admin-only tail (cost + marketing + pack internals). Anything not listed
// anywhere still shows on admin, appended after these.
$ADMIN_TAIL = [
  'field_cost_integer',
  'field_banner_images', 'field_slideshow_image', 'field_front_promoted',
  'field_pack_family', 'field_pack_qty_mid_label', 'field_pack_qty_mid', 'field_pack_qty_case', 'field_pack_data_source',
];

$CLIENT_SET = array_flip($CLIENT);
$TEAMMATE = array_merge($CLIENT, $TEAMMATE_EXTRA);
$TEAMMATE_SET = array_flip($TEAMMATE);
$ADMIN_ORDER = array_merge($TEAMMATE, $ADMIN_TAIL);

// Synthesize a formatter component by field type.
$synth = function (string $type): array {
  $map = [
    'string' => ['type' => 'string', 'label' => 'inline', 'settings' => ['link_to_entity' => FALSE]],
    'text_long' => ['type' => 'text_default', 'label' => 'above', 'settings' => []],
    'text_with_summary' => ['type' => 'text_default', 'label' => 'above', 'settings' => []],
    'integer' => ['type' => 'number_integer', 'label' => 'inline', 'settings' => []],
    'decimal' => ['type' => 'number_decimal', 'label' => 'inline', 'settings' => ['thousand_separator' => '', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE]],
    'boolean' => ['type' => 'boolean', 'label' => 'inline', 'settings' => ['format' => 'default']],
    'list_string' => ['type' => 'list_default', 'label' => 'inline', 'settings' => []],
    'entity_reference' => ['type' => 'entity_reference_label', 'label' => 'inline', 'settings' => ['link' => FALSE]],
    'image' => ['type' => 'image', 'label' => 'hidden', 'settings' => ['image_style' => 'medium', 'image_link' => '']],
    'link' => ['type' => 'link', 'label' => 'inline', 'settings' => []],
    'file' => ['type' => 'file_default', 'label' => 'above', 'settings' => []],
    'timestamp' => ['type' => 'timestamp', 'label' => 'inline', 'settings' => []],
  ];
  return $map[$type] ?? ['type' => 'string', 'label' => 'inline', 'settings' => []];
};

$fm = \Drupal::service('entity_field.manager');
$repo = \Drupal::service('entity_display.repository');
$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo($ENTITY));
sort($bundles);

foreach ($bundles as $bundle) {
  $defs = $fm->getFieldDefinitions($ENTITY, $bundle);
  $bundleFields = [];
  foreach ($defs as $n => $d) {
    if (strpos($n, 'field_') === 0 && $n !== 'field_name') {
      $bundleFields[$n] = $d->getType();
    }
  }
  $default = $repo->getViewDisplay($ENTITY, $bundle, 'default');

  $plan = [
    'client' => array_values(array_filter($CLIENT, fn($f) => isset($bundleFields[$f]))),
    'teammate' => array_values(array_filter($TEAMMATE, fn($f) => isset($bundleFields[$f]))),
  ];
  // admin = ordered known fields present + any leftovers appended.
  $adminFields = array_values(array_filter($ADMIN_ORDER, fn($f) => isset($bundleFields[$f])));
  foreach (array_keys($bundleFields) as $f) {
    if (!in_array($f, $adminFields, TRUE)) {
      $adminFields[] = $f;
    }
  }
  $plan['admin'] = $adminFields;

  foreach ($plan as $mode => $fields) {
    if ($DRY) {
      printf("[%s / %-9s] (%d) %s\n", $bundle, $mode, count($fields), implode(', ', array_map(fn($f) => str_replace('field_', '', $f), $fields)));
      continue;
    }
    $display = $repo->getViewDisplay($ENTITY, $bundle, $mode);
    // clear everything, then set the tier's fields in order.
    foreach (array_keys($display->getComponents()) as $c) {
      if (strpos($c, 'field_') === 0 || $c === 'field_name') {
        $display->removeComponent($c);
      }
    }
    $w = 0;
    foreach ($fields as $f) {
      $comp = $default->getComponent($f) ?? $synth($bundleFields[$f]);
      $comp['weight'] = $w++;
      $comp['region'] = 'content';
      $display->setComponent($f, $comp);
    }
    $display->removeComponent('field_name');
    $display->save();
  }
  if (!$DRY) {
    printf("built %-15s client=%d teammate=%d admin=%d\n", $bundle, count($plan['client']), count($plan['teammate']), count($plan['admin']));
  }
}
print $DRY ? "DRY RUN — nothing saved.\n" : "DONE.\n";
