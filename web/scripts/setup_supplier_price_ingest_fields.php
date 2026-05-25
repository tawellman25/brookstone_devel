<?php

declare(strict_types=1);

/**
 * Phase 3.1 setup — creates field storages + instances for the three
 * supplier_price_ingest entity types, plus field_replaced_by on 17
 * material bundles, plus field_ingest_batch on material_price_history.
 *
 * Idempotent: re-runnable; checks for existing storage/instance before
 * creating.
 *
 * Run AFTER the entity types are registered (cim must have created the
 * eck.eck_entity_type.* + eck.eck_type.* configs first).
 *
 * After this completes, capture each created config to config/sync/
 * via targeted `drush config:get NAME --format=yaml > config/sync/NAME.yml`.
 *
 * Usage:
 *   ddev drush scr web/scripts/setup_supplier_price_ingest_fields.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

// Spec-defined allowed_values lists.
$STATUS_VALUES = [
  'pending_dry_run'    => 'Pending Dry Run',
  'dry_run_complete'   => 'Dry Run Complete',
  'awaiting_approval'  => 'Awaiting Approval',
  'approved'           => 'Approved',
  'committed'          => 'Committed',
  'rejected'           => 'Rejected',
  'failed'             => 'Failed',
];

$MATCH_TIER_VALUES = [
  'tier_1_mfr'             => 'Tier 1 — Manufacturer Item #',
  'tier_2_supplier_sku'    => 'Tier 2 — Supplier SKU',
  'tier_3_fuzzy_high'      => 'Tier 3 — Fuzzy (High Confidence)',
  'tier_3_fuzzy_med'       => 'Tier 3 — Fuzzy (Medium Confidence)',
  'tier_3_fuzzy_low'       => 'Tier 3 — Fuzzy (Low Confidence)',
  'discovery'              => 'Discovery (No Match)',
  'skipped_discontinued'   => 'Skipped — Discontinued',
  'skipped_do_not_use'     => 'Skipped — Do Not Use',
  'skipped_excluded_bundle'=> 'Skipped — Excluded Bundle',
  'error'                  => 'Error',
];

$ROW_STATUS_VALUES = [
  'dry_run'             => 'Dry Run',
  'committed'           => 'Committed',
  'discovery_pending'   => 'Discovery — Pending',
  'discovery_resolved'  => 'Discovery — Resolved',
  'rejected'            => 'Rejected',
  'error'               => 'Error',
];

$RESOLUTION_ACTION_VALUES = [
  'created_link'                   => 'Created Link',
  'updated_link'                   => 'Updated Link',
  'created_new_material_and_link'  => 'Created New Material and Link',
  'linked_to_existing_material'    => 'Linked to Existing Material',
  'marked_as_replacement'          => 'Marked as Replacement',
  'rejected'                       => 'Rejected',
  'noop'                           => 'No-op',
];

$COST_UOM_VALUES = [
  'each' => 'Each',
  'case' => 'Case',
  'box'  => 'Box',
  'bag'  => 'Bag',
  'roll' => 'Roll',
];

// Field definitions: [entity_type, bundle, field_name] => storage_spec + instance_spec.
// storage_spec: type + settings + cardinality (default 1).
// instance_spec: label, required, description, settings (handler etc.), default_value.

$FIELDS = [];

// ───────── supplier_price_ingest_batch — batch ──────────────────────
$BATCH = 'supplier_price_ingest_batch';
$BATCH_B = 'batch';

$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_supplier',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'supplier']],
  'label' => 'Supplier', 'required' => TRUE,
  'instance' => ['settings' => ['handler' => 'default:supplier', 'handler_settings' => ['target_bundles' => ['supplier' => 'supplier'], 'sort' => ['field' => '_none'], 'auto_create' => FALSE]]],
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_source_file',
  'storage' => ['type' => 'file', 'settings' => ['target_type' => 'file', 'display_field' => FALSE, 'display_default' => FALSE, 'uri_scheme' => 'public']],
  'label' => 'Source File', 'required' => TRUE,
  'description' => 'CSV or XLSX file from the supplier (max 50 MB).',
  'instance' => ['settings' => ['file_directory' => 'supplier_ingest', 'file_extensions' => 'csv xls xlsx', 'max_filesize' => '50 MB', 'description_field' => FALSE, 'handler' => 'default:file', 'handler_settings' => []]],
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_source_filename',
  'storage' => ['type' => 'string', 'settings' => ['max_length' => 255, 'is_ascii' => FALSE, 'case_sensitive' => FALSE]],
  'label' => 'Source Filename', 'required' => TRUE,
  'description' => 'Original filename as uploaded, preserved for audit.',
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_uploaded_by',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'user']],
  'label' => 'Uploaded By', 'required' => TRUE,
  'instance' => ['settings' => ['handler' => 'default:user', 'handler_settings' => ['include_anonymous' => FALSE, 'filter' => ['type' => '_none']]]],
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_uploaded_on',
  'storage' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
  'label' => 'Uploaded On', 'required' => TRUE,
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_status',
  'storage' => ['type' => 'list_string', 'settings' => ['allowed_values' => $STATUS_VALUES]],
  'label' => 'Status', 'required' => TRUE,
  'default_value' => [['value' => 'pending_dry_run']],
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_dry_run_report',
  'storage' => ['type' => 'text_long', 'settings' => []],
  'label' => 'Dry Run Report', 'required' => FALSE,
  'description' => 'JSON payload summarizing the dry-run pass — counts per match tier, sample rows, error log.',
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_committed_by',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'user']],
  'label' => 'Committed By', 'required' => FALSE,
  'instance' => ['settings' => ['handler' => 'default:user', 'handler_settings' => ['include_anonymous' => FALSE, 'filter' => ['type' => '_none']]]],
];
$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_committed_on',
  'storage' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
  'label' => 'Committed On', 'required' => FALSE,
];

$COUNT_FIELDS = [
  'field_row_count_total'       => 'Total Rows',
  'field_row_count_tier1'       => 'Tier 1 Matches',
  'field_row_count_tier2'       => 'Tier 2 Matches',
  'field_row_count_tier3_high'  => 'Tier 3 — High Confidence',
  'field_row_count_tier3_med'   => 'Tier 3 — Medium Confidence',
  'field_row_count_discovery'   => 'Discovery',
  'field_row_count_skipped'     => 'Skipped',
];
foreach ($COUNT_FIELDS as $name => $label) {
  $FIELDS[] = [
    'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => $name,
    'storage' => ['type' => 'integer', 'settings' => ['unsigned' => FALSE, 'size' => 'normal']],
    'label' => $label, 'required' => FALSE,
    'default_value' => [['value' => 0]],
  ];
}

$FIELDS[] = [
  'entity' => $BATCH, 'bundle' => $BATCH_B, 'name' => 'field_notes',
  'storage' => ['type' => 'text_long', 'settings' => []],
  'label' => 'Notes', 'required' => FALSE,
  'description' => 'Free-text office notes on this batch.',
];

// ───────── supplier_price_ingest_row — row ──────────────────────────
$ROW = 'supplier_price_ingest_row';
$ROW_B = 'row';

$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_batch',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'supplier_price_ingest_batch']],
  'label' => 'Batch', 'required' => TRUE,
  'instance' => ['settings' => ['handler' => 'default:supplier_price_ingest_batch', 'handler_settings' => ['target_bundles' => ['batch' => 'batch'], 'sort' => ['field' => '_none'], 'auto_create' => FALSE]]],
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_row_number',
  'storage' => ['type' => 'integer', 'settings' => ['unsigned' => TRUE, 'size' => 'normal']],
  'label' => 'Row Number', 'required' => TRUE,
  'description' => 'Row index in source file (1-based, after header).',
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_raw_data',
  'storage' => ['type' => 'text_long', 'settings' => []],
  'label' => 'Raw Data (JSON)', 'required' => TRUE,
  'description' => 'Original CSV row encoded as JSON. Immutable post-creation.',
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_supplier_sku',
  'storage' => ['type' => 'string', 'settings' => ['max_length' => 255, 'is_ascii' => FALSE, 'case_sensitive' => FALSE]],
  'label' => 'Supplier SKU', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_manufacturer_item_number',
  'storage' => ['type' => 'string', 'settings' => ['max_length' => 255, 'is_ascii' => FALSE, 'case_sensitive' => FALSE]],
  'label' => 'Manufacturer Item #', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_manufacturer_name',
  'storage' => ['type' => 'string', 'settings' => ['max_length' => 255, 'is_ascii' => FALSE, 'case_sensitive' => FALSE]],
  'label' => 'Manufacturer Name', 'required' => FALSE,
  'description' => 'Manufacturer as named in the source CSV (string, not reference).',
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_description',
  'storage' => ['type' => 'text', 'settings' => ['max_length' => 1000]],
  'label' => 'Description', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_unit_cost',
  'storage' => ['type' => 'decimal', 'settings' => ['precision' => 10, 'scale' => 2]],
  'label' => 'Unit Cost', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_cost_uom',
  'storage' => ['type' => 'list_string', 'settings' => ['allowed_values' => $COST_UOM_VALUES]],
  'label' => 'Cost UOM', 'required' => FALSE,
  'description' => 'Mirrors material_suppliers.field_cost_unit_of_measure allowed values.',
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_pack_quantity',
  'storage' => ['type' => 'integer', 'settings' => ['unsigned' => TRUE, 'size' => 'normal']],
  'label' => 'Pack Quantity', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_match_tier',
  'storage' => ['type' => 'list_string', 'settings' => ['allowed_values' => $MATCH_TIER_VALUES]],
  'label' => 'Match Tier', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_match_confidence',
  'storage' => ['type' => 'decimal', 'settings' => ['precision' => 5, 'scale' => 2]],
  'label' => 'Match Confidence', 'required' => FALSE,
  'description' => '0.00 – 100.00 confidence score from the matcher.',
];
// field_matched_material → material (all bundles — handler_settings.target_bundles unset means all)
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_matched_material',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'material']],
  'label' => 'Matched Material', 'required' => FALSE,
  'instance' => ['settings' => ['handler' => 'default:material', 'handler_settings' => ['target_bundles' => NULL, 'sort' => ['field' => '_none'], 'auto_create' => FALSE]]],
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_existing_link',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'material_suppliers']],
  'label' => 'Existing Link', 'required' => FALSE,
  'instance' => ['settings' => ['handler' => 'default:material_suppliers', 'handler_settings' => ['target_bundles' => ['supplier' => 'supplier'], 'sort' => ['field' => '_none'], 'auto_create' => FALSE]]],
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_row_status',
  'storage' => ['type' => 'list_string', 'settings' => ['allowed_values' => $ROW_STATUS_VALUES]],
  'label' => 'Row Status', 'required' => TRUE,
  'default_value' => [['value' => 'dry_run']],
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_resolution_notes',
  'storage' => ['type' => 'text_long', 'settings' => []],
  'label' => 'Resolution Notes', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_resolution_action',
  'storage' => ['type' => 'list_string', 'settings' => ['allowed_values' => $RESOLUTION_ACTION_VALUES]],
  'label' => 'Resolution Action', 'required' => FALSE,
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_resolved_by',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'user']],
  'label' => 'Resolved By', 'required' => FALSE,
  'instance' => ['settings' => ['handler' => 'default:user', 'handler_settings' => ['include_anonymous' => FALSE, 'filter' => ['type' => '_none']]]],
];
$FIELDS[] = [
  'entity' => $ROW, 'bundle' => $ROW_B, 'name' => 'field_resolved_on',
  'storage' => ['type' => 'datetime', 'settings' => ['datetime_type' => 'datetime']],
  'label' => 'Resolved On', 'required' => FALSE,
];

// ───────── supplier_ingest_config — config ──────────────────────────
$CONF = 'supplier_ingest_config';
$CONF_B = 'config';

$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_supplier',
  'storage' => ['type' => 'entity_reference', 'settings' => ['target_type' => 'supplier']],
  'label' => 'Supplier', 'required' => TRUE,
  'description' => 'One ingest config per supplier (uniqueness enforced in 3.2 presave hook).',
  'instance' => ['settings' => ['handler' => 'default:supplier', 'handler_settings' => ['target_bundles' => ['supplier' => 'supplier'], 'sort' => ['field' => '_none'], 'auto_create' => FALSE]]],
];
$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_active',
  'storage' => ['type' => 'boolean', 'settings' => []],
  'label' => 'Active', 'required' => FALSE,
  'default_value' => [['value' => 1]],
  'description' => 'When unchecked, the ingest pipeline rejects new uploads for this supplier.',
];
$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_column_mapping',
  'storage' => ['type' => 'text_long', 'settings' => []],
  'label' => 'Column Mapping (JSON)', 'required' => FALSE,
  'description' => 'JSON object mapping CSV header strings to BOS row field names. See supplier_ingest_config.md for shape.',
];
$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_default_cost_uom',
  'storage' => ['type' => 'list_string', 'settings' => ['allowed_values' => $COST_UOM_VALUES]],
  'label' => 'Default Cost UOM', 'required' => FALSE,
  'description' => 'Applied to any row whose UOM column is empty or unmapped.',
];
$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_bundle_policy',
  'storage' => ['type' => 'text_long', 'settings' => []],
  'label' => 'Bundle Policy (JSON)', 'required' => FALSE,
  'description' => 'JSON object mapping material bundle machine names to ingest policy (matched_only / discovery / both / excluded). See supplier_ingest_config.md for shape.',
];
$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_fuzzy_threshold_high',
  'storage' => ['type' => 'decimal', 'settings' => ['precision' => 5, 'scale' => 2]],
  'label' => 'Fuzzy High Threshold', 'required' => FALSE,
  'default_value' => [['value' => '90.00']],
  'description' => 'Confidence >= this auto-applies (Tier 3 fuzzy high).',
];
$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_fuzzy_threshold_med',
  'storage' => ['type' => 'decimal', 'settings' => ['precision' => 5, 'scale' => 2]],
  'label' => 'Fuzzy Medium Threshold', 'required' => FALSE,
  'default_value' => [['value' => '70.00']],
  'description' => 'Confidence >= this goes to office review (Tier 3 fuzzy medium).',
];
$FIELDS[] = [
  'entity' => $CONF, 'bundle' => $CONF_B, 'name' => 'field_notes',
  'storage' => ['type' => 'text_long', 'settings' => []],
  'label' => 'Notes', 'required' => FALSE,
];

// ───────── Create all storages + instances ──────────────────────────
$created_storages = 0;
$created_instances = 0;
$skipped_storages = 0;
$skipped_instances = 0;

foreach ($FIELDS as $spec) {
  $entity = $spec['entity'];
  $bundle = $spec['bundle'];
  $name   = $spec['name'];

  // Storage
  if (!FieldStorageConfig::loadByName($entity, $name)) {
    $storage_def = [
      'field_name'  => $name,
      'entity_type' => $entity,
      'type'        => $spec['storage']['type'],
      'cardinality' => $spec['storage']['cardinality'] ?? 1,
      'settings'    => $spec['storage']['settings'] ?? [],
    ];
    FieldStorageConfig::create($storage_def)->save();
    echo "  + storage $entity.$name\n";
    $created_storages++;
  }
  else {
    $skipped_storages++;
  }

  // Instance
  if (!FieldConfig::loadByName($entity, $bundle, $name)) {
    $inst_def = [
      'field_name'  => $name,
      'entity_type' => $entity,
      'bundle'      => $bundle,
      'label'       => $spec['label'],
      'required'    => $spec['required'] ?? FALSE,
      'description' => $spec['description'] ?? '',
    ];
    if (isset($spec['default_value'])) {
      $inst_def['default_value'] = $spec['default_value'];
    }
    if (isset($spec['instance']['settings'])) {
      $inst_def['settings'] = $spec['instance']['settings'];
    }
    FieldConfig::create($inst_def)->save();
    echo "  + instance $entity.$bundle.$name\n";
    $created_instances++;
  }
  else {
    $skipped_instances++;
  }
}

echo "\nSummary: $created_storages storages, $created_instances instances created. " .
     "Skipped: $skipped_storages storages, $skipped_instances instances (already present).\n";
