<?php

declare(strict_types=1);

/**
 * Phase 3.1 setup — configures default form + view displays for the
 * three supplier_price_ingest entity types.
 *
 * Field weights chosen to group fields logically:
 *   - batch:  metadata → status → counts → notes
 *   - row:    parsed inputs → match result → resolution
 *   - config: supplier → flags → JSON blobs → thresholds
 *
 * feeds_item and similar internal fields are hidden.
 *
 * Usage:
 *   ddev drush scr web/scripts/setup_supplier_price_ingest_displays.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

// ───────── Layout specs ────────────────────────────────────────────
// Each entry: field_name => [form_widget, form_weight, view_formatter, view_weight, view_label]
// view_label: above | inline | hidden | visually_hidden
$LAYOUTS = [
  'supplier_price_ingest_batch.batch' => [
    'field_supplier'              => ['entity_reference_autocomplete', 0,  'entity_reference_label', 0,  'inline'],
    'field_source_file'            => ['file_generic',                  1,  'file_default',          1,  'above'],
    'field_source_filename'        => ['string_textfield',              2,  'string',                2,  'inline'],
    'field_uploaded_by'            => ['entity_reference_autocomplete', 3,  'entity_reference_label', 3,  'inline'],
    'field_uploaded_on'            => ['datetime_default',              4,  'datetime_default',      4,  'inline'],
    'field_status'                 => ['options_select',                5,  'list_default',          5,  'inline'],
    'field_row_count_total'        => ['number',                        10, 'number_integer',        10, 'inline'],
    'field_row_count_tier1'        => ['number',                        11, 'number_integer',        11, 'inline'],
    'field_row_count_tier2'        => ['number',                        12, 'number_integer',        12, 'inline'],
    'field_row_count_tier3_high'   => ['number',                        13, 'number_integer',        13, 'inline'],
    'field_row_count_tier3_med'    => ['number',                        14, 'number_integer',        14, 'inline'],
    'field_row_count_discovery'    => ['number',                        15, 'number_integer',        15, 'inline'],
    'field_row_count_skipped'      => ['number',                        16, 'number_integer',        16, 'inline'],
    'field_committed_by'           => ['entity_reference_autocomplete', 20, 'entity_reference_label', 20, 'inline'],
    'field_committed_on'           => ['datetime_default',              21, 'datetime_default',      21, 'inline'],
    'field_dry_run_report'         => ['text_textarea',                 30, 'text_default',          30, 'above'],
    'field_notes'                  => ['text_textarea',                 31, 'text_default',          31, 'above'],
  ],
  'supplier_price_ingest_row.row' => [
    'field_batch'                  => ['entity_reference_autocomplete', 0,  'entity_reference_label', 0,  'inline'],
    'field_row_number'             => ['number',                        1,  'number_integer',        1,  'inline'],
    'field_row_status'             => ['options_select',                2,  'list_default',          2,  'inline'],
    // Parsed cells:
    'field_supplier_sku'           => ['string_textfield',              10, 'string',                10, 'inline'],
    'field_manufacturer_item_number' => ['string_textfield',            11, 'string',                11, 'inline'],
    'field_manufacturer_name'      => ['string_textfield',              12, 'string',                12, 'inline'],
    'field_description'            => ['string_textfield',              13, 'string',                13, 'above'],
    'field_unit_cost'              => ['number',                        14, 'number_decimal',        14, 'inline'],
    'field_cost_uom'               => ['options_select',                15, 'list_default',          15, 'inline'],
    'field_pack_quantity'          => ['number',                        16, 'number_integer',        16, 'inline'],
    // Match result:
    'field_match_tier'             => ['options_select',                20, 'list_default',          20, 'inline'],
    'field_match_confidence'       => ['number',                        21, 'number_decimal',        21, 'inline'],
    'field_matched_material'       => ['entity_reference_autocomplete', 22, 'entity_reference_label', 22, 'inline'],
    'field_existing_link'          => ['entity_reference_autocomplete', 23, 'entity_reference_label', 23, 'inline'],
    // Resolution:
    'field_resolution_action'      => ['options_select',                30, 'list_default',          30, 'inline'],
    'field_resolved_by'            => ['entity_reference_autocomplete', 31, 'entity_reference_label', 31, 'inline'],
    'field_resolved_on'            => ['datetime_default',              32, 'datetime_default',      32, 'inline'],
    'field_resolution_notes'       => ['text_textarea',                 33, 'text_default',          33, 'above'],
    // Raw audit row at the bottom of the form, hidden in view:
    'field_raw_data'               => ['text_textarea',                 40, 'text_default',          40, 'above'],
  ],
  'supplier_ingest_config.config' => [
    'field_supplier'               => ['entity_reference_autocomplete', 0,  'entity_reference_label', 0,  'inline'],
    'field_active'                 => ['boolean_checkbox',              1,  'boolean',               1,  'inline'],
    'field_default_cost_uom'       => ['options_select',                2,  'list_default',          2,  'inline'],
    'field_fuzzy_threshold_high'   => ['number',                        3,  'number_decimal',        3,  'inline'],
    'field_fuzzy_threshold_med'    => ['number',                        4,  'number_decimal',        4,  'inline'],
    'field_column_mapping'         => ['text_textarea',                 10, 'text_default',          10, 'above'],
    'field_bundle_policy'          => ['text_textarea',                 11, 'text_default',          11, 'above'],
    'field_notes'                  => ['text_textarea',                 20, 'text_default',          20, 'above'],
  ],
];

foreach ($LAYOUTS as $key => $fields) {
  [$entity_type, $bundle] = explode('.', $key);

  // ── Form display ──
  $form = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("$entity_type.$bundle.default");
  if (!$form) {
    $form = EntityFormDisplay::create([
      'targetEntityType' => $entity_type,
      'bundle'           => $bundle,
      'mode'             => 'default',
      'status'           => TRUE,
    ]);
  }
  foreach ($fields as $field_name => [$widget, $form_weight,, ,]) {
    $form->setComponent($field_name, [
      'type'     => $widget,
      'weight'   => $form_weight,
      'settings' => [],
      'third_party_settings' => [],
      'region'   => 'content',
    ]);
  }
  $form->save();
  echo "  + form_display $entity_type.$bundle.default (" . count($fields) . " components)\n";

  // ── View display ──
  $view = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load("$entity_type.$bundle.default");
  if (!$view) {
    $view = EntityViewDisplay::create([
      'targetEntityType' => $entity_type,
      'bundle'           => $bundle,
      'mode'             => 'default',
      'status'           => TRUE,
    ]);
  }
  foreach ($fields as $field_name => [, , $formatter, $view_weight, $view_label]) {
    $view->setComponent($field_name, [
      'type'     => $formatter,
      'label'    => $view_label,
      'weight'   => $view_weight,
      'settings' => [],
      'third_party_settings' => [],
      'region'   => 'content',
    ]);
  }
  $view->save();
  echo "  + view_display $entity_type.$bundle.default (" . count($fields) . " components)\n";
}

echo "\nDisplays configured. Capture to sync next.\n";
