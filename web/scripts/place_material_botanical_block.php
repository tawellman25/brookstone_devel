<?php

declare(strict_types=1);

/**
 * Place the botanical title block, and hide the core title on the same paths.
 *
 * THE PAIRING RULE. Two conditions have to stay identical: the block that SHOWS
 * the custom title, and the negated condition that HIDES core's. If they drift
 * you get a page with no heading (core hidden, custom absent) or two headings,
 * and neither errors or logs. So this script is the single source of both: it
 * derives one path list and writes it to both places in one run. Re-run it
 * whenever a plant category is added, renamed or moved — that is the whole
 * maintenance procedure.
 *
 * THE LIST IS DERIVED FROM THE CATEGORIES, not hardcoded. For each material_types
 * category that holds tree items, the item paths are that category's own alias
 * plus "/*". That is exact: an item lives one segment below its category, and
 * Drupal's "/a/b/*" does NOT match "/a/b", so the category page itself keeps
 * core's title while its items get the custom one.
 *
 * SCOPED TO BUNDLES THAT HAVE CATEGORIES — today, trees. Shrubs, annuals and the
 * plants bundle have no `field_material_category`, so their items sit at the SAME
 * depth as category pages (/material/plants/shrubs/lilac is an item,
 * /material/plants/shrubs/roses is a category) and no path pattern can separate
 * them. Giving those bundles the category field is what makes them eligible; the
 * script picks them up automatically once they have it.
 *
 * Idempotent. Dry-run by default; BOS_BOTANICAL_APPLY=1 to write.
 */

const BOS_BOT_BLOCK = 'brookstone_olivero_views_block__material_botanical_name_block_1';
const BOS_BOT_TITLE_BLOCK = 'brookstone_olivero_page_title';
const BOS_BOT_PLUGIN = 'views_block:material_botanical_name-block_1';

$apply = getenv('BOS_BOTANICAL_APPLY') === '1';

// ---- derive the paths ------------------------------------------------------
$db = \Drupal::database();
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$aliases = \Drupal::service('path_alias.manager');

if (!$db->schema()->tableExists('material__field_material_category')) {
  print "material__field_material_category missing — nothing to derive. Aborting.\n";
  return;
}

$tids = $db->query('SELECT DISTINCT field_material_category_target_id FROM {material__field_material_category}')->fetchCol();
$paths = [];
foreach ($tids as $tid) {
  $term = $terms->load($tid);
  if (!$term) {
    continue;
  }
  $alias = $aliases->getAliasByPath('/taxonomy/term/' . $tid);
  if (!str_starts_with($alias, '/material/')) {
    // No real alias, or somewhere unexpected — never guess a path.
    printf("  WARN  %s (tid %s) has no /material/ alias — skipped\n", $term->label(), $tid);
    continue;
  }
  $paths[$alias . '/*'] = $term->label();
}
ksort($paths);

if (!$paths) {
  print "no categorised items found — nothing to place. Aborting.\n";
  return;
}

print "derived item paths (one per category that holds items):\n";
foreach ($paths as $p => $label) {
  printf("  %-52s %s\n", $p, $label);
}
$pathList = implode("\r\n", array_keys($paths));

// ---- 1. the block that SHOWS the custom title ------------------------------
$blockStorage = \Drupal::entityTypeManager()->getStorage('block');
$block = $blockStorage->load(BOS_BOT_BLOCK);
$verb = $block ? 'update' : 'create';
printf("\n  %s show-block %s (region highlighted, weight -21)\n", $apply ? strtoupper($verb) : 'would ' . $verb, BOS_BOT_BLOCK);

if ($apply) {
  if (!$block) {
    $block = $blockStorage->create([
      'id' => BOS_BOT_BLOCK,
      'theme' => 'brookstone_olivero',
      'plugin' => BOS_BOT_PLUGIN,
      'settings' => [
        'id' => BOS_BOT_PLUGIN,
        'label' => 'Material botanical name',
        'label_display' => '0',
        'views_label' => '',
        'items_per_page' => 'none',
      ],
    ]);
  }
  $block->setRegion('highlighted');
  $block->setWeight(-21);
  $block->setStatus(TRUE);
  $block->setVisibilityConfig('request_path', [
    'id' => 'request_path',
    'negate' => FALSE,
    'context_mapping' => [],
    'pages' => $pathList,
  ]);
  $block->save();
}

// ---- 2. the core title block, HIDDEN on exactly the same paths --------------
$title = $blockStorage->load(BOS_BOT_TITLE_BLOCK);
if (!$title) {
  print "  ABORT: core title block " . BOS_BOT_TITLE_BLOCK . " not found. Show-block left as-is.\n";
  return;
}
$vis = $title->getVisibility();
$existing = $vis['request_path']['pages'] ?? '';
$lines = preg_split('/\r\n|\r|\n/', $existing);
// Drop every /material/ line this script has previously written, then re-add
// the current set, so a removed category stops hiding the title.
$kept = array_values(array_filter($lines, static fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '/material/')));
$merged = array_merge($kept, array_keys($paths));
$newList = implode("\r\n", $merged);

$negate = $vis['request_path']['negate'] ?? NULL;
if ($negate !== TRUE) {
  printf("  ABORT: %s request_path negate is %s, expected TRUE — refusing to edit a condition I do not understand.\n",
    BOS_BOT_TITLE_BLOCK, var_export($negate, TRUE));
  return;
}

printf("  %s hide-list on %s: %d kept + %d material paths\n",
  $apply ? 'WRITE ' : 'would ', BOS_BOT_TITLE_BLOCK, count($kept), count($paths));

if ($apply) {
  $vis['request_path']['pages'] = $newList;
  $title->setVisibilityConfig('request_path', $vis['request_path']);
  $title->save();
  print "\n  both conditions written from the same list.\n";
}
else {
  print "\nDRY RUN. BOS_BOTANICAL_APPLY=1 to write.\n";
}
