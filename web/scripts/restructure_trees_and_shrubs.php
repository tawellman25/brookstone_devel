<?php

declare(strict_types=1);

/**
 * Restructure the Trees and Shrubs branches, in one pass.
 *
 * Two prompts combined deliberately. Run separately, the five shrub-form
 * conifers (Yew, Mugo Pine, three junipers) would land in Shrubs from the tree
 * pass and move again to Evergreen Shrubs in the shrub pass — two URL changes
 * for the same items. Here they go straight to their final home.
 *
 * TREES — Evergreens splits by GENUS, Deciduous by PURPOSE. That asymmetry is
 * intentional: evergreen buyers ask by genus ("a blue spruce"), deciduous buyers
 * by purpose ("a shade tree"). The top-level Junipers category is dissolved;
 * juniper becomes one genus among peers, holding tree-form junipers only.
 *
 * SHRUBS — Evergreen / Deciduous / Roses. NOT genus: 62 shrubs across ~60
 * genera would give sixty single-item categories. A long tail wants filtering.
 *
 * ⚠ CLASSIFIED BY WHAT A PLANT DOES IN A DELTA COUNTY WINTER, not by the nursery
 * tag. "Semi-evergreen" means evergreen somewhere warmer. Pyracantha, Euonymus
 * and Oregon Grape Holly all carry an Evergreen characteristic in BOS and are
 * all filed DECIDUOUS here, because a customer who buys a screen and watches it
 * go bare in January was misled by a category. Under-promise in the taxonomy;
 * the item copy can explain.
 *
 * ⚠ DOUGLAS FIR sits under Fir while its genus stays Pseudotsuga. That is
 * correct and deliberate — the category is a common-name shelf, the genus field
 * is the botanical fact. Do not "fix" either to match the other.
 *
 * Idempotent. Dry-run by default; BOS_RESTRUCTURE_APPLY=1 to write.
 */

$apply = getenv('BOS_RESTRUCTURE_APPLY') === '1';
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$materials = \Drupal::entityTypeManager()->getStorage('material');
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$aliasManager = \Drupal::service('path_alias.manager');

/** name => [parent name, list order, alias] */
$NEW_TERMS = [
  'Pine' => ['Evergreens', 10, '/material/plants/trees/evergreens/pine'],
  'Spruce' => ['Evergreens', 20, '/material/plants/trees/evergreens/spruce'],
  'Fir' => ['Evergreens', 30, '/material/plants/trees/evergreens/fir'],
  'Juniper' => ['Evergreens', 40, '/material/plants/trees/evergreens/juniper'],
  'Arborvitae' => ['Evergreens', 50, '/material/plants/trees/evergreens/arborvitae'],
  'Evergreen Shrubs' => ['Shrubs', 10, '/material/plants/shrubs/evergreen-shrubs'],
  'Deciduous Shrubs' => ['Shrubs', 20, '/material/plants/shrubs/deciduous-shrubs'],
];

/**
  * Moves, SCOPED BY BUNDLE. "Willow" exists as both a tree and a shrub, so a
  * title-only lookup would drag the tree Willow out of Shade and into Deciduous
  * Shrubs. Every entry names the bundle it applies to.
  */
$TREE_MOVES = [
  // --- Evergreens, by genus -------------------------------------------------
  'Austrian Pine' => 'Pine', 'Bosnian Pine' => 'Pine', 'Bristlecone Pine' => 'Pine',
  'Limber Pine' => 'Pine', 'Pinyon Pine' => 'Pine', 'Ponderosa Pine' => 'Pine',
  'Scotch Pine' => 'Pine', 'Southwestern White Pine' => 'Pine',
  'Colorado / Blue Spruce' => 'Spruce', 'Norway Spruce' => 'Spruce', 'White Spruce' => 'Spruce',
  // Douglas Fir: common-name shelf. Its genus stays Pseudotsuga — see above.
  'Douglas Fir' => 'Fir', 'White / Concolor Fir' => 'Fir',
  'Arborvitae' => 'Arborvitae',
  // Tree-form junipers only.
  'Rocky Mountain Juniper' => 'Juniper', 'One-Seed Juniper' => 'Juniper',
  // Species entries spanning tree/shrub/groundcover forms. Left generic on
  // purpose (prompt §6 default) rather than split into cultivar items.
  'Chinese Juniper' => 'Juniper', 'Common Juniper' => 'Juniper',

  // --- leaving the Trees branch --------------------------------------------
  'Yew' => 'Evergreen Shrubs',
  'Mugo Pine' => 'Evergreen Shrubs',
  'Blue Star Juniper' => 'Evergreen Shrubs',
  'Savin Juniper' => 'Evergreen Shrubs',
  'Japanese Garden Juniper' => 'Evergreen Shrubs',
  'Creeping Juniper' => 'Groundcovers',
];

