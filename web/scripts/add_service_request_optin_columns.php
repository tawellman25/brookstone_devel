<?php

/**
 * P3.3 #1 + P1.2 — surface the opt-in booleans on the service_request_admin
 * queue: columns for all three opt-ins + an exposed filter on the specific-date
 * demand signal (the one that needs a call-back). Idempotent; run per env.
 *
 *   drush php:script web/scripts/add_service_request_optin_columns.php
 */

$view = \Drupal::entityTypeManager()->getStorage('view')->load('service_request_admin');
if (!$view) {
  echo "service_request_admin view not found.\n";
  return;
}
$display = $view->get('display');
$fields = $display['default']['display_options']['fields'] ?? [];
$filters = $display['default']['display_options']['filters'] ?? [];
$changed = [];

$boolCol = fn(string $name, string $label) => [
  'id' => $name, 'table' => 'service_request__' . $name, 'field' => $name,
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
  'plugin_id' => 'field', 'label' => $label, 'exclude' => FALSE,
  'type' => 'boolean', 'settings' => ['format' => 'yes-no', 'format_custom_false' => '', 'format_custom_true' => ''],
  'entity_type' => 'service_request', 'entity_field' => $name,
];

foreach ([
  'field_wants_recurring' => 'Auto list',
  'field_wants_startup' => 'Spring start-up',
  'field_wants_specific_date' => 'Specific date',
] as $name => $label) {
  if (!isset($fields[$name])) {
    $fields[$name] = $boolCol($name, $label);
    $changed[] = "column $name";
  }
}

if (!isset($filters['field_wants_specific_date_value'])) {
  $filters['field_wants_specific_date_value'] = [
    'id' => 'field_wants_specific_date_value', 'table' => 'service_request__field_wants_specific_date',
    'field' => 'field_wants_specific_date_value', 'relationship' => 'none', 'group_type' => 'group',
    'admin_label' => '', 'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1', 'group' => 1,
    'exposed' => TRUE,
    'expose' => [
      'operator_id' => 'field_wants_specific_date_value_op', 'label' => 'Requested a specific date',
      'description' => '', 'use_operator' => FALSE, 'operator' => 'field_wants_specific_date_value_op',
      'operator_limit_selection' => FALSE, 'operator_list' => [], 'identifier' => 'specific_date',
      'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE,
      'remember_roles' => ['authenticated' => 'authenticated'],
    ],
    'is_grouped' => FALSE, 'entity_type' => 'service_request', 'entity_field' => 'field_wants_specific_date',
  ];
  $changed[] = 'filter specific_date';
}

if (!$changed) {
  echo "opt-in columns/filter already present.\n";
  return;
}
$display['default']['display_options']['fields'] = $fields;
$display['default']['display_options']['filters'] = $filters;
$view->set('display', $display);
$view->save();
echo "Added to service_request_admin: " . implode(', ', $changed) . ".\n";
