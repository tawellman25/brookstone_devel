<?php

/**
 * @file
 * READ-ONLY report on the 16:35 bulk "marked as Invoiced" action (2026-10-15).
 *
 * Jackie Hodson (uid 6165) invoiced work orders all day; at 16:35:49–51 a bulk
 * action marked 97 of them at once, which was too many. This works out exactly
 * what to reverse WITHOUT touching anything.
 *
 * Why the status timeline and not the nightly dump: work_order is NOT
 * revisionable, so there is no prior value stored on the entity. But
 * wo_status_updates is an append-only audit trail with one record per status
 * change, so the record immediately BEFORE each 16:35 one holds that work
 * order's previous status. That is a per-work-order answer from the system's
 * own audit trail, where the 02:30 dump could only say what was true fourteen
 * hours and a full day of legitimate invoicing earlier.
 *
 * The burst is identified by its two exact creation timestamps, not by "today"
 * or by the note text — she was invoicing all day, and every one of those
 * earlier actions wrote the same note.
 *
 * Writes NOTHING. Run: drush php:script web/scripts/report_invoiced_burst_20261015.php
 */

const BURST = ['2026-10-15 22:35:49 UTC', '2026-10-15 22:35:51 UTC'];
const INVOICED_TID = 1281;

$etm = \Drupal::entityTypeManager();
$stamps = array_map('strtotime', BURST);

$burstIds = $etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)
  ->condition('created', $stamps, 'IN')
  ->sort('id')
  ->execute();
printf("Burst status-update records: %d\n", count($burstIds));

$updates = $etm->getStorage('wo_status_updates')->loadMultiple($burstIds);
$byWo = [];
foreach ($updates as $u) {
  $wo = (int) $u->get('field_status_of_wo')->target_id;
  if ($wo) {
    $byWo[$wo] = (int) $u->id();
  }
}
printf("Distinct work orders in the burst: %d\n\n", count($byWo));

$statusLabels = [];
$labelOf = function (?int $tid) use (&$statusLabels, $etm): string {
  if (!$tid) {
    return '(none)';
  }
  if (!isset($statusLabels[$tid])) {
    $t = $etm->getStorage('taxonomy_term')->load($tid);
    $statusLabels[$tid] = $t ? $t->label() : "tid $tid";
  }
  return $statusLabels[$tid];
};

$plan = [];
$noPrior = [];
$alreadyInvoiced = [];

foreach ($byWo as $woId => $updateId) {
  // The record immediately before this one, for this same work order.
  $priorIds = $etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)
    ->condition('field_status_of_wo', $woId)
    ->condition('id', $updateId, '<')
    ->sort('id', 'DESC')
    ->range(0, 1)
    ->execute();
  $prior = $priorIds ? $etm->getStorage('wo_status_updates')->load(reset($priorIds)) : NULL;
  $priorTid = $prior ? (int) $prior->get('field_status')->target_id : NULL;

  $wo = $etm->getStorage('work_order')->load($woId);
  $nowTid = $wo ? (int) $wo->get('field_status')->target_id : NULL;
  $nowInv = ($wo && $wo->hasField('field_invoiced')) ? (int) $wo->get('field_invoiced')->value : NULL;

  $row = [
    'wo' => $woId,
    'bundle' => $wo ? $wo->bundle() : '?',
    'now_status' => $nowTid,
    'now_invoiced' => $nowInv,
    'prior_status' => $priorTid,
    'prior_update' => $prior ? (int) $prior->id() : NULL,
    'prior_when' => $prior ? date('Y-m-d H:i', $prior->get('created')->value) : NULL,
  ];

  if ($priorTid === NULL) {
    $noPrior[] = $row;
  }
  elseif ($priorTid === INVOICED_TID) {
    // Already Invoiced BEFORE the burst — reversing this one would undo
    // legitimate earlier work, so it is excluded and reported separately.
    $alreadyInvoiced[] = $row;
  }
  else {
    $plan[] = $row;
  }
}

printf("REVERSIBLE (prior status is known and is not Invoiced): %d\n", count($plan));
printf("ALREADY INVOICED before the burst — EXCLUDE:            %d\n", count($alreadyInvoiced));
printf("NO PRIOR STATUS RECORD — needs a decision:              %d\n\n", count($noPrior));

$byPrior = [];
foreach ($plan as $r) {
  $byPrior[$r['prior_status']][] = $r['wo'];
}
print "Would revert to:\n";
foreach ($byPrior as $tid => $wos) {
  printf("  %-28s %3d work orders\n", $labelOf((int) $tid), count($wos));
}

if ($alreadyInvoiced) {
  print "\nEXCLUDED (already Invoiced before 16:35):\n";
  foreach ($alreadyInvoiced as $r) {
    printf("  WO %-7d prior update #%d at %s\n", $r['wo'], $r['prior_update'], $r['prior_when']);
  }
}
if ($noPrior) {
  print "\nNO PRIOR RECORD (not touched without a decision):\n";
  foreach ($noPrior as $r) {
    printf("  WO %-7d bundle=%s now=%s\n", $r['wo'], $r['bundle'], $labelOf($r['now_status']));
  }
}

// Sanity: is every work order in the burst currently Invoiced? If one is not,
// something else has changed it since, and this report is already stale.
$notInvoicedNow = array_filter(array_merge($plan, $alreadyInvoiced, $noPrior),
  fn($r) => $r['now_status'] !== INVOICED_TID);
printf("\nWork orders in the burst NOT currently Invoiced: %d%s\n",
  count($notInvoicedNow),
  $notInvoicedNow ? '  ** something changed since the burst — re-check before acting **' : '');

printf("\nWO ids to revert (%d): %s\n", count($plan), implode(',', array_column($plan, 'wo')));
print "\nREAD-ONLY — nothing was changed.\n";
