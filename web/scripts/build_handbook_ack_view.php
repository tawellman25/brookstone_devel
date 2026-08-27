<?php

/**
 * Build the Handbook Acknowledgment LOG — view `handbook_acknowledgments`, page at
 * /admin/operations/handbook-acknowledgments. The raw, sortable, filterable audit
 * trail of online handbook acknowledgments (all versions), complementing the
 * computed status/gap report (bos_handbook_ack). Idempotent (delete + recreate);
 * run per env. Office can refine columns/filters in the Views UI afterward.
 *
 *   drush php:script web/scripts/build_handbook_ack_view.php
 */

use Drupal\user\Entity\Role;
use Drupal\views\Entity\View;

$ENTITY = 'handbook_acknowledgment';
$BASE = 'handbook_acknowledgment_field_data';
$LISTING_PERM = 'access handbook_acknowledgment entity listing';

// Grant the listing permission to office/manager roles (NOT teammates — this is
// the review screen). Teammates only get the signing screen.
foreach (['administration', 'supervisor', 'site_assistant', 'site_admin', 'administrator'] as $rid) {
  $role = Role::load($rid);
  if ($role && !$role->hasPermission($LISTING_PERM)) {
    $role->grantPermission($LISTING_PERM);
    $role->save();
    print "granted '$LISTING_PERM' to $rid\n";
  }
}

if ($existing = View::load('handbook_acknowledgments')) {
  $existing->delete();
  print "removed existing handbook_acknowledgments (rebuild)\n";
}

// Resolve the "Operations" admin-menu link for THIS env (content plugin id).
$handbookParent = '';
foreach (\Drupal::service('plugin.manager.menu.link')->getDefinitions() as $pid => $def) {
  if (($def['menu_name'] ?? '') === 'admin' && strcasecmp((string) ($def['title'] ?? ''), 'operations') === 0) {
    $handbookParent = $pid;
    break;
  }
}

$f = function (string $name, string $label, string $type, array $settings = []): array {
  return [
    'id' => $name, 'table' => 'handbook_acknowledgment__' . $name, 'field' => $name,
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'handbook_acknowledgment', 'entity_field' => $name,
    'plugin_id' => 'field', 'type' => $type, 'label' => $label, 'exclude' => FALSE,
    'settings' => $settings,
  ];
};

$fields = [
  'field_user' => $f('field_user', 'Staff member', 'entity_reference_label', ['link' => TRUE]),
  'field_typed_name' => $f('field_typed_name', 'Signed name', 'string'),
  'field_handbook_version' => $f('field_handbook_version', 'Version', 'string'),
  'field_acknowledged_on' => $f('field_acknowledged_on', 'Acknowledged on', 'timestamp', ['date_format' => 'short', 'custom_date_format' => '', 'timezone' => '']),
  'field_ip' => $f('field_ip', 'IP address', 'string'),
];

$filters = [
  'field_handbook_version_value' => [
    'id' => 'field_handbook_version_value', 'table' => 'handbook_acknowledgment__field_handbook_version',
    'field' => 'field_handbook_version_value', 'relationship' => 'none',
    'entity_type' => 'handbook_acknowledgment', 'entity_field' => 'field_handbook_version',
    'plugin_id' => 'string', 'operator' => 'contains', 'value' => '', 'exposed' => TRUE,
    'expose' => ['operator_id' => 'field_handbook_version_value_op', 'label' => 'Version', 'identifier' => 'version', 'operator' => 'field_handbook_version_value_op'],
  ],
  'field_acknowledged_on_value' => [
    'id' => 'field_acknowledged_on_value', 'table' => 'handbook_acknowledgment__field_acknowledged_on',
    'field' => 'field_acknowledged_on_value', 'relationship' => 'none',
    'entity_type' => 'handbook_acknowledgment', 'entity_field' => 'field_acknowledged_on',
    'plugin_id' => 'date', 'operator' => 'between',
    'value' => ['min' => '', 'max' => '', 'value' => '', 'type' => 'date'],
    'exposed' => TRUE,
    'expose' => ['operator_id' => 'field_acknowledged_on_value_op', 'label' => 'Acknowledged between', 'identifier' => 'on', 'operator' => 'field_acknowledged_on_value_op'],
  ],
];

$info = [];
foreach (['field_user', 'field_typed_name', 'field_handbook_version', 'field_acknowledged_on', 'field_ip'] as $c) {
  $info[$c] = [
    'sortable' => ($c !== 'field_ip'),
    'default_sort_order' => ($c === 'field_acknowledged_on') ? 'desc' : 'asc',
    'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => '',
  ];
}

$default_display = [
  'display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Default', 'position' => 0,
  'display_options' => [
    'title' => 'Handbook Acknowledgment Log',
    'access' => ['type' => 'perm', 'options' => ['perm' => $LISTING_PERM]],
    'cache' => ['type' => 'tag', 'options' => []],
    'query' => ['type' => 'views_query', 'options' => []],
    'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Filter', 'reset_button' => TRUE, 'reset_button_label' => 'Reset']],
    'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50]],
    'style' => ['type' => 'table', 'options' => [
      'grouping' => [], 'row_class' => '', 'default_row_class' => TRUE,
      'columns' => [
        'field_user' => 'field_user', 'field_typed_name' => 'field_typed_name',
        'field_handbook_version' => 'field_handbook_version',
        'field_acknowledged_on' => 'field_acknowledged_on', 'field_ip' => 'field_ip',
      ],
      'default' => 'field_acknowledged_on', 'order' => 'desc', 'info' => $info,
      'sticky' => TRUE, 'summary' => '', 'empty_table' => FALSE, 'caption' => '', 'description' => '',
    ]],
    'row' => ['type' => 'fields'],
    'fields' => $fields,
    'filters' => $filters,
    'sorts' => [],
    'header' => [
      'result' => [
        'id' => 'result', 'table' => 'views', 'field' => 'result', 'relationship' => 'none',
        'group_type' => 'group', 'plugin_id' => 'result', 'content' => 'Total: @total acknowledgment(s)',
      ],
    ],
    'footer' => [],
    'empty' => [
      'area' => [
        'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none',
        'group_type' => 'group', 'plugin_id' => 'text', 'empty' => TRUE,
        'content' => ['value' => 'No handbook acknowledgments recorded yet.', 'format' => 'basic_html'], 'tokenize' => FALSE,
      ],
    ],
    'arguments' => [], 'relationships' => [],
  ],
];

$page_display = [
  'display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Log', 'position' => 1,
  'display_options' => [
    'path' => 'admin/operations/handbook-acknowledgments',
    'menu' => [
      'type' => 'normal', 'title' => 'Handbook Acknowledgment Log',
      'description' => 'Audit trail of online handbook acknowledgments (all versions).',
      'weight' => 21, 'menu_name' => 'admin', 'parent' => $handbookParent, 'context' => '', 'expanded' => FALSE,
    ],
  ],
];

$view = View::create([
  'id' => 'handbook_acknowledgments',
  'label' => 'Handbook Acknowledgments',
  'module' => 'views',
  'description' => 'Audit log of online handbook acknowledgments.',
  'base_table' => $BASE,
  'base_field' => 'id',
  'display' => ['default' => $default_display, 'page_1' => $page_display],
]);
$view->save();
print "created view handbook_acknowledgments at /admin/operations/handbook-acknowledgments";
print $handbookParent ? " (menu under Operations)\n" : " (WARNING: Operations parent not found; top-level admin)\n";
print "Done.\n";
