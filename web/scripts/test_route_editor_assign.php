<?php

/**
 * @file
 * Smoke-test the Route Editor crew-assignment backend (roster + assign()),
 * with a fully reversible reassignment on a real scheduling record.
 * Run: ddev drush php:script web/scripts/test_route_editor_assign.php
 */

use Symfony\Component\HttpFoundation\Request;
use Drupal\bos_scheduling\Controller\RouteEditorController;

$c = RouteEditorController::create(\Drupal::getContainer());

$m = new ReflectionMethod($c, 'roster');
$m->setAccessible(TRUE);
$roster = $m->invoke($c);
print 'ROSTER: ' . count($roster) . " active teammates\n";
if ($roster) {
  print '  first: ' . $roster[0]['name'] . ' (uid ' . $roster[0]['uid'] . ")\n";
}
if (count($roster) < 2) {
  print "need >=2 teammates to test — abort\n";
  return;
}

$db = \Drupal::database();
// A real, recent scheduled record: has an assignment AND a schedule date.
$q = $db->select('scheduling__field_assigned_to', 'a');
$q->fields('a', ['entity_id']);
$q->isNotNull('a.field_assigned_to_target_id');
$q->join('scheduling__field_date', 'd', 'd.entity_id = a.entity_id AND d.deleted = 0');
$q->join('scheduling__field_work_order', 'w', 'w.entity_id = a.entity_id AND w.deleted = 0');
$q->condition('w.field_work_order_target_id', 1000, '>');
$q->orderBy('a.entity_id', 'DESC');
$q->range(0, 1);
$sid = $q->execute()->fetchField();
if (!$sid) {
  print "no assigned scheduling record found — abort\n";
  return;
}

$storage = \Drupal::entityTypeManager()->getStorage('scheduling');
$e = $storage->load($sid);
$orig = (int) ($e->get('field_assigned_to')->target_id ?? 0);
$woid = (int) ($e->get('field_work_order')->target_id ?? 0);
print "TEST scheduling #$sid  wo #$woid  current uid=$orig\n";

$target = 0;
foreach ($roster as $t) {
  if ((int) $t['uid'] !== $orig) { $target = (int) $t['uid']; break; }
}
print "reassign -> uid=$target\n";

$countNotes = function () use ($woid) {
  $ids = \Drupal::entityQuery('wo_status_updates')
    ->condition('field_status_of_wo', $woid)
    ->accessCheck(FALSE)
    ->execute();
  return count($ids);
};
$before = $countNotes();

$req = Request::create('/x', 'POST', [], [], [], [], json_encode(['scheduling_ids' => [(int) $sid], 'uid' => $target]));
$resp = $c->assign($req);
print 'RESP(' . $resp->getStatusCode() . '): ' . $resp->getContent() . "\n";

$e2 = $storage->loadUnchanged($sid);
$now = (int) ($e2->get('field_assigned_to')->target_id ?? 0);
$after = $countNotes();
print "AFTER uid=$now (expected $target) | wo_status_updates $before -> $after " . ($after > $before ? '(audit note written ✓)' : '(NO note ✗)') . "\n";

// Idempotency: assigning the same uid again should skip.
$resp2 = $c->assign(Request::create('/x', 'POST', [], [], [], [], json_encode(['scheduling_ids' => [(int) $sid], 'uid' => $target])));
print 'RE-ASSIGN same uid RESP: ' . $resp2->getContent() . " (expect updated=0, skipped=1)\n";

// Bad crew id rejected.
$respBad = $c->assign(Request::create('/x', 'POST', [], [], [], [], json_encode(['scheduling_ids' => [(int) $sid], 'uid' => 99999999])));
print 'BAD uid RESP(' . $respBad->getStatusCode() . '): ' . $respBad->getContent() . " (expect 400)\n";

// Revert.
$e2->set('field_assigned_to', $orig ?: NULL);
if ($e2->hasField('field_notify_assigned_teammate')) {
  $e2->set('field_notify_assigned_teammate', FALSE);
}
$e2->save();
$e3 = $storage->loadUnchanged($sid);
print 'REVERTED uid=' . (int) ($e3->get('field_assigned_to')->target_id ?? 0) . " (expected $orig)\n";
print "DONE\n";
