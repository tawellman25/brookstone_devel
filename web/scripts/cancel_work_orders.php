<?php

/**
 * @file
 * Cancel a named list of stale work orders, with guards.
 *
 * Generalised from the one-off written for a departed teammate's orphans,
 * because the ageing-backlog triage has ~25 more to work through and a fresh
 * script per batch is how a mistake eventually gets made.
 *
 * ⚠ CANCELLED, NOT DELETED (BOS rule 4: no deletion of operational history).
 * The record, its history and its time entries all survive; a Canceled (1098)
 * work order simply sits outside the billing floor, so any logged time cannot
 * bill.
 *
 * Uses the site's own MarkWorkOrderCanceledAction, so each work order gets a
 * wo_status_updates record naming who cancelled it and when. A raw field write
 * would change the status with no trail, and these are records somebody will
 * ask about years later.
 *
 * ⚠ 2026-11-01: THIS GUARD WAS NOT ENOUGH, and the gap is worth stating plainly
 * because it cost a real repair. The original version tested `field_status`
 * alone. WO 35107 and 35110 both read "Active" — a status outside the closed
 * set — so they passed, and both were cancelled. Both had in fact been
 * completed and billed in 2024: their status HISTORY contained Complete, their
 * `field_invoiced` flag was set, and their totals were populated ($264.42 and
 * $121.33). Three signals said "billed" and the one signal checked said
 * "open". On these old records `field_status` had drifted to free-text-ish
 * values (Active / Sold) that no longer reflected the billing reality.
 *
 * Undoing it was not cheap: Canceled -> Invoiced is blocked by the wo_shared
 * transition guard, and the only legal route (via Complete) re-fires the `wo_*`
 * recalculation, which restated one of the totals by +$25 at today's rates.
 * See restore_wrongly_cancelled_invoiced_wos.php.
 *
 * So the rule now is: a work order is "closed" if ANY evidence says so, not
 * only if the status field does.
 *
 * GUARDS — every one must pass per work order, or it is refused, not cancelled:
 *   - the id was explicitly named (nothing is ever derived or inferred);
 *   - the work order exists;
 *   - its status is NOT closed (Complete / Canceled / Invoiced / Paid /
 *     Warrantied);
 *   - `field_invoiced` is NOT set — it has not been billed;
 *   - `field_wo_total` is empty or zero — nothing has been costed against it;
 *   - its status HISTORY contains no Complete / Invoiced / Paid record — the
 *     crew never signed it off, whatever the status field now says;
 *   - it is older than BOS_MIN_AGE days (default 90), so a typo cannot cancel
 *     this week's work.
 *
 * Set BOS_ACTOR to the account the cancellations should be attributed to
 * (default "Todd Wellman"). Without it drush runs as uid 0 and every record
 * reads "Anonymous marked this Work Order as Canceled", which is what made the
 * 2026-11-01 sweep hard to read afterwards.
 *
 * Time logged is printed for every target BEFORE anything is written, because
 * cancelling a work order with real hours on it is a different decision from
 * cancelling an empty one, and it should not pass unnoticed.
 *
 * Usage:
 *   BOS_WO_IDS=35094,35103,35107,35110 drush php:script web/scripts/cancel_work_orders.php
 *   BOS_WO_IDS=... BOS_APPLY=1 drush php:script …     # to write
 *   BOS_MIN_AGE=365                                    # stricter floor
 */

const CLOSED = [1097, 1098, 1281, 1283, 1504];
const CANCELED = 1098;
// A record of any of these in the status HISTORY means the work was signed off
// and/or billed, whatever field_status has since drifted to.
const HISTORY_CLOSED = [1097, 1281, 1504];

$apply = getenv('BOS_APPLY') === '1';
// Not `?: 90` — "0" is falsy in PHP, so an explicit BOS_MIN_AGE=0 would
// silently become 90 and the caller would never know the floor they asked for
// was ignored.
$rawAge = getenv('BOS_MIN_AGE');
$minAge = ($rawAge === FALSE || trim($rawAge) === '') ? 90 : (int) $rawAge;
$raw = trim((string) getenv('BOS_WO_IDS'));
if ($raw === '') {
  print "ABORT: set BOS_WO_IDS to a comma-separated list of work order ids.\n";
  return;
}
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
if (!$ids) {
  print "ABORT: no usable ids in BOS_WO_IDS.\n";
  return;
}

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$now = \Drupal::time()->getRequestTime();

