<?php

/**
 * @file
 * DEV-ONLY test: one WO timeline entry per scheduling change. Writes, then
 * rolls back (throwaway WO + scheduling records + the updates/notes they made).
 *
 *   ddev drush php:script web/scripts/test_wo_schedule_timeline.php
 *
 * Never run on live.
 */

use Drupal\Core\Datetime\DrupalDateTime;

$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  PASS ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
const RUSSELL = 2826;
const CHRIS = 77735;
$tz = new \DateTimeZone('America/Denver');

$pid = $db->query("SELECT pz.entity_id FROM properties__field_zipcode_reference pz JOIN properties__field_nickname n ON n.entity_id = pz.entity_id LIMIT 1")->fetchField();
$svc_tid = (int) $db->query("SELECT b.entity_id FROM taxonomy_term__field_service_bundle b WHERE b.field_service_bundle_value = 'sprinkler_check_up' LIMIT 1")->fetchField();

$notes_for = static function (int $wo_id) use ($etm): array {
  $ids = $etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)->condition('field_status_of_wo', $wo_id)->sort('id')->execute();
  $out = [];
  foreach ($etm->getStorage('wo_status_updates')->loadMultiple($ids) as $u) {
    $out[] = ['id' => (int) $u->id(), 'note' => (string) $u->get('field_status_change_note')->value, 'status' => (int) $u->get('field_status')->target_id, 'scheduled' => $u->hasField('field_scheduled') ? (int) $u->get('field_scheduled')->value : NULL];
  }
  return $out;
};
$last = static fn(array $n) => $n ? end($n) : ['note' => '(none)', 'status' => 0, 'scheduled' => 0];
$allday = static function (string $ymd) use ($tz): array {
  $start = new DrupalDateTime($ymd . ' 00:00:00', $tz);
  $end = new DrupalDateTime($ymd . ' 23:59:59', $tz);
  $su = clone $start; $su->setTimezone(new \DateTimeZone('UTC'));
  $eu = clone $end; $eu->setTimezone(new \DateTimeZone('UTC'));
  return [
    'field_date' => ['value' => $start->getTimestamp(), 'end_value' => $end->getTimestamp(), 'duration' => 1439],
    'field_scheduled_date_and_time' => ['value' => $su->format('Y-m-d\TH:i:s'), 'end_value' => $eu->format('Y-m-d\TH:i:s')],
  ];
};

$wos = []; $scheds = [];
$new_wo = static function () use ($etm, $pid, $svc_tid, &$wos) {
  $wo = $etm->getStorage('work_order')->create(['type' => 'sprinkler_check_up', 'uid' => 1, 'field_property' => $pid, 'field_service' => $svc_tid, 'field_status' => 1089, 'field_invoiced' => 0]);
  $wo->save(); $wo->set('title', ''); $wo->save();
  $wos[] = (int) $wo->id();
  return (int) $wo->id();
};

