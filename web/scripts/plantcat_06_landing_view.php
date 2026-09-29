<?php

declare(strict_types=1);

/**
 * Stage 6 — the landing view at /material/plants/characteristics.
 *
 * Replaces node 112, which had the eight categories and their text typed into
 * its body. That is why adding a ninth category never appeared: the node did
 * not know about it. This view lists the vocabulary, so it does.
 *
 * Header and footer are view AREAS, which is correct here precisely because
 * there is only one of this page — shared text with nothing to duplicate. The
 * eight per-category openings are the opposite case and live on the terms.
 *
 * Path handover is the part that breaks things: a view page cannot cleanly take
 * a path an alias already claims, and the failure is silent — you get the node
 * or the view depending on routing order. So the node's alias is removed first
 * and the node unpublished, never deleted.
 *
 * Idempotent. Dry run unless BOS_PC_APPLY=1.
 */

const VIEW_ID = 'plant_character_categories_landing';
const CAT_VID = 'plant_character_categories';
const PATH = 'material/plants/characteristics';
const NODE = 112;

$apply = getenv('BOS_PC_APPLY') === '1';
print $apply ? "APPLYING\n\n" : "DRY RUN\n\n";

$etm = \Drupal::entityTypeManager();
$storage = $etm->getStorage('view');

// Complete field definitions copied from a working children view — a sparse
// Views field definition fails at RENDER, not on save.
$source = $storage->load('material_children');
$src = $source->get('display')['default']['display_options'];
$fields = $src['fields'];
if (isset($fields['field_public_description'])) {
  $f = $fields['field_public_description'];
  unset($fields['field_public_description']);
  $f['id'] = 'field_short_description';
  $f['field'] = 'field_short_description';
  $f['table'] = 'taxonomy_term__field_short_description';
  $fields['field_short_description'] = $f;
}

$header = <<<'HTML'
<p>Most plants that fail here do not fail because somebody watered them wrong. They fail because they were never suited to the site — alkaline soil, a late May frost, deer pressure, a west wind, or a thousand feet of elevation between where the plant was grown and where it was planted.</p>

<p>These are the characteristics we sort by when we put together a planting plan. They are the traits that decide whether a plant is still there in five years, and they carry more weight on the Western Slope than they do in most of the country because the margin for error is narrower.</p>

<p>Choose a category to see how we use it.</p>
HTML;

$footer = <<<'HTML'
<h2>How we actually use these</h2>

<p>No single characteristic decides anything. A plant that is drought-tolerant, deer-resistant and dead in February because it was rated for zone 6 and the site sits at 6,400 feet was still the wrong plant.</p>

<p>On a design we start by stacking the filters the site forces on us — elevation, exposure, soil, available water, browse pressure — and then choose from what is left based on what the planting is supposed to do. A screen, a foundation planting and a pollinator bed can start from the same list and end up nowhere near each other.</p>

<p>The two filters that surprise people most here are alkalinity and elevation. Soil across most of Delta and Montrose counties runs alkaline, which rules out a long list of plants sold as reliable elsewhere. And a Cedaredge yard at 6,200 feet and a Delta yard at 4,900 feet are not the same growing conditions, whatever the zone map says about either of them.</p>

<h2>Planning a planting</h2>

<p>We design and install plantings across Delta and Montrose counties, and we maintain a good share of what we put in — which is the part that keeps us honest about plant selection. A plant that needs babysitting becomes our problem too.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$area = fn(string $id, string $content) => [
  'id' => $id,
  'table' => 'views',
  'field' => 'area_text_custom',
  'plugin_id' => 'text_custom',
  'content' => $content,
  'empty' => FALSE,
];

$display_options = [
  'title' => 'Choosing Plants for Western Colorado',
  'fields' => $fields,
  // Eight items. A pager on eight items is noise.
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => $src['style'],
  'row' => $src['row'],
  'css_class' => 'material-children plant-character-categories',
  'filters' => [
    'vid' => [
      'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'vid',
      'plugin_id' => 'bundle', 'operator' => 'in',
      'value' => [CAT_VID => CAT_VID],
    ],
    'status' => [
      'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'status',
      'plugin_id' => 'boolean', 'operator' => '=', 'value' => '1',
    ],
  ],
  // Deliberately NOT alphabetical: alphabetical puts Aesthetic Features first
  // and Environmental Tolerance fifth, and Environmental Tolerance is the one
  // that decides whether a plant lives here.
  'sorts' => [
    'field_list_order_value' => [
      'id' => 'field_list_order_value',
      'table' => 'taxonomy_term__field_list_order',
      'field' => 'field_list_order_value',
      'plugin_id' => 'numeric',
      'order' => 'ASC',
    ],
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'name',
      'plugin_id' => 'standard', 'order' => 'ASC',
    ],
  ],
  'header' => ['area_text_custom' => $area('area_text_custom', $header)],
  'footer' => ['area_text_custom' => $area('area_text_custom', $footer)],
  // No empty text: if this returns nothing something is broken, and a friendly
  // message would hide it.
  'display_extenders' => [],
];

$view = $storage->load(VIEW_ID);
if (!$view) {
  $view = $storage->create([
    'id' => VIEW_ID,
    'label' => 'Plant character categories landing',
    'description' => 'The eight plant characteristic categories. Replaces node ' . NODE . '.',
    'base_table' => 'taxonomy_term_field_data',
    'base_field' => 'tid',
    'display' => [],
  ]);
  print "  creating view\n";
}

// Build on a temporary path first; the handover below moves it.
$view->set('display', [
  'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Default', 'position' => 0, 'display_options' => $display_options],
  'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Page', 'position' => 1,
    'display_options' => ['display_extenders' => [], 'path' => PATH]],
]);

if (!$apply) {
  print "  would create the view and hand the path over from node " . NODE . "\n";
  return;
}

// --- the handover ---------------------------------------------------------
$node = $etm->getStorage('node')->load(NODE);
if ($node) {
  $alias = $node->get('path')->alias;
  printf("  node %d alias: %s\n", NODE, $alias ?: '(none)');
  // Clear the alias THROUGH the field, not by deleting the path_alias entity.
  // Deleting it out from under the node leaves its path field pointing at a row
  // that no longer exists, and the next save dies in PathItem::postSave()
  // calling getAlias() on null.
  if ($alias || $node->isPublished()) {
    $node->set('path', ['alias' => '', 'pathauto' => 0]);
    $node->setUnpublished();
    $node->save();
    print "  alias cleared and node unpublished (NOT deleted — it holds the original copy)\n";
  }
  else {
    print "  node already unpublished with no alias\n";
  }
}

// Short title: this is the H1 AND the breadcrumb crumb, so the long marketing
// title would read badly in a trail. The marketing title belongs in <title>,
// which metatag_views sets separately — the same split land_spray_location and
// the other parent views already use.
$d = $view->get('display');
$d['default']['display_options']['title'] = 'Plant Characteristics';
$d['page_1']['display_options']['display_extenders']['metatag_display_extender'] = [
  'metatags' => [
    'title' => 'Choosing Plants for Western Colorado | Brookstone Outdoors',
    'description' => 'Alkaline soil, late frosts and deer pressure decide what survives here. The plant characteristics we sort by when planning plantings on the Western Slope.',
  ],
];
$view->set('display', $d);

$view->save();
\Drupal::service('router.builder')->rebuild();
printf("  view saved at /%s\n", PATH);
print "  H1/breadcrumb: Plant Characteristics\n";
print "  <title>:       Choosing Plants for Western Colorado | Brookstone Outdoors\n";
