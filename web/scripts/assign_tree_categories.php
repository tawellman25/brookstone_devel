<?php

declare(strict_types=1);

/**
 * Assign each tree in the catalogue to its subcategory.
 *
 * Sets field_material_category, which both files the tree on its subcategory
 * page and rebuilds its URL:
 *   /material/plants/trees/apple-tree
 *     -> /material/plants/trees/deciduous/fruit/apple-tree
 * The old URL 301s automatically (redirect module + pathauto update_action 2).
 *
 * TWO GROUPS, deliberately separated, because they are different kinds of claim.
 *
 *   BOTANY  — Evergreens, Junipers, Fruit. Objective: a spruce is a conifer, a
 *             Juniperus is a juniper, an apple tree is grown for apples. Nothing
 *             here is a matter of taste.
 *   SHELF   — the Shade / Ornamental split. A merchandising judgement about how
 *             a customer shops, not a fact about the plant. Hornbeam and Turkish
 *             Filbert are street trees at maturity but sold as specimens; either
 *             answer is defensible.
 *
 * Run one group at a time so the judgement calls can be reviewed without holding
 * up the objective ones.
 *
 * Idempotent — a tree already in the right category is skipped, and a tree the
 * OFFICE has put somewhere else is left alone and reported, never overwritten.
 *
 *   drush php:script web/scripts/assign_tree_categories.php              # dry run, both
 *   BOS_TREE_GROUP=botany  BOS_TREE_APPLY=1 drush php:script …           # write botany
 *   BOS_TREE_GROUP=shelf   BOS_TREE_APPLY=1 drush php:script …           # write shelf
 */

$apply = getenv('BOS_TREE_APPLY') === '1';
$group = getenv('BOS_TREE_GROUP') ?: 'all';

/** Title => category. Titles are matched exactly against the material label. */
$BOTANY = [
  // Junipers — genus Juniperus.
  'Blue Star Juniper' => 'Junipers',
  'Chinese Juniper' => 'Junipers',
  'Common Juniper' => 'Junipers',
  'Creeping Juniper' => 'Junipers',
  'Japanese Garden Juniper' => 'Junipers',
  'One-Seed Juniper' => 'Junipers',
  'Rocky Mountain Juniper' => 'Junipers',
  'Savin Juniper' => 'Junipers',

  // Evergreens — conifers other than Juniperus.
  'Arborvitae' => 'Evergreens',
  'Austrian Pine' => 'Evergreens',
  'Bosnian Pine' => 'Evergreens',
  'Bristlecone Pine' => 'Evergreens',
  'Colorado / Blue Spruce' => 'Evergreens',
  'Douglas Fir' => 'Evergreens',
  'Limber Pine' => 'Evergreens',
  'Mugo Pine' => 'Evergreens',
  'Norway Spruce' => 'Evergreens',
  'Pinyon Pine' => 'Evergreens',
  'Ponderosa Pine' => 'Evergreens',
  'Scotch Pine' => 'Evergreens',
  'Southwestern White Pine' => 'Evergreens',
  'White / Concolor Fir' => 'Evergreens',
  'White Spruce' => 'Evergreens',
  'Yew' => 'Evergreens',

  // Fruit — grown for a crop someone eats.
  'Apple Tree' => 'Fruit',
  'Apricot Tree' => 'Fruit',
  'Cherry Tree' => 'Fruit',
  'Peach Tree' => 'Fruit',
  'Pear Tree' => 'Fruit',
  'Plum Tree' => 'Fruit',
];

$SHELF = [
  // Shade — large deciduous canopy, planted for the canopy.
  'Birch' => 'Shade',
  'Boxelder' => 'Shade',
  'Buckeye / Horsechestnut' => 'Shade',
  'Cottonwood' => 'Shade',
  'Elm' => 'Shade',
  'Hackberry' => 'Shade',
  'Hornbeam' => 'Shade',
  'Japanese Pagoda Tree' => 'Shade',
  'Kentucky Coffeetree' => 'Shade',
  'Linden' => 'Shade',
  'Maple' => 'Shade',
  'Oak' => 'Shade',
  'Planetree / Sycamore' => 'Shade',
  'Turkish Filbert' => 'Shade',
  'Western Catalpa' => 'Shade',
  'Willow' => 'Shade',

  // Ornamental — planted for flower, form, bark or fruit display.
  'Canada Red Chokecherry' => 'Ornamental',
  'Cherry Plum' => 'Ornamental',
  'Eastern Redbud' => 'Ornamental',
  'Flowering Crabapple' => 'Ornamental',
  'Goldenrain Tree' => 'Ornamental',
  'Hawthorn' => 'Ornamental',
  'Mayday Tree' => 'Ornamental',
  'Mountain Ash' => 'Ornamental',
  'Ornamental Pear' => 'Ornamental',
  'Serviceberry' => 'Ornamental',
  'Thinleaf Alder' => 'Ornamental',
  'Tree Lilac' => 'Ornamental',
  'Weeping Mulberry' => 'Ornamental',
];