try {
  echo "== A. insert: all-day date + assignee ==\n";
  $wo1 = $new_wo();
  $s = $etm->getStorage('scheduling')->create(['type' => 'work_order', 'title' => 'Scheduled', 'uid' => 1, 'field_work_order' => $wo1, 'field_assigned_to' => RUSSELL, 'field_scheduled' => 1, 'field_scheduled_firm' => 0] + $allday('2026-11-11'));
  $s->save(); $scheds[] = $s;
  $n = $notes_for($wo1);
  $ok(count($n) === 1, 'exactly ONE timeline entry (was three) — ' . count($n));
  $ok($last($n)['note'] === 'Scheduled for Wed 11/11/2026 — assigned to Russell Akers', 'note: ' . $last($n)['note']);
  $ok($last($n)['status'] === 1091 && in_array($last($n)['scheduled'], [1, NULL], TRUE), 'status Scheduled (1091)' . ($last($n)['scheduled'] === NULL ? ' (wo_status_updates has no field_scheduled on this env — create() ignores the key)' : ', scheduled flag 1'));
  $wo = $etm->getStorage('work_order')->load($wo1);
  $ok((int) $wo->get('field_status')->target_id === 1091, 'WO moved to Scheduled (field_scheduled only on bundles that carry it: ' . ($wo->hasField('field_scheduled') ? (int) $wo->get('field_scheduled')->value : 'n/a') . ')');
  $wn = $etm->getStorage('wo_notes')->getQuery()->accessCheck(FALSE)->condition('field_work_order', $wo1)->count()->execute();
  $ok((int) $wn === 1, "schedule-change WO note still written once (wo_notes: $wn)");

  echo "== B. update: reassign only ==\n";
  $s = $etm->getStorage('scheduling')->load($s->id());
  $s->set('field_assigned_to', CHRIS)->save();
  $n = $notes_for($wo1);
  $ok(count($n) === 2, 'one more entry — ' . count($n));
  $ok($last($n)['note'] === 'Reassigned to Chris Palecki for Wed 11/11/2026', 'note: ' . $last($n)['note']);

  echo "== C. update: reschedule only ==\n";
  $s = $etm->getStorage('scheduling')->load($s->id());
  foreach ($allday('2026-11-13') as $f => $v) { $s->set($f, $v); }
  $s->save();
  $n = $notes_for($wo1);
  $ok(count($n) === 3, 'one more entry — ' . count($n));
  $ok($last($n)['note'] === 'Rescheduled to Fri 11/13/2026 (Chris Palecki)', 'note: ' . $last($n)['note']);

  echo "== D. update: clear the assignee ==\n";
  $s = $etm->getStorage('scheduling')->load($s->id());
  $s->set('field_assigned_to', NULL)->save();
  $n = $notes_for($wo1);
  $ok(count($n) === 4, 'one more entry — ' . count($n));
  $ok($last($n)['note'] === 'Unassigned (was Chris Palecki), still scheduled for Fri 11/13/2026', 'note: ' . $last($n)['note']);
  $ok($last($n)['status'] === 1091, 'still Scheduled (dated)');

  echo "== E. update: nothing changed ==\n";
  $s = $etm->getStorage('scheduling')->load($s->id());
  $s->set('field_scheduling_note', 'just a note edit')->save();
  $n = $notes_for($wo1);
  $ok(count($n) === 4, 'no new timeline entry on a no-change save — ' . count($n));

  echo "== F. update: both date and assignee ==\n";
  $s = $etm->getStorage('scheduling')->load($s->id());
  foreach ($allday('2026-11-16') as $f => $v) { $s->set($f, $v); }
  $s->set('field_assigned_to', RUSSELL)->save();
  $n = $notes_for($wo1);
  $ok(count($n) === 5 && $last($n)['note'] === 'Rescheduled to Mon 11/16/2026 — reassigned to Russell Akers', 'note: ' . $last($n)['note']);

  echo "== G. insert: date only ==\n";
  $wo2 = $new_wo();
  $s2 = $etm->getStorage('scheduling')->create(['type' => 'work_order', 'title' => 'Scheduled', 'uid' => 1, 'field_work_order' => $wo2, 'field_scheduled' => 1] + $allday('2026-11-12'));
  $s2->save(); $scheds[] = $s2;
  $n = $notes_for($wo2);
  $ok(count($n) === 1 && $last($n)['note'] === 'Scheduled for Thu 11/12/2026 (unassigned)' && $last($n)['status'] === 1091, 'note: ' . $last($n)['note']);

  echo "== H. insert: assignee only (field_date defaults to today, so it is dated after all) ==\n";
  $wo3 = $new_wo();
  $s3 = $etm->getStorage('scheduling')->create(['type' => 'work_order', 'title' => 'Assigned', 'uid' => 1, 'field_work_order' => $wo3, 'field_assigned_to' => RUSSELL]);
  $s3->save(); $scheds[] = $s3;
  $n = $notes_for($wo3);
  // smart-date field_date carries a default (today), and presave syncs it into
  // the daterange — so a record saved with only an assignee is still dated and
  // the "no date yet" branch is a guard, not a daily path. One entry either way.
  $today = (new DrupalDateTime('now', $tz))->format('D m/d/Y');
  $ok(count($n) === 1 && str_starts_with($last($n)['note'], 'Scheduled for ') && str_ends_with($last($n)['note'], '— assigned to Russell Akers') && $last($n)['status'] === 1091, 'ONE entry, dated by default: ' . $last($n)['note'] . ' / status ' . $last($n)['status']);
  $ok(str_contains($last($n)['note'], $today), "the default date is today ($today)");

  echo "== I. insert: timed stop (9:30 AM MT) ==\n";
  $wo4 = $new_wo();
  $start = new DrupalDateTime('2026-11-11 09:30:00', $tz); $end = (clone $start)->modify('+45 minutes');
  $su = (clone $start)->setTimezone(new \DateTimeZone('UTC')); $eu = (clone $end)->setTimezone(new \DateTimeZone('UTC'));
  $s4 = $etm->getStorage('scheduling')->create(['type' => 'work_order', 'title' => 'Scheduled', 'uid' => 1, 'field_work_order' => $wo4, 'field_assigned_to' => CHRIS, 'field_scheduled' => 1,
    'field_date' => ['value' => $start->getTimestamp(), 'end_value' => $end->getTimestamp(), 'duration' => 45],
    'field_scheduled_date_and_time' => ['value' => $su->format('Y-m-d\TH:i:s'), 'end_value' => $eu->format('Y-m-d\TH:i:s')]]);
  $s4->save(); $scheds[] = $s4;
  $n = $notes_for($wo4);
  $ok(count($n) === 1 && $last($n)['note'] === 'Scheduled for Wed 11/11/2026 9:30 AM — assigned to Chris Palecki', 'note: ' . $last($n)['note']);

  echo "== J. complete WO is never touched ==\n";
  $wo5 = $new_wo();
  $etm->getStorage('work_order')->load($wo5)->set('field_status', 1097)->save();
  $before = count($notes_for($wo5));
  $s5 = $etm->getStorage('scheduling')->create(['type' => 'work_order', 'title' => 'Scheduled', 'uid' => 1, 'field_work_order' => $wo5, 'field_assigned_to' => RUSSELL, 'field_scheduled' => 1] + $allday('2026-11-12'));
  $s5->save(); $scheds[] = $s5;
  $ok(count($notes_for($wo5)) === $before, 'no entry on a Complete work order');
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
  $fail++;
}
finally {
  echo "== rollback ==\n";
  foreach ($scheds as $s) { $etm->getStorage('scheduling')->load($s->id())?->delete(); }
  foreach ($wos as $wo_id) {
    foreach (['wo_status_updates' => 'field_status_of_wo', 'wo_notes' => 'field_work_order'] as $type => $field) {
      $ids = $etm->getStorage($type)->getQuery()->accessCheck(FALSE)->condition($field, $wo_id)->execute();
      foreach ($etm->getStorage($type)->loadMultiple($ids) as $e) { $e->delete(); }
    }
    $etm->getStorage('work_order')->load($wo_id)?->delete();
  }
  $left = $wos ? $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)->condition('id', $wos, 'IN')->count()->execute() : 0;
  $ok((int) $left === 0, 'throwaway work orders, schedules, updates and notes gone');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
