<?php

/**
 * @file
 * The teammate dashboard on a crew member's own profile.
 *
 * The thing most worth guarding is the ACCESS rule, not the layout. This block
 * shows a person their own hours and backlog; rendering it on someone else's
 * profile turns a self-service tool into a readout about a worker for whoever
 * opens their page. That is a different product with different consequences,
 * and it would be an easy accident.
 *
 * Read-only: renders the block under switched accounts, writes nothing.
 *
 * Run: drush php:script web/scripts/test_teammate_dashboard.php
 */

use Drupal\Core\Session\AnonymousUserSession;

$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$switcher = \Drupal::service('account_switcher');
$blockManager = \Drupal::service('plugin.manager.block');
$routeMatch = \Drupal::service('current_route_match');

$ok('the block plugin is discovered', (bool) $blockManager->getDefinition('teammate_dashboard', FALSE));
$ok('the theme hook is registered', isset(\Drupal::service('theme.registry')->get()['teammate_dashboard']));

$block = $etm->getStorage('block')->load('teammate_dashboard_profile');
$ok('the block is placed', (bool) $block);
if ($block) {
  $ok('  in the default theme', $block->getTheme() === \Drupal::config('system.theme')->get('default'), $block->getTheme());
  $ok('  enabled', $block->status());
  $vis = $block->getVisibility()['request_path']['pages'] ?? '';
  $ok('  limited to the profile path', str_contains($vis, '/user/*'), $vis);
}

// A crew member with work assigned.
$uid = (int) $db->query('SELECT a.field_assigned_to_target_id uid, COUNT(*) n
  FROM {scheduling__field_assigned_to} a WHERE a.deleted = 0
  GROUP BY uid ORDER BY n DESC LIMIT 1')->fetchField();
$crew = $uid ? $etm->getStorage('user')->load($uid) : NULL;
$ok('found a crew member with assigned work', (bool) $crew, $crew ? $crew->getAccountName() : 'none');
if (!$crew) { printf("\n%d passed, %d failed\n", $pass, $fail); return; }

/**
 * Access as $viewer, looking at $profileOwner's page.
 *
 * blockAccess() reads the route's {user}, so the route has to carry one — the
 * whole self-view rule hangs on that parameter.
 */
$accessOn = function ($viewer, $profileOwner) use ($blockManager, $switcher): bool {
  $switcher->switchTo($viewer);
  $stack = \Drupal::service('request_stack');
  $route = new \Symfony\Component\Routing\Route('/user/{user}');
  $req = \Symfony\Component\HttpFoundation\Request::create('/user/' . $profileOwner->id());
  $req->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_NAME, 'entity.user.canonical');
  $req->attributes->set(\Drupal\Core\Routing\RouteObjectInterface::ROUTE_OBJECT, $route);
  $req->attributes->set('user', $profileOwner);
  $stack->push($req);
  try {
    $plugin = $blockManager->createInstance('teammate_dashboard', []);
    return $plugin->access($viewer);
  }
  finally {
    $stack->pop();
    $switcher->switchBack();
  }
};

// --- THE RULE THAT MATTERS --------------------------------------------------
$ok('a crew member CAN see it on their OWN profile', $accessOn($crew, $crew) === TRUE);

$other = NULL;
foreach ($etm->getStorage('user')->loadByProperties(['status' => 1]) as $u) {
  if ((int) $u->id() !== (int) $crew->id() && (int) $u->id() > 1) {
    $other = $u;
    break;
  }
}
if ($other) {
  $ok('another user CANNOT see it on someone else\'s profile',
    $accessOn($other, $crew) === FALSE, $other->getAccountName() . ' viewing ' . $crew->getAccountName());
  $ok('  and the crew member cannot see it on THEIR page either',
    $accessOn($crew, $other) === FALSE);
}
$ok('anonymous cannot see it', $accessOn(new AnonymousUserSession(), $crew) === FALSE);

// --- content ----------------------------------------------------------------
$switcher->switchTo($crew);
try {
  $html = (string) \Drupal::service('renderer')->renderRoot(
    $blockManager->createInstance('teammate_dashboard', [])->build()
  );
}
finally {
  $switcher->switchBack();
}
$ok('it renders', $html !== '', strlen($html) . ' bytes');
foreach ([
  'Clocked in now' => 'the clocked-in panel',
  'Hours on work orders today' => 'the hours panel',
  'oldest first' => 'the FIFO list',
  '/teammates/training' => 'the Training link',
  '/teammates/employment' => 'the Employment link',
] as $needle => $what) {
  $ok('  shows ' . $what, str_contains($html, $needle));
}
$ok('  the hours figure is NOT labelled billable',
  !str_contains(strtolower($html), 'billable'),
  'punches carry no billable flag; calling it that overstates it');

// FIFO order: ages must descend (oldest work order first).
if (preg_match_all('/bos-td__age[^>]*>(\d+)d/', $html, $m)) {
  $ages = array_map('intval', $m[1]);
  $sorted = $ages;
  rsort($sorted);
  $ok('  the list really is oldest-first', $ages === $sorted,
    implode(',', array_slice($ages, 0, 6)));
  $ok('  the first row is flagged as first up', str_contains($html, 'first up'));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
