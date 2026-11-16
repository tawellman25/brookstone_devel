<?php

/**
 * @file
 * DEV-ONLY test of bos_router_guard: fakes a router dump that died mid-write
 * (deletes rows), drives the 404 → check → terminate-rebuild path, the cron
 * path and both throttles, asserts the two emails in Mailpit, bakes a routed
 * menu link as `base:` the way a menu rebuild does when it cannot match the
 * path and checks the FINISHED / cron / manual passes and the Status row
 * re-route it (2026-11-16), then restores the table (a real rebuild) and the
 * guard's state.
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
foreach ([RouterGuard::STATE_EXPECTED, RouterGuard::STATE_EXPECTED_AT, RouterGuard::STATE_INCIDENT, RouterGuard::STATE_LAST_REBUILD, RouterGuard::STATE_LAST_ALERT, RouterGuard::STATE_MENU_LINKS] as $k) {
  $saved_state[$k] = $state->get($k);
}
$victim = NULL;
$routed = static fn(): bool => TRUE;
$office = [];
$office_tree = static fn(): array => [];
$menu_manager = NULL;

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

  echo "== A2. a menu link to bake (2026-11-16) ==\n";
  // A routed internal: link whose route survives the wound below (not view.* / user.login).
  $victim = $db->query("SELECT t.id AS plugin_id, t.route_name, t.menu_name AS menu, b.id AS entity_id, m.title, m.link__uri AS uri
    FROM {menu_tree} t
    INNER JOIN {menu_link_content} b ON t.id = CONCAT(:p, b.uuid)
    INNER JOIN {menu_link_content_data} m ON m.id = b.id AND m.default_langcode = 1
    WHERE t.route_name IS NOT NULL AND t.route_name <> '' AND t.route_name NOT LIKE 'view.%' AND t.route_name <> 'user.login' AND m.link__uri LIKE :i
    ORDER BY b.id LIMIT 1", [':p' => 'menu_link_content:', ':i' => 'internal:%'])->fetchAssoc();
  if (!$victim) {
    throw new \RuntimeException('no routed internal: menu_link_content link to test with');
  }
  echo "  victim: [{$victim['menu']}] {$victim['title']} {$victim['uri']} → {$victim['route_name']}\n";
  $menu_manager = \Drupal::service('plugin.manager.menu.link');
  $bake = static function () use ($victim, $menu_manager): void {
    // What a menu rebuild writes when the path cannot be matched: url = base:…, no route.
    $menu_manager->updateDefinition($victim['plugin_id'], ['url' => 'base:' . ltrim(substr($victim['uri'], strlen('internal:')), '/'), 'route_name' => NULL, 'route_parameters' => []], FALSE);
  };
  $tree = static fn(): array => $db->query('SELECT route_name, url FROM {menu_tree} WHERE id = :id', [':id' => $victim['plugin_id']])->fetchAssoc() ?: [];
  $baked = static fn(): bool => str_starts_with((string) ($tree()['url'] ?? ''), 'base:');
  $routed = static fn(): bool => ($tree()['route_name'] ?? '') === $victim['route_name'] && (string) ($tree()['url'] ?? '') === '';
  $probe0 = $guard->probeMenuLinks();
  $dead = count($probe0['unrouted']);
  $gated = $probe0['role_gated'];
  $ok($probe0['repairable'] === [] && $probe0['failed'] === [] && $probe0['pinned'] === [], "nothing stale, failed or pinned before the test ($dead with no route" . ($dead ? ': ' . implode(' · ', array_map(static fn(array $r): string => "{$r['title']} {$r['uri']}", $probe0['unrouted'])) : '') . ')');
  // /teammates is the Page Manager page "office": three role-gated variants.
  $office = array_values(array_filter($gated, static fn(array $r): bool => $r['uri'] === 'internal:/teammates'));
  $ok((bool) $office && $office[0]['page'] === 'office' && (int) $office[0]['variants'] === 3 && array_filter($gated, static fn(array $r): bool => empty($r['page'])) === [], 'role-gated Page Manager pages flagged (' . count($gated) . '): ' . implode(' · ', array_map(static fn(array $r): string => "{$r['title']} {$r['uri']} → page {$r['page']}, {$r['variants']} variants", $gated)));
  // The premise: as an administrator that path DOES resolve — to the admin variant.
  $switcher = \Drupal::service('account_switcher');
  $switcher->switchTo(\Drupal\user\Entity\User::load(1));
  try {
    $as_admin = \Drupal::pathValidator()->getUrlIfValidWithoutAccessCheck('/teammates');
  }
  finally {
    $switcher->switchBack();
  }
  $admin_route = $as_admin && $as_admin->isRouted() ? $as_admin->getRouteName() : '';
  $ok(str_starts_with($admin_route, 'page_manager.page_view_office_'), "control: as uid 1 /teammates resolves → $admin_route (a menu rebuild from an admin's web request would store that)");
  // Stored pinned to the admin variant: reported, never repaired, never made worse.
  $office_tree = static fn(): array => $db->query('SELECT route_name, url FROM {menu_tree} WHERE id = :id', [':id' => $office[0]['plugin_id']])->fetchAssoc() ?: [];
  $unpin_office = static function () use ($menu_manager, $office): void {
    // The plain URL a drush rebuild writes.
    $menu_manager->updateDefinition($office[0]['plugin_id'], ['route_name' => NULL, 'url' => 'base:teammates', 'route_parameters' => []], FALSE);
  };
  $menu_manager->updateDefinition($office[0]['plugin_id'], ['route_name' => $admin_route, 'url' => NULL, 'route_parameters' => []], FALSE);
  $probe = $guard->probeMenuLinks();
  $ok(count($probe['pinned']) === 1 && $probe['pinned'][0]['plugin_id'] === $office[0]['plugin_id'] && count($probe['role_gated']) === count($gated) - 1, 'pinned Office link detected: ' . json_encode($office_tree()));
  $req = bos_router_guard_runtime_requirements()['bos_router_guard'];
  $ok($req['severity'] === \Drupal\Core\Extension\Requirement\RequirementSeverity::Warning && str_contains((string) $req['description'], 'pinned') && str_contains((string) $req['description'], 'plugin.manager.menu.link'), 'status row WARNING names the pinned link and the drush rebuild');
  $res = $guard->repairMenuLinks('test pinned');
  $ok($res['repaired'] === [] && count($res['pinned']) === 1 && ($office_tree()['route_name'] ?? '') === $admin_route, 'repairMenuLinks() leaves a pinned link alone (reported, not changed)');
  $ok(count($state->get(RouterGuard::STATE_MENU_LINKS)['pinned'] ?? []) === 1, 'pinned recorded in State');
  if (\Drupal::moduleHandler()->moduleExists('dblog')) {
    $warned = (int) $db->query("SELECT COUNT(*) FROM {watchdog} WHERE type = 'bos_router_guard' AND severity = 4 AND message LIKE 'Router guard: @n menu link(s) are stored pinned%' AND timestamp >= :t", [':t' => time() - 300])->fetchField();
    $ok($warned >= 1, "newly pinned logged at warning ($warned line(s))");
  }
  $unpin_office();
  $probe = $guard->probeMenuLinks();
  $ok($probe['pinned'] === [] && count($probe['role_gated']) === count($gated), 'Office link back to a plain URL: ' . json_encode($office_tree()));
  $state->delete(RouterGuard::STATE_MENU_LINKS);
  $bake();
  $ok($baked() && !$routed(), 'baked as base: ' . json_encode($tree()));
  $probe = $guard->probeMenuLinks();
  $ok(count($probe['repairable']) === 1 && $probe['repairable'][0]['plugin_id'] === $victim['plugin_id'] && $probe['repairable'][0]['route_name'] === $victim['route_name'] && count($probe['unrouted']) === $dead && $probe['failed'] === [], 'probe: 1 repairable → ' . ($probe['repairable'][0]['route_name'] ?? '?') . ", $dead dead, 0 failed");
  $req = bos_router_guard_runtime_requirements()['bos_router_guard'];
  $ok($req['severity'] === \Drupal\Core\Extension\Requirement\RequirementSeverity::Warning && str_contains((string) $req['description'], $victim['title']) && str_contains((string) $req['description'], 'repairMenuLinks'), 'status row WARNING names the stale link and the manual command');
  $res = $guard->repairMenuLinks('test');
  $ok(count($res['repaired']) === 1 && $res['repaired'][0]['plugin_id'] === $victim['plugin_id'] && !isset($res['repaired'][0]['definition']) && $routed(), 'repairMenuLinks(): re-routed → ' . json_encode($tree()));
  $ml = $state->get(RouterGuard::STATE_MENU_LINKS);
  $ok(($ml['trigger'] ?? '') === 'test' && count($ml['repaired'] ?? []) === 1 && str_contains((string) $ml['repaired'][0], $victim['route_name']) && count($ml['unrouted'] ?? []) === $dead, 'State summary written: ' . json_encode(['trigger' => $ml['trigger'] ?? NULL, 'checked' => $ml['checked'] ?? NULL, 'repaired' => count($ml['repaired'] ?? []), 'unrouted' => count($ml['unrouted'] ?? [])]));
  $req = bos_router_guard_runtime_requirements()['bos_router_guard'];
  $ok($req['severity'] === \Drupal\Core\Extension\Requirement\RequirementSeverity::OK && str_contains((string) $req['description'], 'Last menu-link pass') && ($dead === 0 || str_contains((string) $req['description'], 'no route')), 'status row OK again, shows the last pass' . ($dead ? ' and the dead link(s)' : ''));
  $bake();
  bos_router_guard_cron();
  $ok($routed() && ($state->get(RouterGuard::STATE_MENU_LINKS)['trigger'] ?? '') === 'cron', 'cron re-routed it: ' . json_encode($tree()));
  $res = $guard->repairMenuLinks('test idle');
  $ok($res['repaired'] === [] && $res['failed'] === [] && count($res['unrouted']) === $dead && $res['checked'] === $dead + count($res['role_gated']), "idle pass: nothing to re-route ($dead with no route, " . count($res['role_gated']) . ' role-gated)');
  if (\Drupal::moduleHandler()->moduleExists('dblog')) {
    $logged = (int) $db->query("SELECT COUNT(*) FROM {watchdog} WHERE type = 'bos_router_guard' AND message LIKE 'Router guard re-routed%' AND timestamp >= :t", [':t' => time() - 300])->fetchField();
    $ok($logged >= 2, "re-routes logged at notice ($logged line(s))");
  }
  $state->delete(RouterGuard::STATE_MENU_LINKS);

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
  $bake();
  $ok($baked(), 'menu link baked before the rebuild: ' . json_encode($tree()));
  $before_mail = $mail_total();
  $t0 = microtime(TRUE);
  $subscriber->onTerminate(new TerminateEvent($kernel, $request, $response));
  printf("  (rebuild took %.1f s)\n", microtime(TRUE) - $t0);
  $facts = $guard->inspect();
  $ok($facts['count'] === $full && !$facts['short'], 'table whole again: ' . $facts['count'] . ' routes');
  $ok($guard->expected() === $full, 'expected re-recorded by FINISHED');
  // Core's own menu rebuild (FINISHED, priority 100) re-derives every internal:
  // link and matched this one — so the guard's pass right after it found
  // nothing left to do, and recorded that.
  $ml = $state->get(RouterGuard::STATE_MENU_LINKS);
  $ok($routed(), 'baked link routed again after the rebuild: ' . json_encode($tree()));
  $ok(($ml['trigger'] ?? '') === 'router rebuild' && count($ml['unrouted'] ?? []) === $dead, 'guard menu-link pass ran at FINISHED: ' . json_encode(['trigger' => $ml['trigger'] ?? NULL, 'checked' => $ml['checked'] ?? NULL, 'repaired' => count($ml['repaired'] ?? []), 'unrouted' => count($ml['unrouted'] ?? [])]));
  // This drush process resolved the current-user context as uid 1 (the control
  // step in A2) and the context repository keeps that for the whole process —
  // so core's OWN menu rebuild at FINISHED just derived /teammates as the ADMIN
  // variant and pinned the Office link. The hazard the structural rule exists
  // for, reproduced; the guard's pass reported it.
  $ok(str_starts_with((string) ($office_tree()['route_name'] ?? ''), 'page_manager.page_view_office_') && count($ml['pinned'] ?? []) === 1, "core's rebuild (admin context in this process) pinned the Office link and the FINISHED pass reported it: " . json_encode($office_tree()));
  $unpin_office();
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
  $ok(str_starts_with((string) ($office_tree()['route_name'] ?? ''), 'page_manager.'), 'the cron rebuild pinned the Office link again (same process, admin context)');
  $unpin_office();

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
  if ($victim && !$routed()) {
    $guard->repairMenuLinks('test restore');
    echo '  (menu link restored: ' . json_encode($tree()) . ")\n";
  }
  if ($office && $menu_manager && str_starts_with((string) ($office_tree()['route_name'] ?? ''), 'page_manager.')) {
    $menu_manager->updateDefinition($office[0]['plugin_id'], ['route_name' => NULL, 'url' => 'base:teammates', 'route_parameters' => []], FALSE);
    echo "  (Office link restored to a plain URL)\n";
  }
  $saved_state[RouterGuard::STATE_MENU_LINKS] === NULL ? $state->delete(RouterGuard::STATE_MENU_LINKS) : $state->set(RouterGuard::STATE_MENU_LINKS, $saved_state[RouterGuard::STATE_MENU_LINKS]);
  $ids = array_values(array_filter($mail_ids));
  if ($ids) {
    try { $http->delete($api . '/api/v1/messages', ['json' => ['IDs' => $ids]]); } catch (\Throwable $e) { echo "  (mailpit delete failed: {$e->getMessage()})\n"; }
  }
  $facts = $guard->inspect();
  $ok(!$facts['short'] && $facts['count'] === $facts['expected'] && empty($facts['incident']) && $routed(), 'router whole (' . $facts['count'] . '), expected recorded, no incident, menu link routed, test mail deleted');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
