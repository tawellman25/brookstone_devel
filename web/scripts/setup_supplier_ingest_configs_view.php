<?php

declare(strict_types=1);

/**
 * Phase 3.2 — creates views.view.supplier_ingest_configs (admin list
 * at /admin/materials/supplier-ingest/configs). Idempotent.
 *
 * Columns: Supplier, Active, Last Updated, Operations.
 * Access: permission "administer supplier price ingest".
 *
 * After running, capture via:
 *   ddev drush config:get views.view.supplier_ingest_configs --format=yaml
 *     > config/sync/views.view.supplier_ingest_configs.yml
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\views\Entity\View;

if (View::load('supplier_ingest_configs')) {
  echo "View supplier_ingest_configs already exists. Re-saving with current spec.\n";
  $view = View::load('supplier_ingest_configs');
}
else {
  $view = View::create(['id' => 'supplier_ingest_configs']);
}

$view->set('label', 'Supplier Ingest Configs');
$view->set('module', 'views');
$view->set('description', 'Admin list of supplier_ingest_config entities — per-supplier ingest configurations.');
$view->set('tag', 'supplier_price_ingest');
$view->set('base_table', 'supplier_ingest_config');
$view->set('base_field', 'id');

$display = [
  'default' => [
    'id' => 'default',
    'display_title' => 'Default',
    'display_plugin' => 'default',
    'position' => 0,
    'display_options' => [
      'access' => [
        'type' => 'perm',
        'options' => ['perm' => 'administer supplier price ingest'],
      ],
      'cache' => ['type' => 'tag', 'options' => []],
      'query' => ['type' => 'views_query', 'options' => []],
      'exposed_form' => ['type' => 'basic', 'options' => []],
      'pager' => ['type' => 'mini', 'options' => ['items_per_page' => 50]],
      'style' => ['type' => 'table', 'options' => [
        'grouping' => [],
        'row_class' => '',
        'default_row_class' => TRUE,
        'columns' => [
          'field_supplier' => 'field_supplier',
          'field_active' => 'field_active',
          'changed' => 'changed',
          'operations' => 'operations',
        ],
        'default' => 'changed',
        'info' => [
          'field_supplier' => ['sortable' => TRUE, 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
          'field_active'   => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
          'changed'        => ['sortable' => TRUE, 'default_sort_order' => 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
          'operations'     => ['align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => ''],
        ],
        'override' => TRUE,
        'sticky' => TRUE,
        'order' => 'desc',
        'empty_table' => TRUE,
      ]],
      'row' => ['type' => 'fields', 'options' => []],
      'fields' => [
        'field_supplier' => [
          'id' => 'field_supplier',
          'table' => 'supplier_ingest_config__field_supplier',
          'field' => 'field_supplier',
          'relationship' => 'none',
          'plugin_id' => 'field',
          'label' => 'Supplier',
          'type' => 'entity_reference_label',
          'settings' => ['link' => FALSE],
        ],
        'field_active' => [
          'id' => 'field_active',
          'table' => 'supplier_ingest_config__field_active',
          'field' => 'field_active',
          'relationship' => 'none',
          'plugin_id' => 'field',
          'label' => 'Active',
          'type' => 'boolean',
          'settings' => ['format' => 'yes-no', 'format_custom_true' => '', 'format_custom_false' => ''],
        ],
        'changed' => [
          'id' => 'changed',
          'table' => 'supplier_ingest_config',
          'field' => 'changed',
          'relationship' => 'none',
          'plugin_id' => 'field',
          'label' => 'Last Updated',
          'type' => 'timestamp',
          'settings' => ['date_format' => 'short', 'custom_date_format' => '', 'timezone' => ''],
        ],
        'operations' => [
          'id' => 'operations',
          'table' => 'supplier_ingest_config',
          'field' => 'operations',
          'relationship' => 'none',
          'plugin_id' => 'entity_operations',
          'label' => 'Operations',
          'destination' => TRUE,
        ],
      ],
      'sorts' => [
        'changed' => [
          'id' => 'changed',
          'table' => 'supplier_ingest_config',
          'field' => 'changed',
          'relationship' => 'none',
          'plugin_id' => 'date',
          'order' => 'DESC',
        ],
      ],
      'filters' => [],
      'arguments' => [],
      'empty' => [
        'area_text_custom' => [
          'id' => 'area_text_custom',
          'table' => 'views',
          'field' => 'area_text_custom',
          'plugin_id' => 'text_custom',
          'empty' => TRUE,
          'content' => 'No supplier ingest configurations yet. <a href="/admin/materials/supplier-ingest/configs/add">Add the first one.</a>',
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
    'id' => 'page_1',
    'display_title' => 'Page',
    'display_plugin' => 'page',
    'position' => 1,
    'display_options' => [
      'display_extenders' => [],
      'path' => 'admin/materials/supplier-ingest/configs',
      'menu' => [
        'type' => 'none',
        'title' => 'Supplier Ingest Configs',
        'description' => '',
        'weight' => 0,
        'menu_name' => 'admin',
        'parent' => '',
        'context' => 0,
      ],
    ],
    'cache_metadata' => [
      'max-age' => -1,
      'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user.permissions'],
      'tags' => [],
    ],
  ],
];

$view->set('display', $display);
$view->save();
echo "Saved view supplier_ingest_configs.\n";
