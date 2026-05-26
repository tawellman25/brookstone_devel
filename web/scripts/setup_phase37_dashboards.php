<?php

declare(strict_types=1);

/**
 * Phase 3.7 — create the three Office Manager dashboard views:
 *
 *   - supplier_ingest_batches        (Batch Manager)
 *   - supplier_ingest_discovery_queue (Discovery Queue)
 *   - supplier_ingest_fuzzy_review   (Fuzzy Match Review)
 *
 * Idempotent — re-running overwrites the active config with the
 * shipped spec. Capture to config/sync after running:
 *
 *   ddev drush config:get views.view.supplier_ingest_batches --format=yaml \
 *     > config/sync/views.view.supplier_ingest_batches.yml
 *   (repeat for the other two)
 *
 * Mirrors the Phase 3.2 setup_supplier_ingest_configs_view.php
 * pattern. Same base-table convention: `{entity_type}_field_data`,
 * never the bare entity table (re-introducing the Phase 3.2 bug is
 * not in scope here).
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\views\Entity\View;

// ────────────────────────────────────────────────────────────────────
// 1. supplier_ingest_batches — Batch Manager
// ────────────────────────────────────────────────────────────────────

$batches = View::load('supplier_ingest_batches') ?? View::create(['id' => 'supplier_ingest_batches']);
$batches->set('label', 'Supplier Ingest Batches');
$batches->set('module', 'views');
$batches->set('description', 'Office Manager batch manager — every supplier ingest batch with status, counts, and operations.');
$batches->set('tag', 'supplier_price_ingest');
$batches->set('base_table', 'supplier_price_ingest_batch_field_data');
$batches->set('base_field', 'id');

$batchesDisplay = [
  'default' => [
    'id' => 'default',
    'display_title' => 'Default',
    'display_plugin' => 'default',
    'position' => 0,
    'display_options' => [
      'access' => ['type' => 'perm', 'options' => ['perm' => 'administer supplier price ingest']],
      'cache' => ['type' => 'tag', 'options' => []],
      'query' => ['type' => 'views_query', 'options' => []],
      'exposed_form' => ['type' => 'basic', 'options' => []],
      'pager' => ['type' => 'mini', 'options' => ['items_per_page' => 50]],
      'style' => [
        'type' => 'table',
        'options' => [
          'grouping' => [],
          'row_class' => '',
          'default_row_class' => TRUE,
          'columns' => [
            'id' => 'id',
            'field_source_filename' => 'field_source_filename',
            'field_supplier' => 'field_supplier',
            'field_status' => 'field_status',
            'field_row_count_total' => 'field_row_count_total',
            'field_row_count_discovery' => 'field_row_count_discovery',
            'field_uploaded_on' => 'field_uploaded_on',
            'field_uploaded_by' => 'field_uploaded_by',
            'operations' => 'operations',
          ],
          // Default sort: most recent first by upload date, with id DESC
          // as the deterministic secondary tiebreaker per the range-audit
          // gotcha. The 'default' here is the COLUMN to sort by initially.
          'default' => 'field_uploaded_on',
          'info' => [
            'id'                       => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_source_filename'    => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_supplier'           => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_status'             => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_row_count_total'    => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => 'right', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_row_count_discovery' => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => 'right', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_uploaded_on'        => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_uploaded_by'        => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'operations'               => ['align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
          ],
          'override' => TRUE,
          'sticky' => TRUE,
          'order' => 'desc',
          'empty_table' => TRUE,
        ],
      ],
      'row' => ['type' => 'fields', 'options' => []],
      'fields' => [
        'id' => [
          'id' => 'id', 'table' => 'supplier_price_ingest_batch_field_data', 'field' => 'id',
          'relationship' => 'none', 'plugin_id' => 'field', 'label' => 'Batch',
          'type' => 'number_integer',
          'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE],
          'alter' => ['make_link' => TRUE, 'path' => '/admin/materials/supplier-ingest/batch/{{ id }}'],
        ],
        'field_source_filename' => [
          'id' => 'field_source_filename', 'table' => 'supplier_price_ingest_batch__field_source_filename',
          'field' => 'field_source_filename', 'relationship' => 'none', 'plugin_id' => 'field',
          'label' => 'Source File', 'type' => 'string',
        ],
        'field_supplier' => [
          'id' => 'field_supplier', 'table' => 'supplier_price_ingest_batch__field_supplier',
          'field' => 'field_supplier', 'relationship' => 'none', 'plugin_id' => 'field',
          'label' => 'Supplier', 'type' => 'entity_reference_label',
          'settings' => ['link' => TRUE],
        ],
        'field_status' => [
          'id' => 'field_status', 'table' => 'supplier_price_ingest_batch__field_status',
          'field' => 'field_status', 'relationship' => 'none', 'plugin_id' => 'field',
          'label' => 'Status', 'type' => 'list_default',
        ],
        'field_row_count_total' => [
          'id' => 'field_row_count_total', 'table' => 'supplier_price_ingest_batch__field_row_count_total',
          'field' => 'field_row_count_total', 'relationship' => 'none', 'plugin_id' => 'field',
          'label' => 'Total', 'type' => 'number_integer',
          'settings' => ['thousand_separator' => ',', 'prefix_suffix' => FALSE],
        ],
        'field_row_count_discovery' => [
          'id' => 'field_row_count_discovery', 'table' => 'supplier_price_ingest_batch__field_row_count_discovery',
          'field' => 'field_row_count_discovery', 'relationship' => 'none', 'plugin_id' => 'field',
          'label' => 'Discovery', 'type' => 'number_integer',
          'settings' => ['thousand_separator' => ',', 'prefix_suffix' => FALSE],
        ],
        'field_uploaded_on' => [
          'id' => 'field_uploaded_on', 'table' => 'supplier_price_ingest_batch__field_uploaded_on',
          'field' => 'field_uploaded_on', 'relationship' => 'none', 'plugin_id' => 'field',
          'label' => 'Uploaded On', 'type' => 'datetime_custom',
          'settings' => ['date_format' => 'm/d/Y', 'timezone' => ''],
        ],
        'field_uploaded_by' => [
          'id' => 'field_uploaded_by', 'table' => 'supplier_price_ingest_batch__field_uploaded_by',
          'field' => 'field_uploaded_by', 'relationship' => 'none', 'plugin_id' => 'field',
          'label' => 'Uploaded By', 'type' => 'entity_reference_label',
          'settings' => ['link' => FALSE],
        ],
      ],
      'sorts' => [
        'field_uploaded_on_value' => [
          'id' => 'field_uploaded_on_value', 'table' => 'supplier_price_ingest_batch__field_uploaded_on',
          'field' => 'field_uploaded_on_value', 'relationship' => 'none', 'plugin_id' => 'date',
          'order' => 'DESC',
        ],
        // Secondary tiebreaker — id DESC for determinism per the range-audit gotcha.
        'id' => [
          'id' => 'id', 'table' => 'supplier_price_ingest_batch_field_data', 'field' => 'id',
          'relationship' => 'none', 'plugin_id' => 'standard', 'order' => 'DESC',
        ],
      ],
      'filters' => [
        'field_supplier_target_id' => [
          'id' => 'field_supplier_target_id', 'table' => 'supplier_price_ingest_batch__field_supplier',
          'field' => 'field_supplier_target_id', 'relationship' => 'none', 'plugin_id' => 'numeric',
          'operator' => '=',
          'exposed' => TRUE,
          'expose' => [
            'operator_id' => 'field_supplier_target_id_op', 'label' => 'Supplier', 'description' => '',
            'use_operator' => FALSE, 'operator' => 'field_supplier_target_id_op',
            'identifier' => 'supplier', 'required' => FALSE, 'remember' => FALSE,
            'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'],
          ],
        ],
        'field_status_value' => [
          'id' => 'field_status_value', 'table' => 'supplier_price_ingest_batch__field_status',
          'field' => 'field_status_value', 'relationship' => 'none', 'plugin_id' => 'list_field',
          'operator' => 'or',
          'exposed' => TRUE,
          'expose' => [
            'operator_id' => 'field_status_value_op', 'label' => 'Status', 'description' => '',
            'use_operator' => FALSE, 'operator' => 'field_status_value_op',
            'identifier' => 'status', 'required' => FALSE, 'remember' => FALSE,
            'multiple' => TRUE, 'remember_roles' => ['authenticated' => 'authenticated'],
          ],
        ],
        'field_uploaded_on_value' => [
          'id' => 'field_uploaded_on_value', 'table' => 'supplier_price_ingest_batch__field_uploaded_on',
          'field' => 'field_uploaded_on_value', 'relationship' => 'none', 'plugin_id' => 'datetime',
          'operator' => 'between',
          'exposed' => TRUE,
          'expose' => [
            'operator_id' => 'field_uploaded_on_value_op', 'label' => 'Uploaded On', 'description' => '',
            'use_operator' => TRUE, 'operator' => 'field_uploaded_on_value_op',
            'identifier' => 'uploaded_on', 'required' => FALSE, 'remember' => FALSE,
            'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'],
          ],
        ],
      ],
      'arguments' => [],
      'empty' => [
        'area_text_custom' => [
          'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom',
          'plugin_id' => 'text_custom', 'empty' => TRUE,
          'content' => 'No batches yet. <a href="/admin/materials/supplier-ingest/upload">Upload a supplier price catalog</a> to get started.',
        ],
      ],
      'header' => [],
      'footer' => [],
      'relationships' => [],
      'use_more' => FALSE,
      'use_ajax' => FALSE,
      'display_extenders' => [],
    ],
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user.permissions'],
      'tags' => [],
    ],
  ],
  'page_1' => [
    'id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1,
    'display_options' => [
      'display_extenders' => [],
      'path' => 'admin/materials/supplier-ingest/batches',
      'menu' => [
        'type' => 'none', 'title' => 'Batches', 'description' => '',
        'weight' => 0, 'menu_name' => 'admin', 'parent' => '', 'context' => 0,
      ],
    ],
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user.permissions'],
      'tags' => [],
    ],
  ],
];
$batches->set('display', $batchesDisplay);
$batches->save();
echo "Saved view supplier_ingest_batches.\n";

// ────────────────────────────────────────────────────────────────────
// Shared row-view scaffolding (reused by discovery + fuzzy_review)
// ────────────────────────────────────────────────────────────────────

/**
 * Build the shared field set for both row views. Both show the row
 * description, batch, supplier (via reference chain), and key cost
 * fields; differ on operations columns + filter set + sort.
 */
