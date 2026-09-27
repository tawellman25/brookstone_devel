<?php

declare(strict_types=1);

/**
 * Build the five credential displays (§5) as one Views config.
 *
 *   page_company   /admin/office/credentials            scope = company
 *   page_expiring  /admin/office/credentials/expiring    status IN (pending_renewal, expired) — CARDS
 *   page_mine      /teammates/credentials                contextual: current user — CARDS
 *   block_profile  (block)                               contextual: the ROUTE user, for /user/{uid}
 *   block_public   (block)                               publish_publicly = 1, safe fields only
 *
 * Handler plugin ids were read from views.views_data rather than guessed:
 * list_string -> filter `list_field`; entity_reference -> `numeric` filter +
 * `entity_target_id` argument; datetime -> `datetime`; boolean -> `boolean`.
 *
 * Every child display sets defaults[<opt>] = FALSE for each option it overrides —
 * without that flag a child silently inherits the default display (documented
 * gotcha). Saved through the View ENTITY so postSave rebuilds routes.
 *
 * Idempotent.
 *   drush php:script web/scripts/build_credential_views.php
 */

use Drupal\views\Entity\View;

$ENTITY = 'credential';
$ID = 'credentials';

/**
 * The FULL default option set every Views field handler needs.
 *
 * Views does NOT merge plugin defaults into options written straight into config,
 * so a sparse field definition renders as `$item + $this->options['alter']` with
 * alter NULL → "Unsupported operand types: array + null" in
 * FieldPluginBase::advancedRender(). Executing a view does not hit that path; only
 * RENDERING a row does. (Recorded in memory as feedback_views_pattern: always the
 * full field definition, never a sparse one.)
 */
$FIELD_DEFAULTS = [
  'label' => '',
  'exclude' => FALSE,
  'alter' => [
    'alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '',
    'absolute' => FALSE, 'external' => FALSE, 'replace_spaces' => FALSE,
    'path_case' => 'none', 'trim_whitespace' => FALSE, 'alt' => '', 'rel' => '',
    'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '',
    'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE,
    'more_link' => FALSE, 'more_link_text' => '', 'more_link_path' => '',
    'strip_tags' => FALSE, 'trim' => FALSE, 'preserve_tags' => '', 'html' => FALSE,
  ],
  'element_type' => '', 'element_class' => '',
  'element_label_type' => '', 'element_label_class' => '', 'element_label_colon' => TRUE,
  'element_wrapper_type' => '', 'element_wrapper_class' => '',
  'element_default_classes' => TRUE,
  'empty' => '', 'hide_empty' => FALSE, 'empty_zero' => FALSE, 'hide_alter_empty' => TRUE,
  'group_column' => 'value', 'group_columns' => [], 'group_rows' => TRUE,
  'delta_limit' => 0, 'delta_offset' => 0, 'delta_reversed' => FALSE,
  'delta_first_last' => FALSE, 'multi_type' => 'separator', 'separator' => ', ',
  'field_api_classes' => FALSE,
  'click_sort_column' => 'value',
  'settings' => [], 'type' => 'string',
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
  'plugin_id' => 'field',
];

/** A field handler on the credential base/data table. */
$baseField = function (string $name, string $label, array $extra = []) use ($FIELD_DEFAULTS): array {
  return $extra + [
    'id' => $name, 'table' => 'credential_field_data', 'field' => $name,
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'credential', 'entity_field' => $name,
    'plugin_id' => $name === 'title' ? 'field' : 'field',
    'label' => $label, 'exclude' => FALSE, 'element_label_colon' => TRUE,
    'settings' => [],
  ];
};

/** A field handler on a credential field table. */
$field = function (string $name, string $label, string $type = 'string', array $settings = []) use ($FIELD_DEFAULTS): array {
  return [
    'id' => $name, 'table' => 'credential__' . $name, 'field' => $name,
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'credential', 'entity_field' => $name,
    'plugin_id' => 'field', 'label' => $label, 'exclude' => FALSE,
    'element_label_colon' => TRUE, 'type' => $type, 'settings' => $settings,
  ] + $FIELD_DEFAULTS;
};

