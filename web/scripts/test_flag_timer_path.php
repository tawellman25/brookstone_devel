<?php

/**
 * @file
 * Does the LEGACY flag-based WO timer still work? — before/after harness for
 * the `flag` 4.0-beta7 -> 5.2.0 upgrade (Drupal 11 project, step 4).
 *
 * Why this exists: since wo_clock shipped (2026-07-03) the legacy path carries
 * only ~0.2% of time entries — 9 of 3,952 — but it is NOT dead. The lawn mowing
 * and snow removal TASK-LIST controllers flag `work_order_timer` automatically
 * when crew start their task flow (WOLawnMowingTaskController /
 * WOSnowRemovalTaskController), and wo_timer_flag_update turns that flagging
 * into a wo_time_clock entry. Snow season makes the snow side live again.
 * Retiring that path was deliberately DECOUPLED from the flag upgrade (it is
 * its own roadmap row, off-season), so the upgrade must leave it working.
 *
 * What it exercises, per bundle (lawn_mowing, snow_removal), exactly the way
 * the task controllers do — flag service flag() / unflag() as a crew user:
 *   1. flag    -> a wo_time_clock entry is created, field_source = 'flag',
 *                 open (no end time), and linked from the flagging's own
 *                 field_wo_timer_entered (a field ON the flagging entity, so
 *                 it is part of what the upgrade could break).
 *   2. unflag  -> that same entry gets an end time.
 * Each bundle runs inside a DB transaction that is ROLLED BACK, then the script
 * asserts every field_* on the WO is identical to before. (Hand-cleanup was
 * not enough — see the comment at startTransaction() for why.)
 *
 * Writes inside a transaction that is always rolled back. Even so it refuses
 * to run outside DDEV. Never run on live.
 *
 *   ddev drush scr web/scripts/test_flag_timer_path.php
 *
 * Exits non-zero on any failed assertion.
 */

use Drupal\Core\Session\AccountInterface;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  print "REFUSING: this test writes time-clock data and only runs inside DDEV.\n";
  exit(2);
}

$etm = \Drupal::entityTypeManager();
$flag_service = \Drupal::service('flag');
$switcher = \Drupal::service('account_switcher');
$fails = 0;

$check = function (bool $ok, string $msg) use (&$fails) {
  printf("     [%s] %s\n", $ok ? ' ok ' : 'FAIL', $msg);
  if (!$ok) {
    $fails++;
  }
};

$flag = $flag_service->getFlagById('work_order_timer');
if (!$flag) {
  print "FATAL: work_order_timer flag not found\n";
  exit(1);
}
printf("flag module %s — work_order_timer loaded\n\n",
  \Drupal::service('extension.list.module')->getExtensionInfo('flag')['version'] ?? '?');

// A crew account. uid 6740 is the crew member who actually uses the mowing
// task-list route; fall back to any active teammate.
$crew = $etm->getStorage('user')->load(6740);
if (!$crew || !$crew->isActive()) {
  $ids = $etm->getStorage('user')->getQuery()->accessCheck(FALSE)
    ->condition('roles', 'teammates')->condition('status', 1)->range(0, 1)->execute();
  $crew = $ids ? $etm->getStorage('user')->load(reset($ids)) : NULL;
}
if (!$crew instanceof AccountInterface) {
  print "FATAL: no crew account available\n";
  exit(1);
}
printf("acting as uid %d\n\n", $crew->id());