$rowSharedFields = function (): array {
  return [
    'field_batch' => [
      'id' => 'field_batch', 'table' => 'supplier_price_ingest_row__field_batch',
      'field' => 'field_batch', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'Batch', 'type' => 'entity_reference_label',
      'settings' => ['link' => TRUE],
    ],
    'field_row_number' => [
      'id' => 'field_row_number', 'table' => 'supplier_price_ingest_row__field_row_number',
      'field' => 'field_row_number', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'Row #', 'type' => 'number_integer',
    ],
    'field_description' => [
      'id' => 'field_description', 'table' => 'supplier_price_ingest_row__field_description',
      'field' => 'field_description', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'Description', 'type' => 'string',
      // Views truncates with `trim_length` on the string formatter.
      // Setting it to 80 puts the full text in the cell's title attr.
      'alter' => [
        'alter_text' => FALSE, 'max_length' => 80, 'word_boundary' => TRUE,
        'ellipsis' => TRUE, 'html' => FALSE,
      ],
    ],
    'field_supplier_sku' => [
      'id' => 'field_supplier_sku', 'table' => 'supplier_price_ingest_row__field_supplier_sku',
      'field' => 'field_supplier_sku', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'Supplier SKU', 'type' => 'string',
    ],
    'field_manufacturer_item_number' => [
      'id' => 'field_manufacturer_item_number', 'table' => 'supplier_price_ingest_row__field_manufacturer_item_number',
      'field' => 'field_manufacturer_item_number', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'Mfr Item #', 'type' => 'string',
    ],
    'field_manufacturer_name' => [
      'id' => 'field_manufacturer_name', 'table' => 'supplier_price_ingest_row__field_manufacturer_name',
      'field' => 'field_manufacturer_name', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'Manufacturer', 'type' => 'string',
    ],
    'field_unit_cost' => [
      'id' => 'field_unit_cost', 'table' => 'supplier_price_ingest_row__field_unit_cost',
      'field' => 'field_unit_cost', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'Unit Cost', 'type' => 'number_decimal',
      'settings' => ['prefix' => '$', 'suffix' => '', 'decimal_separator' => '.', 'thousand_separator' => ',', 'scale' => 2],
    ],
    'field_cost_uom' => [
      'id' => 'field_cost_uom', 'table' => 'supplier_price_ingest_row__field_cost_uom',
      'field' => 'field_cost_uom', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => 'UOM', 'type' => 'list_default',
    ],
  ];
};

