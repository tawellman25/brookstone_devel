<?php

/**
 * @file
 * Generates the config/sync YAML for the QuickBooks mirror entity (qb_list) —
 * ECK entity type, three bundles (customer / item / class), base-field
 * relabels, field storages + instances, form + view displays and the three
 * admin Views (customers with a Review display, items, classes).
 *
 * Standalone — no Drupal bootstrap:
 *   ddev exec php web/scripts/bos_quickbooks_config_gen.php
 *
 * Idempotent: an existing file keeps its uuid, so a re-run after a spec change
 * rewrites content without churning identifiers. Apply with
 * web/scripts/bos_quickbooks_setup.php (entity API, uuid kept) — never a full
 * cim. The file list it writes is web/scripts/bos_quickbooks_config_files.txt.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Uuid\Php as Uuid;

const ET = 'qb_list';
const BUNDLES = ['customer' => 'QuickBooks Customer', 'item' => 'QuickBooks Item', 'class' => 'QuickBooks Class'];
const VIEW_PERM = 'view quickbooks lists';

$sync = realpath(__DIR__ . '/../../config/sync');
if (!$sync) {
  fwrite(STDERR, "config/sync not found\n");
  exit(1);
}
$uuid = new Uuid();
$files = [];
$write = static function (string $name, array $data) use ($sync, $uuid, &$files): void {
  $file = "$sync/$name.yml";
  $id = NULL;
  if (is_file($file)) {
    $old = Yaml::decode((string) file_get_contents($file));
    $id = $old['uuid'] ?? NULL;
  }
  $data = ['uuid' => $id ?: $uuid->generate()] + $data;
  file_put_contents($file, Yaml::encode($data));
  $files[] = "$name.yml";
};

// ── Entity type + bundles ───────────────────────────────────────────────
$write('eck.eck_entity_type.' . ET, [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [],
  'id' => ET,
  'label' => 'QuickBooks List Entry',
  'uid' => FALSE,
  'created' => TRUE,
  'changed' => TRUE,
  'title' => TRUE,
  'standalone_url' => TRUE,
]);
$bundle_desc = [
  'customer' => 'A customer record mirrored from QuickBooks Desktop by the Web Connector sync (bos_quickbooks). Title = the exact QuickBooks name; the ListID is the stable key. Only the BOS client link and match status are edited here — everything else is overwritten by the next sync.',
  'item' => 'A service / other-charge / inventory item mirrored from QuickBooks Desktop. Read-only: the next sync overwrites it. Work-order services map to these for invoicing.',
  'class' => 'A class (department) mirrored from QuickBooks Desktop. Read-only: the next sync overwrites it.',
];
foreach (BUNDLES as $b => $label) {
  $write('eck.eck_type.' . ET . '.' . $b, [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['config' => ['eck.eck_entity_type.' . ET]],
    'name' => $label,
    'type' => $b,
    'description' => $bundle_desc[$b],
  ]);
  $write('core.base_field_override.' . ET . '.' . $b . '.title', [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['config' => ['eck.eck_type.' . ET . '.' . $b]],
    'id' => ET . '.' . $b . '.title',
    'field_name' => 'title',
    'entity_type' => ET,
    'bundle' => $b,
    'label' => 'QuickBooks Full Name',
    'description' => 'Exactly as QuickBooks has it (sub-entries carry their parent path with colons). Synced — do not edit here; rename in QuickBooks.',
    'required' => TRUE,
    'translatable' => TRUE,
    'default_value' => [],
    'default_value_callback' => '',
    'settings' => [],
    'field_type' => 'string',
  ]);
  $write('core.base_field_override.' . ET . '.' . $b . '.status', [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['config' => ['eck.eck_type.' . ET . '.' . $b]],
    'id' => ET . '.' . $b . '.status',
    'field_name' => 'status',
    'entity_type' => ET,
    'bundle' => $b,
    'label' => 'Active in QuickBooks',
    'description' => 'Mirrors the QuickBooks IsActive flag. Synced — make a record inactive in QuickBooks, not here.',
    'required' => FALSE,
    'translatable' => TRUE,
    'default_value' => [['value' => 1]],
    'default_value_callback' => '',
    'settings' => ['on_label' => 'Active', 'off_label' => 'Inactive'],
    'field_type' => 'boolean',
  ]);
}

// ── Fields ──────────────────────────────────────────────────────────────
$storage = static function (string $name, string $type, array $settings, array $modules, string $module = 'core', int $card = 1) use ($write): void {
  sort($modules);
  $write('field.storage.' . ET . ".$name", [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['module' => $modules],
    'id' => ET . ".$name",
    'field_name' => $name,
    'entity_type' => ET,
    'type' => $type,
    'settings' => $settings,
    'module' => $module,
    'locked' => FALSE,
    'cardinality' => $card,
    'translatable' => TRUE,
    'indexes' => [],
    'persist_with_no_fields' => FALSE,
    'custom_storage' => FALSE,
  ]);
};
$instance = static function (string $bundle, string $name, string $type, string $label, array $settings, array $opts = []) use ($write): void {
  $cfg = array_merge(['eck.eck_type.' . ET . ".$bundle", 'field.storage.' . ET . ".$name"], $opts['config'] ?? []);
  $cfg = array_values(array_unique($cfg));
  sort($cfg);
  $deps = ['config' => $cfg];
  if (!empty($opts['module'])) {
    $deps['module'] = $opts['module'];
  }
  $write('field.field.' . ET . ".$bundle.$name", [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => $deps,
    'id' => ET . ".$bundle.$name",
    'field_name' => $name,
    'entity_type' => ET,
    'bundle' => $bundle,
    'label' => $label,
    'description' => $opts['description'] ?? '',
    'required' => $opts['required'] ?? FALSE,
    'translatable' => TRUE,
    'default_value' => $opts['default'] ?? [],
    'default_value_callback' => '',
    'settings' => $settings,
    'field_type' => $type,
  ]);
};
$string = static fn(int $len) => ['max_length' => $len, 'case_sensitive' => FALSE, 'is_ascii' => FALSE];
$datetime = ['datetime_type' => 'datetime'];
$synced = 'Synced from QuickBooks — overwritten by the next sync.';

// Shared on all three bundles.
$shared = [
  // name => [type, storage settings, storage modules, module, label, instance opts]
  'field_list_id' => ['string', $string(64), ['eck'], 'core', 'ListID', ['required' => TRUE, 'description' => 'The QuickBooks ListID — the only identifier QuickBooks keeps stable across renames. ' . $synced]],
  'field_name' => ['string', $string(255), ['eck'], 'core', 'Name', ['description' => 'The QuickBooks Name (leaf, without the parent path). ' . $synced]],
  'field_edit_sequence' => ['string', $string(32), ['eck'], 'core', 'EditSequence', ['description' => 'QuickBooks record version; a modify request must quote it. ' . $synced]],
  'field_sublevel' => ['integer', ['unsigned' => TRUE, 'size' => 'normal'], ['eck'], 'core', 'Sublevel', ['description' => '0 = top level; 1+ = a sub-entry (job / sub-item). ' . $synced]],
  'field_parent_list_id' => ['string', $string(64), ['eck'], 'core', 'Parent ListID', ['description' => 'ListID of the parent entry for a sub-entry. ' . $synced]],
  'field_time_created' => ['datetime', $datetime, ['datetime', 'eck'], 'datetime', 'Created in QuickBooks', ['description' => $synced]],
  'field_time_modified' => ['datetime', $datetime, ['datetime', 'eck'], 'datetime', 'Modified in QuickBooks', ['description' => $synced]],
  'field_last_seen' => ['datetime', $datetime, ['datetime', 'eck'], 'datetime', 'Last seen by sync', ['description' => 'When the Web Connector sync last returned this record.']],
  'field_missing' => ['boolean', [], ['eck'], 'core', 'Missing from QuickBooks', ['description' => 'Set when a complete sync of this list no longer returned the record (deleted or merged away in QuickBooks). It is kept here so history stays readable.', 'default' => [['value' => 0]]]],
];
foreach ($shared as $name => [$type, $settings, $mods, $module, $label, $opts]) {
  $storage($name, $type, $settings, $mods, $module);
  foreach (array_keys(BUNDLES) as $b) {
    $inst_settings = $type === 'boolean' ? ['on_label' => 'Missing', 'off_label' => 'Present'] : [];
    $instance($b, $name, $type, $label, $inst_settings, $opts);
  }
}

// Customer-only.
$storage('field_first_name', 'string', $string(64), ['eck']);
$instance('customer', 'field_first_name', 'string', 'First Name', [], ['description' => $synced]);
$storage('field_last_name', 'string', $string(64), ['eck']);
$instance('customer', 'field_last_name', 'string', 'Last Name', [], ['description' => $synced]);
$storage('field_company_name', 'string', $string(128), ['eck']);
$instance('customer', 'field_company_name', 'string', 'Company Name', [], ['description' => $synced]);
$storage('field_customer_type', 'string', $string(64), ['eck']);
$instance('customer', 'field_customer_type', 'string', 'Customer Type', [], ['description' => $synced]);
$storage('field_terms', 'string', $string(64), ['eck']);
$instance('customer', 'field_terms', 'string', 'Terms', [], ['description' => $synced]);
$storage('field_bill_address', 'string_long', ['case_sensitive' => FALSE], ['eck']);
$instance('customer', 'field_bill_address', 'string_long', 'Bill To', [], ['description' => 'Bill-to address as QuickBooks has it — the quickest way to tell two same-name customers apart when resolving a match. ' . $synced]);
$storage('field_bos_client', 'entity_reference', ['target_type' => 'user'], ['eck', 'user']);
$instance('customer', 'field_bos_client', 'entity_reference', 'BOS Client', [
  'handler' => 'default:user',
  'handler_settings' => [
    'include_anonymous' => FALSE,
    'filter' => ['type' => 'role', 'role' => ['client' => 'client']],
    'target_bundles' => NULL,
    'sort' => ['field' => 'name', 'direction' => 'ASC'],
    'auto_create' => FALSE,
  ],
], ['config' => ['user.role.client'], 'description' => 'The BOS client this QuickBooks customer is billed as. Picking one here is the ONLY way a QuickBooks identity is attached to a client — it writes the ListID onto the client\'s customer profile. Leave empty if this QuickBooks customer has no BOS client (and set the status to Ignored).']);
$match_values = [
  ['value' => 'unmatched', 'label' => 'Unmatched'],
  ['value' => 'auto', 'label' => 'Matched automatically'],
  ['value' => 'manual', 'label' => 'Matched by the office'],
  ['value' => 'ambiguous', 'label' => 'Ambiguous — two or more BOS clients fit'],
  ['value' => 'duplicate', 'label' => 'Duplicate — another QuickBooks customer already points at this client'],
  ['value' => 'ignored', 'label' => 'Ignored — no BOS client (historical / not ours)'],
];
$storage('field_match_status', 'list_string', ['allowed_values' => $match_values, 'allowed_values_function' => ''], ['eck', 'options'], 'options');
$instance('customer', 'field_match_status', 'list_string', 'Match Status', [], ['module' => ['options'], 'default' => [['value' => 'unmatched']], 'description' => 'Set by the matching pass; becomes "Matched by the office" when someone picks the client by hand. Unmatched, Ambiguous and Duplicate rows make the Review list.']);
$storage('field_match_note', 'string_long', ['case_sensitive' => FALSE], ['eck']);
$instance('customer', 'field_match_note', 'string_long', 'Match Note', [], ['description' => 'What the matching pass found — the key it matched on, or the candidates it could not choose between.']);

// Item-only.
$storage('field_item_type', 'string', $string(32), ['eck']);
$instance('item', 'field_item_type', 'string', 'Item Type', [], ['description' => 'Service, OtherCharge, Inventory, NonInventory, Subtotal, Discount, Payment, SalesTax, Group… ' . $synced]);
$storage('field_income_account', 'string', $string(128), ['eck']);
$instance('item', 'field_income_account', 'string', 'Income Account', [], ['description' => $synced]);
$storage('field_price', 'decimal', ['precision' => 10, 'scale' => 2], ['eck']);
$instance('item', 'field_price', 'decimal', 'Price', ['min' => NULL, 'max' => NULL, 'prefix' => '$', 'suffix' => ''], ['description' => 'The item\'s default rate in QuickBooks (often 0 — the invoice line carries the real amount). ' . $synced]);
$storage('field_description', 'string_long', ['case_sensitive' => FALSE], ['eck']);
$instance('item', 'field_description', 'string_long', 'Description', [], ['description' => 'The sales description QuickBooks prints on the invoice line by default. ' . $synced]);

// ── Displays ────────────────────────────────────────────────────────────
$shared_order = ['field_list_id', 'field_name', 'field_sublevel', 'field_parent_list_id', 'field_edit_sequence', 'field_time_created', 'field_time_modified', 'field_last_seen', 'field_missing'];
$bundle_fields = [
  'customer' => ['field_first_name', 'field_last_name', 'field_company_name', 'field_customer_type', 'field_terms', 'field_bill_address', 'field_bos_client', 'field_match_status', 'field_match_note'],
  'item' => ['field_item_type', 'field_income_account', 'field_price', 'field_description'],
  'class' => [],
];
$formatter = static function (string $field, int $w): array {
  $map = [
    'field_time_created' => ['datetime_default', ['timezone_override' => '', 'format_type' => 'medium'], ['datetime']],
    'field_time_modified' => ['datetime_default', ['timezone_override' => '', 'format_type' => 'medium'], ['datetime']],
    'field_last_seen' => ['datetime_default', ['timezone_override' => '', 'format_type' => 'medium'], ['datetime']],
    'field_missing' => ['boolean', ['format' => 'default', 'format_custom_false' => '', 'format_custom_true' => ''], []],
    'field_sublevel' => ['number_integer', ['thousand_separator' => '', 'prefix_suffix' => FALSE], []],
    'field_price' => ['number_decimal', ['thousand_separator' => ',', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE], []],
    'field_bos_client' => ['entity_reference_label', ['link' => TRUE], ['user']],
    'field_match_status' => ['list_default', [], ['options']],
    'field_bill_address' => ['basic_string', [], []],
    'field_match_note' => ['basic_string', [], []],
    'field_description' => ['basic_string', [], []],
  ];
  [$type, $settings] = $map[$field] ?? ['string', ['link_to_entity' => FALSE]];
  return ['type' => $type, 'label' => 'inline', 'settings' => $settings, 'third_party_settings' => [], 'weight' => $w, 'region' => 'content'];
};
$widget = static function (string $field, int $w): array {
  $map = [
    'field_bos_client' => ['entity_reference_autocomplete', ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => 'Type a client name…']],
    'field_match_status' => ['options_select', []],
    'field_match_note' => ['string_textarea', ['rows' => 3, 'placeholder' => '']],
  ];
  [$type, $settings] = $map[$field];
  return ['type' => $type, 'weight' => $w, 'region' => 'content', 'settings' => $settings, 'third_party_settings' => []];
};
foreach (BUNDLES as $b => $label) {
  $fields = array_merge($bundle_fields[$b], $shared_order);
  $cfg_deps = array_merge(['eck.eck_type.' . ET . ".$b"], array_map(static fn($f) => 'field.field.' . ET . ".$b.$f", $fields));
  sort($cfg_deps);
  // View display: identity first, then the bundle facts, then sync bookkeeping.
  $content = ['title' => ['type' => 'string', 'label' => 'hidden', 'settings' => ['link_to_entity' => FALSE], 'third_party_settings' => [], 'weight' => 0, 'region' => 'content']];
  $w = 1;
  foreach ($fields as $f) {
    $content[$f] = $formatter($f, $w++);
  }
  $content['status'] = ['type' => 'boolean', 'label' => 'inline', 'settings' => ['format' => 'default', 'format_custom_false' => '', 'format_custom_true' => ''], 'third_party_settings' => [], 'weight' => $w++, 'region' => 'content'];
  $mods = ['datetime', 'options', 'user'];
  if ($b !== 'customer') {
    $mods = ['datetime'];
  }
  $write('core.entity_view_display.' . ET . ".$b.default", [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['config' => $cfg_deps, 'module' => $mods],
    'id' => ET . ".$b.default",
    'targetEntityType' => ET,
    'bundle' => $b,
    'mode' => 'default',
    'content' => $content,
    'hidden' => ['created' => TRUE, 'changed' => TRUE],
  ]);
  // Form display: only what a human may edit. Everything synced is hidden —
  // bos_quickbooks_form_alter() shows a read-only summary of the QuickBooks
  // record at the top instead, so the office knows what it is linking.
  $form_content = [];
  $hidden = ['title' => TRUE, 'status' => TRUE, 'created' => TRUE, 'changed' => TRUE];
  if ($b === 'customer') {
    $form_content['field_bos_client'] = $widget('field_bos_client', 0);
    $form_content['field_match_status'] = $widget('field_match_status', 1);
    $form_content['field_match_note'] = $widget('field_match_note', 2);
  }
  foreach ($fields as $f) {
    if (!isset($form_content[$f])) {
      $hidden[$f] = TRUE;
    }
  }
  $write('core.entity_form_display.' . ET . ".$b.default", [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['config' => $cfg_deps, 'module' => $b === 'customer' ? ['options', 'user'] : []],
    'id' => ET . ".$b.default",
    'targetEntityType' => ET,
    'bundle' => $b,
    'mode' => 'default',
    'content' => $form_content,
    'hidden' => $hidden,
  ]);
}

// ── Views ───────────────────────────────────────────────────────────────
$vf = static function (string $field, string $label, string $type, array $settings, array $extra = []) {
  return array_merge([
    'id' => $field,
    'table' => ET . '__' . $field,
    'field' => $field,
    'plugin_id' => 'field',
    'label' => $label,
    'type' => $type,
    'settings' => $settings,
  ], $extra);
};
$base_field = static fn(string $name, string $label, string $type, array $settings) => [
  'id' => $name,
  'table' => ET . '_field_data',
  'field' => $name,
  'entity_type' => ET,
  'entity_field' => $name,
  'plugin_id' => 'field',
  'label' => $label,
  'type' => $type,
  'settings' => $settings,
];
$col = static fn(bool $sortable = FALSE, string $align = '', bool $empty = FALSE, string $default_order = 'asc') => ['sortable' => $sortable, 'default_sort_order' => $default_order, 'align' => $align, 'separator' => '', 'empty_column' => $empty, 'responsive' => ''];
$expose = static fn(string $id, string $label, array $extra = []) => array_merge([
  'operator_id' => $id . '_op',
  'label' => $label,
  'description' => '',
  'use_operator' => FALSE,
  'operator' => $id . '_op',
  'operator_limit_selection' => FALSE,
  'operator_list' => [],
  'identifier' => $id,
  'required' => FALSE,
  'remember' => FALSE,
  'multiple' => FALSE,
  'remember_roles' => ['authenticated' => 'authenticated'],
], $extra);
$string_filter = static fn(string $field, string $label) => [
  'id' => $field . '_value',
  'table' => ET . '__' . $field,
  'field' => $field . '_value',
  'plugin_id' => 'string',
  'operator' => 'contains',
  'value' => '',
  'group' => 1,
  'exposed' => TRUE,
  'expose' => $expose($field . '_value', $label, ['placeholder' => '']),
];
$title_filter = [
  'id' => 'title',
  'table' => ET . '_field_data',
  'field' => 'title',
  'entity_type' => ET,
  'entity_field' => 'title',
  'plugin_id' => 'string',
  'operator' => 'contains',
  'value' => '',
  'group' => 1,
  'exposed' => TRUE,
  'expose' => $expose('title', 'QuickBooks name', ['placeholder' => '']),
];
$type_filter = static fn(string $bundle) => [
  'id' => 'type',
  'table' => ET . '_field_data',
  'field' => 'type',
  'entity_type' => ET,
  'entity_field' => 'type',
  'plugin_id' => 'bundle',
  'operator' => 'in',
  'value' => [$bundle => $bundle],
  'group' => 1,
  'exposed' => FALSE,
];
$status_filter = static fn(bool $exposed) => array_merge([
  'id' => 'status',
  'table' => ET . '_field_data',
  'field' => 'status',
  'entity_type' => ET,
  'entity_field' => 'status',
  'plugin_id' => 'boolean',
  'operator' => '=',
  'value' => $exposed ? 'All' : '1',
  'group' => 1,
  'exposed' => $exposed,
], $exposed ? ['expose' => $expose('status', 'Active in QuickBooks')] : []);
$missing_filter = static fn(bool $exposed) => array_merge([
  'id' => 'field_missing_value',
  'table' => ET . '__field_missing',
  'field' => 'field_missing_value',
  'plugin_id' => 'boolean',
  'operator' => $exposed ? '=' : '!=',
  'value' => $exposed ? 'All' : '1',
  'group' => 1,
  'exposed' => $exposed,
], $exposed ? ['expose' => $expose('field_missing_value', 'Missing from QuickBooks')] : []);
$match_filter = static fn(bool $exposed, array $values = []) => array_merge([
  'id' => 'field_match_status_value',
  'table' => ET . '__field_match_status',
  'field' => 'field_match_status_value',
  'plugin_id' => 'list_field',
  'operator' => 'or',
  'value' => array_combine($values, $values) ?: [],
  'group' => 1,
  'exposed' => $exposed,
], $exposed ? ['expose' => $expose('field_match_status_value', 'Match status', ['multiple' => TRUE, 'reduce' => FALSE])] : []);
$pager_full = ['type' => 'full', 'options' => [
  'pagination_heading_level' => 'h4', 'items_per_page' => 50, 'offset' => 0,
  'tags' => ['previous' => '‹ Previous', 'next' => 'Next ›', 'first' => '« First', 'last' => 'Last »'],
  'expose' => ['items_per_page' => TRUE, 'items_per_page_label' => 'Items per page', 'items_per_page_options' => '25, 50, 100, 200', 'items_per_page_options_all' => TRUE, 'items_per_page_options_all_label' => '- All -', 'offset' => FALSE, 'offset_label' => 'Offset'],
  'quantity' => 9,
]];
$text_area = static fn(string $html, bool $empty) => ['area' => ['id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text', 'empty' => $empty, 'content' => ['value' => $html, 'format' => 'full_html']]];
$cache_meta = ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url', 'url.query_args', 'user.permissions'], 'tags' => []];
$date_col = static fn(string $f, string $label) => $vf($f, $label, 'datetime_custom', ['timezone_override' => '', 'date_format' => 'm/d/Y g:i A']);
$missing_col = $vf('field_missing', 'Missing', 'boolean', ['format' => 'custom', 'format_custom_true' => '⚠ Missing', 'format_custom_false' => ''], ['element_class' => 'bos-qb-missing', 'element_default_classes' => TRUE]);
$status_col = $base_field('status', 'Active', 'boolean', ['format' => 'custom', 'format_custom_true' => 'Active', 'format_custom_false' => 'Inactive']);
$ops_col = ['id' => 'operations', 'table' => ET, 'field' => 'operations', 'entity_type' => ET, 'plugin_id' => 'entity_operations', 'label' => 'Operations', 'destination' => TRUE];
$title_sort = ['title' => ['id' => 'title', 'table' => ET . '_field_data', 'field' => 'title', 'entity_type' => ET, 'entity_field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC', 'exposed' => FALSE]];
$style = static fn(array $fields, array $info, string $default = 'title') => ['type' => 'table', 'options' => [
  'grouping' => [], 'row_class' => '', 'default_row_class' => TRUE,
  'columns' => array_combine(array_keys($fields), array_keys($fields)), 'default' => $default, 'info' => $info,
  'override' => TRUE, 'sticky' => TRUE, 'summary' => '', 'empty_table' => TRUE, 'caption' => '', 'description' => '',
]];
$common_display = static fn(string $title, array $fields, array $info, array $filters, string $header, string $empty) => [
  'title' => $title,
  'fields' => $fields,
  'pager' => $pager_full,
  'access' => ['type' => 'perm', 'options' => ['perm' => VIEW_PERM]],
  'cache' => ['type' => 'tag', 'options' => []],
  'header' => $text_area($header, FALSE),
  'empty' => $text_area($empty, TRUE),
  'sorts' => $title_sort,
  'filters' => $filters,
  'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
  'style' => $style($fields, $info),
  'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
  'query' => ['type' => 'views_query', 'options' => []],
  'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Filter', 'reset_button' => TRUE, 'reset_button_label' => 'Reset', 'exposed_sorts_label' => 'Sort by', 'expose_sort_order' => TRUE, 'sort_asc_label' => 'Asc', 'sort_desc_label' => 'Desc']],
  'display_extenders' => [],
];
$view_deps = static function (array $fields, array $modules, string $bundle) {
  $deps = array_map(static fn($f) => 'field.storage.' . ET . ".$f", $fields);
  // The bundle filter depends on the bundle config entity.
  $deps[] = 'eck.eck_type.' . ET . '.' . $bundle;
  sort($deps);
  sort($modules);
  return ['config' => $deps, 'module' => $modules];
};

// Customers (+ Review display).
$cust_fields = [
  'title' => array_merge($base_field('title', 'QuickBooks name', 'string', ['link_to_entity' => TRUE])),
  'field_last_name' => $vf('field_last_name', 'Last', 'string', ['link_to_entity' => FALSE]),
  'field_first_name' => $vf('field_first_name', 'First', 'string', ['link_to_entity' => FALSE]),
  'field_company_name' => $vf('field_company_name', 'Company', 'string', ['link_to_entity' => FALSE]),
  'field_customer_type' => $vf('field_customer_type', 'Type', 'string', ['link_to_entity' => FALSE]),
  'field_terms' => $vf('field_terms', 'Terms', 'string', ['link_to_entity' => FALSE]),
  'status' => $status_col,
  'field_match_status' => $vf('field_match_status', 'Match', 'list_default', []),
  'field_bos_client' => $vf('field_bos_client', 'BOS client', 'entity_reference_label', ['link' => TRUE]),
  'field_last_seen' => $date_col('field_last_seen', 'Last seen'),
  'field_missing' => $missing_col,
  'operations' => $ops_col,
];
$cust_info = [
  'title' => $col(TRUE), 'field_last_name' => $col(TRUE), 'field_first_name' => $col(TRUE), 'field_company_name' => $col(TRUE),
  'field_customer_type' => $col(TRUE), 'field_terms' => $col(TRUE), 'status' => $col(TRUE), 'field_match_status' => $col(TRUE),
  'field_bos_client' => $col(TRUE), 'field_last_seen' => $col(TRUE), 'field_missing' => $col(TRUE, '', FALSE, 'desc'), 'operations' => $col(),
];
$cust_filters_all = [
  'type' => $type_filter('customer'),
  'title' => $title_filter,
  'field_last_name_value' => $string_filter('field_last_name', 'Last name'),
  'field_company_name_value' => $string_filter('field_company_name', 'Company'),
  'status' => $status_filter(TRUE),
  'field_match_status_value' => $match_filter(TRUE),
  'field_missing_value' => $missing_filter(TRUE),
];
$cust_filters_review = [
  'type' => $type_filter('customer'),
  'title' => $title_filter,
  'field_last_name_value' => $string_filter('field_last_name', 'Last name'),
  'status' => $status_filter(FALSE),
  'field_missing_value' => $missing_filter(FALSE),
  'field_match_status_value' => $match_filter(FALSE, ['unmatched', 'ambiguous', 'duplicate']),
];
$write('views.view.qb_customers', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => $view_deps(['field_bos_client', 'field_company_name', 'field_customer_type', 'field_first_name', 'field_last_name', 'field_last_seen', 'field_match_status', 'field_missing', 'field_terms'], ['datetime', 'eck', 'options', 'user'], 'customer'),
  'id' => 'qb_customers',
  'label' => 'QuickBooks Customers',
  'module' => 'views',
  'description' => 'Customer list mirrored from QuickBooks Desktop by the Web Connector sync, with each customer\'s BOS client link and match status. Review display = the ones the office must resolve.',
  'tag' => 'quickbooks',
  'base_table' => ET . '_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_title' => 'Default',
      'display_plugin' => 'default',
      'position' => 0,
      'display_options' => $common_display(
        'QuickBooks Customers',
        $cust_fields,
        $cust_info,
        $cust_filters_all,
        '<p>Every customer QuickBooks Desktop has, as of the last Web Connector sync. <strong>BOS client</strong> is the link invoicing uses — it is set by the matching pass or by the office on the Review list, never typed. <strong>⚠ Missing</strong> means a complete sync no longer returned the record (deleted or merged in QuickBooks).</p>',
        '<p>No QuickBooks customers match. If the list is empty altogether, the Web Connector has not completed a sync yet — see the QuickBooks status page.</p>'
      ),
      'cache_metadata' => $cache_meta,
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_title' => 'All customers',
      'display_plugin' => 'page',
      'position' => 1,
      'display_options' => ['display_extenders' => [], 'path' => 'admin/office/quickbooks/customers'],
      'cache_metadata' => $cache_meta,
    ],
    'page_2' => [
      'id' => 'page_2',
      'display_title' => 'Review',
      'display_plugin' => 'page',
      'position' => 2,
      'display_options' => [
        'display_extenders' => [],
        'path' => 'admin/office/quickbooks/review',
        'title' => 'QuickBooks customers to resolve',
        'defaults' => ['title' => FALSE, 'filters' => FALSE, 'filter_groups' => FALSE, 'header' => FALSE, 'empty' => FALSE],
        'header' => $text_area('<p>Active QuickBooks customers with no BOS client yet. <strong>Unmatched</strong> — no BOS client has this name; <strong>Ambiguous</strong> — two or more do (the note lists them; the Bill To address on the record usually settles it); <strong>Duplicate</strong> — another QuickBooks customer already points at that client (usually a merge in QuickBooks that BOS has not heard about). Open the row, pick the client, save. Mark a customer <em>Ignored</em> when it has no BOS client at all. Nothing is invoiced for a client until its row is off this list.</p>', FALSE),
        'empty' => $text_area('<p>Nothing to resolve — every active QuickBooks customer is linked to a BOS client or marked Ignored.</p>', TRUE),
        'filters' => $cust_filters_review,
        'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
      ],
      'cache_metadata' => $cache_meta,
    ],
  ],
]);

// Items.
$item_fields = [
  'title' => $base_field('title', 'QuickBooks item', 'string', ['link_to_entity' => TRUE]),
  'field_item_type' => $vf('field_item_type', 'Type', 'string', ['link_to_entity' => FALSE]),
  'field_income_account' => $vf('field_income_account', 'Income account', 'string', ['link_to_entity' => FALSE]),
  'field_price' => $vf('field_price', 'Price', 'number_decimal', ['thousand_separator' => ',', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE]),
  'field_description' => $vf('field_description', 'Description', 'basic_string', [], ['alter' => ['alter_text' => FALSE, 'max_length' => 200, 'trim' => TRUE, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'html' => FALSE, 'strip_tags' => FALSE]]),
  'status' => $status_col,
  'field_last_seen' => $date_col('field_last_seen', 'Last seen'),
  'field_missing' => $missing_col,
];
$item_info = ['title' => $col(TRUE), 'field_item_type' => $col(TRUE), 'field_income_account' => $col(TRUE), 'field_price' => $col(TRUE, 'views-align-right'), 'field_description' => $col(), 'status' => $col(TRUE), 'field_last_seen' => $col(TRUE), 'field_missing' => $col(TRUE, '', FALSE, 'desc')];
$item_filters = [
  'type' => $type_filter('item'),
  'title' => $title_filter,
  'field_item_type_value' => $string_filter('field_item_type', 'Item type'),
  'status' => $status_filter(TRUE),
  'field_missing_value' => $missing_filter(TRUE),
];
$write('views.view.qb_items', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => $view_deps(['field_description', 'field_income_account', 'field_item_type', 'field_last_seen', 'field_missing', 'field_price'], ['datetime', 'eck'], 'item'),
  'id' => 'qb_items',
  'label' => 'QuickBooks Items',
  'module' => 'views',
  'description' => 'Item list (services, other charges, inventory…) mirrored from QuickBooks Desktop by the Web Connector sync. Work-order services map to these for invoicing.',
  'tag' => 'quickbooks',
  'base_table' => ET . '_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_title' => 'Default',
      'display_plugin' => 'default',
      'position' => 0,
      'display_options' => $common_display(
        'QuickBooks Items',
        $item_fields,
        $item_info,
        $item_filters,
        '<p>Every item QuickBooks Desktop has, as of the last Web Connector sync — the names an invoice line must use. Sub-items show their parent path with colons (e.g. <em>Maintenance:Mowing</em>). Read-only: add or rename items in QuickBooks.</p>',
        '<p>No QuickBooks items match. If the list is empty altogether, the Web Connector has not completed a sync yet — see the QuickBooks status page.</p>'
      ),
      'cache_metadata' => $cache_meta,
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_title' => 'Items',
      'display_plugin' => 'page',
      'position' => 1,
      'display_options' => ['display_extenders' => [], 'path' => 'admin/office/quickbooks/items'],
      'cache_metadata' => $cache_meta,
    ],
  ],
]);

// Classes.
$class_fields = [
  'title' => $base_field('title', 'QuickBooks class', 'string', ['link_to_entity' => TRUE]),
  'status' => $status_col,
  'field_last_seen' => $date_col('field_last_seen', 'Last seen'),
  'field_missing' => $missing_col,
];
$class_info = ['title' => $col(TRUE), 'status' => $col(TRUE), 'field_last_seen' => $col(TRUE), 'field_missing' => $col(TRUE, '', FALSE, 'desc')];
$class_filters = [
  'type' => $type_filter('class'),
  'title' => $title_filter,
  'status' => $status_filter(TRUE),
  'field_missing_value' => $missing_filter(TRUE),
];
$write('views.view.qb_classes', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => $view_deps(['field_last_seen', 'field_missing'], ['datetime', 'eck'], 'class'),
  'id' => 'qb_classes',
  'label' => 'QuickBooks Classes',
  'module' => 'views',
  'description' => 'Class (department) list mirrored from QuickBooks Desktop by the Web Connector sync.',
  'tag' => 'quickbooks',
  'base_table' => ET . '_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_title' => 'Default',
      'display_plugin' => 'default',
      'position' => 0,
      'display_options' => $common_display(
        'QuickBooks Classes',
        $class_fields,
        $class_info,
        $class_filters,
        '<p>The classes QuickBooks Desktop tracks (one per department, used on every invoice line), as of the last Web Connector sync. Read-only.</p>',
        '<p>No QuickBooks classes yet — the Web Connector has not completed a sync. See the QuickBooks status page.</p>'
      ),
      'cache_metadata' => $cache_meta,
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_title' => 'Classes',
      'display_plugin' => 'page',
      'position' => 1,
      'display_options' => ['display_extenders' => [], 'path' => 'admin/office/quickbooks/classes'],
      'cache_metadata' => $cache_meta,
    ],
  ],
]);

// ── File list for the setup script + the partial-cim stage ──────────────
file_put_contents(__DIR__ . '/bos_quickbooks_config_files.txt', implode("\n", $files) . "\n");
echo count($files) . " config files written to config/sync (list: web/scripts/bos_quickbooks_config_files.txt)\n";
