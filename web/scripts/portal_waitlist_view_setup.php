<?php

/**
 * @file
 * Portal waitlist lead list — idempotent setup of views.view.portal_waitlist_leads.
 *
 *   drush php:script web/scripts/portal_waitlist_view_setup.php -- --dry-run
 *   drush php:script web/scripts/portal_waitlist_view_setup.php
 *
 * Why: OFF-ADM-WTL-001 (2026-11-13) tells the office to work waitlist leads,
 * but nothing listed them — the portal fields on contacts were created by
 * script, no view existed, and only site_admin may open the raw contacts
 * listing. This is a plain View (office keeps Views-UI control of filters and
 * columns): contacts with Portal waitlist interest = Interested, newest sign-up
 * first, with email, the details note and the three consent flags.
 *
 * Path /admin/office/portal-waitlist under the Office menu (parent resolved at
 * run time from the "Office" admin menu link, as build_service_request_admin_view.php
 * does). If config/sync carries the view, its uuid is kept so a later partial
 * cim is a no-op (gotchas: "A UUID mismatch makes cim DELETE + CREATE").
 */

use Drupal\views\Entity\View;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };

const PW_VIEW = 'portal_waitlist_leads';
const PW_PATH = 'admin/office/portal-waitlist';
$roles = ['administrator' => 'administrator', 'site_admin' => 'site_admin', 'administration' => 'administration', 'supervisor' => 'supervisor'];

// Office menu link (menu_link_content pointing at /admin/office).
$officeParent = '';
$ids = \Drupal::entityQuery('menu_link_content')->accessCheck(FALSE)->condition('menu_name', 'admin')->execute();
foreach (\Drupal::entityTypeManager()->getStorage('menu_link_content')->loadMultiple($ids) as $l) {
  if ($l->getUrlObject()->toString() === '/admin/office') {
    $officeParent = 'menu_link_content:' . $l->uuid();
    break;
  }
}
$out('Office menu parent: ' . ($officeParent ?: 'NOT FOUND (menu link will sit at the admin root)'));

