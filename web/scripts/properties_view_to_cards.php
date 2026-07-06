<?php

/**
 * Convert the `properties` admin view (/admin/properties) to the card layout:
 *   - style: HTML table -> Unformatted list (so each row is a card div)
 *   - fields: strip to just field_nickname (the row template renders the card
 *     from the property entity via properties_preprocess_views_view_fields();
 *     the removed Property ID / Aerial view / Map Point / Operations / VBO
 *     bulk form / Full Address columns are no longer shown).
 *
 * Idempotent. Run: drush php:script web/scripts/properties_view_to_cards.php
 */

use Drupal\views\Entity\View;

$view = View::load('properties');
if (!$view) {
  print "properties view not found\n";
  return;
}

$display = $view->get('display');
$do = &$display['default']['display_options'];

// Unformatted list style.
$do['style'] = [
  'type' => 'default',
  'options' => [
    'grouping' => [],
    'row_class' => '',
    'default_row_class' => TRUE,
    'uses_fields' => FALSE,
  ],
];

// Keep field_nickname as the row anchor + add field_full_address and id as
// EXCLUDED fields so the combine search filter can reach them. (Removes VBO,
// Property ID column, Full Address column, Aerial view, Map Point, Operations,
// entity-link, title, Contract-id.)
if (!isset($do['fields']['field_nickname'])) {
  print "WARNING: field_nickname not present — leaving fields untouched\n";
  return;
}
$do['fields'] = [
  'field_nickname' => $do['fields']['field_nickname'],
  'field_full_address' => [
    'id' => 'field_full_address',
    'table' => 'properties__field_full_address',
    'field' => 'field_full_address',
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'label' => '',
    'exclude' => TRUE,
    'element_type' => '',
    'element_class' => '',
    'element_label_type' => '',
    'element_label_class' => '',
    'element_label_colon' => FALSE,
    'element_wrapper_type' => '',
    'element_wrapper_class' => '',
    'element_default_classes' => TRUE,
    'empty' => '',
    'hide_empty' => FALSE,
    'empty_zero' => FALSE,
    'hide_alter_empty' => TRUE,
    'click_sort_column' => 'value',
    'type' => 'string',
    'settings' => ['link_to_entity' => FALSE],
    'plugin_id' => 'field',
    'entity_type' => 'properties',
    'entity_field' => 'field_full_address',
  ],
  'id' => [
    'id' => 'id',
    'table' => 'properties_field_data',
    'field' => 'id',
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'label' => '',
    'exclude' => TRUE,
    'element_type' => '',
    'element_class' => '',
    'element_label_type' => '',
    'element_label_class' => '',
    'element_label_colon' => FALSE,
    'element_wrapper_type' => '',
    'element_wrapper_class' => '',
    'element_default_classes' => TRUE,
    'empty' => '',
    'hide_empty' => FALSE,
    'empty_zero' => FALSE,
    'hide_alter_empty' => TRUE,
    'type' => 'number_integer',
    'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE],
    'plugin_id' => 'field',
    'entity_type' => 'properties',
    'entity_field' => 'id',
  ],
];

// Single "Search properties" box: combine over name + full address + ID, so one
// input matches property name, street, city, ZIP, or Property ID. Replaces the
// four separate exposed filters (nickname / street / city / id).
$do['filter_groups'] = [
  'operator' => 'AND',
  'groups' => [1 => 'AND'],
];
$do['filters'] = [
  'combine' => [
    'id' => 'combine',
    'table' => 'views',
    'field' => 'combine',
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'operator' => 'contains',
    'value' => '',
    'group' => 1,
    'exposed' => TRUE,
    'expose' => [
      'operator_id' => '',
      'label' => 'Search properties',
      'description' => '',
      'use_operator' => FALSE,
      'operator' => 'combine_op',
      'operator_limit_selection' => FALSE,
      'operator_list' => [],
      'identifier' => 'search',
      'required' => FALSE,
      'remember' => FALSE,
      'multiple' => FALSE,
      'remember_roles' => ['authenticated' => 'authenticated'],
      'placeholder' => 'Name, address, or ID…',
    ],
    'is_grouped' => FALSE,
    'group_info' => [
      'label' => '',
      'description' => '',
      'identifier' => '',
      'optional' => TRUE,
      'widget' => 'select',
      'multiple' => FALSE,
      'remember' => FALSE,
      'default_group' => 'All',
      'default_group_multiple' => [],
      'group_items' => [],
    ],
    'fields' => [
      'field_nickname' => 'field_nickname',
      'field_full_address' => 'field_full_address',
      'id' => 'id',
    ],
    'plugin_id' => 'combine',
  ],
];
$do['exposed_form'] = [
  'type' => 'basic',
  'options' => [
    'submit_button' => 'Search',
    'reset_button' => TRUE,
    'reset_button_label' => 'Clear',
    'exposed_sorts_label' => 'Sort by',
    'expose_sort_order' => TRUE,
    'sort_asc_label' => 'Asc',
    'sort_desc_label' => 'Desc',
  ],
];

$view->set('display', $display);
$view->save();

print "properties view converted: style=unformatted, fields=" . implode(',', array_keys($do['fields']))
  . ", search=combine(name+address+id)\n";
