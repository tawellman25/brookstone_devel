<?php

/**
 * @file
 * Reversible test of the Move-and-assign SEQUENCE the combined button drives.
 *
 * The button itself is client-side, so what is tested here is the server
 * behaviour it depends on:
 *
 *   1. assign then reschedule on the same stop both apply, and the stop ends up
 *      with BOTH the new crew member and the new date;
 *   2. on a stop whose work order is finished, the ASSIGN half is refused — which
 *      is what makes "stop before moving" the right order, because the move is
 *      then never attempted;
 *   3. a refused assign leaves the date untouched, so stopping really does mean
 *      nothing moved.
 *
 * Every write is undone before exit, including the WO status flip used to
 * exercise the guard.
 *
 * Run: drush php:script web/scripts/test_route_editor_move_and_assign.php
 */

use Drupal\bos_scheduling\Controller\RouteEditorController;
use Symfony\Component\HttpFoundation\Request;

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$tz = new \DateTimeZone(date_default_timezone_get());
$controller = RouteEditorController::create(\Drupal::getContainer());
$pass = 0; $fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  — $detail" : '');
  $ok ? $pass++ : $fail++;
};
$assign = function (array $payload) use ($controller) {
  $r = Request::create('/x/assign', 'POST', [], [], [], [], json_encode($payload));
  return json_decode($controller->assign($r)->getContent(), TRUE);
};
$move = function (array $payload) use ($controller) {
  $r = Request::create('/x/reschedule', 'POST', [], [], [], [], json_encode($payload));
  return json_decode($controller->reschedule($r)->getContent(), TRUE);
};
// Mirrors the JS actionClean(): skipped is a no-op, not a refusal.
$clean = function (?array $b): bool {
  $b = $b ?: [];
  if (!empty($b['blocked']) || !empty($b['errors'])) { return FALSE; }
  return !empty($b['ok']) || !empty($b['updated']) || !empty($b['skipped']);
};

// --- a live scheduling record on a NON-terminal work order -------------------
$q = $db->select('scheduling__field_work_order', 'swo')
  ->fields('swo', ['entity_id', 'field_work_order_target_id'])
  ->condition('swo.deleted', 0);
$q->join('scheduling__field_date', 'fd', 'fd.entity_id = swo.entity_id AND fd.deleted = 0');
$q->addField('fd', 'field_date_value', 'date_ts');
$q->join('work_order__field_status', 'wos', 'wos.entity_id = swo.field_work_order_target_id AND wos.deleted = 0');
$q->condition('wos.field_status_target_id', [1097, 1283, 1281, 1504, 1098], 'NOT IN');
$q->addField('wos', 'field_status_target_id', 'status_tid');
$q->range(0, 1);
$rec = $q->execute()->fetchObject();
if (!$rec) { print "No editable scheduling record found — cannot test.\n"; return; }

$sid = (int) $rec->entity_id;
$woId = (int) $rec->field_work_order_target_id;
$origTs = (int) $rec->date_ts;
$origStatus = (int) $rec->status_tid;
$sStorage = $etm->getStorage('scheduling');
$wStorage = $etm->getStorage('work_order');
$orig = $sStorage->load($sid);
$origUid = (int) ($orig->get('field_assigned_to')->target_id ?? 0);

printf("\nscheduling #%d (WO %d, status %d, %s, crew uid %d)\n\n", $sid, $woId, $origStatus,
  (new \DateTime('@' . $origTs))->setTimezone($tz)->format('Y-m-d'), $origUid);

// A crew member who is NOT the current one.
$uids = $db->select('user__roles', 'r')->fields('r', ['entity_id'])
  ->condition('r.roles_target_id', 'teammates')->condition('r.entity_id', $origUid, '<>')
  ->range(0, 1)->execute()->fetchCol();
if (!$uids) { print "No alternative teammate found — cannot test.\n"; return; }
$newUid = (int) reset($uids);
$newDate = (new \DateTime('@' . $origTs))->setTimezone($tz)->modify('+9 days')->format('Y-m-d');
printf("target: crew uid %d, date %s\n\n", $newUid, $newDate);

