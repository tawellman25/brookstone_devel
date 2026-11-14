<?php

/**
 * @file
 * Morning-crew pre-select on the crew clock — acceptance test.
 *
 * Brief (Todd / My 2nd Brain, 2026-11-13): when "Clock crew in" opens after
 * lunch, the people who worked this morning and are now clocked out are
 * already ticked; nobody still clocked in, nobody with no entry today; it is
 * a default the foreman can change; scoped to today; grouped by crew/foreman
 * where that is tracked (field_clocked_in_by) and by this work order.
 *
 * Walks the real day as foreman A on work order X, then checks what the
 * roster proposes on X and on a second job Y, as A and as another foreman B:
 *
 *   A crew-clocks c1 c2 c3 pOpenX in on X (morning) -> crew-clocks them out
 *   c3's entry is moved to YESTERDAY            -> must not count
 *   pSelf clocks THEMSELF in on X, then out      -> counts for X (same job)
 *   pOpenX clocks back in on X and stays open    -> never pre-ticked
 *   A clocks pOpenY in on Y and leaves them open -> never pre-ticked anywhere
 *   pNone has no entry today                     -> never pre-ticked
 *
 * ⚠ WRITES AND ROLLS BACK. Explicit $tx->rollBack(); unset() would COMMIT.
 *
 * Run: drush php:script web/scripts/test_crew_morning_preselect.php
 */

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $good, string $detail = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $detail !== '' ? "  [$detail]" : '');
};

$svc = \Drupal::service('wo_clock.clock_service');
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$switch = \Drupal::service('account_switcher');
$tc = $etm->getStorage('wo_time_clock');

if (!method_exists($svc, 'getMorningCrewForWo')) {
  print "ABORT: WoClockService::getMorningCrewForWo missing — deploy the wo_clock code first.\n";
  return;
}
if (!$db->schema()->tableExists('wo_time_clock__field_clocked_in_by')) {
  print "ABORT: field_clocked_in_by not installed — run wo_clock_morning_crew_setup.php first.\n";
  return;
}

