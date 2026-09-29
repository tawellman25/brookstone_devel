<?php

declare(strict_types=1);

/**
 * Build the plant listing on a plant_characteristics term page.
 *
 * `field_plant_characteristics` (material → plant_characteristics) is already
 * populated — 28 of the 41 characteristics carry tagged plants — but nothing
 * rendered them, so every characteristic page was header + footer copy with the
 * plants themselves invisible. This adds the missing listing: an EVA attached to
 * taxonomy_term:plant_characteristics whose contextual argument is the current
 * term id, listing every material tagged with it.
 *
 * Cloned from `material_type_items` (the listing on a material_types CATEGORY
 * page) so a characteristic page looks like the category pages it sits beside —
 * same table, same columns, same image style. Deltas:
 *
 *   - argument   → field_plant_characteristics_target_id (numeric), and the EVA
 *                  passes the term ID rather than material_type_items' token
 *                  `[term:field_material_bundle:value]`, which a characteristic
 *                  term does not carry.
 *   - query      → DISTINCT. The argument joins a multi-value field table; the
 *                  source view's argument is a single-value base column and did
 *                  not need it.
 *   - header     → `empty: FALSE`, so the heading appears only when the term
 *                  actually has plants. The source view sets `empty: TRUE` plus
 *                  an empty-area message, which is why /material/plants renders
 *                  a heading over "No materials found for this type."; an
 *                  untagged characteristic should render nothing at all.
 *   - empty area → removed, for the same reason.
 *
 * The heading is deliberately static. The argument is a term ID, so a token
 * would print the number, not the name — and the term's own H1 already names
 * the characteristic directly above this.
 *
 * Idempotent (deletes + rebuilds). Entity-API; the view is not cim-managed.
 * Run per env, AFTER the plant_characteristics vocabulary exists.
 *
 *   drush php:script web/scripts/build_material_characteristic_items_view.php
 */

use Drupal\views\Entity\View;

const BOS_CHAR_VIEW = 'material_characteristic_items';
const BOS_CHAR_SRC = 'material_type_items';
const BOS_CHAR_VID = 'plant_characteristics';
const BOS_CHAR_ARG = 'field_plant_characteristics_target_id';

// Guard: the vocabulary must exist, or the EVA would attach to nothing.
$vocab = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load(BOS_CHAR_VID);
if (!$vocab) {
  print "vocabulary " . BOS_CHAR_VID . " not found — run the plantcat scripts first. Aborting.\n";
  return;
}

// Guard: the field table the argument joins against must exist.
if (!\Drupal::database()->schema()->tableExists('material__field_plant_characteristics')) {
  print "material__field_plant_characteristics table missing — field not installed. Aborting.\n";
  return;
}

$src = View::load(BOS_CHAR_SRC);
if (!$src) {
  print 'source view ' . BOS_CHAR_SRC . " not found — cannot clone. Aborting.\n";
  return;
}

if ($existing = View::load(BOS_CHAR_VIEW)) {
  print 'view ' . BOS_CHAR_VIEW . " already exists — rebuilding it.\n";
  $existing->delete();
}

$new = $src->createDuplicate();
$new->set('id', BOS_CHAR_VIEW);
$new->set('label', 'Material characteristic items');
$new->set('description', 'EVA: plants tagged with the current plant_characteristics term, on that term page.');

/**
 * The contextual filter: the current characteristic term id, matched against
 * the material's field_plant_characteristics values.
 */
$argument = [
  BOS_CHAR_ARG => [
    'id' => BOS_CHAR_ARG,
    'table' => 'material__field_plant_characteristics',
    'field' => BOS_CHAR_ARG,
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'plugin_id' => 'numeric',
    // No term id passed (a non-characteristic context) renders nothing rather
    // than every plant in the catalogue.
    'default_action' => 'not found',
    'exception' => [
      'value' => 'all',
      'title_enable' => FALSE,
      'title' => 'All',
    ],
    'title_enable' => FALSE,
    'default_argument_type' => 'fixed',
    'default_argument_options' => ['argument' => ''],
    'summary_options' => [
      'base_path' => '',
      'count' => TRUE,
      'override' => FALSE,
      'items_per_page' => 25,
    ],
    'summary' => [
      'sort_order' => 'asc',
      'number_of_records' => 0,
      'format' => 'default_summary',
    ],
    'specify_validation' => FALSE,
    'validate' => ['type' => 'none', 'fail' => 'not found'],
    'validate_options' => [],
    'break_phrase' => FALSE,
    'not' => FALSE,
  ],
];

$display = $new->get('display');

// ---- default display -------------------------------------------------------
$o = &$display['default']['display_options'];

$o['title'] = 'Plants with this characteristic';
$o['arguments'] = $argument;

// Heading renders only when the term actually has plants.
$o['header'] = [
  'area' => [
    'id' => 'area',
    'table' => 'views',
    'field' => 'area',
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'plugin_id' => 'text',
    'empty' => FALSE,
    'content' => [
      'value' => '<h4>Plants with this characteristic</h4>',
      'format' => 'full_html',
    ],
    'tokenize' => FALSE,
  ],
];

// An untagged characteristic renders nothing — no "none found" message.
$o['empty'] = [];

$o['style']['options']['row_class'] = 'material-characteristic-item';

// The argument joins a multi-value field table: without DISTINCT a plant could
// repeat. (Harmless today — one row per term per material — but the join makes
// it possible, and the source view had no such join.)
$o['query']['options']['distinct'] = TRUE;

unset($o);

// ---- EVA display -----------------------------------------------------------
$e = &$display['entity_view_1']['display_options'];

$e['title'] = 'Plants with this characteristic';
$e['arguments'] = $argument;
$e['defaults']['arguments'] = FALSE;
$e['entity_type'] = 'taxonomy_term';
$e['bundles'] = [BOS_CHAR_VID => BOS_CHAR_VID];
$e['show_title'] = FALSE;
// Pass the term ID itself. material_type_items uses a token
// (`[term:field_material_bundle:value]`) that characteristic terms do not have.
$e['argument_mode'] = 'id';
$e['default_argument'] = '';

unset($e);

$new->set('display', $display);
$new->save();

print "built view " . BOS_CHAR_VIEW . " (cloned from " . BOS_CHAR_SRC . ")\n";
print "  attached to: taxonomy_term:" . BOS_CHAR_VID . "\n";
print "  argument:    " . BOS_CHAR_ARG . " (term id)\n";
print "  displays:    " . implode(', ', array_keys($new->get('display'))) . "\n";
