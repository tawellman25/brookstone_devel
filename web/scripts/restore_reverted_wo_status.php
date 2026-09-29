<?php

declare(strict_types=1);

/**
 * Restore the 17 work orders my renumber knocked back to Scheduled.
 *
 * WHAT HAPPENED. renumber_route_order.php saved scheduling entities to fix route
 * order. Saving one fires wo_schedule, which writes a "Scheduled" (1091)
 * wo_status_updates record, and wo_status_updates presave propagates that status
 * onto the parent work order — overwriting Complete and Invoiced on 17 jobs that
 * crews had finished and the office had billed THAT MORNING.
 *
 * The 2026-07-11 resurrection guard exists for exactly this and did not catch
 * it: it blocks a terminal WO being reopened to 1092 (In Progress) and says
 * nothing about 1091 (Scheduled). Fixing that gap is a separate change; this
 * script only repairs the data.
 *
 * Sign-off data was never touched — every one still has its wo_complete_info
 * record, which is how the prior status was recoverable. Targets come from the
 * append-only audit trail: the last status each WO held before 13:46 today.
 *
 * Writes field_status DIRECTLY and creates no status-update record, because a
 * status-update is what caused this. _skip_invoiced_guard is set for the
 * Invoiced rows: wo_shared refuses an Invoiced transition without a prior
 * Complete, which is right for new billing and wrong for restoring a WO that was
 * already there.
 *
 * Idempotent. Dry-run by default; BOS_RESTORE_APPLY=1 to write.
 */

/** WO id => status tid to restore, recovered from wo_status_updates. */
$PLAN = [
  // All Invoiced: Jackie invoiced these six at 13:55, AFTER my first write at
  // 13:45 and BEFORE the renumber's at 14:04. Deriving the target from a clock
  // time put them back at Complete and lost her billing; deriving it from the
  // last HUMAN status update is correct and needs no cutoff at all.
  52205 => 1281, 52488 => 1281, 52531 => 1281, 52582 => 1281, 52607 => 1281, 52720 => 1281,
  53934 => 1281, 53935 => 1281, 53936 => 1281, 53937 => 1281, 53939 => 1281, 53940 => 1281,
  53941 => 1281, 53942 => 1281, 53943 => 1281, 53945 => 1281, 53948 => 1281,
];
$NAMES = [1091 => 'Scheduled', 1092 => 'In Progress', 1097 => 'Complete', 1281 => 'Invoiced'];

$apply = getenv('BOS_RESTORE_APPLY') === '1';
$storage = \Drupal::entityTypeManager()->getStorage('work_order');
$done = $skipped = 0;

foreach ($PLAN as $woId => $want) {
  $wo = $storage->load($woId);
  if (!$wo) {
    printf("  MISSING WO %-7s\n", $woId);
    continue;
  }
  $now = $wo->get('field_status')->isEmpty() ? 0 : (int) $wo->get('field_status')->target_id;
  if ($now === $want) {
    printf("  ok      WO %-7s already %s\n", $woId, $NAMES[$want] ?? $want);
    $skipped++;
    continue;
  }
  // Only ever repair a WO still sitting where the incident left it. If someone
  // has since moved it deliberately, leave it alone and say so.
  if (!in_array($now, [1091, 1092, 1097], TRUE)) {
    printf("  LEAVE   WO %-7s is %s, not the state the incident left — untouched\n", $woId, $NAMES[$now] ?? $now);
    $skipped++;
    continue;
  }
  printf("  %s WO %-7s %s -> %s\n", $apply ? 'RESTORE' : 'would  ', $woId, $NAMES[$now] ?? $now, $NAMES[$want] ?? $want);
  if ($apply) {
    $wo->set('field_status', ['target_id' => $want]);
    // wo_shared blocks Invoiced without a prior Complete; correct for new
    // billing, wrong for putting back a status the WO already held.
    $wo->_skip_invoiced_guard = TRUE;
    $wo->save();
  }
  $done++;
}
printf("\n  %s: %d   left alone: %d\n", $apply ? 'restored' : 'would restore', $done, $skipped);
if (!$apply) {
  print "\nDRY RUN. BOS_RESTORE_APPLY=1 to write.\n";
}
