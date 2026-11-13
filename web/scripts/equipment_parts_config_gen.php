<?php

/**
 * @file
 * Generates the config/sync YAML for the equipment_part ECK entity
 * (Parts & Consumables) — entity type, bundle, base-field overrides, 17 field
 * storages/instances (15 on equipment_part, parts-used on
 * equipment_maintenance_event, parts-needed on equipment_defect), form + view
 * display, two views, pathauto pattern.
 *
 * Standalone — no Drupal bootstrap:
 *   ddev exec php web/scripts/equipment_parts_config_gen.php
 *
 * Idempotent: an existing file keeps its uuid, so a re-run after a spec change
 * rewrites content without churning identifiers. Apply with
 * web/scripts/equipment_parts_setup.php + a surgical partial cim (see
 * __BOS_AI/Entities/equipment_part.md → Deploy).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Uuid\Php as Uuid;

const ET = 'equipment_part';
const B = 'standard';
const EQUIPMENT_BUNDLES = ['attachements', 'heavy_equipment', 'power_tools', 'small_engine', 'snow_plows', 'sprayers', 'trailers', 'vehicles'];

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

// ── Entity type + bundle ────────────────────────────────────────────────
$write('eck.eck_entity_type.' . ET, [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => [],
  'id' => ET,
  'label' => 'Equipment Part',
  'uid' => TRUE,
  'created' => TRUE,
  'changed' => TRUE,
  'title' => TRUE,
  'standalone_url' => TRUE,
]);
$write('eck.eck_type.' . ET . '.' . B, [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['config' => ['eck.eck_entity_type.' . ET]],
  'name' => 'Standard',
  'type' => B,
  'description' => 'Shop part or consumable for owned equipment (small engine, mowers, trucks, ATVs). Internal stock only — not a billable material and never offered in estimate or work-order material pickers.',
]);

// Base-field relabels: the ECK title IS the part name; the ECK published flag
// IS the active/stocked flag (BOS rule 4 — retire, never delete).
$write('core.base_field_override.' . ET . '.' . B . '.title', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['config' => ['eck.eck_type.' . ET . '.' . B]],
  'id' => ET . '.' . B . '.title',
  'field_name' => 'title',
  'entity_type' => ET,
  'bundle' => B,
  'label' => 'Part Name',
  'description' => 'Short name as the shop knows it, e.g. "Spark Plug CMR6H" or "Trimmer line .095 round".',
  'required' => TRUE,
  'translatable' => TRUE,
  'default_value' => [],
  'default_value_callback' => '',
  'settings' => [],
  'field_type' => 'string',
]);
$write('core.base_field_override.' . ET . '.' . B . '.status', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['config' => ['eck.eck_type.' . ET . '.' . B]],
  'id' => ET . '.' . B . '.status',
  'field_name' => 'status',
  'entity_type' => ET,
  'bundle' => B,
  'label' => 'Active (stocked)',
  'description' => 'Untick when a part is discontinued or no longer stocked. It stays on record — maintenance events and defects keep pointing at it — but drops out of the active parts list.',
  'required' => FALSE,
  'translatable' => TRUE,
  'default_value' => [['value' => 1]],
  'default_value_callback' => '',
  'settings' => ['on_label' => 'Active', 'off_label' => 'Inactive'],
  'field_type' => 'boolean',
]);

// ── Fields ──────────────────────────────────────────────────────────────
$storage = static function (string $et, string $name, string $type, array $settings, array $modules, string $module = 'core', int $card = 1) use ($write): void {
  sort($modules);
  $write("field.storage.$et.$name", [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['module' => $modules],
    'id' => "$et.$name",
    'field_name' => $name,
    'entity_type' => $et,
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
$instance = static function (string $et, string $bundle, string $name, string $type, string $label, array $settings, array $opts = []) use ($write): void {
  $cfg = array_merge(["eck.eck_type.$et.$bundle", "field.storage.$et.$name"], $opts['config'] ?? []);
  $cfg = array_values(array_unique($cfg));
  sort($cfg);
  $deps = ['config' => $cfg];
  if (!empty($opts['module'])) {
    $deps['module'] = $opts['module'];
  }
  $write("field.field.$et.$bundle.$name", [
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => $deps,
    'id' => "$et.$bundle.$name",
    'field_name' => $name,
    'entity_type' => $et,
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
$ref = static fn(string $target) => ['target_type' => $target];
$number_inst = static fn($min = NULL, string $prefix = '', string $suffix = '') => ['min' => $min, 'max' => NULL, 'prefix' => $prefix, 'suffix' => $suffix];
$equipment_cfg_deps = array_map(static fn($b) => "eck.eck_type.equipment.$b", EQUIPMENT_BUNDLES);

$category_values = [
  ['value' => 'spark_plug', 'label' => 'Spark Plug'],
  ['value' => 'air_filter', 'label' => 'Air Filter'],
  ['value' => 'fuel_filter', 'label' => 'Fuel Filter'],
  ['value' => 'carb_kit', 'label' => 'Carb Kit / Diaphragm'],
  ['value' => 'fuel_line_primer', 'label' => 'Fuel Line / Primer'],
  ['value' => 'cutting_line', 'label' => 'Cutting Line'],
  ['value' => 'cutting_blade', 'label' => 'Cutting Blade'],
  ['value' => 'belt_pulley', 'label' => 'Belt / Pulley'],
  ['value' => 'oil_fluid', 'label' => 'Oil / Fluid'],
  ['value' => 'gearhead_grease', 'label' => 'Gearhead Grease'],
  ['value' => 'starter_cord', 'label' => 'Starter / Cord'],
  ['value' => 'hardware', 'label' => 'Hardware'],
  ['value' => 'other', 'label' => 'Other'],
];

// 1. Identity.
$storage(ET, 'field_part_number', 'string', $string(64), ['eck']);
$instance(ET, B, 'field_part_number', 'string', 'Part Number', [], [
  'required' => TRUE,
  'description' => 'Manufacturer or vendor part number exactly as printed (e.g. 4237 120 1800, CMR6H). Unique across the catalog.',
]);
$storage(ET, 'field_part_category', 'list_string', ['allowed_values' => $category_values, 'allowed_values_function' => ''], ['eck', 'options'], 'options');
$instance(ET, B, 'field_part_category', 'list_string', 'Category', [], ['required' => TRUE, 'module' => ['options']]);
$storage(ET, 'field_manufacturer', 'entity_reference', $ref('manufacturer'), ['eck']);
$instance(ET, B, 'field_manufacturer', 'entity_reference', 'Brand / Manufacturer', [
  'handler' => 'default:manufacturer',
  'handler_settings' => ['target_bundles' => ['manufacturer' => 'manufacturer'], 'sort' => ['field' => 'title', 'direction' => 'ASC'], 'auto_create' => FALSE],
], ['config' => ['eck.eck_type.manufacturer.manufacturer'], 'description' => 'Who makes it (NGK, Stihl, Oregon…). Add the brand under Manufacturers first.']);
$storage(ET, 'field_oem_cross', 'string', $string(255), ['eck'], 'core', -1);
$instance(ET, B, 'field_oem_cross', 'string', 'OEM / Cross-Reference Numbers', [], [
  'description' => 'Other numbers this part is known by — OEM equivalent, aftermarket substitute, superseded number. One per line.',
]);
$storage(ET, 'field_part_photo', 'image', [
  'target_type' => 'file',
  'display_field' => FALSE,
  'display_default' => FALSE,
  'uri_scheme' => 'public',
  'default_image' => ['uuid' => '', 'alt' => '', 'title' => '', 'width' => NULL, 'height' => NULL],
], ['eck', 'file', 'image'], 'image', -1);
$instance(ET, B, 'field_part_photo', 'image', 'Photo', [
  'handler' => 'default:file',
  'handler_settings' => [],
  'file_directory' => 'equipment/parts',
  'file_extensions' => 'png gif jpg jpeg webp',
  'max_filesize' => '',
  'max_resolution' => '',
  'min_resolution' => '',
  'alt_field' => TRUE,
  'alt_field_required' => TRUE,
  'title_field' => FALSE,
  'title_field_required' => FALSE,
  'default_image' => ['uuid' => '', 'alt' => '', 'title' => '', 'width' => NULL, 'height' => NULL],
], ['module' => ['image'], 'description' => 'A picture of the part or its packaging — the quickest way to confirm a match on the shelf.']);

// 2. Fit.
$storage(ET, 'field_equipment_types', 'entity_reference', $ref('taxonomy_term'), ['eck', 'taxonomy'], 'core', -1);
$instance(ET, B, 'field_equipment_types', 'entity_reference', 'Fits Equipment Type(s)', [
  'handler' => 'default:taxonomy_term',
  'handler_settings' => ['target_bundles' => ['equipment_types' => 'equipment_types'], 'sort' => ['field' => 'name', 'direction' => 'asc'], 'auto_create' => FALSE],
], ['config' => ['taxonomy.vocabulary.equipment_types'], 'description' => 'The kind(s) of machine this part is for — the same Equipment Types the fleet itself is classified by. Pick several for a part that fits more than one type.']);
$storage(ET, 'field_fits', 'entity_reference', $ref('equipment'), ['eck'], 'core', -1);
$instance(ET, B, 'field_fits', 'entity_reference', 'Fits These Units', [
  'handler' => 'default:equipment',
  'handler_settings' => ['target_bundles' => array_combine(EQUIPMENT_BUNDLES, EQUIPMENT_BUNDLES), 'sort' => ['field' => '_none', 'direction' => 'ASC'], 'auto_create' => FALSE],
], ['config' => $equipment_cfg_deps, 'description' => 'Specific machines in the fleet this part fits. Each machine lists its parts on its own page.']);

// 3. Stock.
$storage(ET, 'field_bin_location', 'string', $string(64), ['eck']);
$instance(ET, B, 'field_bin_location', 'string', 'Bin / Shelf Location', [], ['description' => 'Where it lives in the shop, e.g. "Bay 2, shelf C, bin 14".']);
$storage(ET, 'field_on_hand', 'integer', ['unsigned' => TRUE, 'size' => 'normal'], ['eck']);
$instance(ET, B, 'field_on_hand', 'integer', 'On Hand', $number_inst(0), ['default' => [['value' => 0]]]);
$storage(ET, 'field_reorder_min', 'integer', ['unsigned' => TRUE, 'size' => 'normal'], ['eck']);
$instance(ET, B, 'field_reorder_min', 'integer', 'Reorder Minimum', $number_inst(0), [
  'default' => [['value' => 0]],
  'description' => 'Flag for reorder when On Hand is at or below this number. 0 = no minimum, never flagged.',
]);
$storage(ET, 'field_needs_reorder', 'boolean', [], ['eck']);
$instance(ET, B, 'field_needs_reorder', 'boolean', 'Needs Reorder', ['on_label' => 'Reorder', 'off_label' => 'OK'], [
  'default' => [['value' => 0]],
  'description' => 'Set automatically on every save: On Hand ≤ Reorder Minimum (when a minimum is set). Not hand-edited.',
]);

// 4. Purchasing.
$storage(ET, 'field_vendor', 'entity_reference', $ref('supplier'), ['eck']);
$instance(ET, B, 'field_vendor', 'entity_reference', 'Vendor', [
  'handler' => 'default:supplier',
  'handler_settings' => ['target_bundles' => ['supplier' => 'supplier'], 'sort' => ['field' => 'title', 'direction' => 'ASC'], 'auto_create' => FALSE],
], ['config' => ['eck.eck_type.supplier.supplier'], 'description' => 'Where we buy it. Add a new vendor under Suppliers first.']);
$storage(ET, 'field_last_price', 'decimal', ['precision' => 10, 'scale' => 2], ['eck']);
$instance(ET, B, 'field_last_price', 'decimal', 'Last Price', $number_inst(0, '$'), ['description' => 'What we paid last time, per unit.']);
$storage(ET, 'field_date_priced', 'datetime', ['datetime_type' => 'date'], ['datetime', 'eck'], 'datetime');
$instance(ET, B, 'field_date_priced', 'datetime', 'Date Priced', [], ['description' => 'When Last Price was recorded.']);

// 5. Notes.
$storage(ET, 'field_part_notes', 'text_long', [], ['eck', 'text'], 'text');
$instance(ET, B, 'field_part_notes', 'text_long', 'Notes', ['allowed_formats' => []], ['module' => ['text']]);

// 6. Links from the maintenance machinery back to parts.
$storage('equipment_maintenance_event', 'field_parts_used', 'entity_reference', $ref(ET), ['eck'], 'core', -1);
$instance('equipment_maintenance_event', 'standard', 'field_parts_used', 'entity_reference', 'Parts Used', [
  'handler' => 'default:' . ET,
  'handler_settings' => ['target_bundles' => [B => B], 'sort' => ['field' => 'title', 'direction' => 'ASC'], 'auto_create' => FALSE],
], ['config' => ['eck.eck_type.' . ET . '.' . B], 'description' => 'Shop parts consumed by this service/repair. Stock is not adjusted automatically (yet) — update On Hand on the part.']);
$storage('equipment_defect', 'field_parts_needed', 'entity_reference', $ref(ET), ['eck'], 'core', -1);
$instance('equipment_defect', 'standard', 'field_parts_needed', 'entity_reference', 'Parts Needed', [
  'handler' => 'default:' . ET,
  'handler_settings' => ['target_bundles' => [B => B], 'sort' => ['field' => 'title', 'direction' => 'ASC'], 'auto_create' => FALSE],
], ['config' => ['eck.eck_type.' . ET . '.' . B], 'description' => 'Shop parts this repair will need — check the parts list for stock before scheduling.']);

// ── Form display ────────────────────────────────────────────────────────
$part_fields = [
  'field_part_number', 'field_part_category', 'field_manufacturer', 'field_oem_cross', 'field_part_photo',
  'field_equipment_types', 'field_fits',
  'field_bin_location', 'field_on_hand', 'field_reorder_min', 'field_needs_reorder',
  'field_vendor', 'field_last_price', 'field_date_priced',
  'field_part_notes',
];
$field_cfg_deps = array_map(static fn($f) => 'field.field.' . ET . '.' . B . '.' . $f, $part_fields);
$group = static fn(array $children, string $label, int $weight, bool $open = TRUE) => [
  'children' => $children,
  'label' => $label,
  'region' => 'content',
  'parent_name' => '',
  'weight' => $weight,
  'format_type' => 'details',
  'format_settings' => ['classes' => '', 'show_empty_fields' => FALSE, 'id' => '', 'open' => $open, 'description' => '', 'required_fields' => TRUE],
];
$w = static fn(string $type, int $weight, array $settings = []) => ['type' => $type, 'weight' => $weight, 'region' => 'content', 'settings' => $settings, 'third_party_settings' => []];
$text = static fn(int $size) => ['size' => $size, 'placeholder' => ''];
$autocomplete = ['match_operator' => 'CONTAINS', 'match_limit' => 10, 'size' => 60, 'placeholder' => ''];
$cfg = array_merge(['eck.eck_type.' . ET . '.' . B], $field_cfg_deps);
sort($cfg);
$write('core.entity_form_display.' . ET . '.' . B . '.default', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['config' => $cfg, 'module' => ['datetime', 'field_group', 'image', 'options', 'text']],
  'third_party_settings' => [
    'field_group' => [
      'group_identity' => $group(['title', 'field_part_number', 'field_part_category', 'field_manufacturer', 'field_oem_cross', 'field_part_photo'], 'Part', 0),
      'group_fit' => $group(['field_equipment_types', 'field_fits'], 'What it fits', 1),
      'group_stock' => $group(['field_bin_location', 'field_on_hand', 'field_reorder_min'], 'Stock', 2),
      'group_purchasing' => $group(['field_vendor', 'field_last_price', 'field_date_priced'], 'Purchasing', 3),
      'group_notes' => $group(['field_part_notes', 'status'], 'Notes & status', 4),
    ],
  ],
  'id' => ET . '.' . B . '.default',
  'targetEntityType' => ET,
  'bundle' => B,
  'mode' => 'default',
  'content' => [
    'title' => $w('string_textfield', 0, $text(60)),
    'field_part_number' => $w('string_textfield', 1, $text(32)),
    'field_part_category' => $w('options_select', 2),
    'field_manufacturer' => $w('entity_reference_autocomplete', 3, $autocomplete),
    'field_oem_cross' => $w('string_textfield', 4, $text(60)),
    'field_part_photo' => $w('image_image', 5, ['progress_indicator' => 'throbber', 'preview_image_style' => 'thumbnail']),
    'field_equipment_types' => $w('options_select', 6),
    'field_fits' => $w('entity_reference_autocomplete', 7, $autocomplete),
    'field_bin_location' => $w('string_textfield', 8, $text(32)),
    'field_on_hand' => $w('number', 9, ['placeholder' => '']),
    'field_reorder_min' => $w('number', 10, ['placeholder' => '']),
    'field_vendor' => $w('entity_reference_autocomplete', 11, $autocomplete),
    'field_last_price' => $w('number', 12, ['placeholder' => '']),
    'field_date_priced' => $w('datetime_default', 13),
    'field_part_notes' => $w('text_textarea', 14, ['rows' => 5, 'placeholder' => '']),
    'status' => $w('boolean_checkbox', 15, ['display_label' => TRUE]),
  ],
  'hidden' => ['created' => TRUE, 'field_needs_reorder' => TRUE, 'path' => TRUE, 'uid' => TRUE],
]);

// ── View display ────────────────────────────────────────────────────────
$f = static fn(string $type, int $weight, array $settings = [], string $label = 'inline') => ['type' => $type, 'label' => $label, 'settings' => $settings, 'third_party_settings' => [], 'weight' => $weight, 'region' => 'content'];
$write('core.entity_view_display.' . ET . '.' . B . '.default', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['config' => $cfg, 'module' => ['datetime', 'image', 'options', 'text']],
  'id' => ET . '.' . B . '.default',
  'targetEntityType' => ET,
  'bundle' => B,
  'mode' => 'default',
  'content' => [
    'field_part_photo' => $f('image', 0, ['image_link' => '', 'image_style' => 'medium', 'image_loading' => ['attribute' => 'lazy']], 'hidden'),
    'field_part_number' => $f('string', 1, ['link_to_entity' => FALSE]),
    'field_part_category' => $f('list_default', 2),
    'field_manufacturer' => $f('entity_reference_label', 3, ['link' => TRUE]),
    'field_oem_cross' => $f('string', 4, ['link_to_entity' => FALSE]),
    'field_equipment_types' => $f('entity_reference_label', 5, ['link' => TRUE]),
    'field_fits' => $f('entity_reference_label', 6, ['link' => TRUE]),
    'field_bin_location' => $f('string', 7, ['link_to_entity' => FALSE]),
    'field_on_hand' => $f('number_integer', 8, ['thousand_separator' => ',', 'prefix_suffix' => TRUE]),
    'field_reorder_min' => $f('number_integer', 9, ['thousand_separator' => ',', 'prefix_suffix' => TRUE]),
    'field_needs_reorder' => $f('boolean', 10, ['format' => 'custom', 'format_custom_true' => '⚠ Reorder', 'format_custom_false' => 'OK']),
    'field_vendor' => $f('entity_reference_label', 11, ['link' => TRUE]),
    'field_last_price' => $f('number_decimal', 12, ['thousand_separator' => ',', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE]),
    'field_date_priced' => $f('datetime_custom', 13, ['timezone_override' => '', 'date_format' => 'm/d/Y']),
    'field_part_notes' => $f('text_default', 14, [], 'above'),
    'status' => $f('boolean', 15, ['format' => 'custom', 'format_custom_true' => 'Active', 'format_custom_false' => 'Inactive']),
  ],
  'hidden' => ['created' => TRUE, 'search_api_excerpt' => TRUE, 'uid' => TRUE],
]);

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
$title_field = [
  'id' => 'title',
  'table' => ET . '_field_data',
  'field' => 'title',
  'entity_type' => ET,
  'entity_field' => 'title',
  'plugin_id' => 'field',
  'label' => 'Part Name',
  'type' => 'string',
  'settings' => ['link_to_entity' => TRUE],
];
$multi = ['multi_type' => 'separator', 'separator' => ', '];
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
$string_filter = static fn(string $field, string $label, string $id = NULL) => [
  'id' => $id ?? $field . '_value',
  'table' => ET . '__' . $field,
  'field' => $field . '_value',
  'plugin_id' => 'string',
  'operator' => 'contains',
  'value' => '',
  'group' => 1,
  'exposed' => TRUE,
  'expose' => $expose($id ?? $field . '_value', $label, ['placeholder' => '']),
];
$status_filter = static fn(bool $exposed) => array_merge([
  'id' => 'status',
  'table' => ET . '_field_data',
  'field' => 'status',
  'entity_type' => ET,
  'entity_field' => 'status',
  'plugin_id' => 'boolean',
  'operator' => '=',
  'value' => '1',
  'group' => 1,
  'exposed' => $exposed,
], $exposed ? ['expose' => $expose('status', 'Active')] : []);
$needs_reorder_filter = static fn(bool $exposed) => array_merge([
  'id' => 'field_needs_reorder_value',
  'table' => ET . '__field_needs_reorder',
  'field' => 'field_needs_reorder_value',
  'plugin_id' => 'boolean',
  'operator' => '=',
  'value' => $exposed ? 'All' : '1',
  'group' => 1,
  'exposed' => $exposed,
], $exposed ? ['expose' => $expose('field_needs_reorder_value', 'Needs reorder')] : []);
$category_filter = [
  'id' => 'field_part_category_value',
  'table' => ET . '__field_part_category',
  'field' => 'field_part_category_value',
  'plugin_id' => 'list_field',
  'operator' => 'or',
  'value' => [],
  'group' => 1,
  'exposed' => TRUE,
  'expose' => $expose('field_part_category_value', 'Category', ['multiple' => TRUE, 'reduce' => FALSE]),
];
// Core wires a term-reference column to its taxonomy filter (select of the
// vocabulary's terms) — the right widget here; it needs the vocabulary set.
$types_filter = [
  'id' => 'field_equipment_types_target_id',
  'table' => ET . '__field_equipment_types',
  'field' => 'field_equipment_types_target_id',
  'plugin_id' => 'taxonomy_index_tid',
  'operator' => 'or',
  'value' => [],
  'group' => 1,
  'exposed' => TRUE,
  'expose' => $expose('field_equipment_types_target_id', 'Equipment type', ['multiple' => TRUE, 'reduce' => FALSE]),
  'reduce_duplicates' => FALSE,
  'vid' => 'equipment_types',
  'type' => 'select',
  'hierarchy' => FALSE,
  'limit' => TRUE,
  'error_message' => TRUE,
];
// field_fits gets core's entity_reference filter (autocomplete on the fleet) —
// wired onto the column by bos_equipment_parts_views_data_alter(); core itself
// leaves non-taxonomy reference columns on "numeric".
$fits_filter = [
  'id' => 'field_fits_target_id',
  'table' => ET . '__field_fits',
  'field' => 'field_fits_target_id',
  'plugin_id' => 'entity_reference',
  'operator' => 'or',
  'value' => [],
  'group' => 1,
  'exposed' => TRUE,
  'expose' => $expose('field_fits_target_id', 'Fits unit', ['multiple' => FALSE, 'reduce' => FALSE]),
  'sub_handler' => 'default:equipment',
  'sub_handler_settings' => ['target_bundles' => array_combine(EQUIPMENT_BUNDLES, EQUIPMENT_BUNDLES), 'sort' => ['field' => '_none', 'direction' => 'ASC'], 'auto_create' => FALSE],
  'widget' => 'autocomplete',
];
$pager_full = ['type' => 'full', 'options' => [
  'pagination_heading_level' => 'h4', 'items_per_page' => 50, 'offset' => 0,
  'tags' => ['previous' => '‹ Previous', 'next' => 'Next ›', 'first' => '« First', 'last' => 'Last »'],
  'expose' => ['items_per_page' => TRUE, 'items_per_page_label' => 'Items per page', 'items_per_page_options' => '25, 50, 100, 200', 'items_per_page_options_all' => TRUE, 'items_per_page_options_all_label' => '- All -', 'offset' => FALSE, 'offset_label' => 'Offset'],
  'quantity' => 9,
]];
$text_area = static fn(string $html, bool $empty) => ['area' => ['id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text', 'empty' => $empty, 'content' => ['value' => $html, 'format' => 'full_html']]];
$list_fields = [
  'field_part_number' => $vf('field_part_number', 'Part #', 'string', ['link_to_entity' => TRUE]),
  'title' => $title_field,
  'field_part_category' => $vf('field_part_category', 'Category', 'list_default', []),
  'field_equipment_types' => $vf('field_equipment_types', 'Equipment type', 'entity_reference_label', ['link' => FALSE], $multi),
  'field_fits' => $vf('field_fits', 'Fits units', 'entity_reference_label', ['link' => TRUE], $multi),
  'field_bin_location' => $vf('field_bin_location', 'Bin', 'string', ['link_to_entity' => FALSE]),
  'field_on_hand' => $vf('field_on_hand', 'On hand', 'number_integer', ['thousand_separator' => ',', 'prefix_suffix' => FALSE]),
  'field_reorder_min' => $vf('field_reorder_min', 'Min', 'number_integer', ['thousand_separator' => ',', 'prefix_suffix' => FALSE]),
  'field_needs_reorder' => $vf('field_needs_reorder', 'Reorder', 'boolean', ['format' => 'custom', 'format_custom_true' => '⚠ Reorder', 'format_custom_false' => ''], ['element_class' => 'bos-parts-reorder', 'element_default_classes' => TRUE]),
  'field_vendor' => $vf('field_vendor', 'Vendor', 'entity_reference_label', ['link' => TRUE]),
  'field_last_price' => $vf('field_last_price', 'Last price', 'number_decimal', ['thousand_separator' => ',', 'decimal_separator' => '.', 'scale' => 2, 'prefix_suffix' => TRUE]),
  'field_date_priced' => $vf('field_date_priced', 'Priced', 'datetime_custom', ['timezone_override' => '', 'date_format' => 'm/d/Y']),
  'operations' => ['id' => 'operations', 'table' => ET, 'field' => 'operations', 'entity_type' => ET, 'plugin_id' => 'entity_operations', 'label' => 'Operations', 'destination' => TRUE],
];
$list_columns = array_combine(array_keys($list_fields), array_keys($list_fields));
$list_info = [
  'field_part_number' => $col(TRUE),
  'title' => $col(TRUE),
  'field_part_category' => $col(TRUE),
  'field_equipment_types' => $col(),
  'field_fits' => $col(FALSE, '', TRUE),
  'field_bin_location' => $col(TRUE),
  'field_on_hand' => $col(TRUE, 'views-align-right'),
  'field_reorder_min' => $col(FALSE, 'views-align-right'),
  'field_needs_reorder' => $col(TRUE, '', FALSE, 'desc'),
  'field_vendor' => $col(TRUE),
  'field_last_price' => $col(TRUE, 'views-align-right'),
  'field_date_priced' => $col(TRUE),
  'operations' => $col(),
];
$roles = ['administrator', 'site_admin', 'site_assistant', 'administration', 'supervisor'];
$view_storage_deps = static fn(array $fields) => array_map(static fn($f) => 'field.storage.' . ET . '.' . $f, $fields);
$deps = array_merge($view_storage_deps(['field_bin_location', 'field_date_priced', 'field_equipment_types', 'field_fits', 'field_last_price', 'field_needs_reorder', 'field_on_hand', 'field_part_category', 'field_part_number', 'field_reorder_min', 'field_vendor']), array_map(static fn($r) => "user.role.$r", $roles));
sort($deps);
$cache_meta = ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url', 'url.query_args', 'user.roles'], 'tags' => []];
$write('views.view.equipment_parts', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['config' => $deps, 'module' => ['datetime', 'eck', 'options', 'taxonomy', 'user']],
  'id' => 'equipment_parts',
  'label' => 'Equipment Parts & Consumables',
  'module' => 'views',
  'description' => 'Shop parts/consumables catalog for owned equipment (equipment_part). Filter by category, equipment type, unit; reorder flag.',
  'tag' => 'equipment',
  'base_table' => ET . '_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_title' => 'Default',
      'display_plugin' => 'default',
      'position' => 0,
      'display_options' => [
        'title' => 'Parts & Consumables',
        'fields' => $list_fields,
        'pager' => $pager_full,
        'access' => ['type' => 'role', 'options' => ['role' => array_combine($roles, $roles)]],
        'cache' => ['type' => 'tag', 'options' => []],
        'header' => $text_area('<p>Shop parts and consumables for our own equipment (plugs, filters, carb kits, line, blades, belts, oil, hardware). Internal stock only — never offered as a billable material on an estimate or work order. <strong>⚠ Reorder</strong> means On Hand is at or below the part\'s Reorder Minimum.</p>', FALSE),
        'empty' => $text_area('<p>No parts match. Clear the filters, or add the first part with the button above.</p>', TRUE),
        'sorts' => ['title' => ['id' => 'title', 'table' => ET . '_field_data', 'field' => 'title', 'entity_type' => ET, 'entity_field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC', 'exposed' => FALSE]],
        'filters' => [
          'status' => $status_filter(TRUE),
          'title' => array_merge($string_filter('title', 'Part name', 'title'), ['table' => ET . '_field_data', 'field' => 'title', 'entity_type' => ET, 'entity_field' => 'title']),
          'field_part_number_value' => $string_filter('field_part_number', 'Part #'),
          'field_part_category_value' => $category_filter,
          'field_equipment_types_target_id' => $types_filter,
          'field_fits_target_id' => $fits_filter,
          'field_needs_reorder_value' => $needs_reorder_filter(TRUE),
        ],
        'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
        'style' => ['type' => 'table', 'options' => [
          'grouping' => [], 'row_class' => '', 'default_row_class' => TRUE,
          'columns' => $list_columns, 'default' => 'title', 'info' => $list_info,
          'override' => TRUE, 'sticky' => TRUE, 'summary' => '', 'empty_table' => TRUE, 'caption' => '', 'description' => '',
        ]],
        'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
        'query' => ['type' => 'views_query', 'options' => []],
        'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Filter', 'reset_button' => TRUE, 'reset_button_label' => 'Reset', 'exposed_sorts_label' => 'Sort by', 'expose_sort_order' => TRUE, 'sort_asc_label' => 'Asc', 'sort_desc_label' => 'Desc']],
        'display_extenders' => [],
      ],
      'cache_metadata' => $cache_meta,
    ],
    'page_1' => [
      'id' => 'page_1',
      'display_title' => 'All parts',
      'display_plugin' => 'page',
      'position' => 1,
      'display_options' => [
        'display_extenders' => [],
        'path' => 'admin/operations/equipment/parts',
        'menu' => ['type' => 'normal', 'title' => 'Parts & Consumables', 'description' => 'Shop parts and consumables for owned equipment, with reorder flags.', 'weight' => 5, 'expanded' => FALSE, 'menu_name' => 'admin', 'parent' => 'views_view:views.equipment.page_1', 'context' => '0'],
      ],
      'cache_metadata' => $cache_meta,
    ],
    'page_2' => [
      'id' => 'page_2',
      'display_title' => 'Reorder list',
      'display_plugin' => 'page',
      'position' => 2,
      'display_options' => [
        'display_extenders' => [],
        'path' => 'admin/operations/equipment/parts/reorder',
        'title' => 'Parts to reorder',
        'defaults' => ['title' => FALSE, 'filters' => FALSE, 'filter_groups' => FALSE, 'header' => FALSE, 'empty' => FALSE],
        'header' => $text_area('<p>Active parts whose On Hand count is at or below their Reorder Minimum. Receive stock by editing the part and raising On Hand — the flag clears on save.</p>', FALSE),
        'empty' => $text_area('<p>Nothing to reorder — every tracked part is above its minimum.</p>', TRUE),
        'filters' => [
          'status' => $status_filter(FALSE),
          'field_needs_reorder_value' => $needs_reorder_filter(FALSE),
          'field_part_category_value' => $category_filter,
          'field_equipment_types_target_id' => $types_filter,
        ],
        'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
      ],
      'cache_metadata' => $cache_meta,
    ],
  ],
]);

// "Parts that fit this unit" — embedded on each equipment page by
// bos_equipment_parts_equipment_view() with the machine's id as the argument.
$fit_fields = [
  'field_part_number' => $vf('field_part_number', 'Part #', 'string', ['link_to_entity' => TRUE]),
  'title' => $title_field,
  'field_part_category' => $vf('field_part_category', 'Category', 'list_default', []),
  'field_bin_location' => $vf('field_bin_location', 'Bin', 'string', ['link_to_entity' => FALSE]),
  'field_on_hand' => $vf('field_on_hand', 'On hand', 'number_integer', ['thousand_separator' => ',', 'prefix_suffix' => FALSE]),
  'field_needs_reorder' => $vf('field_needs_reorder', 'Reorder', 'boolean', ['format' => 'custom', 'format_custom_true' => '⚠ Reorder', 'format_custom_false' => ''], ['element_class' => 'bos-parts-reorder', 'element_default_classes' => TRUE]),
];
$fit_deps = $view_storage_deps(['field_bin_location', 'field_fits', 'field_needs_reorder', 'field_on_hand', 'field_part_category', 'field_part_number']);
sort($fit_deps);
$write('views.view.equipment_parts_fit', [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['config' => $fit_deps, 'module' => ['eck', 'options', 'user']],
  'id' => 'equipment_parts_fit',
  'label' => 'Equipment Parts — fits this unit',
  'module' => 'views',
  'description' => 'Parts whose "Fits These Units" includes the machine (argument = equipment id). Embedded on each equipment page by bos_equipment_parts.',
  'tag' => 'equipment',
  'base_table' => ET . '_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default',
      'display_title' => 'Default',
      'display_plugin' => 'default',
      'position' => 0,
      'display_options' => [
        'title' => 'Parts that fit this unit',
        'fields' => $fit_fields,
        'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
        'access' => ['type' => 'perm', 'options' => ['perm' => 'view any equipment_part entities']],
        'cache' => ['type' => 'tag', 'options' => []],
        'empty' => $text_area('<p>No parts are linked to this unit yet.</p>', TRUE),
        'sorts' => ['title' => ['id' => 'title', 'table' => ET . '_field_data', 'field' => 'title', 'entity_type' => ET, 'entity_field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC', 'exposed' => FALSE]],
        'arguments' => ['field_fits_target_id' => [
          'id' => 'field_fits_target_id',
          'table' => ET . '__field_fits',
          'field' => 'field_fits_target_id',
          'plugin_id' => 'entity_target_id',
          'default_action' => 'not found',
          'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
          'title_enable' => FALSE,
          'title' => '',
          'default_argument_type' => 'fixed',
          'default_argument_options' => ['argument' => ''],
          'summary_options' => ['base_path' => '', 'count' => TRUE, 'override' => FALSE, 'items_per_page' => 25],
          'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
          'specify_validation' => FALSE,
          'validate' => ['type' => 'none', 'fail' => 'not found'],
          'validate_options' => [],
          'break_phrase' => FALSE,
          'not' => FALSE,
          'target_entity_type_id' => 'equipment',
        ]],
        'filters' => ['status' => $status_filter(FALSE)],
        'filter_groups' => ['operator' => 'AND', 'groups' => [1 => 'AND']],
        'style' => ['type' => 'table', 'options' => [
          'grouping' => [], 'row_class' => '', 'default_row_class' => TRUE,
          'columns' => array_combine(array_keys($fit_fields), array_keys($fit_fields)),
          'default' => 'title',
          'info' => [
            'field_part_number' => $col(TRUE), 'title' => $col(TRUE), 'field_part_category' => $col(TRUE),
            'field_bin_location' => $col(), 'field_on_hand' => $col(FALSE, 'views-align-right'), 'field_needs_reorder' => $col(),
          ],
          'override' => TRUE, 'sticky' => FALSE, 'summary' => '', 'empty_table' => FALSE, 'caption' => '', 'description' => '',
        ]],
        'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
        'query' => ['type' => 'views_query', 'options' => []],
        'display_extenders' => [],
      ],
      'cache_metadata' => ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url', 'user.permissions'], 'tags' => []],
    ],
    'embed_1' => [
      'id' => 'embed_1',
      'display_title' => 'Embed',
      'display_plugin' => 'embed',
      'position' => 1,
      'display_options' => ['display_extenders' => []],
      'cache_metadata' => ['max-age' => -1, 'contexts' => ['languages:language_content', 'languages:language_interface', 'url', 'user.permissions'], 'tags' => []],
    ],
  ],
]);

// ── Pathauto ────────────────────────────────────────────────────────────
$write('pathauto.pattern.' . ET, [
  'langcode' => 'en',
  'status' => TRUE,
  'dependencies' => ['module' => ['eck']],
  'id' => ET,
  'label' => 'Equipment Part',
  'type' => 'canonical_entities:' . ET,
  'pattern' => 'equipment/parts/[equipment_part:field_part_number]',
  'selection_criteria' => [],
  'selection_logic' => 'and',
  'weight' => 0,
  'relationships' => [],
]);

echo count($files) . " files written to config/sync:\n  " . implode("\n  ", $files) . "\n";
file_put_contents($sync . '/../../web/scripts/equipment_parts_config_files.txt', implode("\n", $files) . "\n");
