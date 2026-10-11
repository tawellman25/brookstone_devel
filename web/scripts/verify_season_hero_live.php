<?php
/**
 * Prove the season hero works on LIVE without publishing a stray photo.
 *
 * The hero can only be observed with a banner attached, and attaching a random
 * image to a live public page -- even for a minute -- can be picked up by the
 * edge cache (max-age 300) and served after it is removed. So the banner is set
 * inside a TRANSACTION THAT IS ROLLED BACK: the render happens in-process
 * against the uncommitted value, nothing reaches the database, no cache tag is
 * invalidated, and no visitor can ever see it.
 *
 * Read-only in effect. Creates no files and no records.
 */
$etm = \Drupal::entityTypeManager();
$sw = \Drupal::service('account_switcher');
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? " [$x]" : '');
};

// --- The things that differ between environments -------------------------
$ok('image style max_2600x2600 exists',
  (bool) $etm->getStorage('image_style')->load('max_2600x2600'));
$ok('theme hook bo_hero_banner is registered',
  isset(\Drupal::service('theme.registry')->get()['bo_hero_banner']));
$ok('library brookstone_olivero/bo_hero is defined',
  (bool) \Drupal::service('library.discovery')->getLibraryByName('brookstone_olivero', 'bo_hero'));
$ok('brookstone_olivero has a hero region',
  array_key_exists('hero', \Drupal::service('theme_handler')->getTheme('brookstone_olivero')->info['regions'] ?? []));

$inst0 = $etm->getStorage('field_config')->load('taxonomy_term.season.field_banner_images');
$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']);
$ok('season terms found', count($terms) > 0, count($terms) . ' terms');
// Use a term that has NO banner, so attaching one inside the rolled-back
// transaction cannot be confused with a photo the office genuinely uploaded.
$t = NULL;
foreach ($terms as $x) { if ($x->get('field_banner_images')->isEmpty()) { $t = $x; break; } }
$t = $t ?: reset($terms);
$ok('field_banner_images on season', $t->hasField('field_banner_images'));
$ok('field_icon still separate and present', $t->hasField('field_icon'));
// Informational, NOT an assertion. The first version asserted that no season
// carried a banner -- true at deploy time and wrong an hour later, once Todd
// started uploading. A verifier that fails because the feature is being used is
// worse than no verifier.
foreach ($terms as $x) {
  printf("  ....  %-12s %d photo(s)%s\n", $x->label(), $x->get('field_banner_images')->count(),
    $x->isPublished() ? '' : ' (unpublished)');
}
$alts = []; $caps = [];
foreach ($terms as $x) {
  foreach ($x->get('field_banner_images') as $i) {
    $alts[] = trim((string) $i->alt);
    $caps[] = trim((string) $i->title);
  }
}
$ok('caption sub-field is enabled on the season instance',
  (bool) $inst0->getSetting('title_field'));
$ok('every attached hero photo is described (alt, for screen readers)',
  !array_filter($alts, fn($a) => $a === ''));
// Not required -- a photo may legitimately have no caption -- so this reports
// rather than fails, and only matters because the migration should have filled
// every photo that predates the caption field.
printf("  ....  %d of %d photos carry a caption\n", count(array_filter($caps)), count($caps));

$fids = $etm->getStorage('file')->getQuery()->accessCheck(FALSE)
  ->condition('filemime', 'image/%', 'LIKE')->condition('status', 1)->range(0, 1)->execute();
if (!$fids) { printf("\n%d passed, %d failed\n", $pass, $fail); return; }

