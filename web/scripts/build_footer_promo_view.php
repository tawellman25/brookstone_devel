<?php

/**
 * @file
 * Sitewide footer — the date-driven `footer_promo` view. Shows the ONE currently
 * active promo (block_content:promo where active_from <= today <= active_until),
 * most-recently-started first, 1 item, renders nothing when none is active.
 *
 * Cache: 'time' with a 1-hour lifespan so the max-age bubbles to the page cache
 * and a date-boundary flip can never be frozen for more than an hour by BigPipe /
 * Internal Page Cache. (Verified on dev — see the deploy notes.)
 *
 *   ddev drush php:script web/scripts/build_footer_promo_view.php   (dev)
 *   drush php:script web/scripts/build_footer_promo_view.php        (live)
 */

use Drupal\views\Entity\View;

if (View::load('footer_promo')) {
  echo "• view footer_promo already exists — leaving as-is.\n";
  return;
}

$fields = [
  'field_promo_heading' => ['table' => 'block_content__field_promo_heading', 'field' => 'field_promo_heading'],
  'field_promo_body' => ['table' => 'block_content__field_promo_body', 'field' => 'field_promo_body'],
  'field_promo_btn_label' => ['table' => 'block_content__field_promo_btn_label', 'field' => 'field_promo_btn_label'],
  'field_promo_btn_url' => ['table' => 'block_content__field_promo_btn_url', 'field' => 'field_promo_btn_url'],
];
$field_defs = [];
foreach ($fields as $name => $meta) {
  $field_defs[$name] = [
    'id' => $name,
    'table' => $meta['table'],
    'field' => $name,
    'entity_type' => 'block_content',
    'entity_field' => $name,
    'plugin_id' => 'field',
    'label' => '',
    'element_label_colon' => FALSE,
  ];
}

$display_options = [
  'title' => '',
  'fields' => $field_defs,
  'pager' => ['type' => 'some', 'options' => ['items_per_page' => 1, 'offset' => 0]],
  'exposed_form' => ['type' => 'basic'],
  'access' => ['type' => 'none'],
  'cache' => ['type' => 'time', 'options' => ['results_lifespan' => 3600, 'results_lifespan_custom' => 0, 'output_lifespan' => 3600, 'output_lifespan_custom' => 0]],
  'query' => ['type' => 'views_query', 'options' => []],
  'style' => ['type' => 'default', 'options' => ['row_class' => 'footer-promo', 'default_row_class' => TRUE]],
  'row' => ['type' => 'fields'],
  'sorts' => [
    'field_promo_from_value' => [
      'id' => 'field_promo_from_value',
      'table' => 'block_content__field_promo_from',
      'field' => 'field_promo_from_value',
      'entity_type' => 'block_content',
      'entity_field' => 'field_promo_from',
      'plugin_id' => 'datetime',
      'order' => 'DESC',
    ],
  ],
  'filters' => [
    'type' => [
      'id' => 'type',
      'table' => 'block_content_field_data',
      'field' => 'type',
      'entity_type' => 'block_content',
      'entity_field' => 'type',
      'plugin_id' => 'bundle',
      'operator' => 'in',
      'value' => ['promo' => 'promo'],
    ],
    'status' => [
      'id' => 'status',
      'table' => 'block_content_field_data',
      'field' => 'status',
      'entity_type' => 'block_content',
      'entity_field' => 'status',
      'plugin_id' => 'boolean',
      'operator' => '=',
      'value' => '1',
    ],
    'field_promo_from_value' => [
      'id' => 'field_promo_from_value',
      'table' => 'block_content__field_promo_from',
      'field' => 'field_promo_from_value',
      'entity_type' => 'block_content',
      'entity_field' => 'field_promo_from',
      'plugin_id' => 'datetime',
      'operator' => '<=',
      'value' => ['min' => '', 'max' => '', 'value' => 'now', 'type' => 'offset'],
    ],
    'field_promo_until_value' => [
      'id' => 'field_promo_until_value',
      'table' => 'block_content__field_promo_until',
      'field' => 'field_promo_until_value',
      'entity_type' => 'block_content',
      'entity_field' => 'field_promo_until',
      'plugin_id' => 'datetime',
      'operator' => '>=',
      'value' => ['min' => '', 'max' => '', 'value' => 'now', 'type' => 'offset'],
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
    'default' => [
      'display_plugin' => 'default',
      'id' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => $display_options,
    ],
    'block_1' => [
      'display_plugin' => 'block',
      'id' => 'block_1',
      'display_title' => 'Footer promo block',
      'position' => 1,
      'display_options' => ['display_description' => '', 'block_description' => 'Footer: Seasonal promo'],
    ],
  ],
]);
$view->save();
echo "• Created view footer_promo (block_1).\n";