$US_DATE = ['type' => 'datetime_custom', 'settings' => ['date_format' => 'm/d/Y', 'timezone_override' => '']];
$dateField = function (string $name, string $label) use ($US_DATE, $FIELD_DEFAULTS): array {
  return [
    'id' => $name, 'table' => 'credential__' . $name, 'field' => $name,
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'credential', 'entity_field' => $name,
    'plugin_id' => 'field', 'label' => $label, 'exclude' => FALSE,
    'element_label_colon' => TRUE,
  ] + $US_DATE + $FIELD_DEFAULTS;
};

$titleLinked = [
  'id' => 'title', 'table' => 'credential_field_data', 'field' => 'title',
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
  'entity_type' => 'credential', 'entity_field' => 'title', 'plugin_id' => 'field',
  'label' => '', 'exclude' => FALSE, 'element_label_colon' => FALSE,
  'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
] + $FIELD_DEFAULTS;

$bundleFilter = [
  'id' => 'type', 'table' => 'credential_field_data', 'field' => 'type',
  'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'credential',
  'entity_field' => 'type', 'plugin_id' => 'bundle', 'operator' => 'in',
  'value' => ['credential' => 'credential'], 'group' => 1,
];

// NOTE: do NOT use the `list_field` filter here. Contrib token_views_filter
// OVERRIDES that plugin id site-wide (TokensListFieldFilter), and with this
// configuration its subclass produced no WHERE clause and no join at all — the
// view silently returned every row. Core's `in_operator` works on the varchar
// column directly. Caught by executing the view and dumping the query, not by
// reading the saved config. See Governance/drupal_bos_gotchas.md.
$listFilter = function (string $name, array $values, string $op = 'in'): array {
  return [
    'id' => $name . '_value', 'table' => 'credential__' . $name, 'field' => $name . '_value',
    'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
    'entity_type' => 'credential', 'entity_field' => $name,
    'plugin_id' => 'in_operator', 'operator' => $op,
    'value' => array_combine($values, $values), 'group' => 1,
    'expose' => ['operator' => FALSE], 'is_grouped' => FALSE,
  ];
};

$teammateArgument = function (string $defaultPlugin): array {
  return [
    'id' => 'field_teammate_target_id', 'table' => 'credential__field_teammate',
    'field' => 'field_teammate_target_id', 'relationship' => 'none', 'group_type' => 'group',
    'plugin_id' => 'entity_target_id', 'target_entity_type_id' => 'user',
    'default_action' => 'default', 'exception' => ['value' => 'all', 'title_enable' => FALSE],
    'default_argument_type' => $defaultPlugin,
    'summary' => ['sort_order' => 'asc', 'format' => 'default_summary'],
    'specify_validation' => FALSE, 'break_phrase' => FALSE,
  ];
};

$sortExpiry = [
  'id' => 'field_expiration_date_value', 'table' => 'credential__field_expiration_date',
  'field' => 'field_expiration_date_value', 'relationship' => 'none', 'group_type' => 'group',
  'plugin_id' => 'datetime', 'order' => 'ASC',
];
$sortTitle = [
  'id' => 'title', 'table' => 'credential_field_data', 'field' => 'title',
  'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'credential',
  'entity_field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC',
];

/** The raw status key, excluded from output, purely to feed the row class. */
$statusKeyField = [
  'id' => 'field_status_key', 'table' => 'credential__field_status', 'field' => 'field_status',
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
  'entity_type' => 'credential', 'entity_field' => 'field_status',
  'plugin_id' => 'field', 'label' => '', 'exclude' => TRUE,
  'element_label_colon' => FALSE, 'type' => 'list_key', 'settings' => [],
] + $FIELD_DEFAULTS;

/* ---------- the default display: everything shared ---------- */
$default = [
  'id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0,
  'display_options' => [
    'title' => 'Credentials',
    'access' => ['type' => 'perm', 'options' => ['perm' => 'view any credential entities']],
    'cache' => ['type' => 'tag', 'options' => []],
    'query' => ['type' => 'views_query', 'options' => []],
    'exposed_form' => ['type' => 'basic', 'options' => []],
    'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50]],
    'style' => ['type' => 'table', 'options' => []],
    'row' => ['type' => 'fields', 'options' => []],
    'fields' => [
      'title' => $titleLinked,
      'field_credential_type' => $field('field_credential_type', 'Type', 'entity_reference_label', ['link' => FALSE]),
      'field_teammate' => $field('field_teammate', 'Held by', 'entity_reference_label', ['link' => FALSE]),
      'field_issuing_authority' => $field('field_issuing_authority', 'Issued by'),
      'field_credential_number' => $field('field_credential_number', 'Number'),
      'field_expiration_date' => $dateField('field_expiration_date', 'Expires'),
      'field_status' => $field('field_status', 'Status', 'list_default'),
      'field_status_key' => $statusKeyField,
    ],
    'filters' => ['type' => $bundleFilter],
    'sorts' => ['title' => $sortTitle],
    'empty' => [
      'area_text_custom' => [
        'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom',
        'relationship' => 'none', 'group_type' => 'group', 'plugin_id' => 'text_custom',
        'empty' => TRUE, 'content' => 'No credentials found.',
      ],
    ],
  ],
];