$SHRUB_MOVES = [
  // --- shrubs that hold their leaves HERE -----------------------------------
  'Boxwood' => 'Evergreen Shrubs',
  'Manzanita' => 'Evergreen Shrubs',
  'False Yucca, Red Yucca' => 'Evergreen Shrubs',
  'Yucca' => 'Evergreen Shrubs',
  'Mountain Mahogany' => 'Evergreen Shrubs',
  'Ephedra /Joint Fir' => 'Evergreen Shrubs',
  'Bird\'s Nest Norway Spruce' => 'Evergreen Shrubs',
  'Dwarf Alberta Spruse' => 'Evergreen Shrubs',

  // --- vines filed under Shrubs --------------------------------------------
  'Clematis' => 'Vines',
  'Climbing Honeysuckle' => 'Vines',
  'Grape' => 'Vines',
  'Trumpet Vine' => 'Vines',
  // English Ivy deliberately NOT moved — see the report at the end.

  'Rose' => 'Roses',
];

/**
 * Everything else under Shrubs. Listed explicitly rather than derived as "the
 * remainder", so adding a shrub later does not silently get filed deciduous.
 */
$DECIDUOUS = [
  'Alpine Currant', 'Alternate-leaf Butterfly Bush', 'Althea / Rose-of-Sharon',
  'Apache Plume', 'Barberry', 'Blue Mist Spirea / Bluebeard', 'Boulder Raspberry',
  'Broom', 'Buckthorn', 'Burning Bush / Winged Euonymus', 'Butterfly Bush',
  'Chokeberry', 'Coralberry / Snowberry', 'Cotoneaster', 'Cranberrybush',
  'Currant & Gooseberry', 'Daphne', 'Dogwood', 'Dwarf Russian Almond', 'Elderberry',
  // Tagged Evergreen in BOS; semi-evergreen at best here. Under-promise.
  'Euonymus', 'Oregon Grape Holly', 'Pyracantha / Firethorn', 'Privet',
  'Fernbush', 'Forsythia', 'Honeysuckle', 'Hydrangea', 'Leadplant', 'Lilac',
  'Mockorange', 'Nanking Cherry', 'New Mexico Privet', 'Ninebark', 'Peashrub',
  'Potentilla / Shrubby Cinquefoil', 'Purple-leaf Sand Cherry', 'Quince',
  'Rabbitbrush', 'Raspberry', 'Sage / Artemisia', 'Smokebush', 'Spirea', 'Sumac',
  'Viburnum', 'Weigela', 'Western Sand Cherry', 'Willow',
];
foreach ($DECIDUOUS as $t) {
  $SHRUB_MOVES[$t] = 'Deciduous Shrubs';
}

// title => [category, bundle]
$MOVES = [];
foreach ($TREE_MOVES as $title => $cat) {
  $MOVES[] = [$title, $cat, 'trees'];
}
foreach ($SHRUB_MOVES as $title => $cat) {
  $MOVES[] = [$title, $cat, 'shrubs'];
}

// ---- resolve / create the terms -------------------------------------------
$byName = static function (string $name) use ($terms) {
  foreach ($terms->loadByProperties(['vid' => 'material_types', 'name' => $name]) as $t) {
    return $t;
  }
  return NULL;
};

