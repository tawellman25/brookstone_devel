<?php

/**
 * @file
 * Reverse the 16:35 bulk "marked as Invoiced" action of 2026-10-15.
 *
 * Jackie Hodson (uid 6165) invoiced work orders all day. At 16:35:49 and
 * 16:35:51 one bulk action marked 97 at once — too many. Only that burst is
 * reversed; every earlier invoicing of hers stands.
 *
 * HOW THE TARGET LIST IS BUILT. From the wo_status_updates audit trail, by the
 * two exact creation timestamps — not "invoiced today", not the note text
 * (every one of her legitimate actions wrote the same note), and not a
 * hand-copied list of ids. Each of the 97 is then re-checked individually at
 * run time and skipped unless ALL of these still hold:
 *
 *   - it is currently Invoiced (1281) — if something changed it since, leave it
 *   - its field_invoiced flag is 1
 *   - the status update immediately BEFORE the burst one says Complete (1097)
 *
 * That last check is what makes this safe: work_order is NOT revisionable, so
 * the append-only timeline is the only per-work-order record of what the status
 * was before. All 97 came from Complete, confirmed twice over — the forward
 * action writes "re-confirmed this Work Order as Invoiced" for a WO that was
 * ALREADY invoiced and "marked" otherwise, and all 97 say "marked".
 *
 * MECHANISM. The site's own MarkWorkOrderNotInvoicedAction, not hand-written
 * field writes: it clears field_invoiced and appends a wo_status_updates
 * record returning the WO to Complete, so the reversal is audited the same way
 * the mistake was. The wo_shared guard permits this (any status -> any
 * non-Invoiced status is allowed); it only blocks entering Invoiced from
 * somewhere other than Complete.
 *
 * KNOWN SIDE EFFECT, measured rather than assumed: returning a WO to Complete
 * (1097) re-triggers the wo_* presave billing recalculation. It reads the same
 * immutable child-entity snapshots, so totals should reproduce exactly — but
 * "should" is not evidence, so field_wo_total is captured before and after for
 * every work order and any change is reported loudly at the end.
 *
 * DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/reverse_invoiced_burst_20261015.php
 */

const BURST = ['2026-10-15 22:35:49 UTC', '2026-10-15 22:35:51 UTC'];
const INVOICED = 1281;
const COMPLETE = 1097;

$apply = getenv('BOS_APPLY') === '1';
$etm = \Drupal::entityTypeManager();

$burstIds = $etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)
  ->condition('created', array_map('strtotime', BURST), 'IN')
  ->sort('id')->execute();
$updates = $etm->getStorage('wo_status_updates')->loadMultiple($burstIds);
printf("Burst audit records: %d\n", count($updates));

$targets = [];
$skipped = [];
foreach ($updates as $u) {
  $woId = (int) $u->get('field_status_of_wo')->target_id;
  if (!$woId) {
    continue;
  }
  $wo = $etm->getStorage('work_order')->load($woId);
  if (!$wo) {
    $skipped[] = [$woId, 'work order not found'];
    continue;
  }
  $status = (int) $wo->get('field_status')->target_id;
  $inv = (int) ($wo->get('field_invoiced')->value ?? 0);
  if ($status !== INVOICED) {
    $skipped[] = [$woId, "no longer Invoiced (status $status) — left alone"];
    continue;
  }
  if ($inv !== 1) {
    $skipped[] = [$woId, 'field_invoiced already 0 — left alone'];
    continue;
  }
  $priorIds = $etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)
    ->condition('field_status_of_wo', $woId)
    ->condition('id', (int) $u->id(), '<')
    ->sort('id', 'DESC')->range(0, 1)->execute();
  $prior = $priorIds ? $etm->getStorage('wo_status_updates')->load(reset($priorIds)) : NULL;
  $priorStatus = $prior ? (int) $prior->get('field_status')->target_id : NULL;
  if ($priorStatus !== COMPLETE) {
    $skipped[] = [$woId, 'prior status was ' . ($priorStatus ?? 'unknown') . ', not Complete — NOT touched'];
    continue;
  }
  $targets[$woId] = $wo;
}

printf("Eligible to reverse: %d\n", count($targets));
printf("Skipped:             %d\n", count($skipped));
foreach ($skipped as [$id, $why]) {
  printf("   skip WO %-7d %s\n", $id, $why);
}

if (!$targets) {
  print "\nNothing to do.\n";
  return;
}

// Money before.
$before = [];
foreach ($targets as $id => $wo) {
  $before[$id] = $wo->hasField('field_wo_total') ? (string) $wo->get('field_wo_total')->value : NULL;
}

if (!$apply) {
  printf("\nDRY RUN — would reverse %d work orders to Complete and NOT Invoiced.\n", count($targets));
  printf("WO ids: %s\n", implode(',', array_keys($targets)));
  print "Re-run with BOS_APPLY=1 to write.\n";
  return;
}

// Run as the user whose action is being reversed is NOT right — the audit note
// should name whoever actually performed the reversal. Left as the current
// drush user, which the note will reflect.
$action = $etm->getStorage('action')->load('mark_work_order_not_invoiced_action');
$plugin = $action ? $action->getPlugin() : NULL;
if (!$plugin) {
  // The action config may not exist even though the plugin does.
  $plugin = \Drupal::service('plugin.manager.action')
    ->createInstance('mark_work_order_not_invoiced_action');
}
printf("\nUsing action plugin: %s\n", get_class($plugin));

$done = 0;
$failed = [];
foreach ($targets as $id => $wo) {
  try {
    $plugin->execute($wo);
    $done++;
  }
  catch (\Throwable $e) {
    $failed[] = [$id, $e->getMessage()];
  }
}
printf("Executed: %d   Failed: %d\n", $done, count($failed));
foreach ($failed as [$id, $msg]) {
  printf("   FAILED WO %-7d %s\n", $id, substr($msg, 0, 120));
}

// --- verify from a fresh read, and compare the money -------------------------
$stillInvoiced = [];
$moneyMoved = [];
$backToComplete = 0;
foreach (array_keys($targets) as $id) {
  $wo = $etm->getStorage('work_order')->loadUnchanged($id);
  if (!$wo) {
    continue;
  }
  $status = (int) $wo->get('field_status')->target_id;
  $inv = (int) ($wo->get('field_invoiced')->value ?? 0);
  if ($status === COMPLETE && $inv === 0) {
    $backToComplete++;
  }
  if ($status === INVOICED || $inv === 1) {
    $stillInvoiced[] = $id;
  }
  $after = $wo->hasField('field_wo_total') ? (string) $wo->get('field_wo_total')->value : NULL;
  if ((string) $before[$id] !== (string) $after) {
    $moneyMoved[] = [$id, $before[$id], $after];
  }
}

printf("\nNow Complete AND not invoiced: %d of %d\n", $backToComplete, count($targets));
printf("Still Invoiced (should be 0):  %d%s\n", count($stillInvoiced),
  $stillInvoiced ? '  -> ' . implode(',', $stillInvoiced) : '');
printf("WO totals CHANGED by the recalc: %d%s\n", count($moneyMoved),
  $moneyMoved ? '  ** review these **' : '  (every total reproduced exactly)');
foreach ($moneyMoved as [$id, $b, $a]) {
  printf("   WO %-7d %s -> %s\n", $id, $b ?? 'null', $a ?? 'null');
}