// ────────────────────────────────────────────────────────────────────
// 2. supplier_ingest_discovery_queue
// ────────────────────────────────────────────────────────────────────

$discovery = View::load('supplier_ingest_discovery_queue') ?? View::create(['id' => 'supplier_ingest_discovery_queue']);
$discovery->set('label', 'Supplier Ingest — Discovery Queue');
$discovery->set('module', 'views');
$discovery->set('description', 'Rows from supplier ingest batches that the matcher could not resolve — Office Manager creates, links, marks as replacement, or rejects.');
$discovery->set('tag', 'supplier_price_ingest');
$discovery->set('base_table', 'supplier_price_ingest_row_field_data');
$discovery->set('base_field', 'id');

$rowSharedFieldsArray = $rowSharedFields();

$discoveryDisplayOptions = [
  'access' => ['type' => 'perm', 'options' => ['perm' => 'administer supplier price ingest']],
  'cache' => ['type' => 'tag', 'options' => []],
  'query' => ['type' => 'views_query', 'options' => []],
  'exposed_form' => ['type' => 'basic', 'options' => []],
  'pager' => ['type' => 'mini', 'options' => ['items_per_page' => 50]],
  'style' => [
    'type' => 'table',
    'options' => [
      'grouping' => [], 'row_class' => '', 'default_row_class' => TRUE,
      'columns' => [
        'field_batch' => 'field_batch',
        'field_row_number' => 'field_row_number',
        'field_description' => 'field_description',
        'field_supplier_sku' => 'field_supplier_sku',
        'field_manufacturer_item_number' => 'field_manufacturer_item_number',
        'field_manufacturer_name' => 'field_manufacturer_name',
        'field_unit_cost' => 'field_unit_cost',
        'field_cost_uom' => 'field_cost_uom',
        'operations' => 'operations',
      ],
      'default' => 'field_row_number',
      'info' => [
        'field_batch' => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'field_row_number' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => 'right', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'field_description' => ['sortable' => FALSE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'field_supplier_sku' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'field_manufacturer_item_number' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'field_manufacturer_name' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'field_unit_cost' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => 'right', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'field_cost_uom' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        'operations' => ['align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
      ],
      'override' => TRUE, 'sticky' => TRUE, 'order' => 'asc', 'empty_table' => TRUE,
    ],
  ],
  'row' => ['type' => 'fields', 'options' => []],
  'fields' => $rowSharedFieldsArray,
  'sorts' => [
    'field_batch_target_id' => [
      'id' => 'field_batch_target_id', 'table' => 'supplier_price_ingest_row__field_batch',
      'field' => 'field_batch_target_id', 'relationship' => 'none', 'plugin_id' => 'standard',
      'order' => 'DESC',
    ],
    'field_row_number_value' => [
      'id' => 'field_row_number_value', 'table' => 'supplier_price_ingest_row__field_row_number',
      'field' => 'field_row_number_value', 'relationship' => 'none', 'plugin_id' => 'standard',
      'order' => 'ASC',
    ],
    // id ASC as deterministic tiebreaker per range-audit gotcha.
    'id' => [
      'id' => 'id', 'table' => 'supplier_price_ingest_row_field_data', 'field' => 'id',
      'relationship' => 'none', 'plugin_id' => 'standard', 'order' => 'ASC',
    ],
  ],
  // CRITICAL — bake the discovery-queue scoping into the view's
  // default filters. row_status = discovery_pending AND
  // match_tier = discovery distinguishes this from the Fuzzy Match
  // Review queue (which filters on tier_3_fuzzy_med).
  'filters' => [
    'field_row_status_value' => [
      'id' => 'field_row_status_value', 'table' => 'supplier_price_ingest_row__field_row_status',
      'field' => 'field_row_status_value', 'relationship' => 'none', 'plugin_id' => 'list_field',
      'operator' => '=', 'value' => ['discovery_pending' => 'discovery_pending'],
      'group' => 1, 'exposed' => FALSE,
    ],
    'field_match_tier_value' => [
      'id' => 'field_match_tier_value', 'table' => 'supplier_price_ingest_row__field_match_tier',
      'field' => 'field_match_tier_value', 'relationship' => 'none', 'plugin_id' => 'list_field',
      'operator' => '=', 'value' => ['discovery' => 'discovery'],
      'group' => 1, 'exposed' => FALSE,
    ],
    'field_batch_target_id' => [
      'id' => 'field_batch_target_id', 'table' => 'supplier_price_ingest_row__field_batch',
      'field' => 'field_batch_target_id', 'relationship' => 'none', 'plugin_id' => 'numeric',
      'operator' => '=',
      'exposed' => TRUE,
      'expose' => [
        'operator_id' => 'field_batch_target_id_op', 'label' => 'Batch', 'description' => '',
        'use_operator' => FALSE, 'operator' => 'field_batch_target_id_op',
        'identifier' => 'batch', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE,
        'remember_roles' => ['authenticated' => 'authenticated'],
      ],
    ],
    'field_description_value' => [
      'id' => 'field_description_value', 'table' => 'supplier_price_ingest_row__field_description',
      'field' => 'field_description_value', 'relationship' => 'none', 'plugin_id' => 'string',
      'operator' => 'contains',
      'exposed' => TRUE,
      'expose' => [
        'operator_id' => 'field_description_value_op', 'label' => 'Description contains', 'description' => '',
        'use_operator' => FALSE, 'operator' => 'field_description_value_op',
        'identifier' => 'description', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE,
        'remember_roles' => ['authenticated' => 'authenticated'],
      ],
    ],
    'field_resolution_notes_value' => [
      'id' => 'field_resolution_notes_value', 'table' => 'supplier_price_ingest_row__field_resolution_notes',
      'field' => 'field_resolution_notes_value', 'relationship' => 'none', 'plugin_id' => 'string',
      'operator' => 'contains',
      'exposed' => TRUE,
      'expose' => [
        'operator_id' => 'field_resolution_notes_value_op', 'label' => 'Inferred bundle / notes contain', 'description' => 'Search resolution notes (Phase 3.4 writes "inferred bundles: X" for some rows).',
        'use_operator' => FALSE, 'operator' => 'field_resolution_notes_value_op',
        'identifier' => 'notes', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE,
        'remember_roles' => ['authenticated' => 'authenticated'],
      ],
    ],
  ],
  'arguments' => [],
  'empty' => [
    'area_text_custom' => [
      'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom',
      'plugin_id' => 'text_custom', 'empty' => TRUE,
      'content' => 'Discovery queue is empty. Rows land here when a committed batch contains items the matcher could not resolve to existing materials.',
    ],
  ],
  'header' => [], 'footer' => [], 'relationships' => [], 'use_more' => FALSE, 'use_ajax' => FALSE, 'display_extenders' => [],
];

$discovery->set('display', [
  'default' => [
    'id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0,
    'display_options' => $discoveryDisplayOptions,
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user.permissions'],
      'tags' => [],
    ],
  ],
  'page_1' => [
    'id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1,
    'display_options' => [
      'display_extenders' => [],
      'path' => 'admin/materials/supplier-ingest/discovery',
      'menu' => ['type' => 'none', 'title' => 'Discovery Queue', 'description' => '', 'weight' => 0, 'menu_name' => 'admin', 'parent' => '', 'context' => 0],
    ],
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user.permissions'],
      'tags' => [],
    ],
  ],
]);
$discovery->save();
echo "Saved view supplier_ingest_discovery_queue.\n";

