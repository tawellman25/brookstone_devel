<?php

/**
 * @file
 * Sitewide footer — the date-driven `footer_promo` view. Shows the ONE currently
 * active promo (block_content:promo where active_from <= today <= active_until),
 * most-recently-started first, 1 item, renders nothing when none is active.
 *
 * Field handlers carry the COMPLETE default option set (esp. `alter`, which
 * FieldPluginBase::advancedRender() unions with `+` — a null there is a fatal
 * "array + null"; Views fills these in the UI, not on a raw config save).
 *
 * Cache: 'time', 1-hour lifespan, so the max-age bubbles to the page cache and a
 * date-boundary flip can never be frozen for more than an hour by BigPipe.
 *
 * Re-running deletes and recreates the view (so a fix re-applies cleanly).
 *
 *   ddev drush php:script web/scripts/build_footer_promo_view.php   (dev)
 *   drush php:script web/scripts/build_footer_promo_view.php        (live)
 */

use Drupal\views\Entity\View;

if ($existing = View::load('footer_promo')) {
  $existing->delete();
  echo "• removed existing footer_promo (recreating)\n";
}

$alter = [
  'alter_text' => FALSE, 'text' => '', 'make_link' => FALSE, 'path' => '', 'absolute' => FALSE,
  'external' => FALSE, 'replace_spaces' => FALSE, 'path_case' => 'none', 'trim_whitespace' => FALSE,
  'alt' => '', 'rel' => '', 'link_class' => '', 'prefix' => '', 'suffix' => '', 'target' => '',
  'nl2br' => FALSE, 'max_length' => 0, 'word_boundary' => TRUE, 'ellipsis' => TRUE, 'more_link' => FALSE,
  'more_link_text' => '', 'more_link_path' => '', 'strip_tags' => FALSE, 'trim' => FALSE,
  'preserve_tags' => '', 'html' => FALSE,
];
$common = [
  'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
  'label' => '', 'exclude' => FALSE, 'alter' => $alter,
  'element_type' => '', 'element_class' => '', 'element_label_type' => '', 'element_label_class' => '',
  'element_label_colon' => FALSE, 'element_wrapper_type' => '', 'element_wrapper_class' => '',
  'element_default_classes' => TRUE, 'empty' => '', 'hide_empty' => FALSE, 'empty_zero' => FALSE,
  'hide_alter_empty' => TRUE, 'click_sort_column' => 'value', 'settings' => [], 'group_column' => 'value',
  'group_columns' => [], 'group_rows' => TRUE, 'delta_limit' => 0, 'delta_offset' => 0,
  'delta_reversed' => FALSE, 'delta_first_last' => FALSE, 'multi_type' => 'separator', 'separator' => ', ',
  'field_api_classes' => FALSE, 'plugin_id' => 'field',
];

/** Build a field def with all defaults. */
function _promo_field(array $common, string $name, string $table, string $type): array {
  return $common + [
    'id' => $name, 'table' => $table, 'field' => $name,
    'entity_type' => 'block_content', 'entity_field' => $name, 'type' => $type,
  ];
}
$fields = [
  'field_promo_heading' => _promo_field($common, 'field_promo_heading', 'block_content__field_promo_heading', 'string'),
  'field_promo_body' => _promo_field($common, 'field_promo_body', 'block_content__field_promo_body', 'basic_string'),
  'field_promo_btn_label' => _promo_field($common, 'field_promo_btn_label', 'block_content__field_promo_btn_label', 'string'),
];

$display_options = [
  'title' => '',
  'fields' => $fields,
  'pager' => ['type' => 'some', 'options' => ['items_per_page' => 1, 'offset' => 0]],
  'exposed_form' => ['type' => 'basic', 'options' => []],
  'access' => ['type' => 'none', 'options' => []],
  'cache' => ['type' => 'time', 'options' => ['results_lifespan' => 3600, 'results_lifespan_custom' => 0, 'output_lifespan' => 3600, 'output_lifespan_custom' => 0]],
  'query' => ['type' => 'views_query', 'options' => []],
  'style' => ['type' => 'default', 'options' => ['row_class' => 'footer-promo', 'default_row_class' => TRUE]],
  'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
  'sorts' => [
    'field_promo_from_value' => [
      'id' => 'field_promo_from_value', 'table' => 'block_content__field_promo_from', 'field' => 'field_promo_from_value',
      'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '',
      'entity_type' => 'block_content', 'entity_field' => 'field_promo_from', 'plugin_id' => 'datetime',
      'order' => 'DESC', 'expose' => ['label' => ''], 'exposed' => FALSE, 'granularity' => 'second',
    ],
  ],
  'filters' => [
    'type' => [
      'id' => 'type', 'table' => 'block_content_field_data', 'field' => 'type', 'relationship' => 'none',
      'group_type' => 'group', 'admin_label' => '', 'entity_type' => 'block_content', 'entity_field' => 'type',
      'plugin_id' => 'bundle', 'operator' => 'in', 'value' => ['promo' => 'promo'], 'group' => 1,
    ],
    'status' => [
      'id' => 'status', 'table' => 'block_content_field_data', 'field' => 'status', 'relationship' => 'none',
      'group_type' => 'group', 'admin_label' => '', 'entity_type' => 'block_content', 'entity_field' => 'status',
      'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1', 'group' => 1,
    ],
    'field_promo_from_value' => [
      'id' => 'field_promo_from_value', 'table' => 'block_content__field_promo_from', 'field' => 'field_promo_from_value',
      'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'entity_type' => 'block_content',
      'entity_field' => 'field_promo_from', 'plugin_id' => 'datetime', 'operator' => '<=',
      'value' => ['min' => '', 'max' => '', 'value' => 'now', 'type' => 'offset'], 'group' => 1,
    ],
    'field_promo_until_value' => [
      'id' => 'field_promo_until_value', 'table' => 'block_content__field_promo_until', 'field' => 'field_promo_until_value',
      'relationship' => 'none', 'group_type' => 'group', 'admin_label' => '', 'entity_type' => 'block_content',
      'entity_field' => 'field_promo_until', 'plugin_id' => 'datetime', 'operator' => '>=',
      'value' => ['min' => '', 'max' => '', 'value' => 'now', 'type' => 'offset'], 'group' => 1,
    ],
  ],
];

$view = View::create([
  'id' => 'footer_promo',
  'label' => 'Footer: Seasonal promo',
  'module' => 'views',
  'base_table' => 'block_content_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Default', 'position' => 0, 'display_options' => $display_options],
    'block_1' => ['display_plugin' => 'block', 'id' => 'block_1', 'display_title' => 'Footer promo block', 'position' => 1, 'display_options' => ['display_description' => '', 'block_description' => 'Footer: Seasonal promo']],
  ],
]);
$view->save();
echo "• Created view footer_promo (block_1).\n";