/**
 * Deliberately NOT assigned. Each is a real question rather than an oversight,
 * and an unassigned tree simply keeps the URL it has today.
 */
$HELD = [
  'Eastern Red Cedar' => 'Juniperus virginiana. Junipers is the botanically correct home and Rocky Mountain / One-Seed Juniper are already there as uprights — awaiting Todd.',
];

// ---- resolve the category terms, by relationship, never by exact name -------
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$trees = NULL;
foreach ($terms->loadByProperties(['vid' => 'material_types']) as $t) {
  if ($t->get('field_material_bundle')->value === 'trees') {
    $trees = $t;
    break;
  }
}
if (!$trees) {
  print "ABORT: no material_types term claims the 'trees' bundle.\n";
  return;
}

$cats = [];
foreach ($terms->loadTree('material_types', (int) $trees->id(), 1, TRUE) as $child) {
  foreach (['Evergreens', 'Junipers', 'Deciduous'] as $want) {
    if (stripos($child->label(), $want) === 0) {
      $cats[$want] = $child;
    }
  }
}
if (isset($cats['Deciduous'])) {
  foreach ($terms->loadTree('material_types', (int) $cats['Deciduous']->id(), 1, TRUE) as $child) {
    foreach (['Shade', 'Ornamental', 'Fruit'] as $want) {
      if (stripos($child->label(), $want) === 0) {
        $cats[$want] = $child;
      }
    }
  }
}
foreach (['Evergreens', 'Junipers', 'Shade', 'Ornamental', 'Fruit'] as $need) {
  if (!isset($cats[$need])) {
    print "ABORT: category '$need' not found under Trees. Nothing written.\n";
    return;
  }
}

// ---- apply -----------------------------------------------------------------
$map = [];
if ($group === 'all' || $group === 'botany') {
  $map += $BOTANY;
}
if ($group === 'all' || $group === 'shelf') {
  $map += $SHELF;
}

$ms = \Drupal::entityTypeManager()->getStorage('material');
$am = \Drupal::service('path_alias.manager');
$ids = $ms->getQuery()->accessCheck(FALSE)->condition('type', 'trees')->sort('title')->execute();

$done = $skip = $conflict = $unknown = 0;
printf("group=%s  mode=%s\n\n", $group, $apply ? 'APPLY' : 'dry run');

foreach ($ms->loadMultiple($ids) as $m) {
  $title = $m->label();
  if (!isset($map[$title])) {
    if (isset($HELD[$title])) {
      $unknown++;
    }
    continue;
  }
  $target = $cats[$map[$title]];

  $current = $m->get('field_material_category')->entity;
  if ($current && (int) $current->id() === (int) $target->id()) {
    $skip++;
    continue;
  }
  if ($current) {
    // Someone filed it elsewhere on purpose. Report, never overwrite.
    printf("  KEEP    %-34s office has it in %s (script wanted %s)\n", $title, $current->label(), $target->label());
    $conflict++;
    continue;
  }

  $before = $am->getAliasByPath('/material/' . $m->id());
  if ($apply) {
    $m->set('field_material_category', ['target_id' => $target->id()]);
    $m->save();
    $after = $am->getAliasByPath('/material/' . $m->id());
    printf("  SET     %-34s %-13s %s\n", $title, $target->label(), $after);
  }
  else {
    printf("  would   %-34s %-13s (now %s)\n", $title, $target->label(), $before);
  }
  $done++;
}

printf("\n  %s: %d   already correct: %d   left alone (office differs): %d\n",
  $apply ? 'assigned' : 'would assign', $done, $skip, $conflict);

if ($group === 'all' || $group === 'shelf') {
  print "\n  HELD — not assigned, each a real question:\n";
  foreach ($HELD as $t => $why) {
    printf("    %-24s %s\n", $t, $why);
  }
}
if (!$apply) {
  print "\nDRY RUN. Re-run with BOS_TREE_APPLY=1 (and BOS_TREE_GROUP=botany|shelf) to write.\n";
}
