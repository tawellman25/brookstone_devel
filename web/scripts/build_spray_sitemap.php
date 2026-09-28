<?php

declare(strict_types=1);

/**
 * Step 4 — sitemap (xmlsitemap, NOT simple_sitemap).
 *
 * Surgical, no full rebuild (a rebuild truncates {xmlsitemap} and would risk the
 * existing custom links). Idempotent. Per env (custom links + link rows are DB;
 * bundle-inclusion is active config — neither rides cim).
 *
 *   1. ADD custom links for the spray parent VIEW pages that are not already in
 *      the sitemap, plus the conversion pages /request-estimate, /contact,
 *      /about-us/credentials. Matched by loc so re-runs and already-present links
 *      (location, methods on live) are no-ops — no duplicates. The two NODE
 *      parents (chemicals, stages-weed-growth) are auto-included as nodes and are
 *      deliberately NOT added here (that would duplicate them).
 *   2. EXCLUDE the seven spray leaf vocabularies (~49 term URLs): set the bundle
 *      inclusion config status=0 AND flip their existing {xmlsitemap} rows to
 *      status=0. Terms stay publicly reachable — only un-submitted. No noindex.
 *   3. DEDUPE weekly-lawn-mowing: delete the stale custom-type link whose loc is
 *      that path (the entity-generated row is kept).
 *   4. REGENERATE the sitemap XML files (no truncate).
 *
 * /winterize is intentionally left out.
 *
 *   drush php:script web/scripts/build_spray_sitemap.php
 */

use Drupal\Core\Language\LanguageInterface;

$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$linkStorage = \Drupal::service('xmlsitemap.link_storage');
$aliasManager = \Drupal::service('path_alias.manager');

/* 1. Custom links to ensure-exist (loc = system path). */
$ADD = [
  '/services/landscape-lawn-care/spraying/frequency',
  '/services/landscape-lawn-care/spraying/wind-speed',
  '/services/landscape-lawn-care/spraying/wind-direction',
  '/services/landscape-lawn-care/spraying/soil-moisture',
  '/services/landscape-lawn-care/spraying/carrier',
  '/services/landscape-lawn-care/spraying/location',
  '/services/landscape-lawn-care/spraying/methods',
  '/services/landscape-lawn-care/spraying/chemicals/signal-words',
  '/request-estimate',
  '/contact',
  '/about-us/credentials',
];

$router = \Drupal::service('router.no_access_checks');
$added = 0;
$skipped = 0;
$unresolved = [];
foreach ($ADD as $path) {
  $loc = $aliasManager->getPathByAlias($path);
  // Never submit a URL that does not resolve to a route (e.g. /about-us/credentials is
  // not built yet). Self-correcting: a re-run adds it once the page exists.
  try {
    $router->match($loc);
  }
  catch (\Throwable $e) {
    printf("  ! SKIP unresolved (no route): %s\n", $loc);
    $unresolved[] = $loc;
    continue;
  }
  // Skip if ANY link already renders this loc (custom or entity), so we never
  // create a second <loc> for a page already in the sitemap.
  $exists = $db->select('xmlsitemap', 'x')->fields('x', ['id'])
    ->condition('loc', $loc)->condition('status', 1)
    ->range(0, 1)->execute()->fetchField();
  if ($exists !== FALSE) {
    printf("  = already present: %s\n", $loc);
    $skipped++;
    continue;
  }
  // Next custom id. The id column is a string, so MAX must cast to an integer
  // (a lexical ORDER BY makes '9' outrank '10' and ids collide) — this mirrors
  // core's XmlSitemapCustomAddForm.
  $q = $db->select('xmlsitemap', 'x')->condition('type', 'custom');
  $q->addExpression('MAX(CAST(id AS UNSIGNED))', 'maxid');
  $maxId = (int) $q->execute()->fetchField();
  $link = [
    'type' => 'custom',
    'id' => $maxId + 1,
    'subtype' => '',
    'loc' => $loc,
    'status' => 1,
    'status_override' => 1,
    'priority' => 0.5,
    'priority_override' => 1,
    'changefreq' => 0,
    'language' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
    'access' => 1,
  ];
  $linkStorage->save($link);
  printf("  + added custom link: %s (id=%d)\n", $loc, $link['id']);
  $added++;
}
printf("Custom links: %d added, %d already present, %d unresolved (skipped).\n", $added, $skipped, count($unresolved));
if ($unresolved) {
  print "  UNRESOLVED (report, not added): " . implode(', ', $unresolved) . "\n";
}
print "\n";