/** Minutes clocked against a work order (closed punches only). */
$minutesOn = static function (int $woId) use ($db): int {
  $rows = $db->query('SELECT s.field_start_time_value st, e.field_end_time_value et
    FROM {wo_time_clock__field_work_order} w
    LEFT JOIN {wo_time_clock__field_start_time} s ON s.entity_id = w.entity_id AND s.deleted = 0
    LEFT JOIN {wo_time_clock__field_end_time} e ON e.entity_id = w.entity_id AND e.deleted = 0
    WHERE w.deleted = 0 AND w.field_work_order_target_id = :id', [':id' => $woId])->fetchAll();
  $m = 0;
  foreach ($rows as $r) {
    if (empty($r->st) || empty($r->et)) {
      continue;
    }
    $m += max(0, (int) round(((new \DateTime($r->et . 'Z'))->getTimestamp()
      - (new \DateTime($r->st . 'Z'))->getTimestamp()) / 60));
  }
  return $m;
};

$targets = [];
$refused = [];
printf("checking %d work order(s), minimum age %d days\n\n", count($ids), $minAge);
printf("%-8s %-22s %-12s %-6s %-8s %s\n", 'WO', 'SERVICE', 'STATUS', 'AGE', 'TIME', 'VERDICT');
printf("%s\n", str_repeat('-', 86));

foreach ($ids as $id) {
  $wo = $etm->getStorage('work_order')->load($id);
  if (!$wo) {
    $refused[] = "$id: does not exist";
    printf("%-8d %-22s %-12s %-6s %-8s %s\n", $id, '-', '-', '-', '-', 'REFUSED — not found');
    continue;
  }
  $tid = (int) $wo->get('field_status')->target_id;
  $term = $etm->getStorage('taxonomy_term')->load($tid);
  $status = $term ? $term->label() : (string) $tid;
  $age = (int) floor(($now - (int) $wo->getCreatedTime()) / 86400);
  $mins = $minutesOn($id);
  $time = $mins > 0 ? round($mins / 60, 1) . 'h' : '—';

  // Billing evidence, read independently of field_status — which is exactly
  // what drifted on the 2026-11-01 records.
  $invoiced = (int) ($wo->get('field_invoiced')->value ?? 0) === 1;
  $total = $wo->hasField('field_wo_total') ? $wo->get('field_wo_total')->value : NULL;
  $costed = $total !== NULL && $total !== '' && (float) $total != 0.0;
  $historyClosed = (int) $db->query('SELECT COUNT(*) FROM {wo_status_updates__field_status_of_wo} sw
    JOIN {wo_status_updates__field_status} s ON s.entity_id = sw.entity_id AND s.deleted = 0
    WHERE sw.deleted = 0 AND sw.field_status_of_wo_target_id = :id
      AND s.field_status_target_id IN (:closed[])',
    [':id' => $id, ':closed[]' => HISTORY_CLOSED])->fetchField();

  $verdict = 'will cancel';
  if (in_array($tid, CLOSED, TRUE)) {
    $verdict = 'REFUSED — already closed';
    $refused[] = "$id: already $status";
  }
  elseif ($invoiced) {
    $verdict = 'REFUSED — field_invoiced is set';
    $refused[] = "$id: already invoiced (flag set, total " . ($total ?? 'empty') . ')';
  }
  elseif ($costed) {
    $verdict = "REFUSED — has a total ($total)";
    $refused[] = "$id: carries a WO total of $total — it has been costed";
  }
  elseif ($historyClosed) {
    $verdict = 'REFUSED — history shows sign-off';
    $refused[] = "$id: status history contains Complete/Invoiced/Paid, despite reading '$status'";
  }
  elseif ($age < $minAge) {
    $verdict = "REFUSED — only {$age}d old";
    $refused[] = "$id: $age days, under the $minAge-day floor";
  }
  else {
    $targets[] = $id;
    if ($mins >= 120) {
      $verdict = 'will cancel ⚠ REAL HOURS';
    }
  }
  printf("%-8d %-22s %-12s %-6s %-8s %s\n", $id, mb_substr($wo->bundle(), 0, 22),
    mb_substr($status, 0, 12), $age . 'd', $time, $verdict);
}

printf("\nto cancel: %d    refused: %d\n", count($targets), count($refused));
foreach ($refused as $r) {
  printf("  REFUSED %s\n", $r);
}
if (!$targets) {
  print "\nNothing to do.\n";
  return;
}
if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1 to write.\n";
  return;
}

// Attribute the cancellations to a real account. The action reads
// \Drupal::currentUser() for its audit note, and under drush that is uid 0 —
// which is why the 2026-11-01 records all read "Anonymous marked this Work
// Order as Canceled" and nobody could tell what had done it.
$actorName = getenv('BOS_ACTOR') ?: 'Todd Wellman';
$actors = $etm->getStorage('user')->loadByProperties(['name' => $actorName]);
$actor = reset($actors);
if (!$actor) {
  printf("ABORT: no account named '%s'. Set BOS_ACTOR to a real account —\n"
    . "cancellations must not be logged as Anonymous.\n", $actorName);
  return;
}
printf("\nattributing to %s (uid %s)\n", $actor->getDisplayName(), $actor->id());

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($actor);
$plugin = \Drupal::service('plugin.manager.action')->createInstance('mark_work_order_canceled_action');
$failed = [];
try {
  foreach ($targets as $id) {
    try {
      $plugin->execute($etm->getStorage('work_order')->load($id));
    }
    catch (\Throwable $e) {
      $failed[] = "$id: " . $e->getMessage();
    }
  }
}
finally {
  $switcher->switchBack();
}

print "\nafter (fresh reads):\n";
$stillOpen = 0;
foreach ($targets as $id) {
  $wo = $etm->getStorage('work_order')->loadUnchanged($id);
  $tid = (int) $wo->get('field_status')->target_id;
  $term = $etm->getStorage('taxonomy_term')->load($tid);
  $ok = $tid === CANCELED;
  if (!$ok) {
    $stillOpen++;
  }
  // Confirm the audit record exists, not just the field value.
  $records = (int) $db->query('SELECT COUNT(*) FROM {wo_status_updates__field_status_of_wo}
    WHERE deleted = 0 AND field_status_of_wo_target_id = :id', [':id' => $id])->fetchField();
  printf("  WO %-8d %-12s status records: %-3d %s\n", $id,
    $term ? $term->label() : $tid, $records, $ok ? '' : '** still open **');
}
printf("\ncancelled: %d    still open: %d    failures: %d\n",
  count($targets) - $stillOpen, $stillOpen, count($failed));
foreach ($failed as $f) {
  printf("  FAILED %s\n", $f);
}
