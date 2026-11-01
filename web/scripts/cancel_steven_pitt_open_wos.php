<?php

/**
 * @file
 * Cancel the four open work orders still assigned to a departed teammate.
 *
 * Steven Pitt's account has been BLOCKED since October 2024. Four work orders —
 * all `weed_spraying`, all created May 2024, last touched May 2024 — are still
 * open against him. Nobody can action them: the assignee cannot sign in. Todd's
 * read is that they were mistakenly created, and the shape of the data agrees:
 * same service, same week, minimal or no time logged.
 *
 *   35093  USDA Montrose             status "Sold"    0.2h
 *   35098  Dallas Creek Water Co.    status "Active"  0.1h
 *   35104  Wilson, Devin & Shelly    status "Active"  none
 *   35106  Wilson, Devin & Shelly    status "Active"  0.3h
 *
 * ⚠ CANCELLED, NOT DELETED. BOS rule 4: no deletion of operational history. A
 * cancelled work order keeps its record, its time entries and its history; it
 * simply stops being work anybody is waiting on. The small logged time stays
 * attached and will not bill, because a Canceled (1098) work order is outside
 * the billing floor.
 *
 * Uses the site's own MarkWorkOrderCanceledAction, so each one gets a
 * wo_status_updates record naming who cancelled it and when — the same
 * mechanism the office uses from the UI. A raw field write would change the
 * status with no trail, and these are two-year-old records somebody will
 * eventually ask about.
 *
 * ⚠ The target list is RE-DERIVED here, never hardcoded, and every candidate
 * must still satisfy all four conditions: assigned to that blocked account,
 * status not already closed, bundle weed_spraying, and id in the expected set.
 * Anything else is refused rather than cancelled.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/cancel_steven_pitt_open_wos.php
 */

const EXPECTED = [35093, 35098, 35104, 35106];
const EXPECTED_BUNDLE = 'weed_spraying';
const CLOSED = [1097, 1098, 1281, 1283, 1504];
const CANCELED = 1098;

$apply = getenv('BOS_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

$users = $etm->getStorage('user')->loadByProperties(['name' => 'Steven Pitt']);
$user = reset($users);
if (!$user) {
  print "ABORT: no account named 'Steven Pitt'.\n";
  return;
}
printf("assignee: %s (uid %s) — %s\n", $user->getDisplayName(), $user->id(),
  $user->isActive() ? 'ACTIVE' : 'blocked');
if ($user->isActive()) {
  print "ABORT: that account is ACTIVE. The whole premise is that nobody can action these.\n";
  return;
}

$rows = $db->query('SELECT DISTINCT wo.id, wo.type, st.field_status_target_id tid
  FROM {scheduling__field_assigned_to} a
  JOIN {scheduling__field_work_order} swo ON swo.entity_id = a.entity_id AND swo.deleted = 0
  JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE a.deleted = 0 AND a.field_assigned_to_target_id = :uid
    AND st.field_status_target_id NOT IN (:closed[])',
  [':uid' => $user->id(), ':closed[]' => CLOSED])->fetchAll();

$targets = [];
$refused = [];
foreach ($rows as $r) {
  $id = (int) $r->id;
  if (!in_array($id, EXPECTED, TRUE)) {
    $refused[] = "$id not in the expected set";
    continue;
  }
  if ($r->type !== EXPECTED_BUNDLE) {
    $refused[] = "$id is {$r->type}, expected " . EXPECTED_BUNDLE;
    continue;
  }
  $targets[$id] = $r;
}
$missing = array_diff(EXPECTED, array_keys($targets));

printf("\nopen work orders assigned to him: %d\n", count($rows));
printf("matching all conditions:           %d\n", count($targets));
if ($refused) {
  foreach ($refused as $why) {
    printf("  REFUSED %s\n", $why);
  }
}
if ($missing) {
  printf("  expected but not open any more (fine): %s\n", implode(', ', $missing));
}
if (!$targets) {
  print "\nNothing to cancel.\n";
  return;
}

foreach ($targets as $id => $r) {
  $wo = $etm->getStorage('work_order')->load($id);
  $term = $etm->getStorage('taxonomy_term')->load($r->tid);
  printf("  WO %-7d %-16s %-10s %s\n", $id, $r->type,
    $term ? $term->label() : $r->tid, $wo ? mb_substr((string) $wo->label(), 0, 44) : '?');
}

if (!$apply) {
  printf("\nDRY RUN — would cancel %d work order(s). Re-run with BOS_APPLY=1.\n", count($targets));
  return;
}

$plugin = \Drupal::service('plugin.manager.action')
  ->createInstance('mark_work_order_canceled_action');
$done = 0;
$failed = [];
foreach (array_keys($targets) as $id) {
  $wo = $etm->getStorage('work_order')->load($id);
  if (!$wo) {
    $failed[] = "$id: could not load";
    continue;
  }
  try {
    $plugin->execute($wo);
    $done++;
  }
  catch (\Throwable $e) {
    $failed[] = "$id: " . $e->getMessage();
  }
}
printf("\nexecuted on %d work order(s); failures: %d\n", $done, count($failed));
foreach ($failed as $f) {
  printf("  FAILED %s\n", $f);
}

// Verify from fresh reads — the action works through a status-update entity,
// so confirm the status actually landed on the work order.
print "\nafter:\n";
$stillOpen = 0;
foreach (array_keys($targets) as $id) {
  $wo = $etm->getStorage('work_order')->loadUnchanged($id);
  $tid = (int) $wo->get('field_status')->target_id;
  $term = $etm->getStorage('taxonomy_term')->load($tid);
  $okNow = $tid === CANCELED;
  if (!$okNow) {
    $stillOpen++;
  }
  printf("  WO %-7d %-12s %s\n", $id, $term ? $term->label() : $tid, $okNow ? 'CANCELED' : '** still open **');
}
printf("\nstill open: %d\n", $stillOpen);
