<?php

/**
 * @file
 * Verify the live clock status: OpenPunchService, the "Currently clocked into"
 * card on a teammate's user page + variance detail page, and the Active Now
 * board's summary line / stale flag.
 *
 *   ddev drush php:script web/scripts/clock_status_verify.php              # dev: read checks + a write round-trip
 *   drush php:script ~/tmp/clock_status_verify.php -- --no-write          # live: read-only
 *
 * The dev round-trip creates two OPEN wo_time_clock entries for a crew-only
 * teammate (one 10 minutes old, one 20 hours old = stale), renders the pages
 * as uid 1 and as another crew member, then deletes both entries. Nothing is
 * written with --no-write. Exits non-zero only on failure.
 */

use Drupal\bos_teammate_operations\Service\OpenPunchService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$noWrite = in_array('--no-write', $extra ?? [], TRUE);
$pass = 0;
$fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  if ($cond) { $pass++; echo "  ok   $label\n"; }
  else { $fail++; echo "  FAIL $label\n"; }
};

$em = \Drupal::entityTypeManager();
$kernel = \Drupal::service('http_kernel');
$switcher = \Drupal::service('account_switcher');
$get = static function (string $path) use ($kernel): array {
  $req = Request::create($path, 'GET');
  $req->setSession(new Session(new MockArraySessionStorage()));
  $res = $kernel->handle($req, HttpKernelInterface::SUB_REQUEST);
  return [$res->getStatusCode(), (string) $res->getContent()];
};
$as = static function (int $uid, callable $fn) use ($switcher, $em) {
  $switcher->switchTo($em->getStorage('user')->load($uid));
  try { return $fn(); }
  finally { $switcher->switchBack(); }
};

echo "== services + hook\n";
$ok(\Drupal::hasService('bos_teammate_operations.open_punches'), 'service bos_teammate_operations.open_punches exists');
$ok(method_exists(\Drupal::service('wo_clock.clock_service'), 'getOpenEntries'), 'WoClockService::getOpenEntries() exists');
$ok(\Drupal::moduleHandler()->hasImplementations('user_view', 'bos_teammate_operations'), 'hook_user_view registered for bos_teammate_operations');
/** @var \Drupal\bos_teammate_operations\Service\OpenPunchService $svc */
$svc = \Drupal::service('bos_teammate_operations.open_punches');

echo "== stale rule + formatting\n";
$now = \Drupal::time()->getRequestTime();
$ok($svc->staleReason($now - 120, $now) === NULL, '2 minutes ago is not stale');
$ok($svc->staleReason($now - 15 * 3600, $now) !== NULL, '15 hours ago is stale');
$yesterdayNoon = (new \DateTimeImmutable('yesterday 12:00', new \DateTimeZone(\Drupal::config('system.date')->get('timezone.default'))))->getTimestamp();
$ok($svc->staleReason($yesterdayNoon, $now) === OpenPunchService::STALE_PRIOR_DAY, 'yesterday noon (site tz) is stale: prior_day');
$ok($svc->formatElapsed(300) === '5 min', 'formatElapsed 300 s = "5 min"');
$ok($svc->formatElapsed(3 * 3600 + 60) === '3 hr 1 min', 'formatElapsed 3h01 = "3 hr 1 min"');
$ok($svc->formatElapsed(50 * 3600) === '2 d 2 hr', 'formatElapsed 50 h = "2 d 2 hr"');

