<?php

/**
 * Build the Snow Contracts admin list — view `contracts_snow`, page at
 * /admin/office/contracts/snow (admin menu, Office section — sibling of
 * Contracts). Lists the snow_removal contracts with number, property, status,
 * year + view/edit ops and an "Add New Snow Contract" button. Idempotent
 * (delete + recreate); run per env. Refine columns/filters in the Views UI
 * afterward.
 *
 *   drush php:script web/scripts/build_snow_contracts_view.php
 */

use Drupal\views\Entity\View;

if ($existing = View::load('contracts_snow')) {
  $existing->delete();
  print "removed existing contracts_snow (rebuild)\n";
}

// Same Office menu parent the residential Contracts page uses (stable UUID on
// both envs). Fall back to no parent if it can't be resolved.
$parent = 'menu_link_content:4b4baafc-6631-4b64-9157-b570acf68c2c';

$fields = [
  'field_snow_contract_number' => [
    'id' => 'field_snow_contract_number',
    'table' => 'contracts__field_snow_contract_number',
    'field' => 'field_snow_contract_number',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'contracts', 'entity_field' => 'field_snow_contract_number',
    'plugin_id' => 'field', 'label' => 'Contract #', 'exclude' => FALSE,
    'type' => 'string',
    'settings' => ['link_to_entity' => TRUE],
  ],
  'field_nickname' => [
    'id' => 'field_nickname',
    'table' => 'properties__field_nickname',
    'field' => 'field_nickname',
    'relationship' => 'field_property', 'group_type' => 'group', 'admin_label' => '',
    'plugin_id' => 'field', 'label' => 'Property', 'exclude' => FALSE,
    'type' => 'string', 'settings' => ['link_to_entity' => FALSE],
  ],
  'field_contract_status' => [
    'id' => 'field_contract_status',
    'table' => 'contracts__field_contract_status',
    'field' => 'field_contract_status',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'contracts', 'entity_field' => 'field_contract_status',
    'plugin_id' => 'field', 'label' => 'Status', 'exclude' => FALSE,
    'type' => 'entity_reference_label',
    'settings' => ['link' => FALSE],
  ],
  'field_contract_year' => [
    'id' => 'field_contract_year',
    'table' => 'contracts__field_contract_year',
    'field' => 'field_contract_year',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'contracts', 'entity_field' => 'field_contract_year',
    'plugin_id' => 'field', 'label' => 'Year', 'exclude' => FALSE,
    'type' => 'string', 'settings' => [],
  ],
  'operations' => [
    'id' => 'operations',
    'table' => 'contracts',
    'field' => 'operations',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'contracts',
    'plugin_id' => 'entity_operations', 'label' => 'Operations', 'exclude' => FALSE,
    'settings' => [],
  ],
];

$relationships = [
  'field_property' => [
    'id' => 'field_property',
    'table' => 'contracts__field_property',
    'field' => 'field_property',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => 'Properties',
    'plugin_id' => 'standard', 'required' => FALSE,
  ],
];

$filters = [
  'type' => [
    'id' => 'type',
    'table' => 'contracts_field_data',
    'field' => 'type',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'contracts', 'entity_field' => 'type',
    'plugin_id' => 'bundle', 'operator' => 'in',
    'value' => ['snow_removal' => 'snow_removal'],
  ],
];

$sorts = [
  'created' => [
    'id' => 'created',
    'table' => 'contracts_field_data',
    'field' => 'created',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'contracts', 'entity_field' => 'created',
    'plugin_id' => 'date', 'order' => 'DESC',
  ],
];

$access = [
  'type' => 'role',
  'options' => ['role' => [
    'administrator' => 'administrator',
    'site_admin' => 'site_admin',
    'administration' => 'administration',
    'supervisor' => 'supervisor',
  ]],
];

$add_button = '<a class="button--primary button js-form-submit form-submit" href="/admin/content/contracts/add/snow_removal?destination=/admin/office/contracts/snow">Add New Snow Contract</a>';

$default_options = [
  'title' => 'Snow Contracts',
  'fields' => $fields,
  'relationships' => $relationships,
  'filters' => $filters,
  'sorts' => $sorts,
  'access' => $access,
  'cache' => ['type' => 'tag', 'options' => []],
  'query' => ['type' => 'views_query', 'options' => []],
  'exposed_form' => ['type' => 'basic', 'options' => []],
  'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50]],
  'style' => ['type' => 'table', 'options' => [
    'columns' => [
      'field_snow_contract_number' => 'field_snow_contract_number',
      'field_nickname' => 'field_nickname',
      'field_contract_status' => 'field_contract_status',
      'field_contract_year' => 'field_contract_year',
      'operations' => 'operations',
    ],
    'default' => 'field_snow_contract_number',
    'info' => [
      'field_snow_contract_number' => ['sortable' => TRUE, 'default_sort_order' => 'asc'],
      'field_nickname' => ['sortable' => TRUE],
      'field_contract_status' => ['sortable' => TRUE],
      'field_contract_year' => ['sortable' => TRUE],
      'operations' => ['sortable' => FALSE],
    ],
  ]],
  'row' => ['type' => 'fields'],
  'header' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area',
      'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
      'plugin_id' => 'text', 'empty' => FALSE,
      'content' => ['value' => $add_button, 'format' => 'full_html'],
      'tokenize' => FALSE,
    ],
  ],
  'empty' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area',
      'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
      'plugin_id' => 'text', 'empty' => TRUE,
      'content' => ['value' => 'No snow contracts yet. Use "Add New Snow Contract" above.', 'format' => 'basic_html'],
      'tokenize' => FALSE,
    ],
  ],
];

$view = View::create([
  'id' => 'contracts_snow',
  'label' => 'Snow Contracts',
  'module' => 'views',
  'base_table' => 'contracts_field_data',
  'base_field' => 'id',
  'description' => 'Admin list of snow removal contracts.',
  'display' => [
    'default' => [
      'display_plugin' => 'default',
      'id' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => $default_options,
    ],
    'page_1' => [
      'display_plugin' => 'page',
      'id' => 'page_1',
      'display_title' => 'Page',
      'position' => 1,
      'display_options' => [
        'path' => 'admin/office/contracts/snow',
        'menu' => [
          'type' => 'normal',
          'title' => 'Snow Contracts',
          'description' => 'Snow removal contracts.',
          'weight' => -2,
          'expanded' => FALSE,
          'menu_name' => 'admin',
          'parent' => $parent,
        ],
      ],
    ],
  ],
]);
$view->save();
print "created view contracts_snow @ /admin/office/contracts/snow\n";
print "DONE\n";
