<?php

/**
 * "Our {Category} Services" child-service cards, as an EVA on the services
 * taxonomy term page: one compact card per DIRECT CHILD of the current term
 * (arg = current term id matched against each child's parent), name (linked) +
 * short blurb. Auto-populates + nests at every level (a leaf term = no cards).
 * The dynamic "Our {term} Services" heading is set in bos_services_views_pre_render().
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_service_children_view.php
 */

use Drupal\views\Entity\View;

if (View::load('service_children')) {
  View::load('service_children')->delete();
}

$default = [
  'title' => '',
  'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
  'cache' => ['type' => 'tag'],
  'query' => ['type' => 'views_query'],
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => ['type' => 'default', 'options' => ['row_class' => 'service-card', 'default_row_class' => TRUE]],
  'row' => ['type' => 'fields'],
  'fields' => [
    'field_iconic_image' => [
      'id' => 'field_iconic_image', 'table' => 'taxonomy_term__field_iconic_image',
      'field' => 'field_iconic_image', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => '', 'exclude' => FALSE, 'type' => 'image',
      'settings' => ['image_style' => 'large', 'image_link' => 'content'],
    ],
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name',
      'relationship' => 'none', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name',
      'plugin_id' => 'term_name', 'label' => '', 'exclude' => FALSE,
      'type' => 'string', 'settings' => ['link_to_entity' => TRUE],
      'convert_spaces' => FALSE,
    ],
    'field_service_public_desc' => [
      'id' => 'field_service_public_desc', 'table' => 'taxonomy_term__field_service_public_desc',
      'field' => 'field_service_public_desc', 'relationship' => 'none', 'plugin_id' => 'field',
      'label' => '', 'exclude' => FALSE, 'type' => 'text_summary_or_trimmed',
      'settings' => ['trim_length' => 140],
    ],
  ],
  'arguments' => [
    'parent_target_id' => [
      'id' => 'parent_target_id', 'table' => 'taxonomy_term__parent', 'field' => 'parent_target_id',
      'relationship' => 'none', 'plugin_id' => 'numeric', 'default_action' => 'empty',
      'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
      'default_argument_type' => 'fixed', 'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
      'specify_validation' => FALSE, 'validate' => ['type' => 'none', 'fail' => 'not found'],
      'break_phrase' => FALSE,
    ],
  ],
  'filters' => [
    'vid' => [
      'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'plugin_id' => 'bundle',
      'operator' => 'in', 'value' => ['services' => 'services'], 'group' => 1,
    ],
    'status' => [
      'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'status', 'plugin_id' => 'boolean',
      'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ],
  'sorts' => [
    'weight' => [
      'id' => 'weight', 'table' => 'taxonomy_term_field_data', 'field' => 'weight', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'weight', 'plugin_id' => 'standard', 'order' => 'ASC',
    ],
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'relationship' => 'none',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'plugin_id' => 'standard', 'order' => 'ASC',
    ],
  ],
  'header' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area', 'relationship' => 'none', 'plugin_id' => 'text',
      // Placeholder — bos_services_views_pre_render() rewrites this to
      // "Our {current term} Services". Hidden automatically when 0 children.
      'empty' => FALSE,
      'content' => ['value' => '<h2 class="service-children__title">Services We Offer</h2>', 'format' => 'full_html'],
    ],
  ],
  'empty' => [], 'relationships' => [], 'footer' => [], 'display_extenders' => [],
];

View::create([
  'id' => 'service_children',
  'label' => 'Service — child services (cards)',
  'module' => 'views',
  'base_table' => 'taxonomy_term_field_data',
  'base_field' => 'tid',
  'display' => [
    'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default],
    'entity_view_1' => [
      'id' => 'entity_view_1', 'display_title' => 'Child services (EVA)', 'display_plugin' => 'entity_view', 'position' => 1,
      'display_options' => [
        'entity_type' => 'taxonomy_term',
        'bundles' => ['services'],
        'argument_mode' => 'id',
        'default_argument' => '',
        'display_extenders' => [],
      ],
    ],
  ],
])->save();

print "built service_children (EVA -> taxonomy_term:services)\nDONE.\n";