// ────────────────────────────────────────────────────────────────────
// 3. supplier_ingest_fuzzy_review
// ────────────────────────────────────────────────────────────────────

$fuzzy = View::load('supplier_ingest_fuzzy_review') ?? View::create(['id' => 'supplier_ingest_fuzzy_review']);
$fuzzy->set('label', 'Supplier Ingest — Fuzzy Match Review');
$fuzzy->set('module', 'views');
$fuzzy->set('description', 'Tier 3 medium-confidence fuzzy matches awaiting Office Manager confirmation. Reviewer confirms, overrides, sends to discovery, or rejects.');
$fuzzy->set('tag', 'supplier_price_ingest');
$fuzzy->set('base_table', 'supplier_price_ingest_row_field_data');
$fuzzy->set('base_field', 'id');

// Fuzzy review's field set is a superset — adds the matched material
// and the confidence number alongside the shared fields.
$fuzzyFields = $rowSharedFieldsArray + [
  'field_matched_material' => [
    'id' => 'field_matched_material', 'table' => 'supplier_price_ingest_row__field_matched_material',
    'field' => 'field_matched_material', 'relationship' => 'none', 'plugin_id' => 'field',
    'label' => 'Proposed Match', 'type' => 'entity_reference_label',
    'settings' => ['link' => TRUE],
  ],
  'field_match_confidence' => [
    'id' => 'field_match_confidence', 'table' => 'supplier_price_ingest_row__field_match_confidence',
    'field' => 'field_match_confidence', 'relationship' => 'none', 'plugin_id' => 'field',
    'label' => 'Confidence', 'type' => 'number_decimal',
    'settings' => ['decimal_separator' => '.', 'thousand_separator' => '', 'scale' => 1, 'prefix' => '', 'suffix' => '%'],
  ],
  'field_resolution_notes' => [
    'id' => 'field_resolution_notes', 'table' => 'supplier_price_ingest_row__field_resolution_notes',
    'field' => 'field_resolution_notes', 'relationship' => 'none', 'plugin_id' => 'field',
    'label' => 'Score Breakdown', 'type' => 'text_default',
  ],
];

