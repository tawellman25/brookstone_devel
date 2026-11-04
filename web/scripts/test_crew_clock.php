<?php

/**
 * @file
 * The crew clock, against the acceptance criteria in Modules/wo_clock_crew.md.
 *
 * The whole reason this feature exists is that sign-off reconciliation had to
 * INVENT a window for anyone who had not clocked in, and on a work order
 * visited twice that produced 271-hour entries for seven people (WO 54221).
 * The crew clock replaces the guess with an observation — but only if each
 * person gets their OWN entry, so the crew stays independent afterwards.
 *
 * This walks the real scenario:
 *
 *   foreman clocks in 5  ->  one leaves early  ->  a 6th arrives late
 *   ->  foreman clocks out the crew  ->  the early finisher is untouched
 *
 * ⚠ WRITES AND ROLLS BACK. Explicit $tx->rollBack(); unset() would COMMIT.
 *
 * Run: drush php:script web/scripts/test_crew_clock.php
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

// A work order that is open, on a bundle with a generous cap so the cap path
// is tested deliberately rather than tripped by accident.
$woId = (int) $db->query("SELECT wo.id FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE wo.type = 'fall_cleanup' AND st.field_status_target_id NOT IN (1098, 1281, 1504)
  ORDER BY wo.id DESC LIMIT 1")->fetchField();
if (!$woId) {
  print "ABORT: no usable fall_cleanup work order.\n";
  return;
}
$uids = $db->query('SELECT uid FROM {users_field_data} WHERE status = 1 AND uid > 1 ORDER BY uid LIMIT 6')->fetchCol();
if (count($uids) < 6) {
  print "ABORT: need 6 active users to simulate a crew.\n";
  return;
}
$crew = array_slice($uids, 0, 5);
$latecomer = $uids[5];
printf("WO %d (fall_cleanup), crew of %d + 1 late arrival\n", $woId, count($crew));
printf("pre-existing open entries on it: %d\n\n", count($svc->getOpenEntriesOnWo($woId)));

// --- 0. the roster comes from the CREW TYPE, not just scheduling ------------
// Todd, 2026-11-03: "The roster should come from the crew type. Irrigation
// crew for irrigation type of work orders." The WO bundle picks its sign-off
// type, which picks a crew_types entity, whose members come from
// teammate_profile.field_assigned_crew.
$expectCrew = [
  'sprinkler_repair' => 'Irrigation Crew',
  'fall_cleanup' => 'Clean-up Crew',
  'weed_spraying' => 'Spray Crew',
  'lawn_mowing' => 'Lawn Maintenance Crew',
];
foreach ($expectCrew as $bundle => $crewLabel) {
  $sampleWo = (int) $db->query('SELECT id FROM {work_order_field_data} WHERE type = :t ORDER BY id DESC LIMIT 1',
    [':t' => $bundle])->fetchField();
  if (!$sampleWo) {
    continue;
  }
  $roster = $svc->getCrewRosterForWo($sampleWo);
  $inCrew = array_filter($roster, fn($p) => $p['in_crew']);
  // Compare against the crew's real membership rather than a magic number.
  $crewEntity = NULL;
  foreach ($etm->getStorage('crew_types')->loadMultiple() as $c) {
    if ($c->label() === $crewLabel) {
      $crewEntity = $c;
    }
  }
  $expected = $crewEntity ? (int) $db->query('SELECT COUNT(DISTINCT p.uid)
    FROM {profile__field_assigned_crew} a
    JOIN {profile} p ON p.profile_id = a.entity_id
    JOIN {users_field_data} u ON u.uid = p.uid AND u.status = 1
    WHERE a.deleted = 0 AND a.field_assigned_crew_target_id = :c', [':c' => $crewEntity->id()])->fetchField() : -1;
  $ok(sprintf('%s offers the %s', $bundle, $crewLabel), count($inCrew) === $expected,
    count($inCrew) . ' of ' . $expected . ' crew members');
}
// Nobody outside the scheduled set may be pre-ticked — a 19-person crew
// ticked by default turns one careless press into 19 false entries.
$cleanupWo = (int) $db->query("SELECT id FROM {work_order_field_data} WHERE type = 'fall_cleanup' ORDER BY id DESC LIMIT 1")->fetchField();
$cleanupRoster = $svc->getCrewRosterForWo($cleanupWo);
$preticked = array_filter($cleanupRoster, fn($p) => $p['scheduled'] && !$p['open']);
$ok('only the SCHEDULED are pre-ticked, not the whole crew',
  count($preticked) < count($cleanupRoster),
  count($preticked) . ' pre-ticked of ' . count($cleanupRoster) . ' offered');

print "\n";
$tx = $db->startTransaction();
try {
  // --- 1. foreman clocks the crew in ---------------------------------------
  $res = $svc->crewClockIn($woId, $crew, 38.74, -108.07);
  $ok('crew clock-in creates one entry per person', count($res['created']) === count($crew),
    count($res['created']) . ' created, ' . count($res['skipped']) . ' skipped');

  $open = $svc->getOpenEntriesOnWo($woId);
  $ok('  all of them are OPEN', count($open) >= count($crew), count($open) . ' open');

  $sources = [];
  $starts = [];
  foreach ($res['created'] as $e) {
    $sources[(string) $e->get('field_source')->value] = TRUE;
    $starts[(string) $e->get('field_start_time')->value] = TRUE;
  }
  $ok('  tagged source=wo_clock_crew', array_keys($sources) === ['wo_clock_crew'],
    implode(',', array_keys($sources)));
  $ok('  all share one start time', count($starts) === 1, implode(',', array_keys($starts)));

  // Each entry belongs to one person — the thing that makes the rest work.
  $owners = [];
  foreach ($res['created'] as $uid => $e) {
    $owners[] = (int) $e->get('field_teammate')->target_id;
  }
  sort($owners);
  $expected = array_map('intval', $crew);
  sort($expected);
  $ok('  one entry per teammate, no sharing', $owners === $expected);

  // --- 2. pressing it again must not duplicate ------------------------------
  $again = $svc->crewClockIn($woId, $crew, 38.74, -108.07);
  $ok('pressing crew clock-in twice creates nothing new', count($again['created']) === 0,
    count($again['skipped']) . ' skipped as already clocked in');

  // --- 3. one person leaves early ------------------------------------------
  $earlyUid = (int) $crew[0];
  $earlyEntry = $svc->getCurrentEntryOnWo($earlyUid, $woId);
  $svc->clockOut((int) $earlyEntry->id(), 38.74, -108.07);
  $earlyEnd = (string) $etm->getStorage('wo_time_clock')->loadUnchanged($earlyEntry->id())->get('field_end_time')->value;
  $ok('an early finisher can close their own entry', $earlyEnd !== '');
  $ok('  the rest stay open', count($svc->getOpenEntriesOnWo($woId)) === count($crew) - 1,
    count($svc->getOpenEntriesOnWo($woId)) . ' still open');

  // --- 4. a late arrival clocks themselves in -------------------------------
  $lateEntry = $svc->clockIn((int) $latecomer, $woId, 38.74, -108.07);
  $ok('a late arrival gets their own entry', (int) $lateEntry->get('field_teammate')->target_id === (int) $latecomer);
  $ok('  and the ordinary button source', (string) $lateEntry->get('field_source')->value === 'wo_clock_button',
    (string) $lateEntry->get('field_source')->value);

  // ⚠ "Their start differs from the crew's" cannot be asserted directly: the
  // whole test runs inside one second, so both stamps are identical — an
  // artifact of the test, not the behaviour. Nor can the late entry simply be
  // pushed forward: a guard correctly rejects future-dated starts ("Time
  // entries cannot be dated forward"), which is worth knowing and is why this
  // simulates the gap the other way round. Backdate the CREW, leaving the
  // latecomer's real "now" genuinely later, and both stamps stay in the past.
  $crewStart = (new DateTime('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
  foreach ($svc->getOpenEntriesOnWo($woId) as $e) {
    if ((int) $e->get('field_teammate')->target_id === (int) $latecomer) {
      continue;
    }
    $e->set('field_start_time', $crewStart);
    $e->_wo_clock_write = TRUE;
    $e->save();
  }
  $lateStart = (string) $etm->getStorage('wo_time_clock')->loadUnchanged($lateEntry->id())->get('field_start_time')->value;
  $ok('  their start is genuinely later than the crew\'s', $lateStart > $crewStart,
    "crew $crewStart vs late $lateStart");

  // --- 5. foreman clocks the crew out ---------------------------------------
  $before = count($svc->getOpenEntriesOnWo($woId));
  $out = $svc->crewClockOut($woId, 38.74, -108.07);
  $ok('crew clock-out closes everyone still open',
    isset($out['closed']) && count($out['closed']) === $before,
    isset($out['closed']) ? count($out['closed']) . ' of ' . $before : json_encode(array_keys($out)));
  $ok('  nothing is left open', count($svc->getOpenEntriesOnWo($woId)) === 0,
    count($svc->getOpenEntriesOnWo($woId)) . ' open');

  // --- 6. the early finisher's time must be untouched -----------------------
  $earlyNow = (string) $etm->getStorage('wo_time_clock')->loadUnchanged($earlyEntry->id())->get('field_end_time')->value;
  $ok('the early finisher keeps their EARLIER end time', $earlyNow === $earlyEnd,
    $earlyNow === $earlyEnd ? 'unchanged' : "was $earlyEnd, now $earlyNow");

  // And the late arrival's own start survives the crew action untouched —
  // the crew clock-out sets END times and must never reach back to a start.
  $lateNow = (string) $etm->getStorage('wo_time_clock')->loadUnchanged($lateEntry->id())->get('field_start_time')->value;
  $ok('the late arrival keeps their own LATER start', $lateNow === $lateStart,
    $lateNow === $lateStart ? 'unchanged' : "was $lateStart, now $lateNow");

  // --- 6b. THE CAP MUST BE ALL-OR-NOTHING ----------------------------------
  //
  // The single-entry cap is enforced on SAVE. Closing five people one at a
  // time could therefore close three and reject two, leaving a crew half
  // clocked out with no obvious signal — the exact shape of the failure this
  // feature exists to prevent, reproduced at crew scale. Every entry must be
  // tested BEFORE any is written.
  $capCrew = array_slice($uids, 0, 3);
  $svc->crewClockIn($woId, $capCrew, 38.74, -108.07);
  $cap = (float) _wo_total_time_get_max_entry_hours('fall_cleanup');
  $wayBack = (new DateTime('-' . (int) ($cap + 6) . ' hours', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s');
  foreach ($svc->getOpenEntriesOnWo($woId) as $e) {
    $e->set('field_start_time', $wayBack);
    $e->_wo_clock_write = TRUE;
    $e->save();
  }
  $openBefore = count($svc->getOpenEntriesOnWo($woId));
  $capRes = $svc->crewClockOut($woId, 38.74, -108.07);
  $ok('an over-cap crew clock-out asks instead of writing', isset($capRes['needs_confirm']),
    isset($capRes['needs_confirm']) ? count($capRes['needs_confirm']) . ' over the ' . $cap . 'h cap' : 'it just closed them');
  $ok('  and NOTHING was written — no partial close',
    count($svc->getOpenEntriesOnWo($woId)) === $openBefore,
    count($svc->getOpenEntriesOnWo($woId)) . ' still open of ' . $openBefore);
  $confirmed = $svc->crewClockOut($woId, 38.74, -108.07, TRUE);
  $ok('  confirming closes them all', isset($confirmed['closed']) && count($confirmed['closed']) === $openBefore,
    isset($confirmed['closed']) ? count($confirmed['closed']) . ' closed' : 'still refused');
  $overrides = 0;
  foreach ($svc->getOpenEntriesOnWo($woId) as $e) {
    $overrides++;
  }
  $ok('  nothing left open afterwards', $overrides === 0, $overrides . ' open');

  // --- 7. clocking out an empty WO is refused, not silently "successful" ----
  try {
    $svc->crewClockOut($woId);
    $ok('clocking out with nobody open is refused', FALSE, 'it returned normally');
  }
  catch (\Throwable $e) {
    $ok('clocking out with nobody open is refused', TRUE, mb_substr($e->getMessage(), 0, 40));
  }
}
finally {
  // Explicit. unset() would COMMIT the whole simulated crew day.
  $tx->rollBack();
  print "\nrolled back.\n";
}

// Prove the rollback took rather than trusting it.
$leftover = (int) $db->query("SELECT COUNT(*) FROM {wo_time_clock__field_source}
  WHERE deleted = 0 AND field_source_value = 'wo_clock_crew'")->fetchField();
$ok('no crew entries survive the rollback', $leftover === 0, "$leftover found");

printf("\n%d passed, %d failed\n", $pass, $fail);
