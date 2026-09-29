<?php

declare(strict_types=1);

/**
 * Move Trees and Shrubs under Plants — taxonomy AND URLs.
 *
 * /material had Plants, Shrubs and Trees as three peers, and shrubs and trees
 * are plants. Plants becomes the parent and stays alongside PVC, Rock and the
 * rest.
 *
 * TWO THINGS THAT ARE NOT OBVIOUS FROM THE TAXONOMY:
 *
 *  1. The nested material_types aliases are MANUAL (pathauto_state = 0), not
 *     generated. So reparenting on its own changes no URL at all. If the
 *     addresses are to follow the hierarchy they have to be set here, by hand,
 *     exactly as the existing ones were.
 *
 *  2. The item aliases come from per-bundle patterns that hardcode the
 *     category — /material/trees/[material:title]. Reparenting without
 *     changing those leaves items at /material/trees/x underneath a category
 *     at /material/plants/trees: an item whose URL no longer matches its own
 *     category.
 *
 * Old URLs 301 by themselves: pathauto update_action is 2 and the redirect
 * module is enabled. Verified before relying on it.
 *
 * Idempotent. Dry run unless BOS_MAT_APPLY=1.
 */

$apply = getenv('BOS_MAT_APPLY') === '1';
print $apply ? "APPLYING\n\n" : "DRY RUN (set BOS_MAT_APPLY=1 to apply)\n\n";

$etm = \Drupal::entityTypeManager();
$terms = $etm->getStorage('taxonomy_term');
$aliasStorage = $etm->getStorage('path_alias');

$byName = [];
foreach ($terms->loadByProperties(['vid' => 'material_types']) as $t) {
  $byName[$t->label()] = $t;
}
$plants = $byName['Plants'] ?? NULL;
if (!$plants) {
  print "ABORT: no Plants term\n";
  return;
}

// Snapshot every alias that lives under the two branches, items included.
$db = \Drupal::database();
$before = [];
foreach (['/material/trees', '/material/shrubs'] as $pre) {
  foreach ($db->query('SELECT id, alias, path FROM path_alias WHERE alias = :e OR alias LIKE :p',
    [':e' => $pre, ':p' => $pre . '/%'])->fetchAll() as $r) {
    $before[$r->alias] = $r->path;
  }
}
printf("  snapshot: %d aliases under the two branches\n\n", count($before));

// --- 1. reparent ----------------------------------------------------------
foreach (['Trees', 'Shrubs'] as $name) {
  $t = $byName[$name] ?? NULL;
  if (!$t) {
    printf("  MISS %s\n", $name);
    continue;
  }
  $cur = (int) $t->get('parent')->target_id;
  if ($cur === (int) $plants->id()) {
    printf("  %-8s already under Plants\n", $name);
    continue;
  }
  printf("  %-8s parent %s -> Plants (%s)\n", $name, $cur ?: 'root', $plants->id());
  if ($apply) {
    $t->set('parent', [$plants->id()])->save();
  }
}

// --- 2. the term aliases, set by hand as the existing ones were ------------
// old alias => new alias
$moves = [
  '/material/trees' => '/material/plants/trees',
  '/material/trees/evergreens' => '/material/plants/trees/evergreens',
  '/material/trees/junipers' => '/material/plants/trees/junipers',
  '/material/trees/deciduous' => '/material/plants/trees/deciduous',
  '/material/trees/deciduous/shade' => '/material/plants/trees/deciduous/shade',
  '/material/trees/deciduous/ornamental' => '/material/plants/trees/deciduous/ornamental',
  '/material/shrubs' => '/material/plants/shrubs',
  '/material/shrubs/roses' => '/material/plants/shrubs/roses',
];

print "\n  term aliases:\n";
foreach ($moves as $old => $new) {
  $rows = $aliasStorage->loadByProperties(['alias' => $old]);
  if (!$rows) {
    printf("    (no alias %s)\n", $old);
    continue;
  }
  foreach ($rows as $a) {
    printf("    %-40s -> %s\n", $old, $new);
    if ($apply) {
      $a->setAlias($new)->save();
      // Create the 301 explicitly. pathauto's update_action creates redirects
      // when an ENTITY's alias changes; editing the path_alias row is not that
      // event, so relying on it here would silently leave the old URL dead.
      $exists = $etm->getStorage('redirect')->loadByProperties([
        'redirect_source__path' => ltrim($old, '/'),
      ]);
      if (!$exists) {
        $etm->getStorage('redirect')->create([
          'redirect_source' => ['path' => ltrim($old, '/'), 'query' => []],
          'redirect_redirect' => ['uri' => 'internal:' . $new],
          'language' => 'und',
          'status_code' => 301,
        ])->save();
      }
    }
  }
}

// --- 3. the item patterns -------------------------------------------------
$patternMoves = [
  'material_trees_path' => '/material/plants/trees/[material:title]',
  'material_shrubs_path' => '/material/plants/shrubs/[material:title]',
];
print "\n  item patterns:\n";
foreach ($patternMoves as $id => $new) {
  $p = $etm->getStorage('pathauto_pattern')->load($id);
  if (!$p) {
    printf("    MISS %s\n", $id);
    continue;
  }
  printf("    %-24s %s -> %s\n", $id, $p->getPattern(), $new);
  if ($apply) {
    $p->setPattern($new)->save();
  }
}

if (!$apply) {
  print "\n  (items re-alias on apply)\n";
  return;
}

// --- 4. re-alias the items ------------------------------------------------
$gen = \Drupal::service('pathauto.generator');
$materials = $etm->getStorage('material');
$ids = $materials->getQuery()->accessCheck(FALSE)
  ->condition('type', ['trees', 'shrubs'], 'IN')->execute();
$n = 0;
foreach ($materials->loadMultiple($ids) as $m) {
  $gen->updateEntityAlias($m, 'update');
  $n++;
}
printf("\n  %d tree/shrub items re-aliased\n", $n);
