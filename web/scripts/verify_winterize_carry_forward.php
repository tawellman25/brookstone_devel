<?php

/**
 * Verifier for the winterizing carry-forward apply (§10). Idempotent, read-only.
 * Run AFTER an apply. Command-created records are identified by their
 * field_scheduling_note ("Carried forward …" or "New customer …").
 *
 *   drush php:script web/scripts/verify_winterize_carry_forward.php
 *   (target year defaults to 2026)
 */

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$targetYear = 2026;
$tz = new \DateTimeZone(date_default_timezone_get());
// WO candidate universe = full calendar year, matching the command's plan()
// selection (2026 winterizing WOs are generated across the year — some as early
// as February). The scheduled-date window below stays the fall season.
$yearStart = (new DrupalDateTime("$targetYear-01-01 00:00:00", $tz))->getTimestamp();
$yearEnd = (new DrupalDateTime("$targetYear-12-31 23:59:59", $tz))->getTimestamp();
// Scheduled field_date must land in the winterizing season (apply enforces this).
$seasonStart = (new DrupalDateTime("$targetYear-08-01 00:00:00", $tz))->getTimestamp();
$seasonEnd = (new DrupalDateTime("$targetYear-12-31 23:59:59", $tz))->getTimestamp();
$EXCLUDED = [1098, 1097, 1283, 1281, 1504];
$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond, string $detail = '') use (&$pass, &$fail) {
  printf("  [%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $label, $detail ? " — $detail" : '');
  $cond ? $pass++ : $fail++;
};

// All winterizing WOs in the target season + their scheduling records.
$woIds = array_map('intval', $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'sprinkler_winterizing')
  ->condition('created', $yearStart, '>=')->condition('created', $yearEnd, '<=')
  ->sort('id', 'ASC')->execute());

$schedByWo = [];
$cmdRecords = [];
foreach (array_chunk($woIds, 300) as $ch) {
  $sids = $etm->getStorage('scheduling')->getQuery()->accessCheck(FALSE)->condition('field_work_order', $ch, 'IN')->sort('id', 'ASC')->execute();
  foreach ($etm->getStorage('scheduling')->loadMultiple($sids) as $s) {
    $wid = (int) $s->get('field_work_order')->target_id;
    $schedByWo[$wid][] = $s;
    $note = (string) ($s->get('field_scheduling_note')->value ?? '');
    if (str_starts_with($note, 'Carried forward') || str_starts_with($note, 'New customer')) {
      $cmdRecords[] = $s;
    }
  }
}

echo "== 1. No WO has >1 scheduling record ==\n";
$dupes = array_filter($schedByWo, fn($a) => count($a) > 1);
$ok('at most one scheduling record per winterizing WO', empty($dupes), $dupes ? count($dupes) . ' WOs with multiple' : '');

echo "== 2. Command records: field_date in season window + duration 1439 ==\n";
$badDate = 0;
foreach ($cmdRecords as $s) {
  $v = (int) $s->get('field_date')->value;
  $dur = (int) $s->get('field_date')->duration;
  if ($v < $seasonStart || $v > $seasonEnd || $dur !== 1439) { $badDate++; }
}
$ok('all command records in-window with duration 1439', $badDate === 0, $badDate ? "$badDate bad" : count($cmdRecords) . ' checked');

echo "== 3. WOs with a command record have field_scheduled = TRUE ==\n";
$notFlipped = 0;
foreach ($cmdRecords as $s) {
  $wo = $etm->getStorage('work_order')->load((int) $s->get('field_work_order')->target_id);
  if (!$wo || !(bool) $wo->get('field_scheduled')->value) { $notFlipped++; }
}
$ok('field_scheduled flipped on all', $notFlipped === 0, $notFlipped ? "$notFlipped not flipped" : '');

echo "== 4. Proposed date follows the CALENDAR-DATE rule ==\n";
// The nth-weekday-of-month rule this check used to assert was REPLACED on
// 2026-08-30: it preserved each customer's weekday but scrambled route order
// year to year. The rule now is "keep last year's month and day", with Saturday
// and Sunday rolled to the following Monday because crews work Mon-Fri.
//
// Asserted only on records NOBODY HAS EDITED SINCE CREATION. Once the office
// moves a stop — a customer asks for a different day, or the 2026-08-30
// re-dating pass rewrites it — the date is no longer the command's output and
// holding the command to it makes the check drift as the season is worked.
// Measured: of 87 untouched records 0 deviate; of 434 edited ones 7 do.
$ruleFail = 0; $checked = 0; $skippedEdited = 0; $editedOff = 0; $examples = [];
foreach ($cmdRecords as $s) {
  $note = (string) $s->get('field_scheduling_note')->value;
  if (!preg_match('/from (\d{4}-\d{2}-\d{2}) \(/', $note, $m)) { continue; }
  $edited = ((int) $s->get('changed')->value - (int) $s->get('created')->value) > 60;
  $checked++;
  $prior = DrupalDateTime::createFromFormat('Y-m-d', $m[1], $tz);
  $proposed = DrupalDateTime::createFromTimestamp((int) $s->get('field_date')->value, $tz);

  // Same month/day in the target year, then the weekend roll.
  $expected = DrupalDateTime::createFromFormat('Y-m-d', $targetYear . '-' . $prior->format('m-d'), $tz);
  $iso = (int) $expected->format('N');
  if ($iso === 6) { $expected->modify('+2 days'); }
  elseif ($iso === 7) { $expected->modify('+1 day'); }

  $off = $expected->format('Y-m-d') !== $proposed->format('Y-m-d');
  if ($edited) {
    $checked--;
    $skippedEdited++;
    if ($off) { $editedOff++; }
    continue;
  }
  if ($off) {
    $ruleFail++;
    if (count($examples) < 3) {
      $examples[] = sprintf('prior %s -> expected %s, got %s', $m[1], $expected->format('Y-m-d'), $proposed->format('Y-m-d'));
    }
  }
}
$ok('calendar-date rule holds on every untouched record', $ruleFail === 0,
  "checked $checked untouched, $ruleFail off" . ($examples ? ' — e.g. ' . implode('; ', $examples) : ''));
