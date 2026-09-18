<?php

/**
 * Build the remaining category landings that map 1:1 to a whole vocabulary
 * (list every term in the vocab). Same pattern as the characteristics category
 * views: separate view each, editable header description, auto child list with
 * aliased links. Placeholder descriptions (edit in Views UI).
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_vocab_landings.php
 */

use Drupal\views\Entity\View;

/**
 * Create a "list all terms in a vocabulary" landing view.
 */
function _vocab_view(string $id, string $vid, string $path, string $title, string $desc): void {
  if (View::load($id)) {
    View::load($id)->delete();
  }
  $default_options = [
    'title' => $title,
    'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
    'cache' => ['type' => 'tag'],
    'query' => ['type' => 'views_query'],
    'exposed_form' => ['type' => 'basic'],
    'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
    'style' => ['type' => 'html_list'],
    'row' => ['type' => 'fields'],
    'fields' => [
      'name' => [
        'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'relationship' => 'none',
        'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name',
        'plugin_id' => 'taxonomy_term_name', 'label' => '', 'exclude' => FALSE,
        'element_label_colon' => FALSE, 'click_sort_column' => 'value', 'type' => 'string',
        'settings' => ['link_to_entity' => TRUE], 'group_column' => 'value', 'group_rows' => TRUE,
      ],
      'description__value' => [
        'id' => 'description__value', 'table' => 'taxonomy_term_field_data', 'field' => 'description__value',
        'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'taxonomy_term',
        'entity_field' => 'description', 'plugin_id' => 'field', 'label' => '', 'type' => 'text_default', 'settings' => [],
      ],
    ],
    'filters' => [
      'vid' => [
        'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'relationship' => 'none',
        'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'plugin_id' => 'bundle',
        'operator' => 'in', 'value' => [$vid => $vid], 'group' => 1,
      ],
      'status' => [
        'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status', 'relationship' => 'none',
        'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'status', 'plugin_id' => 'boolean',
        'operator' => '=', 'value' => '1', 'group' => 1,
      ],
    ],
    'sorts' => [
      'name' => [
        'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'standard',
        'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'order' => 'ASC',
      ],
    ],
    'header' => [
      'area' => [
        'id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text',
        'content' => ['value' => '<p>' . $desc . '</p>', 'format' => 'full_html'],
      ],
    ],
    'empty' => [], 'relationships' => [], 'arguments' => [], 'display_extenders' => [],
  ];
  View::create([
    'id' => $id, 'label' => $title, 'module' => 'views',
    'base_table' => 'taxonomy_term_field_data', 'base_field' => 'tid',
    'display' => [
      'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default_options],
      'page_1' => ['id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => ['path' => $path, 'display_extenders' => []]],
    ],
  ])->save();
}

// [view id, vocabulary, path, title]
$landings = [
  ['land_rock', 'rock_types', 'material/rock', 'Rock'],
  ['land_bulk', 'bulk_material_types', 'material/bulk', 'Bulk Materials'],
  ['land_hardscape', 'hardscape_types', 'material/hardscape', 'Hardscape'],
  ['land_bloom_time', 'bloom_time', 'material/plants/bloom-time', 'Bloom Time'],
  ['land_growth_zone', 'growth_zone', 'material/plants/growth-zone', 'Growth Zones'],
  ['land_spray_location', 'spraying_locations', 'services/landscape-lawn-care/spraying/location', 'Spraying Locations'],
  ['land_wind_direction', 'wind_direction', 'services/landscape-lawn-care/spraying/wind-direction', 'Wind Direction'],
  ['land_spray_methods', 'spraying_methods', 'services/landscape-lawn-care/spraying/methods', 'Spraying Methods'],
  ['land_spray_frequency', 'spraying_frequency', 'services/landscape-lawn-care/spraying/frequency', 'Spraying Frequency'],
  ['land_wind_speed', 'spraying_wind_speed', 'services/landscape-lawn-care/spraying/wind-speed', 'Wind Speed'],
  ['land_carrier', 'carrier', 'services/landscape-lawn-care/spraying/carrier', 'Carrier'],
  ['land_signal_words', 'signal_words', 'services/landscape-lawn-care/spraying/chemicals/signal-words', 'Signal Words'],
  ['land_backflow_uses', 'backflow_uses', 'services/backflow-prevention/uses', 'Backflow Uses'],
  ['land_snow_levels', 'snow_levels', 'services/snow-removal/levels', 'Snow Levels'],
  ['land_system_operation', 'system_operation', 'services/sprinkler-system/operation', 'System Operation'],
  ['land_sprinkler_checkup', 'irrigation_check_up_frequency', 'services/sprinkler-system/sprinkler-system-check', 'Sprinkler Check-Up'],
  ['land_christmas_lights', 'christmas_light_types', 'services/christmas-decorations/lights', 'Christmas Lights'],
  ['land_light_colors', 'christmas_light_colors', 'services/christmas-decorations/lights/colors', 'Light Colors'],
];

$out = [];
foreach ($landings as [$id, $vid, $path, $title]) {
  $desc = $title . ' — explore the options below.';
  _vocab_view($id, $vid, $path, $title, $desc);
  $out[] = "view $id ($vid) at /$path";
}
print implode("\n", $out) . "\nTOTAL: " . count($landings) . "\nDONE.\n";