print ($apply ? "APPLYING\n\n" : "DRY RUN\n\n");
print "--- terms ---\n";
foreach ($NEW_TERMS as $name => [$parentName, $order, $alias]) {
  if ($byName($name)) {
    printf("  exists  %-18s\n", $name);
    continue;
  }
  $parent = $byName($parentName);
  if (!$parent) {
    printf("  ABORT   parent %s not found for %s\n", $parentName, $name);
    return;
  }
  printf("  %s %-18s under %-12s order %-3s %s\n", $apply ? 'CREATE ' : 'would  ', $name, $parentName, $order, $alias);
  if ($apply) {
    $t = $terms->create(['vid' => 'material_types', 'name' => $name, 'parent' => [$parent->id()]]);
    if ($t->hasField('field_list_order')) {
      $t->set('field_list_order', $order);
    }
    $t->save();
    // Nested material_types aliases are MANUAL on this site, not generated.
    $aliasStorage->create(['path' => '/taxonomy/term/' . $t->id(), 'alias' => $alias, 'langcode' => 'en'])->save();
    \Drupal::keyValue('pathauto_state.taxonomy_term')->set($t->id(), 0);
  }
}

// ---- move the items --------------------------------------------------------
print "\n--- items ---\n";
$moved = $same = $missing = 0;
foreach ($MOVES as [$title, $catName, $bundle]) {
  $found = $materials->loadByProperties(['title' => $title, 'type' => $bundle]);
  if (!$found) {
    printf("  MISSING %-34s (no %s item with this title)\n", $title, $bundle);
    $missing++;
    continue;
  }
  $cat = $byName($catName);
  if (!$cat) {
    // On a dry run the new terms have not been created yet, so this is expected
    // for them and only a real problem for a category that should already exist.
    if (!$apply && isset($NEW_TERMS[$catName])) {
      printf("  would   %-34s -> %-18s (category pending creation)\n", $title, $catName);
      $moved++;
      continue;
    }
    printf("  ABORT   category %s not found\n", $catName);
    return;
  }
  foreach ($found as $m) {
    $current = $m->get('field_material_category')->entity;
    if ($current && (int) $current->id() === (int) $cat->id()) {
      $same++;
      continue;
    }
    $before = $aliasManager->getAliasByPath('/material/' . $m->id());
    if ($apply) {
      $m->set('field_material_category', ['target_id' => $cat->id()]);
      $m->save();
      printf("  SET     %-34s -> %-18s %s\n", $title, $catName, $aliasManager->getAliasByPath('/material/' . $m->id()));
    }
    else {
      printf("  would   %-34s -> %-18s (now %s)\n", $title, $catName, $before);
    }
    $moved++;
  }
}
printf("\n  %s: %d   already correct: %d   missing: %d\n", $apply ? 'moved' : 'would move', $moved, $same, $missing);

// ---- retire the old Junipers category --------------------------------------
print "\n--- old Junipers category ---\n";
$old = $byName('Junipers');
if (!$old) {
  print "  already gone\n";
}
else {
  $remaining = \Drupal::database()->query('SELECT COUNT(*) FROM {material__field_material_category} WHERE field_material_category_target_id = :t', [':t' => $old->id()])->fetchField();
  if ((int) $remaining > 0) {
    printf("  %d item(s) still filed there — NOT deleting\n", $remaining);
  }
  else {
    $oldAlias = $aliasManager->getAliasByPath('/taxonomy/term/' . $old->id());
    $new = $byName('Juniper');
    printf("  %s delete Junipers, 301 %s -> %s\n", $apply ? 'DELETE ' : 'would  ', $oldAlias, $new ? $aliasManager->getAliasByPath('/taxonomy/term/' . $new->id()) : '?');
    if ($apply && $new) {
      \Drupal::entityTypeManager()->getStorage('redirect')->create([
        'redirect_source' => ['path' => ltrim($oldAlias, '/'), 'query' => []],
        'redirect_redirect' => ['uri' => 'internal:/taxonomy/term/' . $new->id()],
        'language' => 'und',
        'status_code' => 301,
      ])->save();
      $old->delete();
    }
  }
}

if (!$apply) {
  print "\nDRY RUN. BOS_RESTRUCTURE_APPLY=1 to write.\n";
}