echo "== rows() against the live table\n";
$rows = $svc->rows();
$ok(is_array($rows), 'rows() returns an array (' . count($rows) . ' open entries)');
$allOpen = TRUE;
$keys = ['entry_id', 'uid', 'teammate_label', 'teammate_url', 'wo_id', 'wo_label', 'wo_url', 'start_ts', 'elapsed', 'elapsed_text', 'since', 'since_date', 'stale', 'stale_reason'];
foreach ($rows as $r) {
  foreach ($keys as $k) { if (!array_key_exists($k, $r)) { $allOpen = FALSE; } }
  $e = $em->getStorage('wo_time_clock')->load($r['entry_id']);
  if (!$e || !$e->get('field_end_time')->isEmpty()) { $allOpen = FALSE; }
}
$ok($allOpen, 'every row has every key and its entry really has an empty field_end_time');
// Unbounded: the raw count of open entries equals the row count (minus rows with no resolvable teammate).
$rawOpen = $em->getStorage('wo_time_clock')->getQuery()->accessCheck(FALSE)->exists('field_start_time')->notExists('field_end_time')->count()->execute();
$ok((int) $rawOpen >= count($rows) && (int) $rawOpen - count($rows) <= 0 + count(array_filter($em->getStorage('wo_time_clock')->loadMultiple($em->getStorage('wo_time_clock')->getQuery()->accessCheck(FALSE)->exists('field_start_time')->notExists('field_end_time')->execute()), static fn($e) => $e->get('field_teammate')->isEmpty() && !$e->getOwnerId())), "rows() is unbounded: raw open count $rawOpen vs " . count($rows) . ' rows');
$sum = $svc->summary($rows);
printf("  summary: %d entries, %d teammates, %d active, %d stale, %d with >1 entry\n", $sum['entries'], $sum['teammates'], $sum['active'], $sum['stale'], $sum['multi']);
foreach ($rows as $r) { printf("    %s — WO %d — since %s — %s%s\n", $r['teammate_label'], $r['wo_id'], $r['since'], $r['elapsed_text'], $r['stale'] ? ' — STALE (' . $r['stale_reason'] . ')' : ''); }

echo "== Active Now board as uid 1\n";
[$c, $h] = $as(1, static fn() => $get('/admin/office/operations/teammates/active'));
$ok($c === 200, "Active Now 200 (got $c)");
$ok(str_contains($h, 'Currently Clocked In'), 'section heading present');
if ($rows) {
  $ok(str_contains($h, 'clocked in</strong>'), 'summary line present');
  $ok($sum['stale'] === 0 || str_contains($h, 'possible missed clock-out'), 'stale entries carry the ⚠ possible missed clock-out flag');
  $ok(substr_count($h, '<tr class="bos-row-stale">') === $sum['stale'], 'stale rows highlighted = summary stale count');
}
else {
  $ok(str_contains($h, 'No teammates currently clocked into a WO'), 'empty state shown');
}

echo "== a teammate with nothing open\n";
$openUids = array_unique(array_column($rows, 'uid'));
$teamIds = $em->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('status', 1)->condition('roles', 'teammates')->sort('uid')->range(0, 60)->execute();
$idle = NULL; $crewOnly = []; 
foreach ($em->getStorage('user')->loadMultiple($teamIds) as $u) {
  if ($u->getRoles(TRUE) === ['teammates']) { $crewOnly[] = (int) $u->id(); }
  if ($idle === NULL && !in_array((int) $u->id(), $openUids, TRUE)) { $idle = (int) $u->id(); }
}
$ok($idle !== NULL, "found a teammate with no open entry (uid $idle)");
if ($idle) {
  [$c, $h] = $as(1, static fn() => $get("/user/$idle"));
  $ok($c === 200 && str_contains($h, 'Currently clocked into') && str_contains($h, 'Not clocked in.'), "user page shows the card + \"Not clocked in.\" ($c)");
  $ok(str_contains($h, 'bos-clock-status--none'), 'card carries the --none state class');
  [$c, $h] = $as(1, static fn() => $get("/admin/office/operations/teammates/variance/$idle"));
  $ok($c === 200 && str_contains($h, 'Currently clocked into') && str_contains($h, 'Not clocked in.'), "variance detail page shows the card too ($c)");
}
// A real stale entry already on this database shows on its teammate's page.
foreach ($rows as $r) {
  if ($r['stale']) {
    [$c, $h] = $as(1, static fn() => $get('/user/' . $r['uid']));
    $ok($c === 200 && str_contains($h, 'open since ' . $r['since_date']) && str_contains($h, 'possible missed clock-out'), "{$r['teammate_label']}'s page flags the entry open since {$r['since_date']}");
    break;
  }
}

if ($noWrite) {
  printf("\nRESULT: %d ok, %d failed (read-only)\n", $pass, $fail);
  if ($fail) { exit(1); }
  return;
}

