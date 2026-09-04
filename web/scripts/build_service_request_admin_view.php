<?php

/**
 * Build the office review queue: view `service_request_admin`, page at
 * /admin/operations/service-requests. Idempotent (delete + recreate). Run per
 * env. The office can refine columns/filters/sort in the Views UI afterward.
 *
 *   drush php:script web/scripts/build_service_request_admin_view.php
 */

use Drupal\views\Entity\View;

if ($existing = View::load('service_request_admin')) {
  $existing->delete();
  print "removed existing service_request_admin (rebuild)\n";
}

// Resolve the "Office" admin-menu link for THIS env (content UUID differs per
// env; matched by title then the stable page_manager office route).
$officeParent = '';
$mlm = \Drupal::service('plugin.manager.menu.link');
foreach ($mlm->getDefinitions() as $pid => $def) {
  if (($def['menu_name'] ?? '') === 'admin' && strcasecmp((string) ($def['title'] ?? ''), 'office') === 0) {
    $officeParent = $pid;
    break;
  }
}
if ($officeParent === '') {
  foreach ($mlm->getDefinitions() as $pid => $def) {
    if (($def['menu_name'] ?? '') === 'admin' && ($def['route_name'] ?? '') === 'page_manager.page_view_office_administation_office_administation-layout_builder-0') {
      $officeParent = $pid;
      break;
    }
  }
}

$field = function (string $name, string $label, string $type, ?string $table = NULL, array $settings = []): array {
  return [
    'id' => $name,
    'table' => $table ?? ('service_request__' . $name),
    'field' => $name,
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'entity_type' => 'service_request',
    'entity_field' => $name,
    'plugin_id' => 'field',
    'type' => $type,
    'label' => $label,
    'exclude' => FALSE,
    'settings' => $settings,
  ];
};

$fields = [
  // Ref links through to the request's own page (/service_request/{id}).
  'field_public_ref' => $field('field_public_ref', 'Ref', 'string', NULL, ['link_to_entity' => TRUE]),
  'created' => [
    'id' => 'created', 'table' => 'service_request_field_data', 'field' => 'created',
    'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'service_request',
    'entity_field' => 'created', 'plugin_id' => 'field', 'type' => 'timestamp',
    'label' => 'Submitted', 'settings' => ['date_format' => 'short', 'tz' => ''],
  ],
  // Name also links to the request page. Contact columns follow so the office
  // can actually reach the customer from this list.
  'field_submitted_name' => $field('field_submitted_name', 'Name', 'string', NULL, ['link_to_entity' => TRUE]),
  'field_submitted_phone' => $field('field_submitted_phone', 'Phone', 'telephone_link'),
  'field_submitted_email' => $field('field_submitted_email', 'Email', 'basic_string'),
  'field_submitted_address' => $field('field_submitted_address', 'Address', 'string'),
  'field_submitted_zip' => $field('field_submitted_zip', 'ZIP', 'string'),
  'field_property' => $field('field_property', 'Matched property', 'entity_reference_label'),
  'field_request_status' => $field('field_request_status', 'Status', 'entity_reference_label'),
  'field_source' => $field('field_source', 'Source', 'list_default'),
  'field_campaign' => $field('field_campaign', 'Campaign', 'string'),
  'field_review_flags' => $field('field_review_flags', 'Flags', 'basic_string'),
  'field_work_order' => $field('field_work_order', 'Work order', 'entity_reference_label'),
  'operations' => [
    'id' => 'operations', 'table' => 'service_request', 'field' => 'operations',
    'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'service_request',
    'plugin_id' => 'entity_operations', 'label' => 'Actions', 'destination' => TRUE,
  ],
];

