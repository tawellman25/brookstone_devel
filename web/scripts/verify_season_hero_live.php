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

$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season']);
$ok('season terms found', count($terms) > 0, count($terms) . ' terms');
$t = reset($terms);
$ok('field_banner_images on season', $t->hasField('field_banner_images'));
$ok('field_icon still separate and present', $t->hasField('field_icon'));
$ok('no season carries a banner yet (nothing published by this deploy)',
  !array_filter($terms, fn($x) => !$x->get('field_banner_images')->isEmpty()));

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

$tx = \Drupal::database()->startTransaction();
try {
  $t->set('field_banner_images', [['target_id' => reset($fids), 'alt' => 'live verification only']]);
  $t->save();

  [$hero, $blocked, $ctx] = $check(new \Drupal\Core\Session\AnonymousUserSession());
  $ok('public gets a hero', (bool) $hero, $hero ? count($hero['#images']) . ' image(s)' : 'none');
  $ok('  derivative built through max_2600x2600',
    !empty($hero['#images'][0]['src']) && str_contains($hero['#images'][0]['src'], 'max_2600x2600'));
  $ok('  hero title is the season name', ($hero['#title'] ?? '') === $t->label(), $t->label());
  $ok('  no teaser leaked in as a subtitle', ($hero['#subtitle'] ?? '') === '');
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
$n = \Drupal::database()->select('taxonomy_term__field_banner_images', 'b')
  ->condition('bundle', 'season')->countQuery()->execute()->fetchField();
$ok('ROLLED BACK: zero banner rows for any season', (int) $n === 0, "rows=$n");
printf("\n%d passed, %d failed\n", $pass, $fail);
