<?php

/**
 * @file
 * Keep the new `hoa` property bundle OUT of crew-facing property lists by adding
 * a non-exposed `type = property` filter to views that list all properties:
 *   - teammate_properties (crew /teammates/properties list + map + blocks)
 *   - properties_new (block)
 *
 * The admin /admin/properties view is intentionally left alone — it has an
 * exposed Type filter so office can see/filter both. Per-property (arg-driven)
 * views are unaffected. Idempotent; run per env.
 *   drush php:script web/scripts/exclude_hoa_from_property_lists.php
 */

$views = ['teammate_properties', 'properties_new'];
$groupInfo = [
  'label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE,
  'widget' => 'select', 'multiple' => FALSE, 'remember' => FALSE,
  'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => [],
];

foreach ($views as $vid) {
  $v = \Drupal::entityTypeManager()->getStorage('view')->load($vid);
  if (!$v) { print "$vid: MISSING\n"; continue; }
  $display = $v->get('display');
  $filters = $display['default']['display_options']['filters'] ?? [];
  if (isset($filters['type'])) { print "$vid: already has a type filter — leaving as-is\n"; continue; }
  $filters['type'] = [
    'id' => 'type', 'table' => 'properties_field_data', 'field' => 'type',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'plugin_id' => 'bundle', 'operator' => 'in', 'value' => ['property' => 'property'],
    'group' => 1, 'exposed' => FALSE,
    'expose' => [
      'operator_id' => '', 'label' => '', 'description' => '', 'use_operator' => FALSE,
      'operator' => '', 'operator_limit_selection' => FALSE, 'operator_list' => [],
      'identifier' => '', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE,
      'remember_roles' => ['authenticated' => 'authenticated'], 'reduce' => FALSE,
    ],
    'is_grouped' => FALSE, 'group_info' => $groupInfo,
    'entity_type' => 'properties', 'entity_field' => 'type',
  ];
  $display['default']['display_options']['filters'] = $filters;
  $v->set('display', $display);
  $v->save();
  print "$vid: added non-exposed type=property filter (HOAs excluded)\n";
}
print "DONE.\n";