$fuzzy->set('display', [
  'default' => [
    'id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0,
    'display_options' => [
      'access' => ['type' => 'perm', 'options' => ['perm' => 'administer supplier price ingest']],
      'cache' => ['type' => 'tag', 'options' => []],
      'query' => ['type' => 'views_query', 'options' => []],
      'exposed_form' => ['type' => 'basic', 'options' => []],
      'pager' => ['type' => 'mini', 'options' => ['items_per_page' => 50]],
      'style' => [
        'type' => 'table',
        'options' => [
          'grouping' => [], 'row_class' => '', 'default_row_class' => TRUE,
          'columns' => [
            'field_batch' => 'field_batch',
            'field_description' => 'field_description',
            'field_matched_material' => 'field_matched_material',
            'field_match_confidence' => 'field_match_confidence',
            'field_resolution_notes' => 'field_resolution_notes',
            'operations' => 'operations',
          ],
          'default' => 'field_match_confidence',
          'info' => [
            'field_batch' => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_description' => ['sortable' => FALSE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_matched_material' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_match_confidence' => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => 'right', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'field_resolution_notes' => ['sortable' => FALSE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
            'operations' => ['align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
          ],
          'override' => TRUE, 'sticky' => TRUE, 'order' => 'desc', 'empty_table' => TRUE,
        ],
      ],
      'row' => ['type' => 'fields', 'options' => []],
      'fields' => $fuzzyFields,
      'sorts' => [
        // Sort by confidence DESC by default — show the most-likely-correct
        // matches first so the reviewer can knock those out fastest.
        'field_match_confidence_value' => [
          'id' => 'field_match_confidence_value', 'table' => 'supplier_price_ingest_row__field_match_confidence',
          'field' => 'field_match_confidence_value', 'relationship' => 'none', 'plugin_id' => 'standard',
          'order' => 'DESC',
        ],
        'id' => [
          'id' => 'id', 'table' => 'supplier_price_ingest_row_field_data', 'field' => 'id',
          'relationship' => 'none', 'plugin_id' => 'standard', 'order' => 'ASC',
        ],
      ],
      'filters' => [
        'field_row_status_value' => [
          'id' => 'field_row_status_value', 'table' => 'supplier_price_ingest_row__field_row_status',
          'field' => 'field_row_status_value', 'relationship' => 'none', 'plugin_id' => 'list_field',
          'operator' => '=', 'value' => ['discovery_pending' => 'discovery_pending'],
          'group' => 1, 'exposed' => FALSE,
        ],
        'field_match_tier_value' => [
          'id' => 'field_match_tier_value', 'table' => 'supplier_price_ingest_row__field_match_tier',
          'field' => 'field_match_tier_value', 'relationship' => 'none', 'plugin_id' => 'list_field',
          'operator' => '=', 'value' => ['tier_3_fuzzy_med' => 'tier_3_fuzzy_med'],
          'group' => 1, 'exposed' => FALSE,
        ],
        'field_batch_target_id' => [
          'id' => 'field_batch_target_id', 'table' => 'supplier_price_ingest_row__field_batch',
          'field' => 'field_batch_target_id', 'relationship' => 'none', 'plugin_id' => 'numeric',
          'operator' => '=', 'exposed' => TRUE,
          'expose' => [
            'operator_id' => 'field_batch_target_id_op', 'label' => 'Batch', 'description' => '',
            'use_operator' => FALSE, 'operator' => 'field_batch_target_id_op',
            'identifier' => 'batch', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE,
            'remember_roles' => ['authenticated' => 'authenticated'],
          ],
        ],
        'field_match_confidence_value' => [
          'id' => 'field_match_confidence_value', 'table' => 'supplier_price_ingest_row__field_match_confidence',
          'field' => 'field_match_confidence_value', 'relationship' => 'none', 'plugin_id' => 'numeric',
          'operator' => 'between', 'exposed' => TRUE,
          'expose' => [
            'operator_id' => 'field_match_confidence_value_op', 'label' => 'Confidence range', 'description' => '',
            'use_operator' => TRUE, 'operator' => 'field_match_confidence_value_op',
            'identifier' => 'confidence', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE,
            'remember_roles' => ['authenticated' => 'authenticated'],
          ],
        ],
      ],
      'arguments' => [],
      'empty' => [
        'area_text_custom' => [
          'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom',
          'plugin_id' => 'text_custom', 'empty' => TRUE,
          'content' => 'Fuzzy match review queue is empty. Rows land here when the matcher scored a candidate at medium confidence (between the supplier\'s medium and high thresholds — defaults 70%–89%).',
        ],
      ],
      'header' => [], 'footer' => [], 'relationships' => [], 'use_more' => FALSE, 'use_ajax' => FALSE, 'display_extenders' => [],
    ],
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user.permissions'],
      'tags' => [],
    ],
  ],
  'page_1' => [
    'id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1,
    'display_options' => [
      'display_extenders' => [],
      'path' => 'admin/materials/supplier-ingest/fuzzy-review',
      'menu' => ['type' => 'none', 'title' => 'Fuzzy Match Review', 'description' => '', 'weight' => 0, 'menu_name' => 'admin', 'parent' => '', 'context' => 0],
    ],
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user.permissions'],
      'tags' => [],
    ],
  ],
]);
$fuzzy->save();
echo "Saved view supplier_ingest_fuzzy_review.\n";