/** Child display factory — always stamps the defaults[] override flags. */
$child = function (string $id, string $plugin, string $title, array $opts, int $pos) {
  $overrides = array_keys($opts);
  $defaults = [];
  foreach (['fields', 'filters', 'sorts', 'style', 'row', 'pager', 'arguments', 'access', 'empty', 'title', 'css_class'] as $k) {
    if (in_array($k, $overrides, TRUE)) {
      $defaults[$k] = FALSE;
    }
  }
  return [
    'id' => $id, 'display_title' => $title, 'display_plugin' => $plugin, 'position' => $pos,
    'display_options' => ['defaults' => $defaults] + $opts,
  ];
};

/* The row class carries the status MACHINE value via a Views token, so CSS can
   colour the status accent without a row template — which would need a new
   hook_theme() on a module that is already enabled on live (see the
   "hook ADDED to an already-enabled module" gotcha). The token resolves from the
   EXCLUDED field_status_key field added below, because the visible field_status
   uses the list_default formatter and would emit the human label ("Pending
   renewal"), not a usable class. Views runs the value through Html::getClass(),
   so pending_renewal becomes pending-renewal. */
$cardStyle = ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => 'credential-card credential-card--{{ field_status_key }}', 'default_row_class' => TRUE, 'uses_fields' => TRUE]];


$displays = ['default' => $default];

/* a. Company credentials — the COI-request list. */
$displays['page_company'] = $child('page_company', 'page', 'Company credentials', [
  'title' => 'Company credentials',
  'path' => 'admin/office/credentials',
  'filters' => ['type' => $bundleFilter],
  'fields' => [
    'title' => $titleLinked,
    'field_credential_type' => $field('field_credential_type', 'Type', 'entity_reference_label', ['link' => FALSE]),
    'field_issuing_authority' => $field('field_issuing_authority', 'Issued by'),
    'field_credential_number' => $field('field_credential_number', 'Number'),
    'field_coverage_limits' => $field('field_coverage_limits', 'Limits'),
    'field_expiration_date' => $dateField('field_expiration_date', 'Expires'),
    'field_status' => $field('field_status', 'Status', 'list_default'),
    'field_status_key' => $statusKeyField,
  ],
  'menu' => ['type' => 'normal', 'title' => 'Credentials', 'weight' => 40, 'menu_name' => 'admin', 'parent' => ''],
], 1);

/* b. Expiring soon — cards, phone-first. The status is computed by cron, so this
      filters a stored value instead of doing date maths Views cannot express. */
$displays['page_expiring'] = $child('page_expiring', 'page', 'Expiring soon', [
  'title' => 'Credentials expiring soon',
  'path' => 'admin/office/credentials/expiring',
  'style' => $cardStyle,
  'css_class' => 'credential-cards',
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'filters' => ['type' => $bundleFilter],
  'sorts' => ['field_expiration_date_value' => $sortExpiry],
  'menu' => ['type' => 'tab', 'title' => 'Expiring soon', 'weight' => 10, 'menu_name' => 'admin', 'parent' => ''],
], 2);

/* c. My credentials — the current user, cards. */
$displays['page_mine'] = $child('page_mine', 'page', 'My credentials', [
  'title' => 'My credentials',
  'path' => 'teammates/credentials',
  'style' => $cardStyle,
  'css_class' => 'credential-cards',
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'arguments' => ['field_teammate_target_id' => $teammateArgument('current_user')],
], 3);

/* d. Profile block — the ROUTE user, so a teammate's own page shows only theirs. */
$displays['block_profile'] = $child('block_profile', 'block', 'Profile credentials', [
  'title' => 'Credentials',
  'style' => $cardStyle,
  'css_class' => 'credential-cards',
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'arguments' => ['field_teammate_target_id' => $teammateArgument('user')],
], 4);