$filters = [
  'field_service_year_value' => [
    'id' => 'field_service_year_value', 'table' => 'service_request__field_service_year',
    'field' => 'field_service_year_value', 'relationship' => 'none', 'entity_type' => 'service_request',
    'entity_field' => 'field_service_year', 'plugin_id' => 'numeric', 'operator' => '=',
    'value' => ['min' => '', 'max' => '', 'value' => ''],
    'exposed' => TRUE,
    'expose' => ['operator_id' => 'field_service_year_value_op', 'label' => 'Service year', 'identifier' => 'year', 'operator' => 'field_service_year_value_op'],
  ],
  'field_source_value' => [
    'id' => 'field_source_value', 'table' => 'service_request__field_source', 'field' => 'field_source_value',
    'relationship' => 'none', 'entity_type' => 'service_request', 'entity_field' => 'field_source',
    'plugin_id' => 'list_field', 'operator' => 'or', 'value' => [], 'exposed' => TRUE,
    'expose' => ['operator_id' => 'field_source_value_op', 'label' => 'Source', 'identifier' => 'source', 'multiple' => FALSE, 'reduce' => FALSE],
  ],
  'field_campaign_value' => [
    'id' => 'field_campaign_value', 'table' => 'service_request__field_campaign', 'field' => 'field_campaign_value',
    'relationship' => 'none', 'entity_type' => 'service_request', 'entity_field' => 'field_campaign',
    'plugin_id' => 'string', 'operator' => 'contains', 'value' => '', 'exposed' => TRUE,
    'expose' => ['operator_id' => 'field_campaign_value_op', 'label' => 'Campaign', 'identifier' => 'campaign'],
  ],
  // Request type (bundle) dropdown — Winterize vs Fall Cleanup, etc.
  'type' => [
    'id' => 'type', 'table' => 'service_request_field_data', 'field' => 'type',
    'relationship' => 'none', 'entity_type' => 'service_request', 'entity_field' => 'type',
    'plugin_id' => 'bundle', 'operator' => 'in', 'value' => [], 'exposed' => TRUE,
    'expose' => [
      'operator_id' => 'type_op', 'label' => 'Type', 'identifier' => 'type',
      'operator' => 'type_op', 'multiple' => FALSE, 'reduce' => FALSE,
    ],
  ],
  // Status dropdown (scoped to the service_request_status vocabulary).
  'field_request_status_target_id' => [
    'id' => 'field_request_status_target_id', 'table' => 'service_request__field_request_status',
    'field' => 'field_request_status_target_id', 'relationship' => 'none',
    'entity_type' => 'service_request', 'entity_field' => 'field_request_status',
    'plugin_id' => 'taxonomy_index_tid', 'operator' => 'or', 'value' => [],
    'vid' => 'service_request_status', 'type' => 'select', 'limit' => TRUE,
    'hierarchy' => FALSE, 'error_message' => TRUE,
    'exposed' => TRUE,
    'expose' => [
      'operator_id' => 'field_request_status_target_id_op', 'label' => 'Status',
      'identifier' => 'status', 'operator' => 'field_request_status_target_id_op',
      'multiple' => FALSE, 'reduce' => FALSE,
    ],
  ],
];

$default_display = [
  'display_plugin' => 'default',
  'id' => 'default',
  'display_title' => 'Default',
  'position' => 0,
  'display_options' => [
    'title' => 'Service Requests',
    'access' => ['type' => 'perm', 'options' => ['perm' => 'administer service requests']],
    'cache' => ['type' => 'tag', 'options' => []],
    'query' => ['type' => 'views_query', 'options' => []],
    'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Filter', 'reset_button' => TRUE]],
    'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50]],
    // Unformatted rows — each row becomes a status card via the row template
    // views-view-fields--service-request-admin.html.twig (bos_service_request).
    'style' => ['type' => 'default', 'options' => []],
    'row' => ['type' => 'fields'],
    'fields' => $fields,
    'filters' => $filters,
    'sorts' => [
      'created' => [
        'id' => 'created', 'table' => 'service_request_field_data', 'field' => 'created',
        'relationship' => 'none', 'entity_type' => 'service_request', 'entity_field' => 'created',
        'plugin_id' => 'date', 'order' => 'ASC',
      ],
    ],
    'header' => [], 'footer' => [], 'empty' => [], 'arguments' => [], 'relationships' => [],
  ],
];

$page_display = [
  'display_plugin' => 'page',
  'id' => 'page_1',
  'display_title' => 'Queue',
  'position' => 1,
  'display_options' => [
    'path' => 'admin/office/service-requests',
    // Nested under the "Office" admin menu item (resolved per env above).
    'menu' => ['type' => 'normal', 'title' => 'Service Requests', 'description' => 'Public service-request intake queue.', 'weight' => -5, 'menu_name' => 'admin', 'parent' => $officeParent, 'context' => '', 'expanded' => FALSE],
  ],
];

$view = View::create([
  'id' => 'service_request_admin',
  'label' => 'Service Request Admin',
  'module' => 'views',
  'description' => 'Office review queue for public service requests.',
  'base_table' => 'service_request_field_data',
  'base_field' => 'id',
  'display' => ['default' => $default_display, 'page_1' => $page_display],
]);
$view->save();
print "created view service_request_admin at /admin/operations/service-requests\n";
