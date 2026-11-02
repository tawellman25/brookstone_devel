<?php

/**
 * @file
 * Restore two already-billed work orders that an automated cleanup cancelled.
 *
 * On 2026-11-01 `cancel_work_orders.php` cancelled WO 35107 and 35110 as part
 * of an ageing-backlog sweep. Both were real 2024 jobs that had been completed
 * and billed:
 *
 *   35107  Complete 07/22/2024 (Russell Akers), field_invoiced=YES, $264.42
 *   35110  Complete 05/15/2024, Invoiced 05/21/2024 (Jackie Hodson), $121.33
 *
 * The sweep's guard tested `field_status` alone, which read "Active" on both —
 * outside its closed-status set — while the status HISTORY, the
 * `field_invoiced` flag and the populated totals all said billed. One signal
 * was checked and it was the wrong one. The guard is tightened separately in
 * cancel_work_orders.php.
 *
 * ROUTE. `_wo_shared_assert_invoiced_transition_valid()` blocks any save moving
 * field_status to Invoiced (1281) unless the prior status was Complete (1097)
 * or Invoiced — measured, it throws. So the only legal route is
 * Canceled -> Complete -> Invoiced.
 *
 * ⚠ THE COMPLETE HOP RECALCULATES. wo_shared returns early unless status is
 * 1097, so landing on Complete is exactly what fires the `wo_*` billing
 * recalculation, and it recomputes from CURRENT rates. Measured on dev
 * (test_cancel_to_invoiced_route.php, rolled back):
 *
 *   35107  $264.42 -> $264.42   every billing field held
 *   35110  $121.33 -> $146.33   field_wo_total rewritten, +$25.00
 *
 * 35110's components (labor 35.00 + trip 65.00 + mat/chem 1.33 = $101.33) did
 * NOT move; only the total did, because a sprinkler_start_up rate in
 * business_setting has risen $25 since May 2024. Letting that stand would
 * restate an invoice the client already paid — BOS rule 5, pricing snapshots
 * are immutable once complete.
 *
 * So after the WO lands on Invoiced its historical total is written back. That
 * is safe precisely because the recalc does NOT fire at 1281: the $146.33
 * exists only transiently during the Complete hop.
 *
 * ⚠⚠ WHY THIS RUNS IN SEPARATE PHASES — AND WHY ITS OWN VERIFY ONCE LIED.
 * The first version did all of it in one request and reported "2 restored, 0
 * wrong", reading 121.33 back through loadUnchanged(). The database held
 * 146.33. Measured twice on dev: a total written in the SAME request as the
 * status hops is overwritten again before the request ends — by a post-save
 * hook chain carrying an entity snapshot from the Complete hop — and an
 * in-request read cannot see it. The same write performed alone, in its own
 * request, persists and survives a separate-process read.
 *
 * So the phases must be separate drush invocations, and the verification must
 * be a THIRD one. An in-request "after" read is not evidence here; only a
 * fresh process is. Do not collapse these back into one run.
 *
 *   BOS_PHASE=status  Canceled -> Complete -> Invoiced
 *   BOS_PHASE=totals  restore the historical field_wo_total
 *   BOS_PHASE=verify  read-only; the only trustworthy check
 *
 * GUARDS — every one must pass per work order or it is refused, not touched:
 *   - the id is explicitly named (nothing derived);
 *   - for the status phase, it is currently Canceled (1098);
 *   - for the totals phase, it has reached Invoiced (1281);
 *   - field_invoiced is YES — the evidence it was genuinely billed;
 *   - its status history contains Complete (1097) — the crew really signed off;
 *   - the status phase requires the total to still match the expected figure.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run (three separate invocations):
 *   BOS_PHASE=status BOS_APPLY=1 drush php:script web/scripts/restore_wrongly_cancelled_invoiced_wos.php
 *   BOS_PHASE=totals BOS_APPLY=1 drush php:script web/scripts/restore_wrongly_cancelled_invoiced_wos.php
 *   BOS_PHASE=verify                drush php:script web/scripts/restore_wrongly_cancelled_invoiced_wos.php
 */

