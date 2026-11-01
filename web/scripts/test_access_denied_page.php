<?php

/**
 * @file
 * The 403 page answers the right question for whoever hit it.
 *
 * Core's default is a dead end: "You are not authorized to access this page."
 * This replaces it with three outcomes, and the test is mostly about proving
 * the BRANCHING is right — showing a signed-in person a login button, or
 * telling a visitor they lack permission, would each be worse than the default.
 *
 *   anonymous + internal path  -> "sign in and continue", carrying the path
 *   authenticated              -> "your account lacks access" and NO login link
 *   anonymous + anything else  -> the business, with real service photos
 *
 * ⚠ The load-bearing assertion is that the sign-in link carries the ORIGINAL
 * path. Core serves a custom 403 as a SUBREQUEST, so a controller reading
 * \Drupal::request() sees /access-denied and would send everyone back to the
 * error page after login — the page would look right and be useless.
 *
 * Read-only. Run: drush php:script web/scripts/test_access_denied_page.php
 */

use Drupal\bos_access_denied\Controller\AccessDeniedController;
use Symfony\Component\HttpFoundation\Request;

$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

$ok('the 403 page is configured to this module',
  \Drupal::config('system.site')->get('page.403') === '/access-denied',
  \Drupal::config('system.site')->get('page.403') ?: '(core default)');

$renderer = \Drupal::service('renderer');
$switcher = \Drupal::service('account_switcher');
$stack = \Drupal::service('request_stack');

/**
 * Fetch a real 403 over HTTP, exactly as a browser would get it.
 *
 * ⚠ The ANONYMOUS branches cannot be tested by calling the controller in
 * process. The denied path is read from the MAIN request, and
 * RequestStack::getMainRequest() returns the BOTTOM of the stack — so pushing
 * a request makes it CURRENT while main stays whatever drush started with.
 * An earlier version of this test did exactly that and reported three failures
 * against code that works perfectly over HTTP. Same family as the
 * CurrentRouteMatch trap already documented in gotchas.
 */
$base = rtrim(getenv('BOS_BASE') ?: 'https://brookstone.ddev.site', '/');
$fetch = function (string $path) use ($base): array {
  $ch = curl_init($base . $path);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_SSL_VERIFYPEER => FALSE,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_TIMEOUT => 45,
  ]);
  $body = (string) curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $body];
};

/**
 * Render the controller directly — only valid where the denied PATH does not
 * matter, i.e. the authenticated branch.
 */
$renderAs = function ($account) use ($renderer, $switcher): string {
  $switcher->switchTo($account);
  try {
    $controller = AccessDeniedController::create(\Drupal::getContainer());
    return (string) $renderer->renderRoot($controller->page());
  }
  finally {
    $switcher->switchBack();
  }
};

$anon = new \Drupal\Core\Session\AnonymousUserSession();

// --- 1. anonymous on a working page: the expired-session case ---------------
$deniedInternal = '/admin/office/work-orders/billing';
[$code, $html] = $fetch($deniedInternal);
$ok('the denied page still returns HTTP 403, not 200', $code === 403, "HTTP $code");
$ok('anonymous + internal path gets the SIGN-IN page', str_contains($html, 'bos-403--signin'));
$ok('  it does not accuse them of lacking permission',
  !str_contains($html, "doesn't have") && !str_contains($html, 'not authorized'));
$ok('  THE KEY ONE: the login link carries the original path',
  str_contains($html, 'destination=' . rawurlencode($deniedInternal))
  || str_contains($html, 'destination=' . $deniedInternal),
  'otherwise login dumps them back on the error page');
$ok('  a password reset is offered too', str_contains($html, '/user/password'));
$ok('  and the path they wanted is shown', str_contains($html, $deniedInternal));

// --- 2. signed in, but without the access -----------------------------------
$crew = NULL;
foreach (\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['status' => 1]) as $u) {
  if ((int) $u->id() > 1) {
    $crew = $u;
    break;
  }
}
$ok('found a non-admin account to test with', (bool) $crew, $crew ? $crew->getAccountName() : 'none');
if ($crew) {
  $html2 = $renderAs($crew);
  $ok('authenticated gets the BLOCKED page', str_contains($html2, 'bos-403--blocked'));
  $ok('  it names who they are signed in as',
    str_contains($html2, (string) $crew->getDisplayName()));
  $ok('  it does NOT offer a sign-in link — signing in again cannot help',
    !str_contains($html2, 'Sign in and continue'));
  $ok('  it says the account needs access added', str_contains($html2, "doesn't have"));
}

// --- 3. a visitor: show the business ----------------------------------------
// /user/1 is a 403 for anonymous and is not an internal-looking path.
[$code3, $html3] = $fetch('/user/1');
$ok('a visitor path also returns 403', $code3 === 403, "HTTP $code3");
$ok('anonymous elsewhere gets the PUBLIC page', str_contains($html3, 'bos-403--public'));
$ok('  it offers no error language', !str_contains($html3, 'not authorized'));
$cards = substr_count($html3, 'bos-403__card"');
$ok('  it shows real service photos', $cards > 0, "$cards card(s)");
$ok('  each card links to its service', substr_count($html3, 'bos-403__card-title') === $cards);
$ok('  and a way in for staff who landed here', str_contains($html3, '/user/login'));

// --- the status code must still be 403 --------------------------------------
// A pretty page that returns 200 tells crawlers the URL is public.
$ok('the route itself is reachable by anyone (or 403 would recurse)',
  \Drupal::service('router.route_provider')->getRouteByName('bos_access_denied.page')
    ->getRequirement('_access') === 'TRUE');

printf("\n%d passed, %d failed\n", $pass, $fail);
