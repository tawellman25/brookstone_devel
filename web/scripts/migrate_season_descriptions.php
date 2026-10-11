<?php
/**
 * Move the season bodies off core `description`, in the order that protects the
 * meta description.
 *
 * The trap, which this ordering exists for: the taxonomy metatag default is
 * `[term:description]` and the GLOBAL default defines no description at all, so
 * there is no fallback. Clearing core `description` first strips the meta
 * description from five pages that are all in the sitemap. So the per-term
 * override is written and PROVEN TO RENDER before anything is cleared, and the
 * script refuses to clear if it has not.
 *
 * Metatags are seeded from the AUTHORED copy rather than derived from the body —
 * marketing supplied a title and description for all five.
 *
 * Dry run by default. BOS_SEASON_MIGRATE_APPLY=1 to write.
 */
require __DIR__ . '/bos_seasons_copy_parser.inc.php';

$apply = getenv('BOS_SEASON_MIGRATE_APPLY') === '1';
$md = getenv('BOS_SEASON_MD') ?: '/tmp/seasons.md';
$reloc = getenv('BOS_SEASON_RELOC') ?: '/tmp/relocation.md';
$etm = \Drupal::entityTypeManager();
$renderer = \Drupal::service('renderer');

printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (BOS_SEASON_MIGRATE_APPLY=1 to apply)');
if (!is_readable($md) || !is_readable($reloc)) { printf("copy files not readable: %s / %s\n", $md, $reloc); return; }
$copy = bos_seasons_parse($md, $reloc);

$terms = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']) as $t) {
  $alias = \Drupal::service('path_alias.repository')->lookupBySystemPath('/taxonomy/term/' . $t->id(), 'en');
  $slug = $alias ? basename($alias['alias']) : strtolower($t->label());
  $terms[$slug] = $t;
}

// --- backup --------------------------------------------------------------
$backup = [];
foreach ($terms as $slug => $t) {
  $backup[$t->id()] = ['slug' => $slug, 'name' => $t->label(), 'description' => $t->get('description')->getValue()];
}
$dir = 'public://bos-backups';
\Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
$path = $dir . '/season-core-description-' . date('Ymd-His') . '.json';

print "STEP 2 — copy core description -> field_public_description\n";
foreach ($terms as $slug => $t) {
  $core = (string) $t->get('description')->value;
  $have = !$t->get('field_public_description')->isEmpty();
  printf("  %-12s core=%-6d public=%s\n", $slug, strlen($core), $have ? 'already set — left alone' : 'will copy');
  if (!$apply || $have || $core === '') { continue; }
  $t->set('field_public_description', ['value' => $core, 'format' => $t->get('description')->format ?: 'full_html']);
  $t->save();
}

print "\nSTEP 3 — verify identical\n";
$identical = TRUE;
foreach ($terms as $slug => $t) {
  $f = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
  $a = trim((string) $f->get('description')->value);
  $b = trim((string) $f->get('field_public_description')->value);
  $same = ($a === '' || $a === $b);
  if (!$same) { $identical = FALSE; }
  printf("  %-12s %s  core=%d public=%d\n", $slug, $same ? 'identical' : '*** DIFFERS ***', strlen($a), strlen($b));
}

print "\nSTEP 4 — seed field_meta_tags from the authored copy\n";
foreach ($terms as $slug => $t) {
  $c = $copy['terms'][$slug] ?? NULL;
  $desc = $c['description'] ?? NULL;
  $title = $c['title'] ?? NULL;
  // Winter's meta description is revised by the relocation decision.
  if ($slug === 'winter' && !empty($copy['inserts']['winter_meta'])) { $desc = $copy['inserts']['winter_meta']; }
  if (!$desc) { printf("  %-12s NO AUTHORED META — skipped\n", $slug); continue; }
  printf("  %-12s title=%-46s desc=%d chars%s\n", $slug, substr((string) $title, 0, 46), strlen($desc),
    strlen($desc) > 160 ? '  ** OVER 160 **' : '');
  if (!$apply) { continue; }
  $f = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
  $existing = $f->get('field_meta_tags')->value ? unserialize($f->get('field_meta_tags')->value) : [];
  if (!is_array($existing)) { $existing = []; }
  $existing['title'] = $title;
  $existing['description'] = $desc;
  $existing['og_description'] = $desc;
  $f->set('field_meta_tags', serialize($existing));
  $f->save();
}

print "\nSTEP 5 — do the meta descriptions actually render?\n";
$allRender = TRUE;
foreach ($terms as $slug => $t) {
  $f = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
  $tags = \Drupal::service('metatag.manager')->tagsFromEntityWithDefaults($f);
  $rendered = \Drupal::service('metatag.manager')->generateRawElements($tags, $f);
  $desc = $rendered['description']['#attributes']['content'] ?? '';
  if ($desc === '') { $allRender = FALSE; }
  printf("  %-12s %s\n", $slug, $desc === '' ? '*** NO META DESCRIPTION ***' : substr($desc, 0, 90));
}

print "\nSTEP 6 — clear core description\n";
if (!$apply) { print "  (dry run)\n"; return; }
if (!$identical) { print "  REFUSING: the copy is not identical on every term.\n"; return; }
if (!$allRender) { print "  REFUSING: a meta description did not render. Core description left intact.\n"; return; }
file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT));
if (!file_exists($path)) { print "  REFUSING: backup could not be written.\n"; return; }
printf("  backup: %s\n", $path);
foreach ($terms as $slug => $t) {
  $f = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
  $f->set('description', ['value' => '', 'format' => $f->get('description')->format]);
  $f->save();
  printf("  %-12s cleared\n", $slug);
}
// And off the displays that still name it.
foreach ($etm->getStorage('entity_view_display')->loadByProperties(['targetEntityType' => 'taxonomy_term', 'bundle' => 'season']) as $d) {
  if ($d->getComponent('description')) { $d->removeComponent('description')->save(); printf("  removed from the %s display\n", $d->getMode()); }
}
$fd = $etm->getStorage('entity_form_display')->load('taxonomy_term.season.default');
if ($fd && $fd->getComponent('description')) { $fd->removeComponent('description')->save(); print "  removed from the term form\n"; }
print "\ndone\n";