const COMPLETE = 1097;
const CANCELED = 1098;
const INVOICED = 1281;

// id => the historical total that must survive this repair, to the cent.
const EXPECTED = [
  35107 => '264.42',
  35110 => '121.33',
];

const NOTE = 'Scripted correction: this Work Order was cancelled in error on 11/01/2026 by an automated ageing-backlog sweep. It was completed and billed in 2024; restoring its prior status. Historical total preserved.';

$apply = getenv('BOS_APPLY') === '1';
$phase = getenv('BOS_PHASE') ?: '';
if (!in_array($phase, ['status', 'totals', 'verify'], TRUE)) {
  print "ABORT: set BOS_PHASE to status, totals or verify (see the file header).\n";
  return;
}
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

// Attribute the audit records to a named account. The erroneous cancellation
// logged as "Anonymous" because drush runs as uid 0, which is precisely what
// made it hard to read afterwards.
$actorUid = 0;
$owner = $etm->getStorage('user')->loadByProperties(['name' => 'Todd Wellman']);
if ($owner = reset($owner)) {
  $actorUid = (int) $owner->id();
}
printf("audit records will be attributed to uid %d (%s)\n\n",
  $actorUid, $actorUid ? $owner->getDisplayName() : 'Anonymous');

$label = static fn(int $tid): string => ($t = \Drupal::entityTypeManager()
  ->getStorage('taxonomy_term')->load($tid)) ? $t->label() : (string) $tid;

$money = static function ($v): string {
  return $v === NULL || $v === '' ? 'empty' : number_format((float) $v, 2, '.', '');
};

/**
 * Read the stored state straight from the field tables.
 *
 * Deliberately NOT through the entity API. An entity read inside the request
 * that wrote is what produced the false "0 wrong" the first time round.
 */