$textField = static fn(string $name, string $label, string $type = 'basic_string', array $settings = []) => [
  'id' => $name, 'table' => 'contacts__' . $name, 'field' => $name, 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
  'plugin_id' => 'field', 'label' => $label, 'exclude' => FALSE,
  'alter' => ['alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE, 'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE, 'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '', 'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE, 'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE],
  'element_type' => '', 'element_class' => '', 'element_label_type' => '', 'element_label_class' => '', 'element_label_colon' => TRUE, 'element_wrapper_type' => '', 'element_wrapper_class' => '', 'element_default_classes' => TRUE,
  'empty' => '', 'hide_empty' => FALSE, 'empty_zero' => FALSE, 'hide_alter_empty' => TRUE,
  'click_sort_column' => 'value', 'type' => $type, 'settings' => $settings, 'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE, 'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE, 'delta_first_last' => FALSE, 'multi_type' => 'separator', 'separator' => ', ', 'field_api_classes' => FALSE,
];
$bool = static fn(string $name, string $label) => $textField($name, $label, 'boolean', ['format' => 'custom', 'format_custom_false' => 'No', 'format_custom_true' => 'Opted in']);

$fields = [
  'title' => ['id' => 'title', 'table' => 'contacts_field_data', 'field' => 'title', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'entity_type' => 'contacts', 'entity_field' => 'title', 'plugin_id' => 'field', 'label' => 'Contact', 'exclude' => FALSE,
    'alter' => $textField('x', 'x')['alter'], 'element_type' => '', 'element_class' => '', 'element_label_type' => '', 'element_label_class' => '', 'element_label_colon' => TRUE, 'element_wrapper_type' => '', 'element_wrapper_class' => '', 'element_default_classes' => TRUE, 'empty' => '', 'hide_empty' => FALSE, 'empty_zero' => FALSE, 'hide_alter_empty' => TRUE,
    'click_sort_column' => 'value', 'type' => 'string', 'settings' => ['link_to_entity' => TRUE], 'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE, 'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE, 'delta_first_last' => FALSE, 'multi_type' => 'separator', 'separator' => ', ', 'field_api_classes' => FALSE],
  'field_email' => $textField('field_email', 'Email', 'email_mailto'),
  'field_portal_interest_date' => $textField('field_portal_interest_date', 'Signed up', 'datetime_custom', ['timezone_override' => '', 'date_format' => 'm/d/Y g:i A']),
  'field_portal_interest_note' => $textField('field_portal_interest_note', 'Portal waitlist details'),
  'field_opt_in_service_email' => $bool('field_opt_in_service_email', 'Service email OK'),
  'field_opt_in_marketing_email' => $bool('field_opt_in_marketing_email', 'Marketing email OK'),
  'field_opt_in_service_sms' => $bool('field_opt_in_service_sms', 'Service text/SMS OK'),
  'field_consent_updated' => $textField('field_consent_updated', 'Consent last updated', 'datetime_custom', ['timezone_override' => '', 'date_format' => 'm/d/Y']),
];
$columns = array_combine(array_keys($fields), array_keys($fields));
$info = [];
foreach (array_keys($fields) as $f) {
  $info[$f] = ['sortable' => in_array($f, ['title', 'field_email', 'field_portal_interest_date', 'field_consent_updated'], TRUE), 'default_sort_order' => 'desc', 'align' => '', 'separator' => '', 'empty_column' => FALSE, 'responsive' => $f === 'field_portal_interest_note' ? 'priority-low' : ''];
}

$definition = [
  'langcode' => 'en', 'status' => TRUE,
  'dependencies' => ['module' => ['eck', 'user', 'views', 'datetime', 'options']],
  'id' => PW_VIEW, 'label' => 'Portal waitlist leads',
  'module' => 'views',
  'description' => 'Contacts who signed up on the login-page customer-portal waitlist (field_portal_interest = Interested): sign-up date, details note, consent flags. OFF-ADM-WTL-001.',
  'tag' => 'office', 'base_table' => 'contacts_field_data', 'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0,
      'display_options' => [
        'title' => 'Portal waitlist',
        'fields' => $fields,
        'pager' => ['type' => 'mini', 'options' => ['offset' => 0, 'pagination_heading_level' => 'h4', 'items_per_page' => 50, 'total_pages' => NULL, 'id' => 0, 'tags' => ['next' => 'Older ››', 'previous' => '‹‹ Newer'], 'expose' => ['items_per_page' => FALSE, 'items_per_page_label' => 'Items per page', 'items_per_page_options' => '5, 10, 25, 50', 'items_per_page_options_all' => FALSE, 'items_per_page_options_all_label' => '- All -', 'offset' => FALSE, 'offset_label' => 'Offset']]],
        'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Filter', 'reset_button' => TRUE, 'reset_button_label' => 'Reset', 'exposed_sorts_label' => 'Sort by', 'expose_sort_order' => TRUE, 'sort_asc_label' => 'Asc', 'sort_desc_label' => 'Desc']],
        'access' => ['type' => 'role', 'options' => ['role' => $roles]],
        'cache' => ['type' => 'tag', 'options' => []],
        'empty' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => 'No waitlist sign-ups match. The public panel is on /user/login.', 'tokenize' => FALSE]],
        'sorts' => ['field_portal_interest_date_value' => ['id' => 'field_portal_interest_date_value', 'table' => 'contacts__field_portal_interest_date', 'field' => 'field_portal_interest_date_value', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'datetime', 'order' => 'DESC', 'expose' => ['label' => '', 'field_identifier' => 'field_portal_interest_date_value'], 'exposed' => FALSE, 'granularity' => 'second']],
        'arguments' => [],
        'filters' => [
          'field_portal_interest_value' => ['id' => 'field_portal_interest_value', 'table' => 'contacts__field_portal_interest', 'field' => 'field_portal_interest_value', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1', 'group' => 1, 'exposed' => FALSE, 'expose' => ['operator_id' => '', 'label' => '', 'description' => '', 'use_operator' => FALSE, 'operator' => '', 'operator_limit_selection' => FALSE, 'operator_list' => [], 'identifier' => '', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated']], 'is_grouped' => FALSE, 'group_info' => ['label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE, 'widget' => 'select', 'multiple' => FALSE, 'remember' => FALSE, 'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => []]],
          'title' => ['id' => 'title', 'table' => 'contacts_field_data', 'field' => 'title', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'entity_type' => 'contacts', 'entity_field' => 'title', 'plugin_id' => 'string', 'operator' => 'contains', 'value' => '', 'group' => 1, 'exposed' => TRUE, 'expose' => ['operator_id' => 'title_op', 'label' => 'Name', 'description' => '', 'use_operator' => FALSE, 'operator' => 'title_op', 'operator_limit_selection' => FALSE, 'operator_list' => [], 'identifier' => 'name', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'], 'placeholder' => ''], 'is_grouped' => FALSE, 'group_info' => ['label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE, 'widget' => 'select', 'multiple' => FALSE, 'remember' => FALSE, 'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => []]],
          'field_email_value' => ['id' => 'field_email_value', 'table' => 'contacts__field_email', 'field' => 'field_email_value', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'string', 'operator' => 'contains', 'value' => '', 'group' => 1, 'exposed' => TRUE, 'expose' => ['operator_id' => 'field_email_value_op', 'label' => 'Email', 'description' => '', 'use_operator' => FALSE, 'operator' => 'field_email_value_op', 'operator_limit_selection' => FALSE, 'operator_list' => [], 'identifier' => 'email', 'required' => FALSE, 'remember' => FALSE, 'multiple' => FALSE, 'remember_roles' => ['authenticated' => 'authenticated'], 'placeholder' => ''], 'is_grouped' => FALSE, 'group_info' => ['label' => '', 'description' => '', 'identifier' => '', 'optional' => TRUE, 'widget' => 'select', 'multiple' => FALSE, 'remember' => FALSE, 'default_group' => 'All', 'default_group_multiple' => [], 'group_items' => []]],
        ],
        'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
        'style' => ['type' => 'table', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE, 'columns' => $columns, 'default' => 'field_portal_interest_date', 'info' => $info, 'override' => TRUE, 'sticky' => TRUE, 'summary' => '', 'empty_table' => TRUE, 'caption' => '', 'description' => '']],
        'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
        'query' => ['type' => 'views_query', 'options' => ['query_comment' => '', 'disable_sql_rewrite' => FALSE, 'distinct' => FALSE, 'replica' => FALSE, 'query_tags' => []]],
        'relationships' => [],
        'header' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'plugin_id' => 'text_custom', 'empty' => FALSE, 'content' => 'Everyone who signed up on the login-page customer-portal waitlist, newest first. Signing up grants Service email only; Marketing email and Service SMS are set only when ticked. No customer email goes out from BOS until the send-time consent check exists — see OFF-ADM-WTL-001.', 'tokenize' => FALSE]],
        'footer' => [],
        'display_extenders' => [],
      ],
      'cache_metadata' => ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url', 'url.query_args', 'user.roles'], 'tags' => []],
    ],
    'page_1' => [
      'id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1,
      'display_options' => [
        'display_extenders' => [],
        'path' => PW_PATH,
        'menu' => ['type' => 'normal', 'title' => 'Portal waitlist', 'description' => 'Customer-portal waitlist leads from the login page.', 'weight' => 20, 'menu_name' => 'admin', 'parent' => $officeParent, 'context' => '', 'expanded' => FALSE],
      ],
      'cache_metadata' => ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url', 'url.query_args', 'user.roles'], 'tags' => []],
    ],
  ],
];

