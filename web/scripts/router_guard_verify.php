<?php

/**
 * @file
 * READ-ONLY verification of bos_router_guard + the JSON:API removal (safe on live).
 *
 *   drush php:script web/scripts/router_guard_verify.php
 *
 * Reads config, state and the router table; renders three anonymous GETs through
 * the kernel (/jsonapi, /user/login, a bogus path). The bogus GET exercises the
 * guard's 404 path, which on a whole table only sets a 60-second cache item.
 * The menu-link section re-derives the stored-unrouted links in memory
 * (probeMenuLinks()) and writes nothing. Nothing is created, saved or rebuilt.
 * Write-and-rollback testing is test_router_guard.php (dev only).
 */

use Drupal\bos_router_guard\RouterGuard;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$mh = \Drupal::moduleHandler();
$db = \Drupal::database();

echo "== modules ==\n";
$ok($mh->moduleExists('bos_router_guard'), 'bos_router_guard enabled');
$ok(!$mh->moduleExists('jsonapi'), 'jsonapi NOT enabled');
$ok(!$mh->moduleExists('bos_jsonapi_guard'), 'bos_jsonapi_guard NOT enabled (depended on jsonapi; re-enable both with the mobile app)');
$jsonapi_rows = (int) $db->query("SELECT COUNT(*) FROM {router} WHERE name LIKE 'jsonapi.%'")->fetchField();
$ok($jsonapi_rows === 0, "no jsonapi.* routes left in the router ($jsonapi_rows)");

echo "== wiring ==\n";
$guard = \Drupal::service('bos_router_guard.guard');
$ok($guard instanceof RouterGuard, 'bos_router_guard.guard service');
$dispatcher = \Drupal::service('event_dispatcher');
foreach ([KernelEvents::EXCEPTION => 'onException', KernelEvents::TERMINATE => 'onTerminate', RoutingEvents::FINISHED => 'onRouterFinished'] as $event => $method) {
  $found = FALSE;
  foreach ($dispatcher->getListeners($event) as $listener) {
    if (is_array($listener) && $listener[0] instanceof \Drupal\bos_router_guard\EventSubscriber\RouterGuardSubscriber && $listener[1] === $method) {
      $found = TRUE;
    }
  }
  $ok($found, "subscriber listens to $event → $method");
}
$ok($mh->hasImplementations('cron', 'bos_router_guard'), 'hook_cron');
$ok($mh->hasImplementations('runtime_requirements', 'bos_router_guard'), 'hook_runtime_requirements');
$ok($mh->hasImplementations('mail', 'bos_router_guard'), 'hook_mail');
$ok(method_exists($guard, 'probeMenuLinks') && method_exists($guard, 'repairMenuLinks'), 'guard has the menu-link probe + repair (2026-11-16)');
$settings = \Drupal::config('bos_router_guard.settings');
$to = $guard->recipient();
$ok(filter_var($to, FILTER_VALIDATE_EMAIL) !== FALSE, "alert recipient: $to" . ($settings->get('alert_email') ? ' (settings)' : ' (site mail fallback)'));
echo '  intervals: rebuild every ' . $settings->get('rebuild_interval') . ' s, alert every ' . $settings->get('alert_interval') . " s\n";

echo "== router table ==\n";
$facts = $guard->inspect();
echo '  count ' . $facts['count'] . ' · expected ' . ($facts['expected'] ?? 'none') . ' · recorded ' . ($facts['expected_at'] ? date('Y-m-d H:i:s T', (int) $facts['expected_at']) : '—') . "\n";
$ok(!$facts['short'], 'table is whole (count ≥ expected, sentinels present)');
$ok($facts['expected'] !== NULL, 'expected count recorded (a complete rebuild has happened since install)');
$ok($facts['expected'] === NULL || $facts['count'] === $facts['expected'], 'count equals the recorded expected');
$ok($facts['missing'] === [], 'sentinel routes present: ' . implode(', ', RouterGuard::SENTINELS));
$ok(empty($facts['incident']), 'no open incident' . (!empty($facts['incident']) ? ' — ' . json_encode($facts['incident']) : ''));
$by_prefix = $db->query("SELECT SUBSTRING_INDEX(name, '.', 1) p, COUNT(*) c FROM {router} GROUP BY p ORDER BY c DESC LIMIT 6")->fetchAllKeyed();
echo '  biggest prefixes: ' . implode(' · ', array_map(static fn($p, $c) => "$p $c", array_keys($by_prefix), $by_prefix)) . "\n";

