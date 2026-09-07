<?php

/**
 * @file
 * Add exposed "Type" (Property / HOA) and "Discounted (HOA)" filters to the
 * properties list view (/admin/properties) so office can query by property type
 * and by whether the HOA discount applies. Idempotent; run per env.
 *   drush php:script web/scripts/add_hoa_property_list_filters.php
 */

$v = \Drupal::entityTypeManager()->getStorage('view')->load('properties');
if (!$v) { print "properties view not found\n"; return; }
$display = $v->get('display');
$filters = $display['default']['display_options']['filters'] ?? [];

$groupInfo = [
  'label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE,
  'widget' => 'select', 'multiple' => FALSE, 'remember' => FALSE,
  'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => [],
];

// Bundle (type) exposed filter — empty default value = shows all types.
$filters['type'] = [
  'id' => 'type', 'table' => 'properties_field_data', 'field' => 'type',
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
  'plugin_id' => 'bundle', 'operator' => 'in', 'value' => [], 'group' => 1,
  'exposed' => TRUE,
  'expose' => [
    'operator_id' => 'type_op', 'label' => 'Type', 'description' => '',
    'use_operator' => FALSE, 'operator' => 'type_op', 'operator_limit_selection' => FALSE,
    'operator_list' => [], 'identifier' => 'type', 'required' => FALSE, 'remember' => FALSE,
    'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'], 'reduce' => FALSE,
  ],
  'is_grouped' => FALSE, 'group_info' => $groupInfo,
  'entity_type' => 'properties', 'entity_field' => 'type',
];

// Discounted (HOA) — GROUPED so it defaults to "Any" (no filtering on load).
$filters['field_hoa_contracted_value'] = [
  'id' => 'field_hoa_contracted_value', 'table' => 'properties__field_hoa_contracted',
  'field' => 'field_hoa_contracted_value', 'relationship' => 'none', 'group_type' => 'group',
  'admin_label' => '', 'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1', 'group' => 1,
  'exposed' => TRUE,
  'expose' => [
    'operator_id' => 'field_hoa_contracted_value_op', 'label' => 'Discounted (HOA)', 'description' => '',
    'use_operator' => FALSE, 'operator' => 'field_hoa_contracted_value_op', 'operator_limit_selection' => FALSE,
    'operator_list' => [], 'identifier' => 'discounted', 'required' => FALSE, 'remember' => FALSE,
    'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'],
  ],
  'is_grouped' => TRUE,
  'group_info' => [
    'label' => 'Discounted (HOA)', 'description' => '', 'identifier' => 'discounted',
    'optional' => TRUE, 'widget' => 'select', 'multiple' => FALSE, 'remember' => FALSE,
    'default_group' => 'All', 'default_group_multiple' => [],
    'group_items' => [
      1 => ['title' => 'Discounted', 'operator' => '=', 'value' => '1'],
      2 => ['title' => 'Not discounted', 'operator' => '=', 'value' => '0'],
    ],
  ],
  'entity_type' => 'properties', 'entity_field' => 'field_hoa_contracted',
];

$display['default']['display_options']['filters'] = $filters;
$v->set('display', $display);
$v->save();

print "Added exposed filters: Type + Discounted (HOA) to the properties view.\n";
print "filters now: " . implode(', ', array_keys($filters)) . "\nDONE.\n";