// ────────────────────────────────────────────────────────────────────
// 4. VBO bulk-reject field — inject into BOTH row views
// ────────────────────────────────────────────────────────────────────

$vboFieldConfig = [
  'id' => 'views_bulk_operations_bulk_form',
  'table' => 'views',
  'field' => 'views_bulk_operations_bulk_form',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'plugin_id' => 'views_bulk_operations_bulk_form',
  'label' => '',
  'exclude' => FALSE,
  'batch' => TRUE,
  'batch_size' => 50,
  'form_step' => TRUE,
  'buttons' => FALSE,
  'action_title' => 'Action',
  'clear_on_exposed' => FALSE,
  'force_selection_info' => FALSE,
  'preconfiguration' => [],
  'ajax_loader' => TRUE,
  'show_multipage_selection_box' => 'default',
  'show_select_all' => 'default',
  'selected_actions' => [
    ['action_id' => 'supplier_price_ingest_bulk_reject_rows'],
  ],
];

foreach (['supplier_ingest_discovery_queue', 'supplier_ingest_fuzzy_review'] as $vid) {
  $v = View::load($vid);
  if (!$v) { continue; }
  $display = $v->get('display');
  $defaultFields = $display['default']['display_options']['fields'] ?? [];
  // Prepend the VBO field so it renders as the first column (checkbox).
  $newFields = ['views_bulk_operations_bulk_form' => $vboFieldConfig] + $defaultFields;
  $display['default']['display_options']['fields'] = $newFields;
  // Add VBO column to the table style + put it first.
  $columns = $display['default']['display_options']['style']['options']['columns'] ?? [];
  $newColumns = ['views_bulk_operations_bulk_form' => 'views_bulk_operations_bulk_form'] + $columns;
  $display['default']['display_options']['style']['options']['columns'] = $newColumns;
  // Each column needs an info entry (alignment, sortability flags).
  $info = $display['default']['display_options']['style']['options']['info'] ?? [];
  $info = ['views_bulk_operations_bulk_form' => ['align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => '']] + $info;
  $display['default']['display_options']['style']['options']['info'] = $info;
  $v->set('display', $display);
  $v->save();
  echo "Injected VBO bulk-reject field into view $vid.\n";
}

echo "\nNext: capture each view's active config into config/sync via\n";
echo "  ddev drush config:get views.view.supplier_ingest_batches --format=yaml > config/sync/views.view.supplier_ingest_batches.yml\n";
echo "  ddev drush config:get views.view.supplier_ingest_discovery_queue --format=yaml > config/sync/views.view.supplier_ingest_discovery_queue.yml\n";
echo "  ddev drush config:get views.view.supplier_ingest_fuzzy_review --format=yaml > config/sync/views.view.supplier_ingest_fuzzy_review.yml\n";