try {
  // --- 1. the happy path: assign, then move --------------------------------
  $a = $assign(['scheduling_ids' => [$sid], 'uid' => $newUid]);
  $check('assign half applied', $clean($a), 'updated=' . ($a['updated'] ?? 0));
  $m = $move(['scheduling_ids' => [$sid], 'date' => $newDate]);
  $check('move half applied', $clean($m), 'updated=' . ($m['updated'] ?? 0));

  $sStorage->resetCache([$sid]);
  $after = $sStorage->load($sid);
  $gotUid = (int) ($after->get('field_assigned_to')->target_id ?? 0);
  $gotDate = (new \DateTime('@' . (int) $after->get('field_date')->value))->setTimezone($tz)->format('Y-m-d');
  $check('BOTH stuck: crew is the new one', $gotUid === $newUid, "uid=$gotUid expected=$newUid");
  $check('BOTH stuck: date is the new one', $gotDate === $newDate, "date=$gotDate expected=$newDate");

  // Both halves must leave their own audit note on the work order.
  // The note field is field_status_change_note and the WO link is
  // field_status_of_wo — looked up from the field definitions rather than
  // guessed, after a first version invented wo_status_updates__field_note and
  // threw.
  $notes = $db->select('wo_status_updates__field_status_change_note', 'n')
    ->fields('n', ['field_status_change_note_value']);
  $notes->join('wo_status_updates__field_status_of_wo', 'w', 'w.entity_id = n.entity_id AND w.deleted = 0');
  $notes->condition('w.field_status_of_wo_target_id', $woId)->condition('n.deleted', 0);
  $all = implode(' || ', $notes->execute()->fetchCol());
  $check('an assignment note was written', stripos($all, 'assign') !== FALSE);
  $check('a reschedule note was written', stripos($all, 'schedul') !== FALSE);

  // --- 2. put it back, then prove the refusal order -----------------------
  $move(['scheduling_ids' => [$sid], 'date' => (new \DateTime('@' . $origTs))->setTimezone($tz)->format('Y-m-d')]);
  $assign(['scheduling_ids' => [$sid], 'uid' => $origUid]);

  $wo = $wStorage->load($woId);
  $wo->set('field_status', 1097)->save();   // Complete
  $sStorage->resetCache([$sid]);
  $beforeTs = (int) $sStorage->load($sid)->get('field_date')->value;

  $a2 = $assign(['scheduling_ids' => [$sid], 'uid' => $newUid]);
  $check('finished work: assign is REFUSED', !$clean($a2), 'blocked=' . json_encode($a2['blocked'] ?? []));
  $check('  …so the JS stops and never calls move', !$clean($a2));

  $sStorage->resetCache([$sid]);
  $stillTs = (int) $sStorage->load($sid)->get('field_date')->value;
  $check('refused assign left the DATE untouched', $stillTs === $beforeTs,
    'stopping really does mean nothing moved');
  $stillUid = (int) ($sStorage->load($sid)->get('field_assigned_to')->target_id ?? 0);
  $check('refused assign left the CREW untouched', $stillUid === $origUid, "uid=$stillUid expected=$origUid");

  // And the move half would refuse it too, so neither order can slip it through.
  $m2 = $move(['scheduling_ids' => [$sid], 'date' => $newDate]);
  $check('finished work: move is also refused', !$clean($m2), 'blocked=' . json_encode($m2['blocked'] ?? []));
}
finally {
  // --- restore everything -------------------------------------------------
  $wo = $wStorage->load($woId);
  $wo->set('field_status', $origStatus)->save();
  $move(['scheduling_ids' => [$sid], 'date' => (new \DateTime('@' . $origTs))->setTimezone($tz)->format('Y-m-d')]);
  $assign(['scheduling_ids' => [$sid], 'uid' => $origUid]);
  $sStorage->resetCache([$sid]);
  $final = $sStorage->load($sid);
  $fUid = (int) ($final->get('field_assigned_to')->target_id ?? 0);
  $fTs = (int) $final->get('field_date')->value;
  $check('restored the original crew', $fUid === $origUid, "uid=$fUid expected=$origUid");
  $check('restored the original date', $fTs === $origTs);
  $check('restored the original WO status', (int) ($wStorage->load($woId)->get('field_status')->target_id ?? 0) === $origStatus);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
