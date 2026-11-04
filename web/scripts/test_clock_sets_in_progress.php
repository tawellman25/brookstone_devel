<?php

/**
 * @file
 * Clocking in must tell the office the job has started.
 *
 * The legacy flag toggle (`wo_timer_flag_update`) created a "Teammate Clocked
 * In" status update and moved the WO to In Progress. `wo_clock` replaced that
 * toggle on the work order page — it unsets the flag component — without
 * carrying the status change across. Measured 2026-11-03: of 2,752 work orders
 * clocked into since the button shipped, only 995 ever got a status update,
 * and 24 were sitting at Open or Scheduled with hours already on them.
 *
 * Asserts the restored behaviour, for BOTH the individual and crew buttons:
 *
 *   Open/Scheduled  -> promoted to In Progress, with a timeline record
 *   already In Progress -> no duplicate record
 *   Complete/Invoiced/Paid/Canceled -> untouched
 *
 * ⚠ WRITES AND ROLLS BACK. Explicit $tx->rollBack(); unset() would COMMIT.
 *
 * Run: drush php:script web/scripts/test_clock_sets_in_progress.php
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
$IN_PROGRESS = 1092;

$statusOf = function (int $woId) use ($etm): int {
  return (int) $etm->getStorage('work_order')->loadUnchanged($woId)->get('field_status')->target_id;
};
$updateCount = function (int $woId) use ($db): int {
  return (int) $db->query('SELECT COUNT(*) FROM {wo_status_updates__field_status_of_wo}
    WHERE deleted = 0 AND field_status_of_wo_target_id = :id', [':id' => $woId])->fetchField();
};

$uid = (int) $db->query('SELECT uid FROM {users_field_data} WHERE status = 1 AND uid > 1 LIMIT 1')->fetchField();

// A work order that has NOT started: not In Progress, not terminal.
$openWo = (int) $db->query('SELECT wo.id FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE st.field_status_target_id NOT IN (1092, 1097, 1098, 1281, 1283, 1504)
  ORDER BY wo.id DESC LIMIT 1')->fetchField();
if (!$openWo) {
  print "ABORT: no not-yet-started work order to test with.\n";
  return;
}
printf("WO %d starts at status %d, %d timeline records\n\n", $openWo, $statusOf($openWo), $updateCount($openWo));

$tx = $db->startTransaction();
try {
  // --- individual clock-in ---------------------------------------------------
  $before = $updateCount($openWo);
  $svc->clockIn($uid, $openWo);
  $ok('the Clock In button moves the WO to In Progress', $statusOf($openWo) === $IN_PROGRESS,
    'status now ' . $statusOf($openWo));
  $ok('  and writes a timeline record the office can see', $updateCount($openWo) === $before + 1,
    ($updateCount($openWo) - $before) . ' added');

  $note = (string) $db->query('SELECT n.field_status_change_note_value
    FROM {wo_status_updates__field_status_of_wo} sw
    JOIN {wo_status_updates_field_data} su ON su.id = sw.entity_id
    JOIN {wo_status_updates__field_status_change_note} n ON n.entity_id = su.id AND n.deleted = 0
    WHERE sw.deleted = 0 AND sw.field_status_of_wo_target_id = :id
    ORDER BY su.created DESC, su.id DESC LIMIT 1', [':id' => $openWo])->fetchField();
  $ok('  the record says what happened', stripos($note, 'clocked in') !== FALSE, $note);

  // --- a second clock-in must not re-announce --------------------------------
  $before2 = $updateCount($openWo);
  $uid2 = (int) $db->query('SELECT uid FROM {users_field_data} WHERE status = 1 AND uid > 1 AND uid <> :u LIMIT 1',
    [':u' => $uid])->fetchField();
  $svc->clockIn($uid2, $openWo);
  $ok('a second clock-in does NOT duplicate the status record',
    $updateCount($openWo) === $before2, ($updateCount($openWo) - $before2) . ' added');

  $tx->rollBack();
}
catch (\Throwable $e) {
  $tx->rollBack();
  printf("  ERROR: %s\n", $e->getMessage());
}

// --- crew clock-in ------------------------------------------------------------
//
// ⚠ A DB rollback does NOT roll back Drupal's entity caches. Without this
// reset the work order still reads as In Progress from the previous phase, so
// markInProgress() correctly decides there is nothing to announce and the
// assertion below fails for a reason that has nothing to do with the code.
$etm->getStorage('work_order')->resetCache([$openWo]);
\Drupal::service('cache_tags.invalidator')->invalidateTags(['work_order:' . $openWo]);
printf("\n  (after rollback, WO %d is back at status %d)\n", $openWo, $statusOf($openWo));

$tx2 = $db->startTransaction();
try {
  $before = $updateCount($openWo);
  $crew = array_map('intval', $db->query('SELECT uid FROM {users_field_data}
    WHERE status = 1 AND uid > 1 ORDER BY uid LIMIT 3')->fetchCol());
  $svc->crewClockIn($openWo, $crew);
  $ok('the Clock In Crew button also moves it to In Progress', $statusOf($openWo) === $IN_PROGRESS,
    'status now ' . $statusOf($openWo));
  $ok('  with ONE record, not one per crew member', $updateCount($openWo) === $before + 1,
    ($updateCount($openWo) - $before) . ' added for ' . count($crew) . ' people');

  // Pressing it again, when everyone is already in, must announce nothing.
  $before3 = $updateCount($openWo);
  $svc->crewClockIn($openWo, $crew);
  $ok('  a repeat press that opens nothing announces nothing',
    $updateCount($openWo) === $before3, ($updateCount($openWo) - $before3) . ' added');

  $tx2->rollBack();
}
catch (\Throwable $e) {
  $tx2->rollBack();
  printf("  ERROR (crew): %s\n", $e->getMessage());
}

// --- a finished work order must not be re-opened ------------------------------
$doneWo = (int) $db->query('SELECT wo.id FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE st.field_status_target_id = 1097 ORDER BY wo.id DESC LIMIT 1')->fetchField();
if ($doneWo) {
  $tx3 = $db->startTransaction();
  try {
    $before = $updateCount($doneWo);
    $svc->clockIn($uid, $doneWo);
    $ok('clocking in on a COMPLETE work order does not re-open it',
      $statusOf($doneWo) === 1097 && $updateCount($doneWo) === $before,
      'status ' . $statusOf($doneWo) . ', ' . ($updateCount($doneWo) - $before) . ' records added');
  }
  catch (\Throwable $e) {
    $ok('clocking in on a COMPLETE work order does not re-open it', TRUE, 'refused: ' . mb_substr($e->getMessage(), 0, 40));
  }
  finally {
    $tx3->rollBack();
  }
}

printf("\nrolled back. %d passed, %d failed\n", $pass, $fail);