/* e. Public — safe fields only. Never expiration, documents, notes or renewal
      contact. The number is additionally governed by field access. */
$displays['block_public'] = $child('block_public', 'block', 'Public credentials', [
  'title' => 'Our credentials',
  'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
  'style' => ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => 'credential-public-item', 'default_row_class' => TRUE, 'uses_fields' => FALSE]],
  'css_class' => 'credential-public',
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'filters' => [
    'type' => $bundleFilter,
    'field_publish_publicly_value' => [
      'id' => 'field_publish_publicly_value', 'table' => 'credential__field_publish_publicly',
      'field' => 'field_publish_publicly_value', 'relationship' => 'none', 'group_type' => 'group',
      'entity_type' => 'credential', 'entity_field' => 'field_publish_publicly',
      'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ],
  'fields' => [
    'field_credential_type' => $field('field_credential_type', '', 'entity_reference_label', ['link' => FALSE]),
    'field_issuing_authority' => $field('field_issuing_authority', 'Issued by'),
    'field_coverage_limits' => $field('field_coverage_limits', 'Coverage'),
    'field_credential_number' => $field('field_credential_number', 'Number'),
    'field_public_description' => $field('field_public_description', '', 'text_default'),
  ],
], 5);

$view = View::load($ID);
if (!$view) {
  $view = View::create([
    'id' => $ID,
    'label' => 'Credentials',
    'module' => 'views',
    'description' => 'Company + teammate credentials: company list, expiring soon, my credentials, a profile block, and the public block.',
    'base_table' => 'credential_field_data',
    'base_field' => 'id',
    'display' => $displays,
  ]);
}
else {
  $view->set('display', $displays);
}
$view->save();

/* ---- The list_string filter operator ----
   A Views filter on a list_string column is ManyToOne (via options' ListField).
   ManyToOne::operators() defines ONLY `or` ("Is one of"), `and`, `not` — and
   `empty`/`not empty` when the definition allows it. There is NO `in` operator.
   InOperator::query() dispatches through $info[$this->operator]['method'], so an
   operator of `in` matches nothing, no method is called, and the filter emits NO
   SQL AT ALL — no JOIN, no WHERE, and the display silently returns every row.
   The handler still instantiates with the correct table, value and operator, so
   the saved config looks right and nothing errors.
   Correct operator: `or` with the value as an array.
   (Also worth knowing: the handler plugin is chosen from views DATA, not from a
   `plugin_id` in saved config, so it cannot be swapped for core in_operator; and
   contrib token_views_filter subclasses `list_field` but only overrides
   replaceTokens(), so it is not the cause.)
   Documented in Governance/drupal_bos_gotchas.md. */
$executable = \Drupal::service('views.executable')->get($view);

$scopeFilter = [
  'id' => 'field_scope_value', 'table' => 'credential__field_scope',
  'field' => 'field_scope_value', 'relationship' => 'none', 'group_type' => 'group',
  'admin_label' => '', 'entity_type' => 'credential', 'entity_field' => 'field_scope',
  'plugin_id' => 'list_field', 'operator' => 'or',
  'value' => ['company' => 'company'],
  'group' => 1, 'exposed' => FALSE, 'is_grouped' => FALSE, 'reduce_duplicates' => FALSE,
];
$statusFilter = [
  'id' => 'field_status_value', 'table' => 'credential__field_status',
  'field' => 'field_status_value', 'relationship' => 'none', 'group_type' => 'group',
  'admin_label' => '', 'entity_type' => 'credential', 'entity_field' => 'field_status',
  'plugin_id' => 'list_field', 'operator' => 'or',
  'value' => ['pending_renewal' => 'pending_renewal', 'expired' => 'expired'],
  'group' => 1, 'exposed' => FALSE, 'is_grouped' => FALSE, 'reduce_duplicates' => FALSE,
];

foreach (['page_company' => $scopeFilter, 'page_expiring' => $statusFilter] as $displayId => $filter) {
  $executable->setDisplay($displayId);
  $handler = $executable->displayHandlers->get($displayId);
  $filters = $handler->getOption('filters');
  $filters[$filter['id']] = $filter;
  $handler->setOption('filters', $filters);
}
$executable->save();

printf("saved view '%s' with displays: %s\n", $ID, implode(', ', array_keys($displays)));
print "DONE.\n";
