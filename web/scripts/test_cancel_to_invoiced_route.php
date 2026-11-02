<?php

/**
 * @file
 * Can a wrongly-cancelled, already-billed work order be restored to Invoiced
 * without rewriting its historical totals?
 *
 * On 2026-11-01 `cancel_work_orders.php` cancelled WO 35107 and 35110. Both
 * were real 2024 jobs that had been completed and billed:
 *
 *   35107  Complete 07/22/2024, field_invoiced=YES, total $264.42
 *   35110  Complete 05/15/2024, Invoiced by Jackie Hodson 05/21/2024, $121.33
 *
 * The guard missed them because it tested `field_status` only, which read
 * "Active" / "Sold" — statuses outside its closed set — while the status
 * HISTORY, the `field_invoiced` flag and the populated totals all said billed.
 * One signal was checked and it was the wrong one.
 *
 * Restoring them is not a one-liner. `_wo_shared_assert_invoiced_transition_valid()`
 * blocks any save that moves field_status to Invoiced (1281) unless the prior
 * status was Complete (1097) or Invoiced. So Canceled -> Invoiced throws, and
 * the only legal route is Canceled -> Complete -> Invoiced.
 *
 * ⚠ THE RISK THIS SCRIPT MEASURES: the Complete step is exactly what fires the
 * `wo_*` recalculation (wo_shared returns early unless status === 1097). Those
 * modules recompute labor, trip fee and subtotals from CURRENT rates —
 * `business_setting`, `zipcodes.field_trip_fee` — not from 2024's. If they
 * overwrite $264.42 with a figure computed at today's rates, the repair has
 * silently restated an invoice the client already paid, which is a worse
 * outcome than the wrong status it was fixing, and BOS rule 5 says pricing
 * snapshots are immutable once a WO is complete.
 *
 * So: reproduce the live state on dev, walk the only legal route, and print the
 * before/after of every billing field.
 *
 * ⚠ ROLLS BACK. Uses an explicit $tx->rollBack(). Do NOT switch this to
 * unset($tx) — unsetting a Drupal Transaction COMMITS it, which is how test
 * data reached a real dev entity earlier in this work.
 *
 * DEV ONLY — refuses to run anywhere that looks like production.
 *
 * Run: ddev drush php:script web/scripts/test_cancel_to_invoiced_route.php
 */

const WO_IDS = [35107, 35110];
const COMPLETE = 1097;
const CANCELED = 1098;
const INVOICED = 1281;
const MONEY_FIELDS = [
  'field_wo_total',
  'field_labor_total',
  'field_trip_fee',
  'field_material_chemical_total',
  'field_rental_total',
  'field_dump_fee_total',
  'field_billing_adjustment',
];

// --- environment guard -------------------------------------------------------
// This writes entities and fires billing recalculation. It must never run on
// live, even though it rolls back: a throw between the writes and the rollBack
// would leave real records altered.
$host = \Drupal::request()->getHost();
$dbName = \Drupal::database()->getConnectionOptions()['database'] ?? '';
if (!str_contains($host, 'ddev') && !str_contains($dbName, 'db')) {
  printf("ABORT: this is DEV-ONLY. host=%s db=%s\n", $host, $dbName);
  return;
}
printf("environment: host=%s db=%s\n\n", $host, $dbName);

$etm = \Drupal::entityTypeManager();

/** Read every billing field plus status. */
$snapshot = static function (int $id) use ($etm): array {
  $wo = $etm->getStorage('work_order')->loadUnchanged($id);
  if (!$wo) {
    return [];
  }
  $out = ['_status' => (int) $wo->get('field_status')->target_id];
  $out['_invoiced'] = (int) ($wo->get('field_invoiced')->value ?? 0);
  foreach (MONEY_FIELDS as $f) {
    $out[$f] = $wo->hasField($f) ? $wo->get($f)->value : NULL;
  }
  return $out;
};

/** Drive status through the site's own audit-record mechanism. */
$setStatus = static function (int $id, int $tid, string $note) use ($etm): ?string {
  try {
    $etm->getStorage('wo_status_updates')->create([
      'type' => 'update',
      'field_status_of_wo' => $id,
      'field_status' => $tid,
      'field_status_change_note' => $note,
    ])->save();
    return NULL;
  }
  catch (\Throwable $e) {
    return $e->getMessage();
  }
};

