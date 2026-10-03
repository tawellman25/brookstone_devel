<?php

/**
 * Remove redirects written on an entity's own SYSTEM path.
 *
 * A redirect whose source is a bare `taxonomy/term/N` (or `node/N`, etc.)
 * captures EVERY request that resolves to that entity — including requests that
 * arrive by the entity's own URL alias — so the page becomes permanently
 * unreachable at its canonical address.
 *
 * The live case, found 2026-10-03 sweeping the sitemap before a Search Console
 * submission: rid 36706, `taxonomy/term/1940` -> `internal:/equipment/77683`.
 * The "Cut-Off Saw" equipment-type page 301'd to an equipment record that NO
 * anonymous visitor can view (equipment detail pages are internal by design,
 * all 176 of them), so a public page in the sitemap answered 301 -> 403.
 * Almost certainly fallout from the 2026-09-01 bos_equipment auto-title work:
 * that power_tools record had a BLANK title, took an alias derived from its type
 * term, and when the title was fixed pathauto re-aliased it and the redirect
 * module left rows behind — one of them on the term itself.
 *
 * Redirects from a former ALIAS to an entity are legitimate and are NOT touched
 * (rids 36714 and 36715 here); only sources that are a raw internal entity path.
 *
 * Usage:
 *   drush php:script web/scripts/fix_term_system_path_redirect.php            # dry run
 *   BOS_REDIRECT_APPLY=1 drush php:script web/scripts/fix_term_system_path_redirect.php
 */

$apply = getenv('BOS_REDIRECT_APPLY') === '1';
$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$am = \Drupal::service('path_alias.manager');

printf("mode: %s\n\n", $apply ? 'APPLY' : 'DRY RUN');

// Any redirect whose SOURCE is a raw internal entity path.
$pattern = '#^(taxonomy/term|node|user|media|equipment|material|properties|work_order)/\d+$#';
$rows = $db->query('SELECT rid, redirect_source__path s, redirect_source__query q, redirect_redirect__uri u, status_code, language FROM {redirect}')->fetchAll();

$hits = [];
foreach ($rows as $r) {
  if (preg_match($pattern, (string) $r->s)) {
    $hits[] = $r;
  }
}
printf("redirect rows total: %d\n", count($rows));
printf("sources that are a bare entity system path: %d\n\n", count($hits));

$backup = [];
foreach ($hits as $r) {
  $alias = $am->getAliasByPath('/' . $r->s);
  $canonical = $alias !== '/' . $r->s ? $alias : '(no alias)';
  printf("  rid %-7s %-24s -> %-34s (%s)\n", $r->rid, $r->s, $r->u, $r->status_code);
  printf("      that entity's own alias: %s\n", $canonical);
  printf("      => every request for the alias above is sent to the target instead\n");
  $backup[] = [
    'rid' => $r->rid,
    'source_path' => $r->s,
    'source_query' => $r->q,
    'uri' => $r->u,
    'status_code' => $r->status_code,
    'language' => $r->language,
    'shadowed_alias' => $canonical,
  ];
}

if (!$hits) {
  print "Nothing to do.\n";
  return;
}

$file = sys_get_temp_dir() . '/system_path_redirects_' . date('Ymd_His') . '.json';
file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("\nrows recorded (enough to recreate any of them) -> %s\n", $file);

if (!$apply) {
  print "\nNothing deleted. Re-run with BOS_REDIRECT_APPLY=1 to apply.\n";
  return;
}

$storage = $etm->getStorage('redirect');
$deleted = 0;
foreach ($hits as $r) {
  $e = $storage->load($r->rid);
  if (!$e) {
    printf("  rid %s already gone\n", $r->rid);
    continue;
  }
  $e->delete();
  $deleted++;
  printf("  deleted rid %s\n", $r->rid);
}
printf("\n%d redirect(s) deleted\n", $deleted);
