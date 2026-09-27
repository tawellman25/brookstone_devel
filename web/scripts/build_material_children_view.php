<?php

declare(strict_types=1);

/**
 * Build the "{Category} Categories" child-category cards view for material_types
 * term pages — the material analog of the services `service_children` EVA.
 *
 * A material_types term page (e.g. /material/plants) currently surfaces its
 * subcategories only as hand-written links inside the description prose. This
 * adds a real view: an EVA attached to taxonomy_term:material_types that lists
 * the current term's DIRECT children (contextual arg = current term id) as
 * cards (name + trimmed public description). Leaf terms have no children, so the
 * view (and its header) render nothing there.
 *
 * Cloned from service_children and adapted: vid=material_types, no iconic image
 * (material_types has none), blurb = field_public_description trimmed/stripped,
 * row class material-card, header class material-children__title. The header
 * text + CSS are finished at render by material_views_pre_render().
 *
 * Idempotent; entity-API (view is not cim-managed here). Run per env.
 *
 *   drush php:script web/scripts/build_material_children_view.php
 */

use Drupal\views\Entity\View;

if (View::load('material_children')) {
  print "view material_children already exists — rebuilding it.\n";
  View::load('material_children')->delete();
}

$src = View::load('service_children');
if (!$src) {
  print "source view service_children not found — cannot clone.\n";
  return;
}

$new = $src->createDuplicate();
$new->set('id', 'material_children');
$new->set('label', 'Material children');
$new->set('description', 'Child-category cards on a material_types term page (the material analog of service_children).');

$display = $new->get('display');
$o = &$display['default']['display_options'];

// Fields: term name (linked) + trimmed public description. Drop the iconic image.
$o['fields'] = [
  'name' => [
    'id' => 'name',
    'table' => 'taxonomy_term_field_data',
    'field' => 'name',
    'relationship' => 'none',
    'group_type' => 'group',
    'entity_type' => 'taxonomy_term',
    'entity_field' => 'name',
    'plugin_id' => 'taxonomy_term_name',
    'label' => '',
    'exclude' => FALSE,
    'element_label_colon' => FALSE,
    'settings' => ['link_to_entity' => TRUE],
    'group_column' => 'value',
    'group_rows' => TRUE,
  ],
  'field_public_description' => [
    'id' => 'field_public_description',
    'table' => 'taxonomy_term__field_public_description',
    'field' => 'field_public_description',
    'relationship' => 'none',
    'group_type' => 'group',
    'entity_type' => 'taxonomy_term',
    'entity_field' => 'field_public_description',
    'plugin_id' => 'field',
    'label' => '',
    'exclude' => FALSE,
    'alter' => [
      'alter_text' => FALSE,
      'max_length' => 160,
      'word_boundary' => TRUE,
      'ellipsis' => TRUE,
      'strip_tags' => TRUE,
      'trim' => TRUE,
      'html' => FALSE,
    ],
    'element_default_classes' => TRUE,
    'type' => 'text_default',
    'settings' => [],
  ],
];

// Only material_types terms.
$o['filters']['vid']['value'] = ['material_types' => 'material_types'];

// Card row class + header (text finished per-term by the pre_render hook).
$o['style']['options']['row_class'] = 'material-card';
$o['header']['area']['content']['value'] = '<h2 class="material-children__title">Categories</h2>';

// EVA attaches to material_types term pages; arg = current term id.
$display['entity_view_1']['display_options']['bundles'] = ['material_types'];

$new->set('display', $display);
$new->save();
print "Built view material_children (EVA on taxonomy_term:material_types, arg = current term id).\n";
print "DONE.\n";