$stored = static function (int $id) use ($db): array {
  return [
    'status' => (int) ($db->query('SELECT field_status_target_id FROM {work_order__field_status}
      WHERE entity_id = :id AND deleted = 0', [':id' => $id])->fetchField() ?: 0),
    'total' => $db->query('SELECT field_wo_total_value FROM {work_order__field_wo_total}
      WHERE entity_id = :id AND deleted = 0', [':id' => $id])->fetchField(),
    'invoiced' => (int) ($db->query('SELECT field_invoiced_value FROM {work_order__field_invoiced}
      WHERE entity_id = :id AND deleted = 0', [':id' => $id])->fetchField() ?: 0),
  ];
};

/** Was this WO ever genuinely signed off? */
$everComplete = static fn(int $id): bool => (bool) \Drupal::database()
  ->query('SELECT COUNT(*) FROM {wo_status_updates__field_status_of_wo} sw
    JOIN {wo_status_updates__field_status} s ON s.entity_id = sw.entity_id AND s.deleted = 0
    WHERE sw.deleted = 0 AND sw.field_status_of_wo_target_id = :id
      AND s.field_status_target_id = :c', [':id' => $id, ':c' => COMPLETE])->fetchField();

$setStatus = static function (int $id, int $tid, string $note) use ($etm, $actorUid): ?string {
  try {
    $etm->getStorage('wo_status_updates')->create([
      'type' => 'update',
      'field_status_of_wo' => $id,
      'field_status' => $tid,
      'field_status_change_note' => $note,
      'uid' => $actorUid,
    ])->save();
    return NULL;
  }
  catch (\Throwable $e) {
    return $e->getMessage();
  }
};

printf("phase: %s%s\n\n", $phase, $phase === 'verify' ? ' (read-only)' : ($apply ? '' : '  [DRY RUN]'));
printf("%-8s %-10s %-9s %-10s %-10s %s\n", 'WO', 'STATUS', 'INVOICED', 'TOTAL', 'EXPECTED', 'VERDICT');
printf("%s\n", str_repeat('-', 80));

$act = [];
$wrong = 0;
foreach (EXPECTED as $id => $expectedTotal) {
  $s = $stored($id);
  if (!$s['status']) {
    printf("%-8d %s\n", $id, 'REFUSED — not found');
    $wrong++;
    continue;
  }
  $total = $money($s['total']);
  $invoiced = $s['invoiced'] === 1;
  $verdict = '';

  if ($phase === 'verify') {
    $ok = $s['status'] === INVOICED && $total === $expectedTotal && $invoiced;
    $verdict = $ok ? 'OK' : '** WRONG **';
    if (!$ok) {
      $wrong++;
    }
  }
  elseif (!$invoiced) {
    $verdict = 'REFUSED — field_invoiced is not set';
  }
  elseif (!$everComplete($id)) {
    $verdict = 'REFUSED — no Complete in its status history';
  }
  elseif ($phase === 'status') {
    if ($s['status'] === INVOICED) {
      $verdict = 'already Invoiced — status phase done';
    }
    elseif ($s['status'] !== CANCELED) {
      $verdict = 'REFUSED — not Canceled (is ' . $label($s['status']) . ')';
    }
    elseif ($total !== $expectedTotal) {
      $verdict = "REFUSED — total $total != expected $expectedTotal";
    }
    else {
      $act[$id] = $expectedTotal;
      $verdict = 'will go Complete -> Invoiced';
    }
  }
  else {
    // totals phase.
    if ($s['status'] !== INVOICED) {
      $verdict = 'REFUSED — not Invoiced yet (is ' . $label($s['status']) . '); run the status phase first';
    }
    elseif ($total === $expectedTotal) {
      $verdict = 'total already correct — nothing to do';
    }
    else {
      $act[$id] = $expectedTotal;
      $verdict = "will restore total $total -> $expectedTotal";
    }
  }
  printf("%-8d %-10s %-9s %-10s %-10s %s\n", $id, mb_substr($label($s['status']), 0, 10),
    $invoiced ? 'YES' : 'no', $total, $expectedTotal, $verdict);
}

if ($phase === 'verify') {
  printf("\n%d correct, %d wrong.%s\n", count(EXPECTED) - $wrong, $wrong,
    $wrong ? '' : '  Status restored and historical totals intact.');
  return;
}
if (!$act) {
  print "\nNothing to do in this phase.\n";
  return;
}
if (!$apply) {
  printf("\nDRY RUN — %d work order(s) would change. Re-run with BOS_APPLY=1.\n", count($act));
  return;
}

print "\n";
if ($phase === 'status') {
  foreach ($act as $id => $expectedTotal) {
    printf("WO %d\n", $id);
    if ($err = $setStatus($id, COMPLETE, NOTE)) {
      printf("  FAILED at Complete: %s\n  left as-is.\n\n", $err);
      continue;
    }
    printf("  -> Complete\n");
    if ($err = $setStatus($id, INVOICED, NOTE)) {
      printf("  FAILED at Invoiced: %s\n  ** LEFT AT COMPLETE — needs attention **\n\n", $err);
      continue;
    }
    printf("  -> Invoiced\n\n");
  }
  print "Status phase done. The Complete hop will have recalculated some totals;\n"
    . "run the totals phase next, then the verify phase.\n";
}
else {
  foreach ($act as $id => $expectedTotal) {
    $wo = $etm->getStorage('work_order')->loadUnchanged($id);
    $wo->set('field_wo_total', $expectedTotal);
    $wo->save();
    printf("WO %-8d field_wo_total set to %s\n", $id, $expectedTotal);
  }
  print "\nTotals phase done. Now run the verify phase — in its own process,\n"
    . "because an in-request read here is not trustworthy.\n";
}
