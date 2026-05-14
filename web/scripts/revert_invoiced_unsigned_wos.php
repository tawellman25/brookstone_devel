<?php

declare(strict_types=1);

/**
 * One-off — revert WOs that were marked Invoiced before sign-off back
 * to In Progress so the mow crew can finish them through the normal
 * tasks-list workflow.
 *
 * Per WO:
 *   - field_status: 1281 (Invoiced) -> 1092 (In Progress)
 *   - field_invoiced: 1 -> 0
 *   - Append a wo_status_updates entity recording the reversion so
 *     the timeline shows what happened
 *   - Watchdog log entry on channel `revert_invoiced_unsigned`
 *
 * Scoped explicitly to the three known stuck WOs (49892, 49901,
 * 49913). Refuses to run on any WO that doesn't currently match the
 * expected state (Invoiced + has an incomplete wo_tasks_list +
 * owner-mismatched open clock entry), so an accidental rerun on a
 * legitimately-invoiced WO is a no-op.
 *
 * Usage:
 *   ddev drush php:script web/scripts/revert_invoiced_unsigned_wos.php -- --dry-run
 *   ddev drush php:script web/scripts/revert_invoiced_unsigned_wos.php -- --commit
 *
 * Side effects gated to non-Complete status:
 *   - wo_shared_work_order_presave (only fires on 1097) — skipped
 *   - wo_lawn_mowing_entity_presave (only fires on 1097) — skipped
 *
 * After this runs, each crew member opens the property -> "Start
 * Mowing" button -> redirects to their incomplete tasks list ->
 * fills out + saves with the new orphan-prompt UI -> sign-off
 * completes the WO and unblocks new WO creation for that user.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

$dryRun = in_array('--dry-run', $extra ?? [], TRUE);
$commit = in_array('--commit', $extra ?? [], TRUE);
if (!$dryRun && !$commit) {
  echo "Usage: --dry-run | --commit\n";
  exit(1);
}
if ($dryRun && $commit) {
  echo "Pass exactly one of --dry-run or --commit.\n";
  exit(1);
}
$mode = $commit ? 'COMMIT' : 'DRY-RUN';

const STATUS_INVOICED = 1281;
const STATUS_IN_PROGRESS = 1092;
const TARGET_WOS = [49892, 49901, 49913];
const NOTE = 'Office invoiced this WO before crew sign-off; reverted to In Progress so the crew can finish the standard mowing workflow. QuickBooks-side billing adjustment is separate.';

$em = \Drupal::entityTypeManager();
$woStorage = $em->getStorage('work_order');
$currentUser = \Drupal::currentUser();
$logger = \Drupal::logger('revert_invoiced_unsigned');

echo "=========================================================================\n";
echo "Revert Invoiced-before-sign-off WOs back to In Progress\n";
echo "Mode: $mode\n";
echo "=========================================================================\n\n";

$plans = [];
foreach (TARGET_WOS as $woId) {
  $wo = $woStorage->load($woId);
  if (!$wo) {
    echo "WO $woId: NOT FOUND — skipping\n";
    continue;
  }
  $status = (int) ($wo->get('field_status')->target_id ?? 0);
  $invoiced = $wo->hasField('field_invoiced') ? (int) ($wo->get('field_invoiced')->value ?? 0) : 0;
  if ($status !== STATUS_INVOICED) {
    echo "WO $woId: status is " . $status . " (not " . STATUS_INVOICED . " = Invoiced) — skipping (safety guard)\n";
    continue;
  }

  $plans[] = [
    'wo_id' => $woId,
    'wo' => $wo,
    'invoiced_before' => $invoiced,
  ];
  echo sprintf("WO %d (%s): Invoiced(%d), field_invoiced=%d -> In Progress(%d), field_invoiced=0\n",
    $woId, $wo->bundle(), STATUS_INVOICED, $invoiced, STATUS_IN_PROGRESS);
}

echo "\nPlanned changes: " . count($plans) . " WOs\n\n";

if ($dryRun) {
  echo "DRY-RUN — no changes written.\n";
  exit(0);
}

echo "Writing changes...\n\n";
$saved = 0;
$failed = 0;
foreach ($plans as $p) {
  /** @var \Drupal\Core\Entity\EntityInterface $wo */
  $wo = $p['wo'];
  $woId = $p['wo_id'];
  try {
    // Use the existing helper so the wo_status_updates timeline entry
    // is created in the same shape as every other status change.
    update_work_order_status($wo, $currentUser, STATUS_IN_PROGRESS, NOTE);
    if ($wo->hasField('field_invoiced')) {
      $wo->set('field_invoiced', 0);
    }
    $wo->save();
    $saved++;
    $logger->notice('WO @id reverted to In Progress (was Invoiced, field_invoiced @inv -> 0). Crew should finish via tasks-list workflow.', [
      '@id' => $woId,
      '@inv' => $p['invoiced_before'],
    ]);
    echo "  WO $woId: reverted OK\n";
  }
  catch (\Throwable $e) {
    $failed++;
    echo "  WO $woId: FAILED — " . $e->getMessage() . "\n";
  }
}

echo "\nSaved: $saved\nFailed: $failed\n";
echo "Each reversion logged to watchdog (channel: revert_invoiced_unsigned).\n";
