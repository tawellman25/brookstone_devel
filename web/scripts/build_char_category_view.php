<?php

/**
 * Demo: a public "category landing" as its OWN View (per Todd — separate views so
 * each parent description is editable; no characteristics taxonomy).
 *
 * Builds /material/plants/characteristics/aesthetic-features as a View that:
 *   - lists the child plant_characteristics terms in that category (auto-updates
 *     as terms are added/edited), each linked to its own page + its description,
 *   - shows an editable parent description in the view header.
 *
 * Idempotent. Entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_char_category_view.php
 */

use Drupal\views\Entity\View;
use Drupal\path_alias\Entity\PathAlias;

$VIEW_ID = 'char_cat_aesthetic_features';
$PATH = 'material/plants/characteristics/aesthetic-features';
$CATEGORY_KEY = 1; // Aesthetic Features
$TITLE = 'Aesthetic Features';
$DESC = 'Aesthetic features describe how a plant looks and what it brings to a landscape — its flowers, foliage, fruit, fragrance and overall ornamental character. Explore the options below.';

// Remove the earlier Basic-page prototype so it does not own the path.
$am = \Drupal::service('path_alias.manager');
$sys = $am->getPathByAlias('/' . $PATH);
if (preg_match('#^/node/(\d+)$#', $sys, $m)) {
  $node = \Drupal\node\Entity\Node::load($m[1]);
  if ($node) {
    $aliases = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['path' => '/node/' . $node->id()]);
    if ($aliases) {
      \Drupal::entityTypeManager()->getStorage('path_alias')->delete($aliases);
    }
    $node->delete();
    print "removed Basic-page prototype node {$m[1]}\n";
  }
}

if (View::load($VIEW_ID)) {
  View::load($VIEW_ID)->delete();
}

$fields = [
  'name' => [
    'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'plugin_id' => 'term_name',
    'label' => '', 'settings' => ['link_to_entity' => TRUE],
  ],
  'description__value' => [
    'id' => 'description__value', 'table' => 'taxonomy_term_field_data', 'field' => 'description__value',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'description', 'plugin_id' => 'field',
    'label' => '', 'type' => 'text_default',
  ],
];
$filters = [
  'vid' => [
    'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'plugin_id' => 'bundle',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'value' => ['plant_characteristics' => 'plant_characteristics'],
  ],
  'status' => [
    'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status', 'plugin_id' => 'boolean',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'status', 'value' => '1',
  ],
  'field_characteristic_category_value' => [
    'id' => 'field_characteristic_category_value', 'table' => 'taxonomy_term__field_characteristic_category',
    'field' => 'field_characteristic_category_value', 'plugin_id' => 'numeric',
    'operator' => '=', 'value' => ['value' => (string) $CATEGORY_KEY],
  ],
];
$sorts = [
  'name' => [
    'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'standard',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'order' => 'ASC',
  ],
];
$header = [
  'area' => [
    'id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text',
    'content' => ['value' => '<p>' . $DESC . '</p>', 'format' => 'full_html'],
  ],
];

$default_options = [
  'title' => $TITLE,
  'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
  'cache' => ['type' => 'tag'],
  'query' => ['type' => 'views_query'],
  'exposed_form' => ['type' => 'basic'],
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => ['type' => 'html_list'],
  'row' => ['type' => 'fields'],
  'fields' => $fields,
  'filters' => $filters,
  'sorts' => $sorts,
  'header' => $header,
  'empty' => [],
  'relationships' => [],
  'arguments' => [],
  'display_extenders' => [],
];

$view = View::create([
  'id' => $VIEW_ID,
  'label' => 'Plant Characteristics — Aesthetic Features',
  'module' => 'views',
  'base_table' => 'taxonomy_term_field_data',
  'base_field' => 'tid',
  'display' => [
    'default' => [
      'id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default',
      'position' => 0, 'display_options' => $default_options,
    ],
    'page_1' => [
      'id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page',
      'position' => 1, 'display_options' => ['path' => $PATH, 'display_extenders' => []],
    ],
  ],
]);
$view->save();

print "view $VIEW_ID created at /$PATH\nDONE.\n";
