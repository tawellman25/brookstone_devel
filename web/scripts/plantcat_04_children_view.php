<?php

declare(strict_types=1);

/**
 * Stage 4 — the children view: a category page lists its own characteristics.
 *
 * One view serves all eight categories. The contextual filter takes the term id
 * of the page it is embedded in and matches it against the characteristics'
 * field_character_category. Land on Growth Habit, get Growth Habit's six. Add a
 * ninth category and it works with no change — which is the entire reason the
 * eight hand-built char_cat_* views are being retired.
 *
 * Same shape as material_children and service_children: EVA on the term page,
 * contextual argument, card grid. The only difference is the column — those
 * filter on parent_target_id, this one on the reference field, because the
 * categories are their own vocabulary rather than parent terms.
 *
 * Card CSS is reused from material_children rather than a new component.
 *
 * Idempotent. Run per environment.
 */

const VIEW_ID = 'plant_characteristic_children';
const CAT_VID = 'plant_character_categories';
const LEAF_VID = 'plant_characteristics';

$storage = \Drupal::entityTypeManager()->getStorage('view');

// Copy complete field definitions from an existing children view rather than
// hand-writing sparse ones — a sparse Views field definition does not fail on
// save or on execute, it fails at RENDER, which is the worst place to find it.
$source = $storage->load('material_children');
if (!$source) {
  print "ABORT: material_children not found to copy field definitions from\n";
  return;
}
$src = $source->get('display')['default']['display_options'];

$fields = $src['fields'];
// The blurb comes from the characteristic's own description. These terms keep
// their text in core description — verified, not assumed.
if (isset($fields['field_public_description'])) {
  $desc = $fields['field_public_description'];
  unset($fields['field_public_description']);
  $desc['id'] = 'description__value';
  $desc['field'] = 'description__value';
  $desc['table'] = 'taxonomy_term_field_data';
  $desc['plugin_id'] = 'field';
  $desc['entity_field'] = 'description';
  $fields['description__value'] = $desc;
}

$display_options = [
  'title' => '',
  'fields' => $fields,
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => $src['style'],
  'row' => $src['row'],
  'css_class' => 'material-children plant-characteristic-children',
  'filters' => [
    'vid' => [
      'id' => 'vid',
      'table' => 'taxonomy_term_field_data',
      'field' => 'vid',
      'entity_type' => 'taxonomy_term',
      'entity_field' => 'vid',
      'plugin_id' => 'bundle',
      'operator' => 'in',
      'value' => [LEAF_VID => LEAF_VID],
    ],
    'status' => [
      'id' => 'status',
      'table' => 'taxonomy_term_field_data',
      'field' => 'status',
      'entity_type' => 'taxonomy_term',
      'entity_field' => 'status',
      'plugin_id' => 'boolean',
      'operator' => '=',
      'value' => '1',
    ],
  ],
  'sorts' => [
    'name' => [
      'id' => 'name',
      'table' => 'taxonomy_term_field_data',
      'field' => 'name',
      'entity_type' => 'taxonomy_term',
      'entity_field' => 'name',
      'plugin_id' => 'standard',
      'order' => 'ASC',
    ],
  ],
  'arguments' => [
    'field_character_category_target_id' => [
      'id' => 'field_character_category_target_id',
      'table' => 'taxonomy_term__field_character_category',
      'field' => 'field_character_category_target_id',
      'plugin_id' => 'numeric',
      // No argument means no category: show nothing rather than everything.
      'default_action' => 'empty',
      'exception' => ['value' => '', 'title_enable' => FALSE],
      'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      'specify_validation' => FALSE,
    ],
  ],
  'display_extenders' => [],
];

$view = $storage->load(VIEW_ID);
if (!$view) {
  $view = $storage->create([
    'id' => VIEW_ID,
    'label' => 'Plant characteristic children',
    'description' => 'The characteristics belonging to a category, embedded on that category page.',
    'base_table' => 'taxonomy_term_field_data',
    'base_field' => 'tid',
    'display' => [],
  ]);
  print "  creating view\n";
}
else {
  print "  updating view\n";
}

$view->set('display', [
  'default' => [
    'display_plugin' => 'default',
    'id' => 'default',
    'display_title' => 'Default',
    'position' => 0,
    'display_options' => $display_options,
  ],
  'entity_view_1' => [
    'display_plugin' => 'entity_view',
    'id' => 'entity_view_1',
    'display_title' => 'Category page',
    'position' => 1,
    'display_options' => [
      'display_extenders' => [],
      'entity_type' => 'taxonomy_term',
      'bundles' => [CAT_VID],
      'argument_mode' => 'id',
      'default_argument' => '',
    ],
  ],
]);
$view->save();
print "  saved " . VIEW_ID . " (EVA on taxonomy_term:" . CAT_VID . ")\n";
print "DONE.\n";