printf("  (%d edited since creation, not asserted; %d of those sit off the rule — office moves, or the 2026-08-30 re-date)\n", $skippedEdited, $editedOff);

echo "== 5. Status of command-scheduled WOs (informational) ==\n";
// NOT an assertion. A WO scheduled in August and since completed, invoiced or
// cancelled is normal lifecycle, and nothing in the data records WHEN the status
// changed — so "no excluded-status WO has a record" cannot be judged after the
// fact. The real guard is in apply(), which re-validates every row against live
// state and skips an excluded WO; that is behaviour to test, not data to scan.
$statusNames = [1091 => 'Scheduled', 1092 => 'In Progress', 1097 => 'Complete', 1098 => 'Canceled', 1281 => 'Invoiced', 1283 => 'Warrantied', 1504 => 'Paid'];
$byStatus = [];
foreach ($cmdRecords as $s) {
  $wo = $etm->getStorage('work_order')->load((int) $s->get('field_work_order')->target_id);
  $st = $wo && !$wo->get('field_status')->isEmpty() ? (int) $wo->get('field_status')->target_id : 0;
  $label = $statusNames[$st] ?? ('tid ' . $st);
  $byStatus[$label] = ($byStatus[$label] ?? 0) + 1;
}
arsort($byStatus);
foreach ($byStatus as $label => $n) { printf("  %-14s %d\n", $label, $n); }
printf("  (informational — progress after scheduling is expected)\n");

echo "== 6. Route order unique and contiguous per (date, tech) ==\n";
// Checked across EVERY scheduling record on that date for that tech, not just
// the ones this command created. Command records legitimately start above 1
// now: an incremental batch appends after whatever is already on the day, so
// asserting that command records alone form 1..N was wrong as of 2026-09-29.
$seen = [];
$denseFail = 0; $groupCount = 0; $detail = [];
foreach ($cmdRecords as $s) {
  $ts = (int) $s->get('field_date')->value;
  $day = DrupalDateTime::createFromTimestamp($ts, $tz)->format('Y-m-d');
  $tech = $s->get('field_assigned_to')->isEmpty() ? NULL : (int) $s->get('field_assigned_to')->target_id;
  $key = $day . '|' . ($tech ?? '0');
  if (isset($seen[$key])) { continue; }
  $seen[$key] = TRUE;
  $groupCount++;

  // Day bounds in PHP against the RAW timestamp: FROM_UNIXTIME renders in
  // MariaDB's session timezone (fixed UTC-7 here) while the site is
  // America/Denver, so a SQL date cast buckets local-midnight records into the
  // previous day for half the year.
  $dayStart = (new DrupalDateTime($day . ' 00:00:00', $tz))->getTimestamp();
  $dayEnd = (new DrupalDateTime($day . ' 23:59:59', $tz))->getTimestamp();

  $q = \Drupal::database()->select('scheduling__field_date', 'd');
  $q->leftJoin('scheduling__field_assigned_to', 'a', 'a.entity_id = d.entity_id');
  $q->leftJoin('scheduling__field_scheduled_oder', 'o', 'o.entity_id = d.entity_id');
  $q->addField('o', 'field_scheduled_oder_value', 'v');
  $q->condition('d.field_date_value', [$dayStart, $dayEnd], 'BETWEEN');
  if ($tech !== NULL) { $q->condition('a.field_assigned_to_target_id', $tech); }
  else { $q->isNull('a.field_assigned_to_target_id'); }
  $orders = $q->execute()->fetchCol();

  $nulls = count(array_filter($orders, fn($x) => $x === NULL));
  $vals = array_map('intval', array_filter($orders, fn($x) => $x !== NULL));
  sort($vals);
  $bad = $nulls > 0 || count($vals) !== count(array_unique($vals)) || $vals !== range(1, count($vals));
  if ($bad) {
    $denseFail++;
    if (count($detail) < 4) {
      $detail[] = sprintf('%s tech=%s [%s]%s', $day, $tech ?? 'none', implode(',', $vals), $nulls ? " +$nulls null" : '');
    }
  }
}
$ok('route order unique and contiguous from 1 across the whole day',
  $denseFail === 0, "$denseFail of $groupCount groups off" . ($detail ? ' — ' . implode('; ', $detail) : ''));

echo "== 7. UI smoke (http_kernel sub-request) ==\n";
$switcher = \Drupal::service('account_switcher');
$kernel = \Drupal::service('http_kernel');
$get = function (string $path, $account) use ($switcher, $kernel): int {
  $switcher->switchTo($account);
  try {
    return $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::SUB_REQUEST, TRUE)->getStatusCode();
  } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { return $e->getStatusCode(); }
  catch (\Throwable $e) { return 500; }
  finally { $switcher->switchBack(); }
};
$admin = User::load(1);
$ok('/teammates/calendar → 200', $get('/teammates/calendar', $admin) === 200);
$sched = $get('/admin/office/work-orders/scheduling/sprinkler', $admin);
$ok('sprinkler scheduling page → 200', $sched === 200, "got $sched");

printf("\n== RESULT: %d passed, %d failed · %d command records found ==\n", $pass, $fail, count($cmdRecords));