$label = static fn(int $tid): string => ($t = \Drupal::entityTypeManager()
  ->getStorage('taxonomy_term')->load($tid)) ? $t->label() : (string) $tid;

$before = [];
foreach (WO_IDS as $id) {
  $before[$id] = $snapshot($id);
  if (!$before[$id]) {
    printf("WO %d not present on dev — cannot test.\n", $id);
    return;
  }
  printf("WO %-6d start: status=%-10s invoiced=%s total=%s\n",
    $id, $label($before[$id]['_status']), $before[$id]['_invoiced'] ? 'YES' : 'no',
    $before[$id]['field_wo_total'] ?? 'empty');
}

$tx = \Drupal::database()->startTransaction();
try {
  // 1) Reproduce the live state: cancel them.
  print "\n--- step 1: cancel (reproduce live) ---\n";
  foreach (WO_IDS as $id) {
    $err = $setStatus($id, CANCELED, 'TEST reproduce live cancellation');
    printf("  WO %-6d %s\n", $id, $err ? "THREW: $err" : 'now ' . $label($snapshot($id)['_status']));
  }

  // 2) The illegal shortcut — prove the guard actually blocks it.
  print "\n--- step 2: Canceled -> Invoiced directly (expected to be refused) ---\n";
  foreach (WO_IDS as $id) {
    $err = $setStatus($id, INVOICED, 'TEST illegal direct transition');
    $now = $snapshot($id)['_status'];
    printf("  WO %-6d %s  (status now %s)\n", $id,
      $err ? 'REFUSED: ' . mb_substr($err, 0, 60) : 'ALLOWED — guard did NOT fire',
      $label($now));
  }

  // 3) The legal route, one hop at a time, measuring after each.
  print "\n--- step 3: Canceled -> Complete (this is what fires the recalc) ---\n";
  foreach (WO_IDS as $id) {
    $err = $setStatus($id, COMPLETE, 'TEST restore to Complete');
    $after = $snapshot($id);
    printf("  WO %-6d %s\n", $id, $err ? "THREW: $err" : 'now ' . $label($after['_status']));
    foreach (MONEY_FIELDS as $f) {
      $was = $before[$id][$f];
      $is = $after[$f];
      if ((string) $was !== (string) $is) {
        printf("      ** CHANGED %-32s %s -> %s\n", $f, $was ?? 'empty', $is ?? 'empty');
      }
    }
  }

  print "\n--- step 4: Complete -> Invoiced ---\n";
  foreach (WO_IDS as $id) {
    $err = $setStatus($id, INVOICED, 'TEST restore to Invoiced');
    $after = $snapshot($id);
    printf("  WO %-6d %s  invoiced=%s\n", $id,
      $err ? "THREW: $err" : 'now ' . $label($after['_status']),
      $after['_invoiced'] ? 'YES' : 'no');
  }

  // 5) The verdict: did any money move across the whole journey?
  print "\n--- verdict: billing fields, start vs end ---\n";
  $moved = 0;
  foreach (WO_IDS as $id) {
    $after = $snapshot($id);
    printf("  WO %d  (%s -> %s)\n", $id, $label($before[$id]['_status']), $label($after['_status']));
    foreach (MONEY_FIELDS as $f) {
      $was = $before[$id][$f];
      $is = $after[$f];
      $same = (string) $was === (string) $is;
      if (!$same) {
        $moved++;
      }
      printf("      %-32s %-10s -> %-10s %s\n", $f, $was ?? 'empty', $is ?? 'empty',
        $same ? '' : '** CHANGED **');
    }
  }
  printf("\n%s\n", $moved === 0
    ? 'SAFE: the route restores status without touching a single billing figure.'
    : "UNSAFE: $moved billing field(s) were rewritten — do NOT run this on live.");
}
finally {
  // Explicit. unset() would COMMIT.
  $tx->rollBack();
  print "\nrolled back.\n";
}

print "\npost-rollback check (must match the start values):\n";
foreach (WO_IDS as $id) {
  $now = $snapshot($id);
  $clean = $now['_status'] === $before[$id]['_status']
    && (string) $now['field_wo_total'] === (string) $before[$id]['field_wo_total'];
  printf("  WO %-6d status=%-10s total=%-10s %s\n", $id, $label($now['_status']),
    $now['field_wo_total'] ?? 'empty', $clean ? 'clean' : '** DIRTY — INVESTIGATE **');
}
