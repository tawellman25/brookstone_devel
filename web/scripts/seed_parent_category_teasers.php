<?php

/**
 * Card teasers for the four top-of-catalogue parents (marketing, 2026-10-03).
 *
 * field_short_description is the line that renders on a category's card in its
 * parent's list. These four were skipped while their children were written, so
 * their cards were still falling back to the old "At Brookstone Outdoors, our …
 * category brings year-round beauty" boilerplate.
 *
 * Terms are resolved by URL ALIAS, never by name — this branch has been renamed
 * twice. A script may own the copy; the office owns the name.
 *
 * ONE CORRECTION to the supplied copy, recorded here because it is a change to
 * marketing's words: the Trees teaser read "ornamentals under twenty feet",
 * while the Ornamental Trees page says "under about twenty-five feet" twice. A
 * parent card contradicting its own child page is worse than either number, so
 * this is twenty-five.
 *
 * Usage:
 *   drush php:script web/scripts/seed_parent_category_teasers.php          # dry run
 *   BOS_TEASER_APPLY=1 drush php:script web/scripts/seed_parent_category_teasers.php
 */

$apply = getenv('BOS_TEASER_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$aliasMgr = \Drupal::service('path_alias.manager');

$teasers = [
  '/material/plants' =>
    'Everything in the catalog that grows. Trees, shrubs, perennials, grasses, groundcovers and vines — the part of a landscape that is never quite finished.',
  '/material/plants/trees' =>
    'The longest decision on a property. Evergreen and deciduous, from ornamentals under twenty-five feet to shade trees that outlive the people who plant them.',
  '/material/plants/shrubs' =>
    'The structural middle of a planting. Holds the shape of a bed, screens what needs screening, and carries most of the flowering.',
  '/material/plants/trees/evergreens' =>
    'Conifers that hold their foliage year-round — pine, spruce, fir, juniper and arborvitae. What keeps a yard from reading as empty in February.',
];

printf("mode: %s\n\n", $apply ? 'APPLY' : 'DRY RUN');
$changed = $same = $problems = 0;
$backup = [];

foreach ($teasers as $alias => $text) {
  $internal = $aliasMgr->getPathByAlias($alias);
  if (!preg_match('#^/taxonomy/term/(\d+)$#', $internal, $m)) {
    printf("%-36s ** alias does not resolve — skipped, nothing guessed **\n", $alias);
    $problems++;
    continue;
  }
  $term = $etm->getStorage('taxonomy_term')->load($m[1]);
  if (!$term || !$term->hasField('field_short_description')) {
    printf("%-36s ** no term or no field_short_description — skipped **\n", $alias);
    $problems++;
    continue;
  }
  $cur = $term->get('field_short_description')->isEmpty()
    ? '' : trim((string) $term->get('field_short_description')->first()->getValue()['value']);
  $format = $term->get('field_short_description')->isEmpty()
    ? 'basic_html' : ($term->get('field_short_description')->first()->getValue()['format'] ?? 'basic_html');

  printf("%-18s (tid %s, %s)\n", (string) $term->label(), $term->id(), $alias);
  if ($cur === trim($text)) {
    printf("    already set (%d chars)\n\n", mb_strlen($cur));
    $same++;
    continue;
  }
  printf("    was: %s\n", $cur === '' ? '(empty — card fell back to body copy)' : $cur);
  printf("    now: %s  [%d chars, format %s]\n\n", $text, mb_strlen($text), $format);
  $changed++;
  $backup[] = ['tid' => $term->id(), 'label' => (string) $term->label(), 'field' => 'field_short_description', 'value' => $cur, 'format' => $format];
  if ($apply) {
    $term->set('field_short_description', ['value' => $text, 'format' => $format]);
    $term->save();
  }
}

if ($backup) {
  $f = sys_get_temp_dir() . '/parent_teaser_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  printf("previous values saved to: %s\n", $f);
}
printf("\n%d set, %d already correct, %d problem(s)\n", $changed, $same, $problems);
if (!$apply) {
  print "\nNothing written. Re-run with BOS_TEASER_APPLY=1 to apply.\n";
}
