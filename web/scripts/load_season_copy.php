<?php
/**
 * Paste the authored season copy.
 *
 * Parsed straight out of the marketing markdown rather than retyped — 26 KB of
 * transcription is the one step guaranteed to introduce a difference nobody
 * notices until a customer reads it.
 *
 * Two decisions from `Pre-season Unpublished - Content Relocation.md` are
 * carried here:
 *
 *  - PRE-SEASON COPY IS NOT PASTED. The term comes off the public site, so its
 *    authored body is shelved rather than published. Its migrated old body
 *    stays on field_public_description; nothing is lost.
 *  - WINTER and SPRING each gain a paragraph that was written for Pre-season,
 *    because the argument is the best commercial content in the set and should
 *    not disappear with the page. Winter's short description and meta
 *    description are the REVISED ones, not the originals.
 *
 * The inserts are anchored on the text of the paragraph they follow, not on a
 * position, so a later edit to the body cannot silently move them somewhere
 * that reads wrong. A missing anchor aborts rather than guessing.
 *
 * Dry run by default. BOS_SEASON_COPY_APPLY=1 to write.
 */
require __DIR__ . '/bos_seasons_copy_parser.inc.php';

$apply = getenv('BOS_SEASON_COPY_APPLY') === '1';
$md = getenv('BOS_SEASON_MD') ?: '/tmp/seasons.md';
$reloc = getenv('BOS_SEASON_RELOC') ?: '/tmp/relocation.md';
$etm = \Drupal::entityTypeManager();
printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (BOS_SEASON_COPY_APPLY=1 to apply)');
$copy = bos_seasons_parse($md, $reloc);

// Where each borrowed paragraph goes, and what it must follow.
$INSERTS = [
  'winter' => ['anchor' => 'Pruning is better now', 'html' => $copy['inserts']['winter_body'] ?? NULL],
  'spring' => ['anchor' => 'Planting too early', 'html' => $copy['inserts']['spring_body'] ?? NULL],
];
$SKIP_BODY = ['pre-season'];

$terms = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']) as $t) {
  $alias = \Drupal::service('path_alias.repository')->lookupBySystemPath('/taxonomy/term/' . $t->id(), 'en');
  $terms[$alias ? basename($alias['alias']) : strtolower($t->label())] = $t;
}

$backup = [];
foreach ($terms as $slug => $t) {
  $term = $copy['terms'][$slug] ?? NULL;
  if (!$term) { printf("  %-12s no authored copy — skipped\n", $slug); continue; }

  if (in_array($slug, $SKIP_BODY, TRUE)) {
    printf("  %-12s DELIBERATELY NOT PASTED — coming off the public site; its copy is shelved\n", $slug);
    continue;
  }

  $values = [
    'field_short_description' => $term['field_short_description'],
    'field_public_description' => $term['field_public_description'],
    'field_teammate_description' => $term['field_teammate_description'],
    'field_call_to_action' => $term['field_call_to_action'],
  ];

  // Winter's teaser is revised by the relocation decision.
  if ($slug === 'winter' && !empty($copy['inserts']['winter_short'])) {
    $values['field_short_description'] = $copy['inserts']['winter_short'];
  }

  // The borrowed paragraph, placed after the paragraph it belongs behind.
  if (isset($INSERTS[$slug]) && $INSERTS[$slug]['html']) {
    $anchor = $INSERTS[$slug]['anchor'];
    $paras = preg_split('/\n\s*\n/', $values['field_public_description']);
    $at = NULL;
    foreach ($paras as $i => $p) { if (str_contains($p, $anchor)) { $at = $i; break; } }
    if ($at === NULL) {
      printf("  %-12s ABORT: insert anchor \"%s\" not found in the body\n", $slug, $anchor);
      return;
    }
    array_splice($paras, $at + 1, 0, [$INSERTS[$slug]['html']]);
    $values['field_public_description'] = implode("\n\n", $paras);
    printf("  %-12s insert placed after paragraph %d (\"%s…\")\n", $slug, $at, substr($anchor, 0, 28));
  }

  printf("  %-12s short=%-4d public=%-5d crew=%-5d cta=%-4d\n", $slug,
    strlen($values['field_short_description']), strlen($values['field_public_description']),
    strlen($values['field_teammate_description']), strlen($values['field_call_to_action']));

  if (!$apply) { continue; }
  $backup[$t->id()] = [];
  foreach ($values as $f => $v) {
    $backup[$t->id()][$f] = $t->get($f)->getValue();
    $t->set($f, ['value' => $v, 'format' => 'full_html']);
  }
  $t->save();
}

if ($apply && $backup) {
  $dir = 'public://bos-backups';
  \Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
  $p = $dir . '/season-copy-previous-' . date('Ymd-His') . '.json';
  file_put_contents($p, json_encode($backup, JSON_PRETTY_PRINT));
  printf("\nprevious values backed up: %s\n", $p);
}
print "\ndone" . ($apply ? '' : ' (dry run)') . "\n";