/* 2. Exclude the seven spray leaf vocabularies. */
$LEAF_VIDS = [
  'wind_direction',
  'spraying_wind_speed',
  'spraying_soil_moisture_levels',
  'carrier',
  'spraying_frequency',
  'spraying_locations',
  'spraying_methods',
];
$cf = \Drupal::configFactory();
$rowsFlipped = 0;
foreach ($LEAF_VIDS as $vid) {
  $cfg = $cf->getEditable("xmlsitemap.settings.taxonomy_term.$vid");
  if ($cfg->get('status') !== 0) {
    $cfg->set('status', 0)->save();
  }
  $n = $db->update('xmlsitemap')->fields(['status' => 0, 'status_override' => 0])
    ->condition('type', 'taxonomy_term')->condition('subtype', $vid)
    ->execute();
  $rowsFlipped += $n;
  printf("  excluded vocab %-32s (config status=0, %d link rows -> status 0)\n", $vid, $n);
}
printf("Leaf exclusion: %d term link rows set to status 0.\n\n", $rowsFlipped);

/* 3. Dedupe weekly-lawn-mowing. Root cause: two taxonomy terms share the exact
 * same URL alias — the canonical `services` term "Weekly Lawn Mowing" and an
 * operational `mowing_frequency` term "Weekly" — so both emit that <loc>. Keep
 * the services term; exclude the non-services one from the sitemap. Resolved via
 * the shared alias + vocab, so it is environment-independent (no hardcoded tid).
 */
$wlm = '/services/landscape-lawn-care/mowing/weekly-lawn-mowing';
// Also drop any stale custom-type link for that path (belt-and-suspenders — the
// live duplicate could come from a custom link as well as / instead of the
// duplicate alias).
foreach ($db->select('xmlsitemap', 'x')->fields('x', ['id'])
  ->condition('type', 'custom')->condition('loc', $wlm)->execute()->fetchCol() as $cid) {
  $linkStorage->delete('custom', $cid);
  printf("  weekly-lawn-mowing dedupe: removed stale custom link (id=%s)\n", $cid);
}
$aliasEntities = $etm->getStorage('path_alias')->loadByProperties(['alias' => $wlm]);
$dedup = 0;
foreach ($aliasEntities as $pa) {
  if (!preg_match('#^/taxonomy/term/(\d+)$#', $pa->getPath(), $m)) {
    continue;
  }
  $term = $etm->getStorage('taxonomy_term')->load((int) $m[1]);
  if ($term && $term->bundle() !== 'services') {
    $n = $db->update('xmlsitemap')->fields(['status' => 0, 'status_override' => 1])
      ->condition('type', 'taxonomy_term')->condition('id', $term->id())
      ->execute();
    if ($n) {
      printf("  weekly-lawn-mowing dedupe: excluded %s term '%s' (tid=%s)\n", $term->bundle(), $term->label(), $term->id());
      $dedup++;
    }
  }
}
if (!$dedup) {
  print "  weekly-lawn-mowing: no non-services duplicate term to exclude (already deduped)\n";
}

/* 4. Flag for regeneration; run `drush xmlsitemap:regenerate` after this script. */
\Drupal::state()->set('xmlsitemap_regenerate_needed', TRUE);
print "\nData changes complete. Run: drush xmlsitemap:regenerate\n";
print "DONE.\n";
