<?php

declare(strict_types=1);

/**
 * List a subcategory's items on its own page.
 *
 * A material_types term binds to items through `field_material_bundle` → the
 * material's BUNDLE, which is why `/material/plants/trees` can list all 60 trees
 * but `/material/plants/trees/junipers` lists nothing: a subcategory has no
 * bundle of its own, and there is no `junipers` bundle to give it. The trees now
 * carry `field_material_category` instead, and this renders it.
 *
 * Cloned from `material_characteristic_items` (same table, columns and image
 * style), with the argument moved to `field_material_category_target_id`.
 *
 * A NOTE ON WHERE THIS RENDERS. Term pages on this site are not entity pages —
 * the core `taxonomy_term` view owns /taxonomy/term/%, and it is a list of
 * published NODES with the term rendered in its header area (view mode `full`).
 * That is why a subcategory page looks empty: its rows list nodes, and materials
 * are not nodes. Attaching an EVA to taxonomy_term:material_types puts this list
 * inside the term's own render in that header, which is where the sibling
 * `material_children` and `material_type_items` EVAs already live. The core view
 * is deliberately untouched — it governs roughly 1,300 term pages.
 *
 * The heading is static and says what the list is, because the page H1 already
 * carries the category name and repeating it reads as noise. It renders only
 * when the category actually holds something (header area `empty: FALSE`), and
 * there is no empty-area message: an unpopulated subcategory shows nothing.
 *
 * Idempotent (deletes + rebuilds). Run per env, AFTER the field exists.
 *   drush php:script web/scripts/build_material_subcategory_items_view.php
 */

use Drupal\views\Entity\View;

const BOS_SUBCAT_VIEW = 'material_subcategory_items';
const BOS_SUBCAT_SRC = 'material_characteristic_items';
const BOS_SUBCAT_ARG = 'field_material_category_target_id';

if (!\Drupal::database()->schema()->tableExists('material__field_material_category')) {
  print "material__field_material_category missing — run setup_material_category_field.php first.\n";
  return;
}

$src = View::load(BOS_SUBCAT_SRC);
if (!$src) {
  print 'source view ' . BOS_SUBCAT_SRC . " not found — cannot clone. Aborting.\n";
  return;
}

if ($existing = View::load(BOS_SUBCAT_VIEW)) {
  print 'view ' . BOS_SUBCAT_VIEW . " already exists — rebuilding it.\n";
  $existing->delete();
}

$new = $src->createDuplicate();
$new->set('id', BOS_SUBCAT_VIEW);
$new->set('label', 'Material subcategory items');
$new->set('description', 'EVA: materials filed under the current material_types subcategory, on that term page.');

$argument = [
  BOS_SUBCAT_ARG => [
    'id' => BOS_SUBCAT_ARG,
    'table' => 'material__field_material_category',
    'field' => BOS_SUBCAT_ARG,
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'plugin_id' => 'numeric',
    // No term id in context renders nothing, rather than the whole catalogue.
    'default_action' => 'not found',
    'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
    'title_enable' => FALSE,
    'default_argument_type' => 'fixed',
    'default_argument_options' => ['argument' => ''],
    'summary_options' => ['base_path' => '', 'count' => TRUE, 'override' => FALSE, 'items_per_page' => 25],
    'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
    'specify_validation' => FALSE,
    'validate' => ['type' => 'none', 'fail' => 'not found'],
    'validate_options' => [],
    'break_phrase' => FALSE,
    'not' => FALSE,
  ],
];

$header = [
  'area' => [
    'id' => 'area',
    'table' => 'views',
    'field' => 'area',
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'plugin_id' => 'text',
    // Only when the category holds something.
    'empty' => FALSE,
    'content' => ['value' => '<h4>What we stock</h4>', 'format' => 'full_html'],
    'tokenize' => FALSE,
  ],
];

$display = $new->get('display');

$o = &$display['default']['display_options'];
$o['title'] = 'What we stock';
$o['arguments'] = $argument;
$o['header'] = $header;
$o['empty'] = [];
$o['style']['options']['row_class'] = 'material-subcategory-item';
$o['query']['options']['distinct'] = TRUE;
unset($o);

$e = &$display['entity_view_1']['display_options'];
$e['title'] = 'What we stock';
$e['arguments'] = $argument;
$e['defaults']['arguments'] = FALSE;
$e['entity_type'] = 'taxonomy_term';
$e['bundles'] = ['material_types' => 'material_types'];
$e['show_title'] = FALSE;
$e['argument_mode'] = 'id';
$e['default_argument'] = '';
unset($e);

$new->set('display', $display);
$new->save();

print "built view " . BOS_SUBCAT_VIEW . " (cloned from " . BOS_SUBCAT_SRC . ")\n";
print "  attached to: taxonomy_term:material_types\n";
print "  argument:    " . BOS_SUBCAT_ARG . " (term id)\n";