echo "== write round-trip (dev): two open entries, one stale\n";
$tm = NULL;
foreach ($crewOnly as $cuid) { if (!in_array($cuid, $openUids, TRUE)) { $tm = $cuid; break; } }
$viewer = NULL;
foreach ($crewOnly as $cuid) { if ($cuid !== $tm) { $viewer = $cuid; break; } }
$ok($tm !== NULL && $viewer !== NULL, "crew-only teammate $tm with nothing open, second crew member $viewer as viewer");
$woIds = $em->getStorage('work_order')->getQuery()->accessCheck(FALSE)->condition('field_status', [1281, 1504, 1097, 1098], 'NOT IN')->sort('id', 'DESC')->range(0, 2)->execute();
$ok(count($woIds) === 2, 'two open work orders to clock into: ' . implode(', ', $woIds));
$created = [];
try {
  /** @var \Drupal\wo_clock\Service\WoClockService $clock */
  $clock = \Drupal::service('wo_clock.clock_service');
  $woIds = array_values($woIds);
  $fresh = $clock->createOpenEntry($tm, (int) $woIds[0]);
  $fresh->set('field_start_time', gmdate('Y-m-d\TH:i:s', $now - 600));
  $fresh->save(); $created[] = $fresh;
  $old = $clock->createOpenEntry($tm, (int) $woIds[1]);
  $old->set('field_start_time', gmdate('Y-m-d\TH:i:s', $now - 20 * 3600));
  $old->save(); $created[] = $old;
  $ok($fresh->get('field_end_time')->isEmpty() && $old->get('field_end_time')->isEmpty(), 'both saved entries are open (field_end_time empty)');

  $mine = $svc->rows($tm);
  $ok(count($mine) === 2, 'rows(uid) returns both entries (' . count($mine) . ')');
  $byId = array_column($mine, NULL, 'entry_id');
  $ok(isset($byId[(int) $fresh->id()]) && $byId[(int) $fresh->id()]['stale'] === FALSE, '10-minute entry is not stale');
  $ok(isset($byId[(int) $old->id()]) && $byId[(int) $old->id()]['stale'] === TRUE, '20-hour entry is stale (' . ($byId[(int) $old->id()]['stale_reason'] ?? '-') . ')');
  $ok(($mine[0]['entry_id'] ?? 0) === (int) $old->id(), 'oldest first');

  [$c, $h] = $as(1, static fn() => $get("/user/$tm"));
  $ok($c === 200 && str_contains($h, 'Currently clocked into'), "user page as uid 1: 200 + card ($c)");
  $ok(str_contains($h, 'WO ' . $woIds[0] . ' ') && str_contains($h, 'WO ' . $woIds[1] . ' '), 'both work orders listed');
  $woHref = \Drupal\Core\Url::fromUri('internal:/work_order/' . $woIds[0])->toString();
  $ok(str_contains($h, 'href="' . $woHref . '">WO ' . $woIds[0] . ' '), "work order is linked ($woHref)");
  $ok(str_contains($h, 'elapsed'), 'elapsed shown');
  $ok(substr_count($h, 'possible missed clock-out') === 1, 'exactly the stale one is flagged');
  $ok(str_contains($h, 'bos-clock-status--stale'), 'card carries the --stale state class');
  [$c, $h] = $as($viewer, static fn() => $get("/user/$tm"));
  $ok($c === 200 && str_contains($h, 'Currently clocked into'), "another crew member sees the card too ($c)");

  [$c, $h] = $as(1, static fn() => $get('/admin/office/operations/teammates/active'));
  $ok($c === 200 && substr_count($h, '>' . $woIds[0] . '</a>') >= 1 && substr_count($h, '>' . $woIds[1] . '</a>') >= 1, 'Active Now lists both new entries');
  $ok(str_contains($h, 'with more than one open entry'), 'summary counts the teammate holding two open entries');
  $ok(str_contains($h, 'open since ' . $byId[(int) $old->id()]['since_date']), 'stale row names its open-since date');
  [$c, $h] = $as(1, static fn() => $get("/admin/office/operations/teammates/variance/$tm"));
  $ok($c === 200 && str_contains($h, 'possible missed clock-out'), "variance detail page flags the stale entry ($c)");
}
finally {
  foreach ($created as $e) { $e->delete(); }
  echo '  cleaned up ' . count($created) . " entries\n";
}
$ok(count($svc->rows($tm)) === 0, 'teammate has nothing open again');

printf("\nRESULT: %d ok, %d failed\n", $pass, $fail);
if ($fail) { exit(1); }