$check = function ($account) use ($sw, $t, $etm) {
  $sw->switchTo($account);
  $route = new \Symfony\Component\Routing\Route('/taxonomy/term/{taxonomy_term}');
  $req = \Symfony\Component\HttpFoundation\Request::create('/taxonomy/term/' . $t->id());
  $req->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_NAME, 'entity.taxonomy_term.canonical');
  $req->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_OBJECT, $route);
  $req->attributes->set('taxonomy_term', $t);
  \Drupal::service('request_stack')->push($req);
  try {
    $vars = ['page' => [], '#attached' => [], '#cache' => []];
    bos_seasons_preprocess_page($vars);
    $hero = $vars['page']['hero']['bo_hero_banner'] ?? NULL;
    $ctx = $vars['#cache']['contexts'] ?? [];
    $blocks = $etm->getStorage('block')->loadByProperties(['plugin' => 'page_title_block']);
    $blocked = $blocks ? bos_seasons_block_access(reset($blocks), 'view', $account)->isForbidden() : NULL;
  }
  finally {
    \Drupal::service('request_stack')->pop();
    $sw->switchBack();
  }
  return [$hero, $blocked, $ctx];
};

$rowsBefore = (int) \Drupal::database()->select('taxonomy_term__field_banner_images', 'b')
  ->condition('bundle', 'season')->countQuery()->execute()->fetchField();

$tx = \Drupal::database()->startTransaction();
try {
  $t->set('field_banner_images', [['target_id' => reset($fids), 'alt' => 'live verification only']]);
  $t->save();

  [$hero, $blocked, $ctx] = $check(new \Drupal\Core\Session\AnonymousUserSession());
  $ok('public gets a hero', (bool) $hero, $hero ? count($hero['#images']) . ' image(s)' : 'none');
  $ok('  derivative built through max_2600x2600',
    !empty($hero['#images'][0]['src']) && str_contains($hero['#images'][0]['src'], 'max_2600x2600'));
  $ok('  hero title is the season name', ($hero['#title'] ?? '') === $t->label(), $t->label());
  $ok('  subtitle rotates per slide (alt_subtitle mode)', ($hero['#alt_subtitle'] ?? FALSE) === TRUE);
  $ok('  and the views-only teaser is NOT used', ($hero['#subtitle'] ?? '') === '');
  $ok('  caption reads the TITLE sub-field, with NO fallback to alt',
    ($hero['#images'][0]['caption'] ?? 'x') === '' && ($hero['#images'][0]['alt'] ?? '') !== '');
  $ok('  user.roles cache context set (or the cache leaks one audience to another)',
    in_array('user.roles', $ctx, TRUE));
  $ok('  page title block suppressed, hero carries the H1', $blocked === TRUE);

  $office = NULL; $crew = NULL;
  foreach ($etm->getStorage('user')->loadByProperties(['status' => 1]) as $u) {
    $r = $u->getRoles();
    if (!$office && array_intersect(BOS_SEASONS_OFFICE_ROLES, $r)) { $office = $u; }
    if (!$crew && in_array('teammates', $r, TRUE) && !array_intersect(BOS_SEASONS_OFFICE_ROLES, $r)) { $crew = $u; }
  }
  if ($office) {
    [$h, $b] = $check($office);
    $ok('office gets NO hero (working page)', !$h, $office->getAccountName());
    $ok('  and keeps its page title block', $b === FALSE);
  }
  if ($crew) {
    [$h, $b] = $check($crew);
    $ok('crew gets NO hero (training page)', !$h, $crew->getAccountName());
    $ok('  and keeps its page title block', $b === FALSE);
  }
}
finally {
  $tx->rollBack();
  $etm->getStorage('taxonomy_term')->resetCache([$t->id()]);
}

$after = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
$ok('ROLLED BACK: term has no banner in the database', $after->get('field_banner_images')->isEmpty());
$n = (int) \Drupal::database()->select('taxonomy_term__field_banner_images', 'b')
  ->condition('bundle', 'season')->countQuery()->execute()->fetchField();
$ok('ROLLED BACK: banner row count unchanged', $n === $rowsBefore, "before=$rowsBefore after=$n");
printf("\n%d passed, %d failed\n", $pass, $fail);
