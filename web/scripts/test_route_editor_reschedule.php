<?php

/**
 * @file
 * Reversible test of the Route Editor date-move endpoint and its lock rule.
 *
 * Every write is undone before exit, including the WO status flips used to
 * exercise the guard. Run: drush php:script web/scripts/test_route_editor_reschedule.php
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

$post = function (array $payload) use ($controller) {
  $r = Request::create('/teammates/calendar/route-editor/reschedule', 'POST', [], [], [], [], json_encode($payload));
  return json_decode($controller->reschedule($r)->getContent(), TRUE);
};

// --- Pick a live scheduling record on a NON-terminal work order. -------------
$row = $db->select('scheduling__field_work_order', 'swo')
  ->fields('swo', ['entity_id', 'field_work_order_target_id'])
  ->condition('swo.deleted', 0);
$row->join('scheduling__field_date', 'fd', 'fd.entity_id = swo.entity_id AND fd.deleted = 0');
$row->addField('fd', 'field_date_value', 'date_ts');
$row->join('work_order__field_status', 'wos', 'wos.entity_id = swo.field_work_order_target_id AND wos.deleted = 0');
$row->condition('wos.field_status_target_id', [1097, 1283, 1281, 1504, 1098], 'NOT IN');
$row->addField('wos', 'field_status_target_id', 'status_tid');
$row->range(0, 1);
$rec = $row->execute()->fetchObject();
if (!$rec) { print "No editable scheduling record found — cannot test.\n"; return; }

$sid = (int) $rec->entity_id;
$woId = (int) $rec->field_work_order_target_id;
$origTs = (int) $rec->date_ts;
$origStatus = (int) $rec->status_tid;
printf("\nUsing scheduling #%d (WO %d, status %d, %s)\n\n", $sid, $woId, $origStatus,
  (new \DateTime('@' . $origTs))->setTimezone($tz)->format('Y-m-d'));

$sStorage = $etm->getStorage('scheduling');
$wStorage = $etm->getStorage('work_order');
$noteCountBefore = (int) $db->select('wo_notes_field_data', 'n')->countQuery()->execute()->fetchField();

// === 1. A normal move lands on the target day, all-day, duration 1439. ======
$target = (new \DateTime('@' . $origTs))->setTimezone($tz)->modify('+3 days')->format('Y-m-d');
$expectTs = (\DateTime::createFromFormat('!Y-m-d', $target, $tz))->setTime(0, 0, 0)->getTimestamp();
$res = $post(['scheduling_ids' => [$sid], 'date' => $target]);
$sStorage->resetCache([$sid]);
$e = $sStorage->load($sid);
$check('move writes the target day', (int) $e->get('field_date')->value === $expectTs,
  'got ' . (new \DateTime('@' . (int) $e->get('field_date')->value))->setTimezone($tz)->format('Y-m-d H:i') . " expected $target 00:00");
$check('move keeps the all-day shape (duration 1439)', (int) $e->get('field_date')->duration === 1439,
  'duration=' . $e->get('field_date')->duration);
$check('move reports updated=1', ($res['updated'] ?? 0) === 1 && empty($res['blocked']), json_encode($res));

// The legacy daterange is back-filled by wo_schedule's presave, not by us.
$legacy = $db->select('scheduling__field_scheduled_date_and_time', 'l')
  ->fields('l', ['field_scheduled_date_and_time_value'])
  ->condition('l.entity_id', $sid)->condition('l.deleted', 0)
  ->execute()->fetchField();
$check('wo_schedule synced the legacy daterange', $legacy && str_starts_with((string) $legacy, $target),
  'legacy=' . var_export($legacy, TRUE) . " expected to start $target");

// A move is auditable on the work order.
$noteCountAfter = (int) $db->select('wo_notes_field_data', 'n')->countQuery()->execute()->fetchField();
$check('the move left an audit note on the WO', $noteCountAfter > $noteCountBefore,
  "notes $noteCountBefore -> $noteCountAfter");

// === 2. Bad dates are refused outright. ====================================
foreach ([['banana', 'non-date'], ['2026-02-31', 'impossible date'], ['', 'empty']] as [$bad, $label]) {
  $before = (int) $sStorage->load($sid)->get('field_date')->value;
  $res = $post(['scheduling_ids' => [$sid], 'date' => $bad]);
  $sStorage->resetCache([$sid]);
  $after = (int) $sStorage->load($sid)->get('field_date')->value;
  $check("refuses a $label", ($res['ok'] ?? TRUE) === FALSE && $before === $after, json_encode($res));
}

// === 3. THE RULE: a finished work order is never rescheduled. ==============
$dateBefore = (int) $sStorage->load($sid)->get('field_date')->value;
foreach ([1097 => 'Complete', 1281 => 'Invoiced', 1504 => 'Paid', 1283 => 'Warrantied', 1098 => 'Canceled'] as $tid => $name) {
  $wo = $wStorage->load($woId);
  $wo->set('field_status', $tid);
  $wo->_skip_invoiced_guard = TRUE;
  $wo->save();

  $far = (new \DateTime('@' . $dateBefore))->setTimezone($tz)->modify('+10 days')->format('Y-m-d');
  $res = $post(['scheduling_ids' => [$sid], 'date' => $far]);
  $sStorage->resetCache([$sid]);
  $now = (int) $sStorage->load($sid)->get('field_date')->value;
  $check("$name ($tid) is NOT rescheduled", $now === $dateBefore && count($res['blocked'] ?? []) === 1,
    'blocked=' . json_encode($res['blocked'] ?? []) . ' updated=' . ($res['updated'] ?? '?'));

  // Same rule on the crew-assignment endpoint (same exposure, same rule).
  $a = Request::create('/x', 'POST', [], [], [], [], json_encode(['scheduling_ids' => [$sid], 'uid' => 0]));
  $ares = json_decode($controller->assign($a)->getContent(), TRUE);
  $check("$name ($tid) is NOT reassigned", count($ares['blocked'] ?? []) === 1 && ($ares['updated'] ?? 0) === 0,
    'blocked=' . json_encode($ares['blocked'] ?? []));
}

// --- Restore everything. ----------------------------------------------------
$wo = $wStorage->load($woId);
$wo->set('field_status', $origStatus);
$wo->_skip_invoiced_guard = TRUE;
$wo->save();
$e = $sStorage->load($sid);
$e->set('field_date', ['value' => $origTs, 'end_value' => $origTs + 86340, 'duration' => 1439]);
if ($e->hasField('field_notify_assigned_teammate')) { $e->set('field_notify_assigned_teammate', FALSE); }
$e->save();
$sStorage->resetCache([$sid]);
$restored = (int) $sStorage->load($sid)->get('field_date')->value;
$wStorage->resetCache([$woId]);
$statusBack = (int) $wStorage->load($woId)->get('field_status')->target_id;
$check('restored the original date', $restored === $origTs);
$check('restored the original WO status', $statusBack === $origStatus, "status=$statusBack expected=$origStatus");

printf("\n%d passed, %d failed\n", $pass, $fail);
