<?php

/**
 * Counties EVA for the state page + a towns sub-view.
 *
 * - state_county_towns: lists a county's cities (arg = county id), bare names
 *   linked to each city, inline (styled by CSS to " · " separators).
 * - state_counties: EVA attached to the state entity page; one card per county
 *   in that state (arg = host state id), each with banner + name (linked) +
 *   character line + the towns sub-view (per-row county id). Auto-populates as
 *   counties/cities are added.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_state_counties_views.php
 */

use Drupal\views\Entity\View;

// ---- 1) towns sub-view (city, arg = county id) ------------------------------
if (View::load('state_county_towns')) {
  View::load('state_county_towns')->delete();
}
View::create([
  'id' => 'state_county_towns',
  'label' => 'State — county towns',
  'module' => 'views',
  'base_table' => 'city_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => [
      'id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0,
      'display_options' => [
        'title' => '',
        'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
        'cache' => ['type' => 'tag'],
        'query' => ['type' => 'views_query'],
        'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
        'style' => ['type' => 'default', 'options' => ['row_class' => '', 'default_row_class' => TRUE]],
        'row' => ['type' => 'fields'],
        'fields' => [
          'field_city_name' => [
            'id' => 'field_city_name', 'table' => 'city__field_city_name', 'field' => 'field_city_name',
            'relationship' => 'none', 'plugin_id' => 'field', 'label' => '', 'exclude' => FALSE,
            'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
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
        'empty' => [], 'relationships' => [], 'header' => [], 'footer' => [], 'display_extenders' => [],
      ],
    ],
  ],
])->save();

// ---- 2) counties EVA (county, arg = state id) -------------------------------
if (View::load('state_counties')) {
  View::load('state_counties')->delete();
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
    'title' => [
      'id' => 'title', 'table' => 'county_field_data', 'field' => 'title', 'relationship' => 'none',
      'entity_type' => 'county', 'entity_field' => 'title', 'plugin_id' => 'field', 'label' => '',
      'exclude' => FALSE, 'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
    ],
    'field_county_summary' => [
      'id' => 'field_county_summary', 'table' => 'county__field_county_summary', 'field' => 'field_county_summary',
      'relationship' => 'none', 'plugin_id' => 'field', 'label' => '', 'exclude' => FALSE,
      'type' => 'basic_string', 'settings' => [],
    ],
    'id' => [
      'id' => 'id', 'table' => 'county_field_data', 'field' => 'id', 'relationship' => 'none',
      'entity_type' => 'county', 'entity_field' => 'id', 'plugin_id' => 'field', 'label' => '',
      'exclude' => TRUE, 'type' => 'number_integer', 'settings' => [],
    ],
    'view' => [
      'id' => 'view', 'table' => 'views', 'field' => 'view', 'relationship' => 'none',
      'plugin_id' => 'view', 'label' => 'Towns we serve', 'exclude' => FALSE,
      'view' => 'state_county_towns', 'display' => 'default', 'arguments' => '{{ fields.id }}',
      'query_aggregation' => FALSE, 'inherit_arguments' => FALSE, 'inherit_exposed_filters' => FALSE,
      'hide_empty' => TRUE, 'empty' => '', 'view_to_insert' => 'state_county_towns:default',
    ],
    // Edit link — only renders for users with edit access (office/admin).
    // hide_empty so anon (no access = empty) doesn't get an empty wrapper.
    'edit_county' => [
      'id' => 'edit_county', 'table' => 'county', 'field' => 'edit_county', 'relationship' => 'none',
      'plugin_id' => 'entity_link_edit', 'label' => '', 'exclude' => FALSE,
      'text' => 'Edit county', 'output_url_as_text' => FALSE,
      'hide_empty' => TRUE, 'empty_zero' => FALSE, 'hide_alter_empty' => TRUE,
    ],
  ],
  'arguments' => [
    'field_state_target_id' => [
      'id' => 'field_state_target_id', 'table' => 'county__field_state', 'field' => 'field_state_target_id',
      'relationship' => 'none', 'plugin_id' => 'entity_target_id', 'default_action' => 'empty',
      'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
      'default_argument_type' => 'fixed', 'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      'specify_validation' => FALSE, 'validate' => ['type' => 'none', 'fail' => 'not found'], 'break_phrase' => FALSE,
    ],
  ],
  'filters' => [
    'status' => [
      'id' => 'status', 'table' => 'county_field_data', 'field' => 'status', 'relationship' => 'none',
      'entity_type' => 'county', 'entity_field' => 'status', 'plugin_id' => 'boolean',
      'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ],
  'sorts' => [
    'title' => [
      'id' => 'title', 'table' => 'county_field_data', 'field' => 'title', 'relationship' => 'none',
      'entity_type' => 'county', 'entity_field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC',
    ],
  ],
  'header' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none', 'plugin_id' => 'text',
      'content' => ['value' => '<h2 class="state-counties__title">Counties We Serve</h2>', 'format' => 'full_html'],
    ],
  ],
  'empty' => [], 'relationships' => [], 'footer' => [], 'display_extenders' => [],
];
View::create([
  'id' => 'state_counties',
  'label' => 'State — counties we serve',
  'module' => 'views',
  'base_table' => 'county_field_data',
  'base_field' => 'id',
  'display' => [
    'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default],
    'entity_view_1' => [
      'id' => 'entity_view_1', 'display_title' => 'Counties (EVA)', 'display_plugin' => 'entity_view', 'position' => 1,
      'display_options' => [
        'entity_type' => 'state',
        'bundles' => ['state'],
        'argument_mode' => 'id',
        'default_argument' => '',
        'display_extenders' => [],
      ],
    ],
  ],
])->save();

print "built state_county_towns + state_counties (EVA -> state:state)\nDONE.\n";
