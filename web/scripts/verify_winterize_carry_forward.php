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

echo "== 4. Sample: proposed weekday/month == prior (nth-weekday rule) ==\n";
$sample = array_slice($cmdRecords, 0, 20);
$ruleFail = 0; $checked = 0;
foreach ($sample as $s) {
  $note = (string) $s->get('field_scheduling_note')->value;
  if (!preg_match('/from (\d{4}-\d{2}-\d{2}) \(/', $note, $m)) { continue; }
  $checked++;
  $prior = DrupalDateTime::createFromFormat('Y-m-d', $m[1], $tz);
  $proposed = DrupalDateTime::createFromTimestamp((int) $s->get('field_date')->value, $tz);
  $sameWeekday = $prior->format('N') === $proposed->format('N');
  $sameMonth = $prior->format('n') === $proposed->format('n');
  // ordinal (allow the 5th→last fallback: proposed ordinal <= prior ordinal)
  $po = intdiv(((int) $prior->format('j')) - 1, 7) + 1;
  $qo = intdiv(((int) $proposed->format('j')) - 1, 7) + 1;
  if (!$sameWeekday || !$sameMonth || $qo > $po) { $ruleFail++; }
}
$ok('nth-weekday-of-month rule holds on sample', $ruleFail === 0, "checked $checked, $ruleFail off");

echo "== 5. No excluded-status WO has a command record ==\n";
$badStatus = 0;
foreach ($cmdRecords as $s) {
  $wo = $etm->getStorage('work_order')->load((int) $s->get('field_work_order')->target_id);
  $st = $wo && !$wo->get('field_status')->isEmpty() ? (int) $wo->get('field_status')->target_id : 0;
  if (in_array($st, $EXCLUDED, TRUE)) { $badStatus++; }
}
$ok('no excluded-status WO scheduled', $badStatus === 0, $badStatus ? "$badStatus bad" : '');

echo "== 6. Dense route order within (date, tech) groups ==\n";
$groups = [];
foreach ($cmdRecords as $s) {
  $day = DrupalDateTime::createFromTimestamp((int) $s->get('field_date')->value, $tz)->format('Y-m-d');
  $tech = $s->get('field_assigned_to')->isEmpty() ? '0' : (string) $s->get('field_assigned_to')->target_id;
  $groups["$day|$tech"][] = $s->get('field_scheduled_oder')->isEmpty() ? NULL : (int) $s->get('field_scheduled_oder')->value;
}
$denseFail = 0;
foreach ($groups as $orders) {
  $orders = array_values($orders);
  if (in_array(NULL, $orders, TRUE)) { $denseFail++; continue; }
  sort($orders);
  if ($orders !== range(1, count($orders))) { $denseFail++; }
}
$ok('every (date,tech) group is a dense 1..N with no gaps/dupes/nulls', $denseFail === 0, "$denseFail bad groups of " . count($groups));

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