// Two open work orders on a long-cap bundle so nothing trips the entry cap.
$wos = $db->query("SELECT wo.id FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE wo.type = 'fall_cleanup' AND st.field_status_target_id NOT IN (1097, 1098, 1281, 1504)
  ORDER BY wo.id DESC LIMIT 2")->fetchCol();
if (count($wos) < 2) {
  print "ABORT: need two open fall_cleanup work orders.\n";
  return;
}
[$X, $Y] = array_map('intval', $wos);
// Active teammates with no open entry anywhere right now — so the test's own
// entries are the only ones that matter for "clocked out everywhere".
$pool = $db->query("SELECT u.uid FROM {users_field_data} u
  WHERE u.status = 1 AND u.uid > 1
    AND NOT EXISTS (SELECT 1 FROM {wo_time_clock__field_teammate} t
                    JOIN {wo_time_clock_field_data} d ON d.id = t.entity_id
                    LEFT JOIN {wo_time_clock__field_end_time} e ON e.entity_id = t.entity_id AND e.deleted = 0
                    WHERE t.field_teammate_target_id = u.uid AND t.deleted = 0 AND e.field_end_time_value IS NULL)
  ORDER BY u.uid LIMIT 9")->fetchCol();
if (count($pool) < 9) {
  print "ABORT: need 9 active users with no open entry.\n";
  return;
}
$pool = array_map('intval', $pool);
[$A, $B, $c1, $c2, $c3, $pSelf, $pOpenX, $pOpenY, $pNone] = $pool;
$users = $etm->getStorage('user');
$name = fn(int $u) => $users->load($u)->getDisplayName();
printf("X = WO %d, Y = WO %d; foreman A uid %d, foreman B uid %d; crew c1 %d c2 %d c3 %d; self %d; openX %d; openY %d; none %d\n\n",
  $X, $Y, $A, $B, $c1, $c2, $c3, $pSelf, $pOpenX, $pOpenY, $pNone);

// "This morning": today in the site timezone, safely before now.
$tz = new \DateTimeZone(date_default_timezone_get());
$now = (new \DateTime('now', $tz));
$dayStart = (clone $now)->setTime(0, 0, 0);
$start = (clone $now)->modify('-4 hours');
if ($start < $dayStart) {
  $start = (clone $dayStart)->modify('+5 minutes');
}
$end = (clone $start)->modify('+3 hours');
if ($end >= $now) {
  $end = (clone $now)->modify('-2 minutes');
}
$utc = fn(\DateTime $d) => gmdate('Y-m-d\TH:i:s', $d->getTimestamp());
$yStart = (clone $start)->modify('-1 day');
$yEnd = (clone $end)->modify('-1 day');
$setTimes = function (int $entryId, \DateTime $s, ?\DateTime $e) use ($tc, $utc) {
  $en = $tc->loadUnchanged($entryId);
  $en->set('field_start_time', $utc($s));
  $en->set('field_end_time', $e ? $utc($e) : NULL);
  $en->_wo_clock_write = TRUE;
  $en->save();
};
$row = function (array $roster, int $uid): ?array {
  foreach ($roster as $r) {
    if ((int) $r['uid'] === $uid) {
      return $r;
    }
  }
  return NULL;
};
$flag = fn(array $roster, int $uid, string $k) => (bool) (($row($roster, $uid) ?? [])[$k] ?? FALSE);

$tx = $db->startTransaction();
try {
  // --- the morning, as foreman A -------------------------------------------
  $switch->switchTo($users->load($A));
  $r = $svc->crewClockIn($X, [$c1, $c2, $c3, $pOpenX]);
  $ok('A crew-clocks 4 in on X', count($r['created']) === 4 && !$r['skipped']);
  $byStamped = TRUE;
  foreach ($r['created'] as $uid => $e) {
    $byStamped = $byStamped && ((int) $tc->loadUnchanged($e->id())->get('field_clocked_in_by')->target_id === $A);
    $setTimes((int) $e->id(), $start, NULL);
  }
  $ok('each crew entry is stamped field_clocked_in_by = A (the foreman, not the teammate)', $byStamped);
  $out = $svc->crewClockOut($X);
  $ok('A crew-clocks X out (lunch)', isset($out['closed']) && count($out['closed']) === 4, json_encode(array_keys($out)));
  // Make the closed window "this morning" and move c3's to yesterday.
  foreach ($r['created'] as $uid => $e) {
    $setTimes((int) $e->id(), $uid === $c3 ? $yStart : $start, $uid === $c3 ? $yEnd : $end);
  }
  // pSelf clocked themself in on X this morning and out again.
  $switch->switchBack();
  $switch->switchTo($users->load($pSelf));
  $selfEntry = $svc->clockIn($pSelf, $X);
  $ok('pSelf own-button entry is stamped field_clocked_in_by = pSelf', (int) $tc->loadUnchanged($selfEntry->id())->get('field_clocked_in_by')->target_id === $pSelf);
  $setTimes((int) $selfEntry->id(), $start, $end);
  $switch->switchBack();
  // pOpenX came back early and is clocked in on X right now.
  $switch->switchTo($users->load($pOpenX));
  $reopen = $svc->clockIn($pOpenX, $X);
  $switch->switchBack();
  // A also put pOpenY on Y this morning and never clocked them out.
  $switch->switchTo($users->load($A));
  $ry = $svc->crewClockIn($Y, [$pOpenY]);
  $ok('A crew-clocks pOpenY in on Y (left open)', count($ry['created']) === 1);

  // --- after lunch: the roster on X, as A ----------------------------------
  echo "\n== roster X as foreman A ==\n";
  $rx = $svc->getCrewRosterForWo($X);
  $ok('c1 pre-ticked (worked X this morning, clocked out)', $flag($rx, $c1, 'morning'), 'out ' . (($row($rx, $c1) ?? [])['last_out'] ?? '-'));
  $ok('c2 pre-ticked', $flag($rx, $c2, 'morning'));
  $ok('pSelf pre-ticked (own clock-in on X counts — same job)', $flag($rx, $pSelf, 'morning'));
  $ok('c3 NOT pre-ticked (entry was yesterday)', !$flag($rx, $c3, 'morning'));
  $ok('pOpenX NOT pre-ticked and disabled (still clocked in on X)', !$flag($rx, $pOpenX, 'morning') && $flag($rx, $pOpenX, 'open'));
  $ok('pOpenY listed on X (A\'s crew today) but NOT pre-ticked — flagged clocked in on another job', $row($rx, $pOpenY) !== NULL && !$flag($rx, $pOpenY, 'morning') && $flag($rx, $pOpenY, 'open_elsewhere') && !$flag($rx, $pOpenY, 'open'));
  $ok('pNone NOT pre-ticked (no entry today)', !$flag($rx, $pNone, 'morning'));
  $ok('last_out shows the clock-out time, today', (($row($rx, $c1) ?? [])['last_out'] ?? '') === $end->format('g:i A'), ($row($rx, $c1) ?? [])['last_out'] ?? '-');
  $pre = array_values(array_filter($rx, fn($p) => $p['morning']));
  $ok('exactly 3 pre-ticked on X', count($pre) === 3, implode(', ', array_map(fn($p) => $p['name'], $pre)));
  $ok('pre-ticked rows sort first', $rx[0]['morning'] && $rx[1]['morning'] && $rx[2]['morning']);
  $ok('nobody pre-ticked is open anywhere', !array_filter($pre, fn($p) => $p['open'] || $p['open_elsewhere']));

  // --- the afternoon job Y, as A: the crew follows the foreman -------------
  echo "\n== roster Y as foreman A ==\n";
  $ryA = $svc->getCrewRosterForWo($Y);
  $ok('c1 pre-ticked on Y (A clocked them in this morning — foreman grouping)', $flag($ryA, $c1, 'morning'));
  $ok('c2 pre-ticked on Y', $flag($ryA, $c2, 'morning'));
  $ok('pSelf NOT pre-ticked on Y (clocked themself in on X; not A\'s crew action, not this job)', !$flag($ryA, $pSelf, 'morning'));
  $ok('pOpenY shown as already clocked in on Y, not pre-ticked', $flag($ryA, $pOpenY, 'open') && !$flag($ryA, $pOpenY, 'morning'));
  $ok('c3 NOT pre-ticked on Y (yesterday)', !$flag($ryA, $c3, 'morning'));
  $switch->switchBack();

  // --- Y as a DIFFERENT foreman B: no inheritance --------------------------
  echo "\n== roster Y as foreman B ==\n";
  $switch->switchTo($users->load($B));
  $ryB = $svc->getCrewRosterForWo($Y);
  $ok('c1 NOT pre-ticked for B on Y (another foreman\'s crew, other job)', !$flag($ryB, $c1, 'morning'));
  $ok('c2 NOT pre-ticked for B on Y', !$flag($ryB, $c2, 'morning'));
  // --- X as B: same-job grouping applies to anyone opening THAT job --------
  $rxB = $svc->getCrewRosterForWo($X);
  $ok('c1, c2, pSelf pre-ticked for B on X (they worked X this morning)', $flag($rxB, $c1, 'morning') && $flag($rxB, $c2, 'morning') && $flag($rxB, $pSelf, 'morning'));
  $switch->switchBack();

  // --- submit as A on X with the pre-ticked three: back from lunch ---------
  echo "\n== submit ==\n";
  $switch->switchTo($users->load($A));
  $ids = array_map(fn($p) => (int) $p['uid'], $pre);
  $r2 = $svc->crewClockIn($X, $ids);
  $ok('submitting the pre-ticked three clocks them back in (3 created, 0 skipped)', count($r2['created']) === 3 && !$r2['skipped'], json_encode($r2['skipped']));
  $rx2 = $svc->getCrewRosterForWo($X);
  $ok('now they are open on X and no longer pre-ticked', $flag($rx2, $c1, 'open') && !$flag($rx2, $c1, 'morning') && !array_filter($rx2, fn($p) => $p['morning']));
  $r3 = $svc->crewClockIn($X, $ids);
  $ok('pressing again creates nothing (no double clock-in)', !$r3['created'] && count($r3['skipped']) === 3);
  $r4 = $svc->crewClockIn($X, [$pOpenY]);
  $ok('crew clock-in on X skips pOpenY, who is open on Y (no second open row)', !$r4['created'] && str_contains($r4['skipped'][$pOpenY] ?? '', 'another job'), json_encode($r4['skipped']));
  $openX = count($svc->getOpenEntriesOnWo($X));
  $ok('X has exactly 4 open entries (three back + pOpenX)', $openX === 4, "$openX");
  $switch->switchBack();

  // --- tomorrow it resets: nothing today → nothing proposed ----------------
  echo "\n== reset ==\n";
  // Every entry the test made (all of today's, for the pool) becomes yesterday's.
  $ids = $tc->getQuery()->accessCheck(FALSE)->condition('field_teammate', $pool, 'IN')->condition('field_start_time', $utc($dayStart), '>=')->execute();
  foreach ($ids as $id) {
    $setTimes((int) $id, $yStart, $yEnd);
  }
  $switch->switchTo($users->load($A));
  $ok('with every entry moved to yesterday, X proposes nobody', !$svc->getMorningCrewForWo($X));
  $switch->switchBack();
}
catch (\Throwable $e) {
  $ok('no exception', FALSE, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
finally {
  $tx->rollBack();
  try { $switch->switchBack(); } catch (\Throwable $ignore) {}
}
$left = (int) $db->query("SELECT COUNT(*) FROM {wo_time_clock__field_clocked_in_by} b JOIN {wo_time_clock__field_start_time} s ON s.entity_id = b.entity_id WHERE s.field_start_time_value >= :f", [':f' => $utc($dayStart)])->fetchField();
printf("\nrolled back — entries stamped today still in DB: %d (expect whatever existed before the run)\n", $left);
printf("\n%d passed, %d failed\n", $pass, $fail);