$out('== view ' . PW_VIEW . ' ==');
$sync = realpath(DRUPAL_ROOT . '/../config/sync') . '/views.view.' . PW_VIEW . '.yml';
if (is_file($sync)) {
  $uuid = Yaml::parseFile($sync)['uuid'] ?? NULL;
  if ($uuid) {
    $definition['uuid'] = $uuid;
    $out('   uuid ' . $uuid . ' kept from sync');
  }
}
$view = View::load(PW_VIEW);
if ($view) {
  $cur = $view->getDisplay('page_1')['display_options']['menu']['parent'] ?? '';
  if ($officeParent && $cur !== $officeParent) {
    $d = &$view->getDisplay('page_1');
    $d['display_options']['menu']['parent'] = $officeParent;
    unset($d);
    if (!$dry) {
      $view->save();
    }
    $out('   exists — menu parent UPDATED to ' . $officeParent);
  }
  else {
    $out('   exists (menu parent ok)');
  }
}
else {
  if (!$dry) {
    View::create($definition)->save();
  }
  $out('   CREATED');
}

$out('== verify ==');
$view = View::load(PW_VIEW);
if ($view) {
  try {
    $exec = $view->getExecutable();
    $exec->setDisplay('page_1');
    $exec->initHandlers();
    $exec->preExecute();
    $exec->execute();
    $out('   executes: ' . count($exec->result) . ' lead(s) on this environment; path /' . $exec->getDisplay()->getOption('path'));
  }
  catch (\Throwable $e) {
    $out('   !! execute failed: ' . $e->getMessage());
  }
}
else {
  $out('   view ' . ($dry ? '(would exist after a real run)' : 'MISSING'));
}
if (!$dry) {
  \Drupal::service('router.builder')->rebuild();
  \Drupal::service('plugin.manager.menu.link')->rebuild();
  $out('   router + menu links rebuilt');
}
$out('done');
