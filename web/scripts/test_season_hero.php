<?php
/**
 * The season hero: public gets the photo and one H1; staff get their working page.
 *
 * Reversible — attaches an existing image to a season term, renders, then puts
 * the field back exactly as it was. Creates no files.
 */
$etm = \Drupal::entityTypeManager();
$sw = \Drupal::service('account_switcher');
$renderer = \Drupal::service('renderer');
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? " [$x]" : '');
};

$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'season', 'name' => 'Winter']);
$t = reset($terms);
$ok('found the Winter term', (bool) $t);
$ok('field_banner_images exists on season', $t->hasField('field_banner_images'));
$ok('field_icon is still a separate field', $t->hasField('field_icon'));

// An existing image to borrow. Nothing is created or deleted.
$fids = $etm->getStorage('file')->getQuery()->accessCheck(FALSE)
  ->condition('filemime', 'image/%', 'LIKE')->condition('status', 1)->range(0, 1)->execute();
$file = $fids ? $etm->getStorage('file')->load(reset($fids)) : NULL;
$ok('borrowed an existing image', (bool) $file, $file ? $file->getFilename() : 'none found');
if (!$file) { printf("\n%d passed, %d failed\n", $pass, $fail); return; }

$restore = $t->get('field_banner_images')->getValue();
$t->set('field_banner_images', [['target_id' => $file->id(), 'alt' => 'Winter on the Western Slope']]);
$t->save();

/**
 * Render the hero hooks for one account, with the season term on the route.
 *
 * ⚠ The route has to be stubbed by PUSHING A REQUEST onto the request stack,
 * not by setting an attribute on the current one. CurrentRouteMatch caches its
 * RouteMatch per Request object, so mutating the request drush is already
 * holding changes nothing and getParameter() keeps returning NULL -- which
 * made an earlier version of this test report six failures against code that
 * was working (proven over real anonymous HTTP). A fresh Request object has no
 * cache entry, so createFromRequest() reads these attributes.
 */
$check = function ($account) use ($sw, $t) {
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
    $html = ['attributes' => ['class' => []]];
    bos_seasons_preprocess_html($html);
    $blocked = NULL;
    $blocks = \Drupal::entityTypeManager()->getStorage('block')
      ->loadByProperties(['plugin' => 'page_title_block']);
    if ($blocks) {
      $blocked = bos_seasons_block_access(reset($blocks), 'view', $account)->isForbidden();
    }
  }
  finally {
    \Drupal::service('request_stack')->pop();
    $sw->switchBack();
  }
  return [$hero, in_array('has-bo-hero', $html['attributes']['class'], TRUE), $blocked];
};

$anon = new \Drupal\Core\Session\AnonymousUserSession();
[$heroAnon, $classAnon, $blockedAnon] = $check($anon);
$ok('public gets a hero', (bool) $heroAnon, $heroAnon ? count($heroAnon['#images']) . ' image(s)' : 'none');
$ok('  hero title is the season name', ($heroAnon['#title'] ?? '') === 'Winter');
$ok('  hero carries NO teaser as subtitle (views-only rule)', ($heroAnon['#subtitle'] ?? '') === '');
$ok('  image built through max_2600x2600', !empty($heroAnon['#images'][0]['src']) && str_contains($heroAnon['#images'][0]['src'], 'max_2600x2600'),
  substr($heroAnon['#images'][0]['src'] ?? '-', -60));
$ok('  alt text carried', ($heroAnon['#images'][0]['alt'] ?? '') === 'Winter on the Western Slope');
$ok('  body gets the has-bo-hero class', $classAnon);
$ok('  page title block hidden (the hero carries the H1)', $blockedAnon === TRUE);

[$heroOffice, $classOffice, $blockedOffice] = $check($etm->getStorage('user')->load(1));
$ok('office gets NO hero (working page, not marketing)', !$heroOffice);
$ok('  and no has-bo-hero class', !$classOffice);
$ok('  and office keeps its page title block', $blockedOffice === FALSE);

// A crew member gets the working page too -- the gate lists crew roles
// separately from office, so prove the crew half rather than inferring it from
// the office half passing.
$crew = NULL;
foreach ($etm->getStorage('user')->loadByProperties(['status' => 1]) as $u) {
  if (in_array('teammates', $u->getRoles(), TRUE)
      && !array_intersect(BOS_SEASONS_OFFICE_ROLES, $u->getRoles())) {
    $crew = $u;
    break;
  }
}
if ($crew) {
  [$heroCrew, $classCrew, $blockedCrew] = $check($crew);
  $ok('crew gets NO hero (training page, not marketing)', !$heroCrew, $crew->getAccountName());
  $ok('  and crew keeps its page title block', $blockedCrew === FALSE);
}
else {
  $ok('crew account available to test with', FALSE, 'none found - crew tier UNPROVEN');
}

// With no banner at all, nothing changes.
$t->set('field_banner_images', []);
$t->save();
[$heroNone, $classNone, $blockedNone] = $check($anon);
$ok('no banner -> no hero, ordinary page', !$heroNone && !$classNone);
$ok('no banner -> title block NOT hidden', $blockedNone === FALSE);

$t->set('field_banner_images', $restore);
$t->save();
$after = $etm->getStorage('taxonomy_term')->loadUnchanged($t->id());
$ok('term restored exactly', $after->get('field_banner_images')->getValue() === $restore,
  $restore ? 'had images' : 'was empty, is empty');
printf("\n%d passed, %d failed\n", $pass, $fail);
