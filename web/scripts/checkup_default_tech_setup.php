<?php

/**
 * @file
 * Check-up auto-assignment — default sprinkler tech per zipcode. Idempotent setup.
 *
 *   ddev drush php:script web/scripts/checkup_default_tech_setup.php -- --dry-run
 *   ddev drush php:script web/scripts/checkup_default_tech_setup.php -- --tech=2826
 *   ddev drush php:script web/scripts/checkup_default_tech_setup.php -- --rebuild-views   (dev only: recreate the 3 views from here)
 *
 * Why (Todd, 2026-11-14): 135 of 291 check-ups in 2026 sat dated-but-unassigned
 * and were cancelled; 9 of 10 assigned ones were done. The generator already
 * dates each check-up onto the zipcode's route day, so the zipcode now also
 * names the tech. "Set them all to Russell for now until we know who is on the
 * team for next year."
 *
 * Steps (each checks before it writes):
 *   1. zipcodes.field_default_sprinkler_tech (user ref, teammate picker) from
 *      config/sync via the field API — the sync uuid is KEPT so live == sync
 *      and a later partial cim is a no-op (gotcha: uuid mismatch = DELETE+CREATE).
 *   2. Zipcode form display: autocomplete right after the route day; view
 *      display label if that display exists.
 *   3. Views (built here, uuid kept from sync when the YAML exists):
 *        my_check_ups          /teammates/calendar/my-check-ups  (tab beside My Schedule)
 *        scheduling_gaps       /admin/office/work-orders/scheduling/gaps
 *        wo_never_scheduled    /admin/office/work-orders/scheduling/never-scheduled
 *   4. Data: every zipcode whose default is EMPTY → --tech (default 2826,
 *      Russell Akers). Never overwrites a value somebody set.
 *   5. Verify.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Symfony\Component\Yaml\Yaml;

$args = $extra ?? [];
$dry = in_array('--dry-run', $args, TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };
$tech_uid = 2826;
foreach ($args as $a) {
  if (preg_match('/^--tech=(\d+)$/', $a, $m)) {
    $tech_uid = (int) $m[1];
  }
}

const CDT_FIELD = 'field_default_sprinkler_tech';
// WO statuses that count as "still open" — the My Schedule visible set.
const CDT_OPEN_TIDS = [1089, 1099, 1095, 1503, 1091, 1090, 1092, 1093, 1094, 1096];
$OFFICE_ROLES = ['administrator' => 'administrator', 'site_admin' => 'site_admin', 'administration' => 'administration', 'supervisor' => 'supervisor', 'site_assistant' => 'site_assistant'];
$CREW_ROLES = $OFFICE_ROLES + ['teammates' => 'teammates'];

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}

// ── 1. field ────────────────────────────────────────────────────────────
$out('== 1. zipcodes.' . CDT_FIELD . ' ==');
if (FieldStorageConfig::loadByName('zipcodes', CDT_FIELD)) {
  $out('   storage exists');
}
else {
  $data = Yaml::parseFile("$sync/field.storage.zipcodes." . CDT_FIELD . '.yml');
  unset($data['_core']);
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['type']);
  if (!empty($def['class']) && method_exists($def['class'], 'storageSettingsFromConfigData')) {
    $data['settings'] = $def['class']::storageSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldStorageConfig::create($data)->save();
  }
  $out('   storage CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}
if (FieldConfig::loadByName('zipcodes', 'zipcode', CDT_FIELD)) {
  $out('   instance zipcode.' . CDT_FIELD . ' exists');
}
else {
  $data = Yaml::parseFile("$sync/field.field.zipcodes.zipcode." . CDT_FIELD . '.yml');
  unset($data['_core']);
  $def = \Drupal::service('plugin.manager.field.field_type')->getDefinition($data['field_type']);
  if (!empty($def['class']) && method_exists($def['class'], 'fieldSettingsFromConfigData')) {
    $data['settings'] = $def['class']::fieldSettingsFromConfigData($data['settings'] ?? []);
  }
  if (!$dry) {
    FieldConfig::create($data)->save();
  }
  $out('   instance CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}

// ── 2. displays ─────────────────────────────────────────────────────────
$out('== 2. zipcode displays ==');
$form = EntityFormDisplay::load('zipcodes.zipcode.default');
if (!$form) {
  $out('   !! zipcodes.zipcode.default form display missing — skipped');
}
elseif ($form->getComponent(CDT_FIELD)) {
  $out('   form: already on the form');
}
else {
  $anchor = $form->getComponent('field_check_up_route_day');
  $w = $anchor ? (int) $anchor['weight'] + 1 : 10;
  $form->setComponent(CDT_FIELD, ['type' => 'entity_reference_autocomplete', 'weight' => $w, 'region' => 'content', 'settings' => ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => ''], 'third_party_settings' => []]);
  if (!$dry) {
    $form->save();
  }
  $out("   form: autocomplete ADDED @ $w (right after the route day)");
}
$view_display = EntityViewDisplay::load('zipcodes.zipcode.default');
if (!$view_display) {
  $out('   view display: none saved — skipped (fields render by default)');
}
elseif ($view_display->getComponent(CDT_FIELD)) {
  $out('   view display: already shown');
}
else {
  $anchor = $view_display->getComponent('field_check_up_route_day');
  $w = $anchor ? (int) $anchor['weight'] + 1 : 10;
  $view_display->setComponent(CDT_FIELD, ['type' => 'entity_reference_label', 'label' => 'above', 'weight' => $w, 'region' => 'content', 'settings' => ['link' => TRUE], 'third_party_settings' => []]);
  if (!$dry) {
    $view_display->save();
  }
  $out("   view display: label ADDED @ $w");
}

// ── 3. views ────────────────────────────────────────────────────────────
$out('== 3. views ==');
$ALTER = ['alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE, 'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE, 'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '', 'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE, 'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE];
$ELEMENT = ['element_type' => '', 'element_class' => '', 'element_label_type' => '', 'element_label_class' => '', 'element_label_colon' => TRUE, 'element_wrapper_type' => '', 'element_wrapper_class' => '', 'element_default_classes' => TRUE, 'empty' => '', 'hide_empty' => FALSE, 'empty_zero' => FALSE, 'hide_alter_empty' => TRUE];
$FIELD_TAIL = ['click_sort_column' => 'value', 'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE, 'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE, 'delta_first_last' => FALSE, 'multi_type' => 'separator', 'separator' => ', ', 'field_api_classes' => FALSE];
$EXPOSE_OFF = ['operator_id' => '', 'label' => '', 'description' => '', 'use_operator' => FALSE, 'operator' => '', 'operator_limit_selection' => FALSE, 'operator_list' => [], 'identifier' => '', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'], 'reduce' => FALSE];
$GROUP_INFO = ['label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE, 'widget' => 'select', 'multiple' => FALSE, 'remember' => FALSE, 'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => []];

$field = static fn(string $id, string $table, string $label, string $type, array $settings = [], string $rel = 'none', array $extra = []) => [
  'id' => $id, 'table' => $table, 'field' => $id, 'relationship' => $rel, 'group_type' => 'group', 'admin_label' => '',
] + $extra + ['plugin_id' => 'field', 'label' => $label, 'exclude' => FALSE, 'alter' => $ALTER] + $ELEMENT + ['type' => $type, 'settings' => $settings] + $FIELD_TAIL;
$date_field = static fn(string $id, string $table, string $label, string $rel = 'none', string $fmt = 'D m/d/Y') => [
  'id' => $id, 'table' => $table, 'field' => $id, 'relationship' => $rel, 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'date', 'label' => $label, 'exclude' => FALSE, 'alter' => $ALTER,
] + $ELEMENT + ['date_format' => 'custom', 'custom_date_format' => $fmt, 'timezone' => ''];
$filter = static fn(string $id, string $table, string $plugin, string $operator, $value, string $rel = 'none', array $extra = [], array $expose = []) => [
  'id' => $id, 'table' => $table, 'field' => $id, 'relationship' => $rel, 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => $plugin, 'operator' => $operator, 'value' => $value, 'group' => 1,
  'exposed' => !empty($expose), 'expose' => $expose + $EXPOSE_OFF, 'is_grouped' => FALSE, 'group_info' => $GROUP_INFO,
] + $extra;
$sort = static fn(string $id, string $table, string $plugin, string $order, string $rel = 'none', array $extra = []) => [
  'id' => $id, 'table' => $table, 'field' => $id, 'relationship' => $rel, 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => $plugin, 'order' => $order, 'expose' => ['label' => '', 'field_identifier' => $id], 'exposed' => FALSE,
] + $extra + ($plugin === 'date' ? ['granularity' => 'second'] : []);
$open_tids = array_combine(CDT_OPEN_TIDS, CDT_OPEN_TIDS);
// taxonomy_index_tid is a ManyToOne filter: its operators are or / and / not —
// 'in' is silently ignored (no WHERE at all; caught on the first dev probe).
$status_filter = static fn(string $rel, array $expose = []) => $filter('field_status_target_id', 'work_order__field_status', 'taxonomy_index_tid', 'or', $open_tids, $rel,
  ['reduce_duplicates' => FALSE, 'vid' => 'wo_status', 'type' => 'select', 'hierarchy' => FALSE, 'limit' => TRUE, 'error_message' => TRUE], $expose);
$type_filter = static fn(string $rel, array $value, array $expose = []) => $filter('type', 'work_order_field_data', 'bundle', 'in', $value, $rel, ['entity_type' => 'work_order', 'entity_field' => 'type'], $expose);
$rel_wo = ['id' => 'field_work_order', 'table' => 'scheduling__field_work_order', 'field' => 'field_work_order', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => 'Work order', 'plugin_id' => 'standard', 'required' => TRUE];
$rel_property = static fn(string $rel) => ['id' => 'field_property', 'table' => 'work_order__field_property', 'field' => 'field_property', 'relationship' => $rel, 'group_type' => 'group', 'admin_label' => 'Property', 'plugin_id' => 'standard', 'required' => FALSE];
$common = static fn(array $roles, array $pager, array $style, array $empty = []) => [
  'title' => '', 'fields' => [], 'pager' => $pager,
  'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Filter', 'reset_button' => TRUE, 'reset_button_label' => 'Reset', 'exposed_sorts_label' => 'Sort by', 'expose_sort_order' => TRUE, 'sort_asc_label' => 'Asc', 'sort_desc_label' => 'Desc']],
  'access' => ['type' => 'role', 'options' => ['role' => $roles]],
  'cache' => ['type' => 'tag', 'options' => []],
  'empty' => $empty, 'sorts' => [], 'arguments' => [], 'filters' => [], 'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
  'style' => $style, 'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
  'query' => ['type' => 'views_query', 'options' => ['query_comment' => '', 'disable_sql_rewrite' => FALSE, 'distinct' => FALSE, 'replica' => FALSE, 'query_tags' => []]],
  'relationships' => [], 'header' => [], 'footer' => [], 'display_extenders' => [],
];
$empty_text = static fn(string $text) => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => $text, 'tokenize' => FALSE]];
$table_style = static function (array $cols, string $default_sort, array $sortable, array $low = []) {
  $info = [];
  foreach ($cols as $c) {
    $info[$c] = ['sortable' => in_array($c, $sortable, TRUE), 'default_sort_order' => 'asc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => in_array($c, $low, TRUE) ? 'priority-low' : ''];
  }
  return ['type' => 'table', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE, 'columns' => array_combine($cols, $cols), 'default' => $default_sort, 'info' => $info, 'override' => TRUE, 'sticky' => TRUE, 'summary' => '', 'empty_table' => TRUE, 'caption' => '', 'description' => '']];
};
$mini_pager = ['type' => 'mini', 'options' => ['offset' => 0, 'pagination_heading_level' => 'h4', 'items_per_page' => 50, 'total_pages' => NULL, 'id' => 0, 'tags' => ['next' => 'Older ››', 'previous' => '‹‹ Newer'], 'expose' => ['items_per_page' => FALSE, 'items_per_page_label' => 'Items per page', 'items_per_page_options' => '5, 10, 25, 50', 'items_per_page_options_all' => FALSE, 'items_per_page_options_all_label' => '- All -', 'offset' => FALSE, 'offset_label' => 'Offset']]];
$wo_title = static fn(string $rel) => $field('title', 'work_order_field_data', 'Work order', 'string', ['link_to_entity' => TRUE], $rel, ['entity_type' => 'work_order', 'entity_field' => 'title']);
$wo_type = static fn(string $rel) => $field('type', 'work_order_field_data', 'Type', 'entity_reference_label', ['link' => FALSE], $rel, ['entity_type' => 'work_order', 'entity_field' => 'type']);
$wo_status = static fn(string $rel) => $field('field_status', 'work_order__field_status', 'Status', 'entity_reference_label', ['link' => FALSE], $rel);
$wo_service = static fn(string $rel) => $field('field_service', 'work_order__field_service', 'Service', 'entity_reference_label', ['link' => FALSE], $rel);
$wo_total = static fn(string $rel) => $field('field_wo_total', 'work_order__field_wo_total', 'WO total', 'number_decimal', ['thousand_separator' => ',', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE], $rel);
$prop_nick = $field('field_nickname', 'properties__field_nickname', 'Property', 'string', ['link_to_entity' => TRUE], 'field_property');
$prop_addr = $field('field_full_address', 'properties__field_full_address', 'Address', 'string', ['link_to_entity' => FALSE], 'field_property');

$views = [];

// 3a. my_check_ups — the tech's backlog, a tab beside My Schedule.
$d = $common($CREW_ROLES, ['type' => 'some', 'options' => ['offset' => 0, 'items_per_page' => 200]], ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE, 'uses_fields' => FALSE]], $empty_text('No open check-ups are assigned to you.'));
$d['title'] = 'My Check-ups';
$d['relationships'] = ['field_work_order' => $rel_wo, 'field_property' => $rel_property('field_work_order')];
$d['fields'] = [
  'title' => $wo_title('field_work_order'),
  'field_date_value' => $date_field('field_date_value', 'scheduling__field_date', 'Scheduled'),
  'field_nickname' => $prop_nick,
  'field_full_address' => $prop_addr,
  'field_status' => $wo_status('field_work_order'),
];
$d['arguments'] = ['field_assigned_to_target_id' => [
  'id' => 'field_assigned_to_target_id', 'table' => 'scheduling__field_assigned_to', 'field' => 'field_assigned_to_target_id', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'entity_target_id',
  'default_action' => 'default', 'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'], 'title_enable' => FALSE, 'title' => '',
  'default_argument_type' => 'current_user', 'default_argument_options' => [], 'summary_options' => ['base_path' => '', 'count' => TRUE, 'override' => FALSE, 'items_per_page' => 25],
  'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'], 'specify_validation' => FALSE, 'validate' => ['type' => 'none', 'fail' => 'not found'], 'validate_options' => [],
  'break_phrase' => FALSE, 'not' => FALSE, 'target_entity_type_id' => 'user',
]];
$d['filters'] = ['type' => $type_filter('field_work_order', ['sprinkler_check_up' => 'sprinkler_check_up']), 'field_status_target_id' => $status_filter('field_work_order')];
$d['sorts'] = ['field_date_value' => $sort('field_date_value', 'scheduling__field_date', 'date', 'ASC')];
$views['my_check_ups'] = [
  'label' => 'My Check-ups', 'description' => 'Open irrigation check-ups assigned to the current user, overdue first — the tech\'s backlog (check-ups are auto-assigned by zipcode default tech). Rendered as My Schedule-style cards by bos_scheduling.', 'tag' => 'scheduling',
  'base_table' => 'scheduling_field_data', 'default' => $d,
  'page' => ['path' => 'teammates/calendar/my-check-ups', 'menu' => ['type' => 'tab', 'title' => 'My Check-ups', 'description' => 'Open check-ups assigned to you, overdue first.', 'weight' => 2, 'enabled' => TRUE, 'menu_name' => 'main', 'context' => '', 'expanded' => FALSE, 'parent' => '']],
];

// 3b. scheduling_gaps — dated in the past, nobody assigned, WO still open.
$cols = ['field_date_value', 'title', 'type', 'field_nickname', 'field_full_address', 'field_status', 'field_wo_total'];
$d = $common($OFFICE_ROLES, $mini_pager, $table_style($cols, 'field_date_value', ['field_date_value', 'title', 'type', 'field_nickname', 'field_status', 'field_wo_total'], ['field_full_address']), $empty_text('Nothing is falling through: no past-dated stop is without an assignee.'));
$d['title'] = 'Scheduling gaps — dated, nobody assigned';
$d['relationships'] = ['field_work_order' => $rel_wo, 'field_property' => $rel_property('field_work_order')];
$d['fields'] = [
  'field_date_value' => $date_field('field_date_value', 'scheduling__field_date', 'Scheduled for', 'none', 'D m/d/Y'),
  'title' => $wo_title('field_work_order'),
  'type' => $wo_type('field_work_order'),
  'field_nickname' => $prop_nick,
  'field_full_address' => $prop_addr,
  'field_status' => $wo_status('field_work_order'),
  'field_wo_total' => $wo_total('field_work_order'),
];
$d['filters'] = [
  'field_assigned_to_target_id' => $filter('field_assigned_to_target_id', 'scheduling__field_assigned_to', 'numeric', 'empty', ['min' => '', 'max' => '', 'value' => '']),
  'field_date_value' => $filter('field_date_value', 'scheduling__field_date', 'date', '<', ['min' => '', 'max' => '', 'value' => 'today', 'type' => 'offset']),
  'type' => $type_filter('field_work_order', [], ['operator_id' => 'type_op', 'label' => 'Work order type', 'identifier' => 'type', 'multiple' => TRUE, 'remember_roles' => ['authenticated' => 'authenticated']]),
  'field_status_target_id' => $status_filter('field_work_order', ['operator_id' => 'field_status_target_id_op', 'label' => 'Work order status', 'identifier' => 'status', 'multiple' => TRUE, 'remember_roles' => ['authenticated' => 'authenticated']]),
];
$d['sorts'] = ['field_date_value' => $sort('field_date_value', 'scheduling__field_date', 'date', 'ASC')];
$d['header'] = ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text_custom', 'empty' => FALSE, 'content' => 'Scheduled stops whose date has passed and that name no one. They are on nobody\'s My Schedule and look scheduled to the office — 135 check-ups were lost this way in 2026. Assign them, re-date them, or close the work order.', 'tokenize' => FALSE]];
$views['scheduling_gaps'] = [
  'label' => 'Scheduling gaps — dated, nobody assigned', 'description' => 'Scheduling records dated in the past with no assignee whose work order is still open. The office backstop for check-up auto-assignment.', 'tag' => 'scheduling',
  'base_table' => 'scheduling_field_data', 'default' => $d,
  'page' => ['path' => 'admin/office/work-orders/scheduling/gaps', 'menu' => ['type' => 'normal', 'title' => 'Gaps: dated, nobody assigned', 'description' => 'Past-dated scheduled stops that name no one.', 'weight' => 30, 'enabled' => TRUE, 'menu_name' => 'admin', 'context' => '', 'expanded' => FALSE, 'parent' => 'bos_scheduling.scheduling_hub']],
];

// 3c. wo_never_scheduled — open 90+ days with no scheduling record at all.
$cols = ['created', 'title', 'type', 'field_nickname', 'field_full_address', 'field_status', 'field_wo_total'];
$d = $common($OFFICE_ROLES, $mini_pager, $table_style($cols, 'created', ['created', 'title', 'type', 'field_nickname', 'field_status', 'field_wo_total'], ['field_full_address']), $empty_text('Every open work order older than 90 days has a scheduling record.'));
$d['title'] = 'Open 90+ days, never scheduled';
$d['relationships'] = [
  'reverse__scheduling__field_work_order' => ['id' => 'reverse__scheduling__field_work_order', 'table' => 'work_order_field_data', 'field' => 'reverse__scheduling__field_work_order', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => 'Scheduling record', 'plugin_id' => 'entity_reverse', 'required' => FALSE, 'entity_type' => 'work_order'],
  'field_property' => $rel_property('none'),
];
$d['fields'] = [
  'created' => $field('created', 'work_order_field_data', 'Created', 'timestamp', ['date_format' => 'custom', 'custom_date_format' => 'm/d/Y', 'timezone' => '', 'tooltip' => ['date_format' => '', 'custom_date_format' => ''], 'time_diff' => ['enabled' => FALSE, 'future_format' => '@interval hence', 'past_format' => '@interval ago', 'granularity' => 2, 'refresh' => 60]], 'none', ['entity_type' => 'work_order', 'entity_field' => 'created']),
  'title' => $wo_title('none'),
  'type' => $wo_type('none'),
  'field_nickname' => $prop_nick,
  'field_full_address' => $prop_addr,
  'field_status' => $wo_status('none'),
  'field_wo_total' => $wo_total('none'),
];
$d['filters'] = [
  'id' => $filter('id', 'scheduling_field_data', 'numeric', 'empty', ['min' => '', 'max' => '', 'value' => ''], 'reverse__scheduling__field_work_order', ['entity_type' => 'scheduling', 'entity_field' => 'id']),
  'created' => $filter('created', 'work_order_field_data', 'date', '<', ['min' => '', 'max' => '', 'value' => '-90 days', 'type' => 'offset'], 'none', ['entity_type' => 'work_order', 'entity_field' => 'created']),
  'type' => $type_filter('none', [], ['operator_id' => 'type_op', 'label' => 'Work order type', 'identifier' => 'type', 'multiple' => TRUE, 'remember_roles' => ['authenticated' => 'authenticated']]),
  'field_status_target_id' => $status_filter('none', ['operator_id' => 'field_status_target_id_op', 'label' => 'Work order status', 'identifier' => 'status', 'multiple' => TRUE, 'remember_roles' => ['authenticated' => 'authenticated']]),
];
$d['sorts'] = ['created' => $sort('created', 'work_order_field_data', 'date', 'ASC', 'none', ['entity_type' => 'work_order', 'entity_field' => 'created'])];
$d['header'] = ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text_custom', 'empty' => FALSE, 'content' => 'Open work orders created more than 90 days ago that have never had a scheduling record. Schedule them or close them.', 'tokenize' => FALSE]];
$views['wo_never_scheduled'] = [
  'label' => 'Open 90+ days, never scheduled', 'description' => 'Open work orders older than 90 days with no scheduling record at all. Companion to scheduling_gaps.', 'tag' => 'scheduling',
  'base_table' => 'work_order_field_data', 'default' => $d,
  'page' => ['path' => 'admin/office/work-orders/scheduling/never-scheduled', 'menu' => ['type' => 'normal', 'title' => 'Gaps: never scheduled', 'description' => 'Open 90+ days with no scheduling record.', 'weight' => 31, 'enabled' => TRUE, 'menu_name' => 'admin', 'context' => '', 'expanded' => FALSE, 'parent' => 'bos_scheduling.scheduling_hub']],
];

foreach ($views as $vid => $v) {
  $definition = [
    'langcode' => 'en', 'status' => TRUE, 'dependencies' => [], 'id' => $vid, 'label' => $v['label'], 'module' => 'views', 'description' => $v['description'], 'tag' => $v['tag'],
    'base_table' => $v['base_table'], 'base_field' => 'id',
    'display' => [
      'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $v['default'], 'cache_metadata' => ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user', 'user.roles'], 'tags' => []]],
      'page_1' => ['id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => ['display_extenders' => [], 'path' => $v['page']['path'], 'menu' => $v['page']['menu']], 'cache_metadata' => ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url.query_args', 'user', 'user.roles'], 'tags' => []]],
    ],
  ];
  $file = "$sync/views.view.$vid.yml";
  if (is_file($file) && ($uuid = Yaml::parseFile($file)['uuid'] ?? NULL)) {
    $definition['uuid'] = $uuid;
    $out("   $vid: uuid $uuid kept from sync");
  }
  if ($existing = View::load($vid)) {
    if (!in_array('--rebuild-views', $args, TRUE)) {
      $out("   $vid: exists — left as is (Views UI owns it now; --rebuild-views recreates from this script)");
      continue;
    }
    if (!$dry) {
      $existing->delete();
    }
    $out("   $vid: existing view DELETED for rebuild");
  }
  if (!$dry) {
    View::create($definition)->save();
  }
  $out("   $vid: CREATED at /" . $v['page']['path']);
}
if (!$dry) {
  \Drupal::service('router.builder')->rebuild();
  \Drupal::service('plugin.manager.menu.link')->rebuild();
  $out('   router + menu links rebuilt');
}

// ── 4. data ─────────────────────────────────────────────────────────────
$out('== 4. zipcode defaults ==');
$tech = User::load($tech_uid);
if (!$tech || !$tech->isActive() || !$tech->hasRole('teammates')) {
  $out("   !! uid $tech_uid is not an active teammate — data step skipped");
}
elseif (!FieldConfig::loadByName('zipcodes', 'zipcode', CDT_FIELD) && $dry) {
  $out('   (field not created yet in a dry run — would set ' . $tech->getDisplayName() . ' on every zipcode with an empty default)');
}
else {
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  $set = 0; $kept = 0;
  foreach (\Drupal::entityTypeManager()->getStorage('zipcodes')->loadMultiple() as $z) {
    if ($z->get(CDT_FIELD)->isEmpty()) {
      if (!$dry) {
        $z->set(CDT_FIELD, $tech_uid)->save();
      }
      $set++;
    }
    else {
      $kept++;
    }
  }
  $out("   {$tech->getDisplayName()} (uid $tech_uid) set on $set zipcode(s); $kept already had a default (kept)");
}

// ── 5. verify ───────────────────────────────────────────────────────────
$out('== 5. verify ==');
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('zipcodes', 'zipcode');
$out('   field on zipcodes: ' . (isset($defs[CDT_FIELD]) ? 'yes' : 'NO'));
$form = EntityFormDisplay::load('zipcodes.zipcode.default');
$out('   on the zipcode form: ' . ($form && $form->getComponent(CDT_FIELD) ? 'yes' : 'NO'));
if (isset($defs[CDT_FIELD])) {
  $lines = [];
  foreach (\Drupal::entityTypeManager()->getStorage('zipcodes')->loadMultiple() as $z) {
    $u = $z->get(CDT_FIELD)->entity;
    $lines[] = $z->label() . '→' . ($u ? $u->getDisplayName() : '—');
  }
  $out('   defaults: ' . implode(' · ', $lines));
}
foreach (array_keys($views) as $vid) {
  $view = View::load($vid);
  if (!$view) {
    $out("   view $vid: " . ($dry ? '(would exist after a real run)' : 'MISSING'));
    continue;
  }
  try {
    $exec = $view->getExecutable();
    $exec->setDisplay('page_1');
    if ($vid === 'my_check_ups') {
      $exec->setArguments([$tech_uid]);
    }
    $exec->execute();
    $out("   view $vid: executes, " . count($exec->result) . ' row(s) on this environment, path /' . $exec->getDisplay()->getOption('path'));
  }
  catch (\Throwable $e) {
    $out("   !! view $vid failed: " . $e->getMessage());
  }
}
$out('done');
