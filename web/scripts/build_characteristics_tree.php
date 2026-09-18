<?php

/**
 * Build the plant-characteristics landing tree:
 *   - 7 remaining category VIEWS (aesthetic-features already built) — each a
 *     separate view with an editable header description + auto child list.
 *   - 1 Basic INDEX page at /material/plants/characteristics linking to the 8
 *     category pages (the categories are dropdown values, not entities, so a
 *     view cannot list them — this is a made page).
 *
 * Placeholder descriptions (edit in the Views UI / page body). Idempotent.
 * Entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_characteristics_tree.php
 */

use Drupal\views\Entity\View;
use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Create a "list child terms filtered by X" landing view.
 */
function _cat_view(string $id, string $path, string $title, string $desc, array $filters): void {
  if (View::load($id)) {
    View::load($id)->delete();
  }
  $base_filters = [
    'vid' => [
      'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'plugin_id' => 'bundle',
      'operator' => 'in', 'value' => ['plant_characteristics' => 'plant_characteristics'], 'group' => 1,
    ],
    'status' => [
      'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'status', 'plugin_id' => 'boolean',
      'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ];
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
        'entity_field' => 'description', 'plugin_id' => 'field', 'label' => '', 'type' => 'text_default',
        'settings' => [],
      ],
    ],
    'filters' => $base_filters + $filters,
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

$out = [];

// All 8 categories: [key, slug, label] — aesthetic-features rebuilt too, so all
// are consistent with the corrected config.
$cats = [
  [1, 'aesthetic-features', 'Aesthetic Features'],
  [2, 'environmental-tolerance', 'Environmental Tolerance'],
  [3, 'growth-habit', 'Growth Habit'],
  [5, 'maintenance-behavior', 'Maintenance & Behavior'],
  [6, 'origin', 'Origin'],
  [7, 'seasonal-interest', 'Seasonal Interest'],
  [9, 'special-uses', 'Special Uses'],
  [10, 'wildlife-interaction', 'Wildlife Interaction'],
];
foreach ($cats as [$key, $slug, $label]) {
  $id = 'char_cat_' . str_replace('-', '_', $slug);
  $path = 'material/plants/characteristics/' . $slug;
  $desc = $label . ' — plants grouped by ' . strtolower($label) . '. Explore the options below.';
  $filter = ['field_characteristic_category_value' => [
    'id' => 'field_characteristic_category_value', 'table' => 'taxonomy_term__field_characteristic_category',
    'field' => 'field_characteristic_category_value', 'relationship' => 'none', 'group_type' => 'group',
    'plugin_id' => 'list_field', 'operator' => 'or', 'value' => [(string) $key => (string) $key], 'group' => 1,
  ]];
  _cat_view($id, $path, $label, $desc, $filter);
  $out[] = "view $id at /$path";
}

// Index page /material/plants/characteristics — Basic page linking to all 8 categories.
$INDEX_ALIAS = '/material/plants/characteristics';
$all = $cats;
usort($all, fn($a, $b) => strcmp($a[2], $b[2]));
$links = '';
foreach ($all as [, $slug, $label]) {
  $links .= '  <li><a href="/material/plants/characteristics/' . $slug . '">' . htmlspecialchars($label) . "</a></li>\n";
}
$indexBody = '<p>Plant characteristics group the traits we use to describe and select plants — how they look, where they grow, how they behave, and what they attract. Choose a category to explore.</p>' . "\n<ul>\n" . $links . "</ul>";

$am = \Drupal::service('path_alias.manager');
$sys = $am->getPathByAlias($INDEX_ALIAS);
$node = NULL;
if (preg_match('#^/node/(\d+)$#', $sys, $m)) {
  $node = Node::load($m[1]);
}
if (!$node) {
  $node = Node::create(['type' => 'page', 'uid' => 1, 'status' => 1]);
}
$node->setTitle('Plant Characteristics');
$node->set('body', ['value' => $indexBody, 'format' => 'full_html']);
if ($node->hasField('field_meta_tags')) {
  $node->set('field_meta_tags', ['value' => serialize([
    'title' => 'Plant Characteristics | Brookstone Outdoors',
    'description' => 'Browse plants by characteristic — aesthetic features, growth habit, environmental tolerance, wildlife interaction and more, for Delta & Montrose CO landscapes.',
  ])]);
}
$node->path->pathauto = 0;
$node->save();
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$existing = $aliasStorage->loadByProperties(['path' => '/node/' . $node->id()]);
if ($existing) {
  $aliasStorage->delete($existing);
}
PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $INDEX_ALIAS, 'langcode' => 'und'])->save();
$out[] = "index Basic page node {$node->id()} at $INDEX_ALIAS";

print implode("\n", $out) . "\nDONE.\n";
