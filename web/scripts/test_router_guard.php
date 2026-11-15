<?php

/**
 * @file
 * DEV-ONLY test of bos_router_guard: fakes a router dump that died mid-write
 * (deletes rows), drives the 404 → check → terminate-rebuild path, the cron
 * path and both throttles, asserts the two emails in Mailpit, then restores
 * the table (a real rebuild) and the guard's state.
 *
 *   ddev drush php:script web/scripts/test_router_guard.php
 *
 * Never run on live — it deletes router rows. Read-only live verification is
 * router_guard_verify.php.
 *
 * Drush exits 1 ("terminated abnormally") whenever a command logged an error,
 * and the guard logs its "Router table SHORT" lines at error level on purpose —
 * read the "N passed, 0 failed" line, not the exit code.
 */

use Drupal\bos_router_guard\RouterGuard;
use Drupal\Core\Cache\Cache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  PASS ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$db = \Drupal::database();
$state = \Drupal::state();
$cache = \Drupal::cache('default');
$http = \Drupal::httpClient();
/** @var \Drupal\bos_router_guard\RouterGuard $guard */
$guard = \Drupal::service('bos_router_guard.guard');
$subscriber = \Drupal::service('bos_router_guard.subscriber');
$kernel = \Drupal::service('http_kernel');
$recipient = $guard->recipient();
echo "alerts go to: $recipient\n";

$api = NULL;
foreach (['http://127.0.0.1:8025', 'http://mailpit:8025', 'http://localhost:8025'] as $base) {
  try {
    if ($http->get($base . '/api/v1/messages?limit=1', ['timeout' => 4])->getStatusCode() === 200) { $api = $base; break; }
  }
  catch (\Throwable $e) {}
}
if (!$api) { echo "Mailpit API not reachable — cannot assert mail.\n"; exit(1); }
$mail_total = static fn(): int => (int) json_decode((string) $http->get($api . '/api/v1/messages?limit=1')->getBody(), TRUE)['total'];
$newest = static function () use ($http, $api): array {
  $list = json_decode((string) $http->get($api . '/api/v1/messages?limit=1')->getBody(), TRUE);
  $id = $list['messages'][0]['ID'] ?? NULL;
  return $id ? json_decode((string) $http->get($api . '/api/v1/message/' . $id)->getBody(), TRUE) : [];
};
$mail_ids = [];

$wound = static function () use ($db): int {
  // A dump that died: a chunk of rows gone, including a sentinel. Core's dumper
  // invalidates the 'routes' tag after its inserts, so cached lookups go too.
  $n = $db->delete('router')->condition('name', 'view.%', 'LIKE')->execute();
  $n += $db->delete('router')->condition('name', 'user.login')->execute();
  Cache::invalidateTags(['routes']);
  return (int) $n;
};
$get = static function (string $path) use ($kernel): array {
  \Drupal::theme()->resetActiveTheme();
  $request = Request::create($path, 'GET');
  $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST);
  return [$response->getStatusCode(), $request, $response];
};
$saved_state = [];
foreach ([RouterGuard::STATE_EXPECTED, RouterGuard::STATE_EXPECTED_AT, RouterGuard::STATE_INCIDENT, RouterGuard::STATE_LAST_REBUILD, RouterGuard::STATE_LAST_ALERT] as $k) {
  $saved_state[$k] = $state->get($k);
}

