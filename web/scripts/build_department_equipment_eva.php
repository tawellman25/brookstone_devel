<?php

/**
 * "Department Equipment" — an EVA attached to the department entity page
 * (/teammates/{department}) listing the equipment_types tagged to that
 * department via field_equip_departments. The EVA passes the host department
 * id to a contextual filter, so each department page shows only its own kit.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_department_equipment_eva.php
 */

use Drupal\views\Entity\View;

$VIEW_ID = 'department_equipment';
$ROLES = ['teammates', 'supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator'];

if (View::load($VIEW_ID)) {
  View::load($VIEW_ID)->delete();
}

$default_options = [
  'title' => 'Department Equipment',
  'access' => ['type' => 'role', 'options' => ['role' => array_combine($ROLES, $ROLES)]],
  'cache' => ['type' => 'tag'],
  'query' => ['type' => 'views_query'],
  'exposed_form' => ['type' => 'basic'],
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => ['type' => 'html_list'],
  'row' => ['type' => 'fields'],
  'fields' => [
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name',
      'plugin_id' => 'taxonomy_term_name', 'label' => '', 'exclude' => FALSE,
      'element_label_colon' => FALSE, 'click_sort_column' => 'value', 'type' => 'string',
      'settings' => ['link_to_entity' => TRUE], 'group_column' => 'value', 'group_rows' => TRUE,
    ],
    'description__value' => [
      'id' => 'description__value', 'table' => 'taxonomy_term_field_data', 'field' => 'description__value',
      'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'taxonomy_term',
      'entity_field' => 'description', 'plugin_id' => 'field', 'label' => '', 'type' => 'text_default', 'settings' => [],
    ],
  ],
  'filters' => [
    'vid' => [
      'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'plugin_id' => 'bundle',
      'operator' => 'in', 'value' => ['equipment_types' => 'equipment_types'], 'group' => 1,
    ],
    'status' => [
      'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'status', 'plugin_id' => 'boolean',
      'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ],
  'sorts' => [
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'standard',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'order' => 'ASC',
    ],
  ],
  'arguments' => [
    'field_equip_departments_target_id' => [
      'id' => 'field_equip_departments_target_id', 'table' => 'taxonomy_term__field_equip_departments',
      'field' => 'field_equip_departments_target_id', 'relationship' => 'none', 'group_type' => 'group',
      'plugin_id' => 'entity_target_id', 'default_action' => 'empty',
      'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
      'default_argument_type' => 'fixed', 'default_argument_options' => ['argument' => ''],
      'summary_options' => ['base_path' => '', 'count' => TRUE, 'items_per_page' => 25],
      'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      'specify_validation' => FALSE, 'validate' => ['type' => 'none', 'fail' => 'not found'],
      'validate_options' => [], 'break_phrase' => FALSE,
    ],
  ],
  'empty' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none', 'group_type' => 'group',
      'plugin_id' => 'text', 'empty' => TRUE,
      'content' => ['value' => 'No equipment types are assigned to this department yet.', 'format' => 'basic_html'],
    ],
  ],
  'header' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none', 'group_type' => 'group',
      'plugin_id' => 'text', 'empty' => TRUE,
      'content' => ['value' => '<h3 class="department-equipment__title">Department Equipment</h3>', 'format' => 'full_html'],
    ],
  ],
  'relationships' => [], 'footer' => [], 'display_extenders' => [],
];

View::create([
  'id' => $VIEW_ID, 'label' => 'Department Equipment', 'module' => 'views',
  'base_table' => 'taxonomy_term_field_data', 'base_field' => 'tid',
  'display' => [
    'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default_options],
    'entity_view_1' => [
      'id' => 'entity_view_1', 'display_title' => 'EVA', 'display_plugin' => 'entity_view', 'position' => 1,
      'display_options' => [
        'entity_type' => 'department',
        'bundles' => ['details'],
        'argument_mode' => 'id',
        'default_argument' => '',
        'display_extenders' => [],
      ],
    ],
  ],
])->save();

print "view $VIEW_ID created (EVA -> department:details, arg=field_equip_departments)\nDONE.\n";
