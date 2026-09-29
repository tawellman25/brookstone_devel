<?php

declare(strict_types=1);

/**
 * Stage 2 — create the eight category terms, link the 41 characteristics to
 * them, and hand the category URLs over from the eight hand-built views.
 *
 * Sequence matters and is the whole risk here:
 *   1. create the terms
 *   2. disable the eight char_cat_* views, which currently OWN the category
 *      paths — a term aliased while a view holds the path gets "…-0"
 *   3. alias the categories cleanly
 *   4. add the reference field and migrate from the legacy list key
 *   5. repoint the leaf pattern at the category's alias
 *   6. assert every one of the 41 leaf URLs is byte-identical to before
 *
 * The legacy field_characteristic_category is left populated. It is the audit
 * trail for this migration; it gets retired once the URLs are confirmed on
 * live, not before.
 *
 * Views are DISABLED, never deleted — they are the way back.
 *
 * Idempotent. Dry run unless BOS_PC_APPLY=1.
 */

const CAT_VID = 'plant_character_categories';
const LEAF_VID = 'plant_characteristics';
const REF = 'field_character_category';
const CAT_PATTERN = '/material/plants/characteristics/[term:name]';
const LEAF_PATTERN = '/material/plants/characteristics/[term:field_character_category:entity:name]/[term:name]';

$apply = getenv('BOS_PC_APPLY') === '1';
print $apply ? "APPLYING\n\n" : "DRY RUN (set BOS_PC_APPLY=1 to apply)\n\n";

$etm = \Drupal::entityTypeManager();
$terms = $etm->getStorage('taxonomy_term');

// Snapshot the real URLs first — the assertion must be against reality.
$before = [];
foreach ($terms->loadByProperties(['vid' => LEAF_VID]) as $t) {
  $before[$t->id()] = $t->toUrl()->toString();
}
printf("  snapshot: %d leaf URLs\n\n", count($before));

// Legacy list key => category name. Order is the marketing file's, deliberately
// not alphabetical: Environmental Tolerance decides whether a plant lives here.
$categories = [
  2 => ['Environmental Tolerance', 10],
  3 => ['Growth Habit', 20],
  7 => ['Seasonal Interest', 30],
  10 => ['Wildlife Interaction', 40],
  9 => ['Special Uses', 50],
  5 => ['Maintenance & Behavior', 60],
  1 => ['Aesthetic Features', 70],
  6 => ['Origin', 80],
];

if (!$apply) {
  print "  would create 8 category terms, disable 8 views, add $REF,\n"
      . "  migrate 41 references and re-alias.\n";
  return;
}

// --- 1. terms -------------------------------------------------------------
$existing = [];
foreach ($terms->loadByProperties(['vid' => CAT_VID]) as $t) {
  $existing[$t->label()] = $t;
}
$map = [];
foreach ($categories as $key => [$name, $order]) {
  $term = $existing[$name] ?? NULL;
  if (!$term) {
    $term = $terms->create(['vid' => CAT_VID, 'name' => $name, 'parent' => [0]]);
  }
  $term->set('field_list_order', $order);
  $term->save();
  $map[$key] = $term->id();
  printf("  category  %-24s tid %s\n", $name, $term->id());
}

// --- 2. release the paths -------------------------------------------------
$off = 0;
foreach ($etm->getStorage('view')->loadMultiple() as $v) {
  if (str_starts_with($v->id(), 'char_cat_') && $v->status()) {
    $v->disable()->save();
    $off++;
  }
}
printf("\n  disabled %d category views\n", $off);
\Drupal::service('router.builder')->rebuild();

// --- 3. alias the categories ---------------------------------------------
$patterns = $etm->getStorage('pathauto_pattern');
if (!$patterns->load('plant_character_categories_aliases')) {
  $patterns->create([
    'id' => 'plant_character_categories_aliases',
    'label' => 'Plant Character Categories',
    'type' => 'canonical_entities:taxonomy_term',
    'pattern' => CAT_PATTERN,
    'selection_criteria' => [[
      'id' => 'entity_bundle:taxonomy_term',
      'negate' => FALSE,
      'context_mapping' => ['taxonomy_term' => 'taxonomy_term'],
      'bundles' => [CAT_VID => CAT_VID],
    ]],
    'weight' => -10,
  ])->save();
  print "  category pathauto pattern created\n";
}
$gen = \Drupal::service('pathauto.generator');
foreach ($map as $tid) {
  $gen->updateEntityAlias($terms->load($tid), 'update');
}
print "\n  category URLs:\n";
foreach ($map as $tid) {
  printf("    %-24s %s\n", $terms->load($tid)->label(), $terms->load($tid)->toUrl()->toString());
}

// --- 4. the reference field + migration -----------------------------------
$sc = $etm->getStorage('field_storage_config');
if (!$sc->load('taxonomy_term.' . REF)) {
  $sc->create([
    'field_name' => REF,
    'entity_type' => 'taxonomy_term',
    'type' => 'entity_reference',
    'settings' => ['target_type' => 'taxonomy_term'],
    'cardinality' => 1,
  ])->save();
}
$fc = $etm->getStorage('field_config');
if (!$fc->load('taxonomy_term.' . LEAF_VID . '.' . REF)) {
  $fc->create([
    'field_name' => REF,
    'entity_type' => 'taxonomy_term',
    'bundle' => LEAF_VID,
    'label' => 'Category',
    'description' => 'Which grouping this characteristic belongs to. Drives the URL and the category page listing.',
    'required' => FALSE,
    'settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => [CAT_VID => CAT_VID]]],
  ])->save();
  print "\n  reference field created\n";
}
$form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . LEAF_VID . '.default');
if ($form && !$form->getComponent(REF)) {
  $form->setComponent(REF, ['type' => 'options_select', 'weight' => 3, 'region' => 'content'])->save();
}

$migrated = 0; $unmapped = [];
foreach ($terms->loadByProperties(['vid' => LEAF_VID]) as $t) {
  $key = $t->get('field_characteristic_category')->value;
  if ($key === NULL || !isset($map[$key])) {
    $unmapped[] = $t->label();
    continue;
  }
  if ((string) $t->get(REF)->target_id !== (string) $map[$key]) {
    $t->set(REF, $map[$key])->save();
    $migrated++;
  }
}
printf("  references set: %d   unmapped: %d\n", $migrated, count($unmapped));
foreach ($unmapped as $u) {
  print "    UNMAPPED $u\n";
}

// --- 5. leaf pattern + re-alias -------------------------------------------
$leafPattern = $patterns->load('plant_characteristics_aliases');
$leafPattern->setPattern(LEAF_PATTERN)->save();
foreach ($terms->loadByProperties(['vid' => LEAF_VID]) as $t) {
  $gen->updateEntityAlias($t, 'update');
}

// --- 6. assert nothing moved ---------------------------------------------
$moved = [];
foreach ($before as $tid => $was) {
  $now = $terms->load($tid)->toUrl()->toString();
  if ($now !== $was) {
    $moved[] = "$was  ->  $now";
  }
}
printf("\n  leaf URLs changed: %d\n", count($moved));
foreach (array_slice($moved, 0, 8) as $m) {
  print "    $m\n";
}
if (!$moved) {
  print "    (none — every existing URL preserved exactly)\n";
}