echo "== status report row ==\n";
$req = bos_router_guard_runtime_requirements()['bos_router_guard'];
echo '  ' . $req['title'] . ': ' . strip_tags((string) $req['value']) . ' [' . $req['severity']->name . "]\n";
$ok($req['severity'] === \Drupal\Core\Extension\Requirement\RequirementSeverity::OK, 'severity OK');

echo "== anonymous GETs through the kernel (nothing written) ==\n";
$kernel = \Drupal::service('http_kernel');
$get = static function (string $path) use ($kernel): array {
  \Drupal::theme()->resetActiveTheme();
  $response = $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::SUB_REQUEST);
  return [$response->getStatusCode(), strlen((string) $response->getContent())];
};
// The guard checks once per process, so the FIRST 404 below is the one that
// runs it; the cache item it leaves is the proof.
\Drupal::cache('default')->delete(RouterGuard::CACHE_CHECKED);
[$code, $len] = $get('/jsonapi');
$ok($code === 404, "/jsonapi → $code ($len B) — was 401 while bos_jsonapi_guard was on");
$ok((bool) \Drupal::cache('default')->get(RouterGuard::CACHE_CHECKED), 'that 404 ran the guard check (60 s cache item set)');
$ok(empty(\Drupal::state()->get(RouterGuard::STATE_INCIDENT)), 'and opened no incident on a whole table');
[$code] = $get('/user/login');
$ok($code === 200, "/user/login → $code");
[$code] = $get('/bos-router-guard-verify-' . mt_rand());
$ok($code === 404, "bogus path → $code (404, not 500)");

echo "== menu links stored without a route (2026-11-16) ==\n";
$menu = $guard->probeMenuLinks();
$label = static fn(array $r): string => "[{$r['menu']}] {$r['title']} {$r['uri']}";
$ok($menu['repairable'] === [], 'no menu_link_content link stored unrouted whose path resolves today' . ($menu['repairable'] ? ' — STALE: ' . implode(' · ', array_map(static fn(array $r): string => $label($r) . ' → ' . $r['route_name'], $menu['repairable'])) . ' (the next cron run or router rebuild re-routes them)' : ''));
$ok($menu['failed'] === [], 'every stored-unrouted link could be re-derived' . ($menu['failed'] ? ' — ' . implode(' · ', array_map(static fn(array $r): string => $label($r) . ': ' . $r['error'], $menu['failed'])) : ''));
$ok($menu['pinned'] === [], 'no link pinned to one variant of a role-gated Page Manager page' . ($menu['pinned'] ? ' — PINNED (hidden from the other roles; a drush menu rebuild restores the plain URL): ' . implode(' · ', array_map(static fn(array $r): string => $label($r) . ' → ' . $r['route_name'], $menu['pinned'])) : ''));
echo '  role-gated Page Manager pages, correctly stored as plain URLs: ' . (count($menu['role_gated']) ?: 'none') . "\n";
foreach ($menu['role_gated'] as $r) {
  echo '    ' . $label($r) . sprintf(' — page %s, %d variants', $r['page'], $r['variants']) . "\n";
}
echo '  links with no route (a dead link or a redirect): ' . (count($menu['unrouted']) ?: 'none') . "\n";
foreach ($menu['unrouted'] as $r) {
  echo '    ' . $label($r) . "\n";
}
$last = \Drupal::state()->get(RouterGuard::STATE_MENU_LINKS);
echo '  last pass: ' . (!empty($last['at']) ? date('Y-m-d H:i:s T', (int) $last['at']) . ' (' . ($last['trigger'] ?? '?') . ') — ' . (int) ($last['checked'] ?? 0) . ' checked, ' . count($last['repaired'] ?? []) . ' re-routed' : 'none yet (runs at the first router rebuild or cron after the deploy)') . "\n";

echo "== recent log ==\n";
if ($mh->moduleExists('dblog')) {
  $rows = $db->query("SELECT timestamp, severity, message, variables FROM {watchdog} WHERE type = 'bos_router_guard' ORDER BY wid DESC LIMIT 5")->fetchAll();
  foreach ($rows as $r) {
    $vars = @unserialize($r->variables) ?: [];
    echo '  ' . date('m/d H:i', (int) $r->timestamp) . ' ' . strtr((string) $r->message, is_array($vars) ? $vars : []) . "\n";
  }
  if (!$rows) {
    echo "  (none)\n";
  }
}
echo "\n$pass ok, $fail failed\n";
exit($fail ? 1 : 0);
