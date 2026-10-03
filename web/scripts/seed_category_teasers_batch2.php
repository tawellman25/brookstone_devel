<?php

/**
 * Card teasers: 7 plant categories + 16 root categories (marketing, 2026-10-03).
 *
 * field_short_description is the line that renders on a category's card. The
 * root categories only started being able to show one on 2026-10-03, when the
 * card hook was extended to material_types_landing — before that a teaser
 * written for a root category would have been stored and invisible.
 *
 * Terms are resolved by URL ALIAS and then VERIFIED to be a material_types term
 * whose name matches, because six of these aliases are shared with another path
 * in {path_alias} and resolving one blind could land on a material item. A
 * mismatch is reported and skipped rather than guessed at.
 *
 * Idempotent: a teaser already matching is left alone, so re-running is a no-op.
 *
 * Usage:
 *   drush php:script web/scripts/seed_category_teasers_batch2.php          # dry run
 *   BOS_TEASER_APPLY=1 drush php:script web/scripts/seed_category_teasers_batch2.php
 */

$apply = getenv('BOS_TEASER_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$am = \Drupal::service('path_alias.manager');

// alias => [expected term name, teaser]
$teasers = [
  // --- Seven plant categories (six on Plants, Roses on Shrubs) -------------
  '/material/plants/perennials' => ['Perennials',
    'Come back every year from the same roots. The flowering layer of a bed, and the part you can change your mind about without rebuilding anything.'],
  '/material/plants/annuals' => ['Annuals',
    'One season and done. Maximum colour for containers, entries and the spots where you want something different every year.'],
  '/material/plants/grasses' => ['Grasses',
    'The most underused plants on this ground — wind-hardy, drought-tolerant, and the only thing still standing in the beds in February.'],
  '/material/plants/groundcovers' => ['Groundcovers',
    'Low and spreading, covering ground that turf cannot. Slopes too steep to mow, dry shade under trees, strips too narrow to water properly.'],
  '/material/plants/ferns' => ['Ferns',
    'Shade and steady moisture, which makes them a specialty item in a high desert. Where the conditions suit them, nothing else has the texture.'],
  '/material/plants/vines' => ['Vines',
    'Climb, trail or scramble, and every one of them needs something to hold. Screening, shade over a pergola, and softening anything vertical.'],
  '/material/plants/shrubs/roses' => ['Roses',
    'Asked for by name more than any other shrub, and hardier here than their reputation suggests — with the right varieties and a little winter care.'],

  // --- Sixteen root categories, the front door of the catalog --------------
  '/material/irrigation' => ['Irrigation',
    'Valves, heads, rotors, controllers, drip and filtration. The largest category we stock, because irrigation runs the longest season of any department.'],
  '/material/pvc' => ['PVC (Polyvinyl Chloride) - Pipes and Fittings',
    'Mainline and lateral pipe, fittings and solvent weld supplies. The backbone of most systems here, and the one where burial depth decides everything.'],
  '/material/poly' => ['Poly (Polyethylene) Pipe and Fittings',
    'Flexible pipe, insert fittings and clamps. Goes where rigid pipe is the wrong answer — curves, shifting ground, and repairs you cannot cut straight.'],
  '/material/brass' => ['Brass',
    'Valves, unions, nipples and adapters. Where a connection has to hold pressure and still come apart years later without digging the same hole twice.'],
  '/material/copper' => ['Copper',
    'Copper pipe and fittings, for the connections that get buried or built into something and are not going to be looked at again.'],
  '/material/galvanized' => ['Galvanized Pipe and Fittings',
    'Threaded steel pipe and fittings, for the runs that have to take abuse rather than take pressure. Risers, sleeves and anything that gets hit.'],
  '/material/pumps' => ['Pumps',
    'Pumps, controls and accessories — mostly for getting ditch and pond water up to the pressure a system designed for municipal supply expects.'],
  '/material/backflow' => ['Backflow',
    'The assemblies that keep irrigation water out of the drinking supply. Required by the state, tested annually, and reported by the tester rather than by you.'],
  '/material/electric' => ['Electric',
    'Wire, connectors, transformers, conduit and controls. The electrical side of irrigation, low-voltage lighting and pump work.'],
  '/material/pavers' => ['Blocks and Pavers',
    'Pavers, retaining wall block, caps and edging. The visible half of a hardscape — what decides whether it is still flat in ten years is underneath it.'],
  '/material/decorative_rock' => ['Rock',
    'Decorative rock, cobble, boulders and base material by the ton. What goes under a patio and what goes on top of a bed are not the same product.'],
  '/material/mulch' => ['Mulch',
    'Bark and wood mulch by the yard. Holds moisture in a climate that takes it straight back, and in this sun that is most of what it is there for.'],
  '/material/sod' => ['Sod',
    'Turf by the roll, in blends that hold up to this sun and this water. Sod is perishable, so delivery timing is part of the job rather than a detail.'],
  '/material/landscape' => ['Landscape Materials',
    'Fabric, edging, stakes, ties, amendments and the supporting materials that go into a planting without being the planting.'],
  '/material/xmas' => ['Christmas Lights',
    'Commercial-grade holiday lighting, clips and controllers. Built to go up in November and still be working in January, which the hardware store strings are not.'],
  '/material/misc' => ['Miscellaneous Materials',
    'The catch-all. Hardware, consumables and the one-off items that belong to no category and are needed on a job anyway.'],
];

printf("mode: %s\n\n", $apply ? 'APPLY' : 'DRY RUN');
$set = $same = $problems = 0;
$backup = [];

foreach ($teasers as $alias => [$expected, $text]) {
  $internal = $am->getPathByAlias($alias);
  if (!preg_match('#^/taxonomy/term/(\d+)$#', $internal, $m)) {
    printf("  %-44s ** alias resolves to %s, not a term — skipped **\n", $alias, $internal);
    $problems++;
    continue;
  }
  $term = $etm->getStorage('taxonomy_term')->load($m[1]);
  if (!$term || $term->bundle() !== 'material_types') {
    printf("  %-44s ** not a material_types term — skipped **\n", $alias);
    $problems++;
    continue;
  }
  if ((string) $term->label() !== $expected) {
    // The office owns the name; a mismatch means the copy may be for another
    // term, so this reports rather than writing to the wrong page.
    printf("  %-44s ** name is %s, copy was written for %s — skipped **\n", $alias, var_export((string) $term->label(), TRUE), var_export($expected, TRUE));
    $problems++;
    continue;
  }
  if (!$term->hasField('field_short_description')) {
    printf("  %-44s ** no field_short_description — skipped **\n", $alias);
    $problems++;
    continue;
  }

  $item = $term->get('field_short_description');
  $cur = $item->isEmpty() ? '' : trim((string) $item->first()->getValue()['value']);
  $format = $item->isEmpty() ? 'basic_html' : ($item->first()->getValue()['format'] ?? 'basic_html');
  if ($cur === trim($text)) {
    printf("  %-44s already set\n", $alias);
    $same++;
    continue;
  }
  printf("  %-44s %s (%d chars)\n", $alias, $cur === '' ? 'SET' : 'REPLACED', mb_strlen($text));
  if ($cur !== '') {
    printf("      was: %s\n", mb_substr($cur, 0, 90));
  }
  $set++;
  $backup[] = ['tid' => $term->id(), 'name' => (string) $term->label(), 'value' => $cur, 'format' => $format];
  if ($apply) {
    $term->set('field_short_description', ['value' => $text, 'format' => $format])->save();
  }
}

if ($backup) {
  $f = sys_get_temp_dir() . '/teasers_batch2_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  printf("\nprevious values saved to: %s\n", $f);
}
printf("\n%d set, %d already correct, %d problem(s)\n", $set, $same, $problems);
if (!$apply) {
  print "\nNothing written. Re-run with BOS_TEASER_APPLY=1 to apply.\n";
}