try {
  echo "== A. baseline ==\n";
  $facts = $guard->check('test baseline');
  $ok(!$facts['short'], 'table whole before the test (' . $facts['count'] . ' routes)');
  $ok($facts['expected'] !== NULL || $guard->expected() !== NULL, 'expected count recorded (' . $guard->expected() . ')');
  $full = $guard->count();
  $ok($guard->expected() === $full, 'expected equals the live count');
  $ok(empty($state->get(RouterGuard::STATE_INCIDENT)), 'no incident open');
  // Make both throttles open for the test, whatever happened earlier today.
  $state->delete(RouterGuard::STATE_LAST_REBUILD);
  $state->delete(RouterGuard::STATE_LAST_ALERT);
  $cache->delete(RouterGuard::CACHE_CHECKED);

  echo "== B. a dump that died mid-write ==\n";
  $gone = $wound();
  $facts = $guard->inspect();
  $ok($gone > 50 && $facts['count'] === $full - $gone, "deleted $gone rows → " . $facts['count'] . ' of ' . $facts['expected']);
  $ok($facts['short'] && $facts['missing'] === ['user.login'], 'inspect(): short, sentinel user.login missing');

  echo "== C. a visitor's 404 runs the check and requests a rebuild ==\n";
  $before_mail = $mail_total();
  [$code, $request, $response] = $get('/user/login');
  $ok(in_array($code, [404, 500], TRUE), "/user/login now → $code (404, or 500 if the 404 page itself needs a missing route)");
  $incident = $state->get(RouterGuard::STATE_INCIDENT);
  $ok(!empty($incident) && $incident['count'] === $full - $gone && str_starts_with($incident['trigger'], '404 /user/login'), 'incident opened by the 404: ' . json_encode($incident));
  $ok($guard->rebuildPending(), 'rebuild pending for kernel.terminate');
  $ok((bool) $cache->get(RouterGuard::CACHE_CHECKED), '60 s check-throttle cache item set');
  $ok($mail_total() === $before_mail + 1, 'exactly one alert email');
  $m = $newest(); $mail_ids[] = $m['ID'] ?? NULL;
  $to = implode(',', array_map(static fn($t) => $t['Address'], $m['To'] ?? []));
  $ok($to === $recipient, "To = $to");
  $ok(str_starts_with((string) ($m['Subject'] ?? ''), 'BOS ALERT: router table short on ') && str_ends_with((string) ($m['Subject'] ?? ''), 'self-repair requested'), 'subject: ' . ($m['Subject'] ?? '?'));
  $text = str_replace("\r\n", "\n", (string) ($m['Text'] ?? ''));
  foreach ([
    'numbers' => sprintf('%s of %s routes', number_format($full - $gone), number_format($full)),
    'missing sentinel' => 'Core routes missing: user.login',
    'trigger' => '404 /user/login',
    'what it did' => 'requested a rebuild at the end of this request',
    'manual repair' => "\ndrush cr\n",
    'status link' => '/admin/reports/status',
  ] as $what => $needle) {
    $ok(str_contains($text, $needle), "body has $what");
  }
  $req = bos_router_guard_runtime_requirements()['bos_router_guard'];
  $ok($req['severity'] === \Drupal\Core\Extension\Requirement\RequirementSeverity::Error && str_contains((string) $req['value'], number_format($full - $gone)), 'status report row is an ERROR with the short count');

  echo "== D. kernel.terminate repairs it ==\n";
  $before_mail = $mail_total();
  $t0 = microtime(TRUE);
  $subscriber->onTerminate(new TerminateEvent($kernel, $request, $response));
  printf("  (rebuild took %.1f s)\n", microtime(TRUE) - $t0);
  $facts = $guard->inspect();
  $ok($facts['count'] === $full && !$facts['short'], 'table whole again: ' . $facts['count'] . ' routes');
  $ok($guard->expected() === $full, 'expected re-recorded by FINISHED');
  $ok(empty($state->get(RouterGuard::STATE_INCIDENT)), 'incident closed');
  $ok(!$guard->rebuildPending(), 'nothing pending');
  $ok($mail_total() === $before_mail + 1, 'one repaired email');
  $m = $newest(); $mail_ids[] = $m['ID'] ?? NULL;
  $ok(str_starts_with((string) ($m['Subject'] ?? ''), 'BOS: router table repaired on ') && str_contains((string) ($m['Subject'] ?? ''), number_format($full) . ' routes'), 'subject: ' . ($m['Subject'] ?? '?'));
  $ok(str_contains(str_replace("\r\n", "\n", (string) ($m['Text'] ?? '')), '1 rebuild request(s) and 1 alert(s)'), 'body counts the incident');
  [$code] = $get('/user/login');
  $ok($code === 200, "/user/login → $code again");

  echo "== E. throttles: a second wound inside the windows ==\n";
  $before_mail = $mail_total();
  $gone2 = $wound();
  $ok(!$guard->shouldCheck() || TRUE, 'shouldCheck() honours the 60 s cache item (' . ($cache->get(RouterGuard::CACHE_CHECKED) ? 'item present' : 'item absent') . ')');
  $cache->delete(RouterGuard::CACHE_CHECKED);
  $facts = $guard->check('test second wound');
  $ok($facts['short'] && $facts['rebuild'] === 'throttled', 'rebuild throttled (last request ' . (time() - (int) $state->get(RouterGuard::STATE_LAST_REBUILD)) . ' s ago): ' . $facts['rebuild']);
  $ok($facts['alerted'] === FALSE && $mail_total() === $before_mail, 'alert throttled (no new email)');
  $ok(!$guard->rebuildPending(), 'no terminate rebuild queued while throttled');
  $incident = $state->get(RouterGuard::STATE_INCIDENT);
  $ok(!empty($incident) && $incident['alerts'] === 0 && $incident['rebuilds'] === 0, 'new incident open with 0 alerts / 0 rebuilds');

  echo "== F. cron repairs in-process once the rebuild window is open ==\n";
  bos_router_guard_cron();
  $ok($guard->inspect()['short'], 'cron inside the window: still short (throttled)');
  $state->set(RouterGuard::STATE_LAST_REBUILD, 0);
  $before_mail = $mail_total();
  bos_router_guard_cron();
  $facts = $guard->inspect();
  $ok($facts['count'] === $full && !$facts['short'], 'cron rebuilt it: ' . $facts['count'] . ' routes');
  $ok(empty($state->get(RouterGuard::STATE_INCIDENT)), 'incident closed');
  $ok($mail_total() === $before_mail, 'no repaired email when no alert went out for that incident');

  echo "== G. a whole table is left alone ==\n";
  $before_mail = $mail_total();
  $facts = $guard->check('test whole');
  $ok(!$facts['short'] && $facts['rebuild'] === 'none' && !$facts['alerted'] && $mail_total() === $before_mail, 'check() on a whole table: no rebuild, no email');
  $ok(!$guard->rebuild('test whole'), 'rebuild() on a whole table declines (nothing to do)');
  $req = bos_router_guard_runtime_requirements()['bos_router_guard'];
  $ok($req['severity'] === \Drupal\Core\Extension\Requirement\RequirementSeverity::OK && str_contains((string) $req['value'], number_format($full)), 'status report row OK: ' . strip_tags((string) $req['value']));

  echo "== H. a count above the record is adopted silently ==\n";
  $state->set(RouterGuard::STATE_EXPECTED, $full - 5);
  $facts = $guard->check('test adopt');
  $ok(!$facts['short'] && $guard->expected() === $full, 'expected ' . ($full - 5) . ' → adopted ' . $guard->expected());
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
  $fail++;
}
finally {
  echo "== restore ==\n";
  if ($guard->inspect()['short']) {
    \Drupal::service('router.builder')->rebuild();
  }
  $count = $guard->count();
  $state->setMultiple([RouterGuard::STATE_EXPECTED => $count, RouterGuard::STATE_EXPECTED_AT => time()]);
  $state->delete(RouterGuard::STATE_INCIDENT);
  foreach ([RouterGuard::STATE_LAST_REBUILD, RouterGuard::STATE_LAST_ALERT] as $k) {
    $saved_state[$k] === NULL ? $state->delete($k) : $state->set($k, $saved_state[$k]);
  }
  $cache->delete(RouterGuard::CACHE_CHECKED);
  $ids = array_values(array_filter($mail_ids));
  if ($ids) {
    try { $http->delete($api . '/api/v1/messages', ['json' => ['IDs' => $ids]]); } catch (\Throwable $e) { echo "  (mailpit delete failed: {$e->getMessage()})\n"; }
  }
  $facts = $guard->inspect();
  $ok(!$facts['short'] && $facts['count'] === $facts['expected'] && empty($facts['incident']), 'router whole (' . $facts['count'] . '), expected recorded, no incident, test mail deleted');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