foreach (['lawn_mowing', 'snow_removal'] as $bundle) {
  print "== $bundle ==\n";

  // ONLY pick a WO where clocking in causes NO status change: In Progress
  // (1092 — already there) or a closed-but-unbilled status (1097 Complete,
  // 1283 Warrantied — the hook still creates the entry but suppresses the
  // flip). NOT 1098 Canceled: a separate guard rightly refuses any time entry
  // on a canceled WO ("parent Work Order is Canceled"). Any other status (e.g. 1089 Open) makes the hook
  // write a wo_status_updates record and move the WO to In Progress — a side
  // effect this test must not leave behind. (It did, on the first run, to
  // snow WO 49466; reverted by hand.) Never 1281 Invoiced / 1504 Paid, which
  // the hook refuses outright.
  $wo = NULL;
  foreach ([[1092], [1097], [1283]] as $statuses) {
    $q = $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
      ->condition('type', $bundle)->sort('id', 'DESC')->range(0, 25)
      ->condition('field_status', $statuses, 'IN');
    foreach ($etm->getStorage('work_order')->loadMultiple($q->execute()) as $cand) {
      if (!$flag_service->getFlagging($flag, $cand, $crew)) {
        $wo = $cand;
        break 2;
      }
    }
  }
  if (!$wo) {
    $check(FALSE, "no usable $bundle work order found");
    continue;
  }
  // Snapshot every field_* value so we can PROVE the WO is untouched after.
  $snapshot = function ($id) use ($etm) {
    $etm->getStorage('work_order')->resetCache([$id]);
    $w = $etm->getStorage('work_order')->load($id);
    $o = [];
    foreach ($w->getFields() as $n => $f) {
      if (str_starts_with($n, 'field_')) {
        $o[$n] = json_encode($f->getValue());
      }
    }
    return $o;
  };
  $before = $snapshot($wo->id());
  printf("   WO %d (status %s)\n", $wo->id(), $wo->get('field_status')->target_id);

  // EVERYTHING below runs inside a transaction that is ROLLED BACK.
  //
  // Why: adding a time entry makes wo_total_time re-save the parent WO, and a
  // re-saved COMPLETE WO is re-billed by its wo_* presave under TODAY's logic.
  // The first version of this test cleaned up the flagging and the entry by
  // hand and still left Complete snow WO 40049 re-billed from $16.25 to
  // $100.00. A plain $wo->save() with no flag at all reproduces it (three old
  // Complete snow WOs went from empty billing to $16.25), so it is a BOS
  // property, not a flag one — but it means hand-cleanup can never be trusted
  // to leave the data as found. A rollback can.
  $txn = \Drupal::database()->startTransaction();
  $switcher->switchTo($crew);
  $entry = NULL;
  try {
    // 1. flag — exactly what the task controllers call.
    $flag_service->flag($flag, $wo, $crew);
    $flagging = $flag_service->getFlagging($flag, $wo, $crew);
    $check((bool) $flagging, 'flag() created a flagging');

    $entry_id = $flagging && $flagging->hasField('field_wo_timer_entered')
      ? $flagging->get('field_wo_timer_entered')->target_id : NULL;
    $check((bool) $entry_id, 'flagging.field_wo_timer_entered links a time entry');

    $entry = $entry_id ? $etm->getStorage('wo_time_clock')->load($entry_id) : NULL;
    $check((bool) $entry, 'that wo_time_clock entry exists');
    if ($entry) {
      $src = $entry->hasField('field_source') ? (string) $entry->get('field_source')->value : '(no field)';
      $check($src === 'flag', "field_source = 'flag' (got '$src')");
      $check($entry->get('field_end_time')->isEmpty(), 'entry is OPEN (no end time)');
    }

    // 2. unflag — the clock-out.
    $flag_service->unflag($flag, $wo, $crew);
    $check(!$flag_service->getFlagging($flag, $wo, $crew), 'unflag() removed the flagging');
    if ($entry) {
      $etm->getStorage('wo_time_clock')->resetCache([$entry->id()]);
      $entry = $etm->getStorage('wo_time_clock')->load($entry->id());
      $check($entry && !$entry->get('field_end_time')->isEmpty(), 'entry is CLOSED (end time set)');
    }
  }
  catch (\Throwable $e) {
    $check(FALSE, get_class($e) . ': ' . $e->getMessage());
  }
  finally {
    $switcher->switchBack();
    $txn->rollBack();
    unset($txn);
  }

  // Drop every cache layer that may still hold the rolled-back state.
  $entry_id_seen = $entry ? $entry->id() : NULL;
  foreach (['work_order', 'wo_time_clock', 'flagging', 'wo_status_updates'] as $t) {
    $etm->getStorage($t)->resetCache();
  }
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['work_order:' . $wo->id()]);

  $after = $snapshot($wo->id());
  $changed = array_keys(array_filter($after, fn($v, $k) => ($before[$k] ?? NULL) !== $v, ARRAY_FILTER_USE_BOTH));
  $check(!$changed, 'WO identical after rollback (all ' . count($after) . ' field_* values)'
    . ($changed ? ' — CHANGED: ' . implode(', ', $changed) : ''));
  $check(!$flag_service->getFlagging($flag, $wo, $crew), 'no flagging left behind');
  $check(!$entry_id_seen || !$etm->getStorage('wo_time_clock')->load($entry_id_seen), 'no time entry left behind');
  print "\n";
}

print $fails ? "*** $fails assertion(s) FAILED ***\n" : "Legacy flag timer path works for both task-list bundles.\n";
if ($fails) {
  exit(1);
}
