<?php

/**
 * "Towns We Serve" EVA on the county page — one card per city in that county
 * (arg = host county id), with banner (if present), city name (linked) and a
 * short description line. Reuses the county-card styling. Auto-populates as
 * cities are added.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_county_cities_view.php
 */

use Drupal\views\Entity\View;

if (View::load('county_cities')) {
  View::load('county_cities')->delete();
}

$default = [
  'title' => '',
  'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
  'cache' => ['type' => 'tag'],
  'query' => ['type' => 'views_query'],
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => ['type' => 'default', 'options' => ['row_class' => 'state-county-card', 'default_row_class' => TRUE]],
  'row' => ['type' => 'fields'],
  'fields' => [
    'field_banner_image' => [
      'id' => 'field_banner_image', 'table' => 'city__field_banner_image', 'field' => 'field_banner_image',
      'relationship' => 'none', 'plugin_id' => 'field', 'label' => '', 'exclude' => FALSE,
      'type' => 'image', 'settings' => ['image_style' => 'medium', 'image_link' => 'content'],
    ],
    'field_city_name' => [
      'id' => 'field_city_name', 'table' => 'city__field_city_name', 'field' => 'field_city_name',
      'relationship' => 'none', 'plugin_id' => 'field', 'label' => '', 'exclude' => FALSE,
      'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
    ],
    'field_city_description' => [
      'id' => 'field_city_description', 'table' => 'city__field_city_description', 'field' => 'field_city_description',
      'relationship' => 'none', 'plugin_id' => 'field', 'label' => '', 'exclude' => FALSE,
      'type' => 'text_trimmed', 'settings' => ['trim_length' => 180],
    ],
  ],
  'arguments' => [
    'field_county_target_id' => [
      'id' => 'field_county_target_id', 'table' => 'city__field_county', 'field' => 'field_county_target_id',
      'relationship' => 'none', 'plugin_id' => 'entity_target_id', 'default_action' => 'empty',
      'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
      'default_argument_type' => 'fixed', 'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      'specify_validation' => FALSE, 'validate' => ['type' => 'none', 'fail' => 'not found'], 'break_phrase' => FALSE,
    ],
  ],
  'filters' => [
    'status' => [
      'id' => 'status', 'table' => 'city_field_data', 'field' => 'status', 'relationship' => 'none',
      'entity_type' => 'city', 'entity_field' => 'status', 'plugin_id' => 'boolean',
      'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ],
  'sorts' => [
    'field_city_name_value' => [
      'id' => 'field_city_name_value', 'table' => 'city__field_city_name', 'field' => 'field_city_name_value',
      'relationship' => 'none', 'plugin_id' => 'standard', 'order' => 'ASC',
    ],
  ],
  'header' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none', 'plugin_id' => 'text',
      'content' => ['value' => '<h2 class="state-counties__title">Towns We Serve</h2>', 'format' => 'full_html'],
    ],
  ],
  'empty' => [], 'relationships' => [], 'footer' => [], 'display_extenders' => [],
];

View::create([
  'id' => 'county_cities',
  'label' => 'County — towns we serve',
  'module' => 'views',
  'base_table' => 'city_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default],
    'entity_view_1' => [
      'id' => 'entity_view_1', 'display_title' => 'Towns (EVA)', 'display_plugin' => 'entity_view', 'position' => 1,
      'display_options' => [
        'entity_type' => 'county',
        'bundles' => ['county'],
        'argument_mode' => 'id',
        'default_argument' => '',
        'display_extenders' => [],
      ],
    ],
  ],
])->save();

print "built county_cities (EVA -> county:county)\nDONE.\n";
