<?php

/**
 * P1.5 — add an exposed "Review flags" filter to the service_request_admin queue
 * view, so the office can filter by machine flag (standing_flag_no_contract,
 * supply_mismatch, contract_completed_for_year, unmatched_property, …).
 *
 * field_review_flags is a newline string_long, so a "contains" text filter is
 * the right tool. Idempotent, run per environment (the view is drifted from
 * sync — edit active config, not cim).
 *
 *   drush php:script web/scripts/add_service_request_review_flags_filter.php
 */

$view = \Drupal::entityTypeManager()->getStorage('view')->load('service_request_admin');
if (!$view) {
  echo "service_request_admin view not found.\n";
  return;
}

$display = $view->get('display');
$filters = $display['default']['display_options']['filters'] ?? [];

if (isset($filters['field_review_flags_value'])) {
  echo "review-flags filter already present.\n";
  return;
}

$filters['field_review_flags_value'] = [
  'id' => 'field_review_flags_value',
  'table' => 'service_request__field_review_flags',
  'field' => 'field_review_flags_value',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'plugin_id' => 'string',
  'operator' => 'contains',
  'value' => '',
  'group' => 1,
  'exposed' => TRUE,
  'expose' => [
    'operator_id' => 'field_review_flags_value_op',
    'label' => 'Review flag contains',
    'description' => 'e.g. standing_flag_no_contract, supply_mismatch, unmatched_property',
    'use_operator' => FALSE,
    'operator' => 'field_review_flags_value_op',
    'operator_limit_selection' => FALSE,
    'operator_list' => [],
    'identifier' => 'flag',
    'required' => FALSE,
    'remember' => FALSE,
    'multiple' => FALSE,
    'remember_roles' => ['authenticated' => 'authenticated'],
    'placeholder' => '',
  ],
  'is_grouped' => FALSE,
  'entity_type' => 'service_request',
  'entity_field' => 'field_review_flags',
];

$display['default']['display_options']['filters'] = $filters;
$view->set('display', $display);
$view->save();

echo "Added exposed 'Review flag contains' filter (identifier=flag) to service_request_admin.\n";
