<?php

declare(strict_types=1);

/**
 * The botanical-name block for material item pages.
 *
 * A Views BLOCK display in the `highlighted` region that owns the whole title
 * lockup — the common name as the page's real <h1>, the botanical name beneath
 * it — following the property/work-order/client title blocks exactly:
 * base = the entity's own data table, contextual filter on the entity id with
 * the `views_url_path` default plugin, block in `highlighted`, visibility by
 * request_path.
 *
 * `views_url_path` pops the ROUTE parameter, not a path position (see
 * UrlPath::getArgument), so it is unaffected by items moving from
 * /material/trees/x to /material/plants/trees/evergreens/x.
 *
 * COMPOSITION IS DONE IN PREPROCESS, NOT IN A VIEWS REWRITE. The stored data
 * does not match the textbook shape: field_plant_species already holds the full
 * binomial ("Pinus edulis", not "edulis"), field_cultivar holds a comma list
 * already in typographic quotes ("'Compacta', 'Tovar'"), and three species carry
 * their own hybrid ×. A single rewrite string cannot italicise the binomial
 * while leaving × and the cultivars roman, nor drop punctuation when a part is
 * missing. So the fields are EXCLUDED here and composed in
 * material_preprocess_views_view_fields(), the same way the BOS card patterns do
 * it.
 *
 * Idempotent (deletes + rebuilds). Run per env.
 *   drush php:script web/scripts/build_material_botanical_view.php
 */

use Drupal\views\Entity\View;

const BOS_BOT_VIEW = 'material_botanical_name';

if ($existing = View::load(BOS_BOT_VIEW)) {
  print 'view ' . BOS_BOT_VIEW . " exists — rebuilding.\n";
  $existing->delete();
}

$field = static function (string $name, string $table, array $extra = []): array {
  return $extra + [
    'id' => $name,
    'table' => $table,
    'field' => $name,
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'plugin_id' => 'field',
    'label' => '',
    // Every field is excluded: preprocess composes the markup.
    'exclude' => TRUE,
    'element_label_colon' => FALSE,
    'hide_empty' => TRUE,
    'empty_zero' => FALSE,
  ];
};

$display = [
  'default' => [
    'id' => 'default',
    'display_title' => 'Default',
    'display_plugin' => 'default',
    'position' => 0,
    'display_options' => [
      'title' => '',
      'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
      'cache' => ['type' => 'tag', 'options' => []],
      'query' => ['type' => 'views_query', 'options' => ['distinct' => TRUE]],
      'exposed_form' => ['type' => 'basic', 'options' => []],
      // One row, and no pager chrome in a title block.
      'pager' => ['type' => 'some', 'options' => ['offset' => 0, 'items_per_page' => 1]],
      'style' => ['type' => 'default', 'options' => ['row_class' => 'bo-botanical']],
      'row' => ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]],
      'fields' => [
        'title' => $field('title', 'material_field_data', ['entity_type' => 'material', 'entity_field' => 'title', 'type' => 'string', 'settings' => ['link_to_entity' => FALSE]]),
        'field_plant_species' => $field('field_plant_species', 'material__field_plant_species', ['type' => 'string', 'settings' => ['link_to_entity' => FALSE]]),
        'field_cultivar' => $field('field_cultivar', 'material__field_cultivar', ['type' => 'string', 'settings' => ['link_to_entity' => FALSE]]),
        'field_plant_genus' => $field('field_plant_genus', 'material__field_plant_genus', ['type' => 'string', 'settings' => ['link_to_entity' => FALSE]]),
      ],
      'arguments' => [
        'id' => [
          'id' => 'id',
          'table' => 'material_field_data',
          'field' => 'id',
          'relationship' => 'none',
          'group_type' => 'group',
          'admin_label' => '',
          'entity_type' => 'material',
          'entity_field' => 'id',
          'plugin_id' => 'numeric',
          'default_action' => 'default',
          'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
          'title_enable' => FALSE,
          // Same plugin the property/client title blocks use: reads the route
          // parameter, so it survives URL depth changes.
          'default_argument_type' => 'views_url_path',
          'default_argument_options' => ['provide_static_segments' => 0, 'segments' => ''],
          'summary_options' => ['base_path' => '', 'count' => TRUE, 'items_per_page' => 25],
          'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
          'specify_validation' => FALSE,
          'validate' => ['type' => 'none', 'fail' => 'not found'],
          'validate_options' => [],
          'break_phrase' => FALSE,
          'not' => FALSE,
        ],
      ],
      'filters' => [],
      'sorts' => [],
      'header' => [],
      'footer' => [],
      'empty' => [],
      'relationships' => [],
      'display_extenders' => [],
    ],
  ],
  'block_1' => [
    'id' => 'block_1',
    'display_title' => 'Botanical name',
    'display_plugin' => 'block',
    'position' => 1,
    'display_options' => [
      'display_extenders' => [],
      'block_description' => 'Material — botanical name',
      // No result (no genus/species, or a non-plant item) renders nothing at
      // all rather than an empty wrapper for the theme to put margin under.
      'block_hide_empty' => TRUE,
    ],
  ],
];

$view = View::create([
  'id' => BOS_BOT_VIEW,
  'label' => 'Material botanical name',
  'module' => 'views',
  'description' => 'Title lockup for a material item page: common name as h1, botanical name beneath.',
  'base_table' => 'material_field_data',
  'base_field' => 'id',
  'display' => $display,
]);
$view->save();

print "built view " . BOS_BOT_VIEW . "\n";
print "  displays: " . implode(', ', array_keys($view->get('display'))) . "\n";
print "  block_hide_empty: on — no botanical data renders no block\n";
