<?php

declare(strict_types=1);

/**
 * Backfill — recalculate work_order.field_total_time on WOs affected by
 * the crew-count multiplier bug (commit 34c8ddc0 removed the bug
 * forward-going; this script fixes existing data).
 *
 * Scope: every wo_complete_info changed since 2026-04-25 whose parent WO
 * (a) has 2+ wo_time_clock entries — i.e. Phase 2c reconciliation has
 *     split labor into per-crew-member entries, so the sum already
 *     equals total man-hours and the legacy multiplier double-counted
 * AND
 * (b) currently has field_total_time != sum(wo_time_clock.field_total_time
 *     across the WO's clock entries).
 *
 * Pattern-A WOs (TC=1 entry, the foreman only) are excluded: their
 * stored value reflects the original intended "× crew_count"
 * approximation, not a Phase-2c-caused bug.
 *
 * Per-record operation: set work_order.field_total_time to the correct
 * sum and save the WO.
 *   - For lawn_mowing bundle, the (now-fixed) wo_lawn_mowing presave
 *     also runs and produces the same value plus refreshes
 *     field_labor_total, field_task_rate, etc. (all should be idempotent
 *     since their underlying inputs haven't changed).
 *   - For non-mowing bundles, the direct set is final; no per-bundle
 *     presave touches field_total_time on the WO itself.
 *
 * Usage:
 *   ddev drush php:script web/scripts/backfill_wo_total_time.php -- --dry-run
 *   ddev drush php:script web/scripts/backfill_wo_total_time.php -- --commit
 *
 * Without either flag the script exits without doing anything.
 *
 * Output: per-WO change record (id, bundle, status, crew, before, after,
 * delta) plus a summary footer.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

$dryRun = in_array('--dry-run', $extra ?? [], TRUE);
$commit = in_array('--commit', $extra ?? [], TRUE);

if (!$dryRun && !$commit) {
  echo "Usage: --dry-run | --commit\n";
  echo "Refusing to run without an explicit mode flag.\n";
  exit(1);
}
if ($dryRun && $commit) {
  echo "Pass exactly one of --dry-run or --commit, not both.\n";
  exit(1);
}

$mode = $commit ? 'COMMIT' : 'DRY-RUN';
echo "=========================================================================\n";
echo "Backfill mode: $mode\n";
echo "=========================================================================\n\n";

$em = \Drupal::entityTypeManager();
$db = \Drupal::database();
$woStorage = $em->getStorage('work_order');
$tcStorage = $em->getStorage('wo_time_clock');
$ciStorage = $em->getStorage('wo_complete_info');
$termStorage = $em->getStorage('taxonomy_term');

$cutoff = strtotime('2026-04-25 00:00:00');

// Hand-excluded WOs (per-WO decision; reasons in comments).
$excludeWos = [
  48948, // Spring Cleanup — stale field_total_time from before Todd's
         // manual clock-entry fixes; -828 hr correction needs eyeball
         // audit in BOS before applying. Re-include in a second pass
         // after confirming the underlying clock entries are stable.
  48480, // sprinkler_start_up — current field_total_time is -5.35,
         // suggesting corrupted underlying clock-entry data (negative
         // duration). Fix the corrupt entry first, then re-include.
];

$ciIds = $db->select('wo_complete_info_field_data', 'c')
  ->fields('c', ['id'])
  ->condition('c.changed', $cutoff, '>=')
  ->execute()->fetchCol();

// Track each WO once even if it has multiple wo_complete_info rows.
$woTargets = [];
foreach ($ciIds as $cid) {
  $ci = $ciStorage->load($cid);
  if (!$ci) {
    continue;
  }
  $woId = $ci->hasField('field_work_order') ? ($ci->get('field_work_order')->target_id ?? NULL) : NULL;
  if (!$woId) {
    continue;
  }
  $woTargets[$woId] = TRUE;
}

$statusCache = [];
$statusName = function ($tid) use ($termStorage, &$statusCache) {
  if (!$tid) {
    return '(none)';
  }
  if (isset($statusCache[$tid])) {
    return $statusCache[$tid];
  }
  $t = $termStorage->load($tid);
  return $statusCache[$tid] = $t ? $t->label() : "tid:$tid";
};

$changes = [];
$nochange = 0;
$skipped = 0;
$singleEntry = 0;

$excluded = 0;
foreach (array_keys($woTargets) as $woId) {
  if (in_array($woId, $excludeWos, TRUE)) {
    $excluded++;
    continue;
  }
  $wo = $woStorage->load($woId);
  if (!$wo) {
    $skipped++;
    continue;
  }
  if (!$wo->hasField('field_total_time')) {
    $skipped++;
    continue;
  }

  $tcIds = \Drupal::entityQuery('wo_time_clock')
    ->accessCheck(FALSE)
    ->condition('field_work_order', $woId)
    ->condition('field_end_time', NULL, 'IS NOT NULL')
    ->execute();
  $tcCount = count($tcIds);

  // Pattern A — only the foreman clocked. The historical "× crew_count"
  // formula was an intentional approximation in that case; skip.
  if ($tcCount < 2) {
    $singleEntry++;
    continue;
  }

  $sum = 0.0;
  foreach ($tcStorage->loadMultiple($tcIds) as $tc) {
    $sum += (float) ($tc->get('field_total_time')->value ?? 0);
  }
  // Match the canonical 2-decimal rounding that wo_total_time uses on
  // per-entry totals so we don't churn over float noise.
  $correct = round($sum, 2);

  $current = (float) ($wo->get('field_total_time')->value ?? 0);
  if (abs($current - $correct) < 0.005) {
    $nochange++;
    continue;
  }

  $changes[] = [
    'wo_id' => $woId,
    'bundle' => $wo->bundle(),
    'status' => $statusName($wo->get('field_status')->target_id ?? NULL),
    'tc_count' => $tcCount,
    'before' => $current,
    'after' => $correct,
    'delta' => $correct - $current,
  ];
}

usort($changes, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));

echo sprintf("%-7s %-22s %-12s %-5s %-10s %-10s %-10s\n",
  "WO_ID", "BUNDLE", "STATUS", "TC", "BEFORE", "AFTER", "DELTA");
echo str_repeat('-', 80) . "\n";
foreach ($changes as $c) {
  echo sprintf("%-7d %-22s %-12s %-5d %-10.2f %-10.2f %-+10.2f\n",
    $c['wo_id'], substr($c['bundle'], 0, 22), substr($c['status'], 0, 12),
    $c['tc_count'], $c['before'], $c['after'], $c['delta']);
}

echo str_repeat('-', 80) . "\n";
echo sprintf("Mode:           %s\n", $mode);
echo sprintf("WOs scanned:    %d\n", count($woTargets));
echo sprintf("Would change:   %d\n", count($changes));
echo sprintf("Already correct: %d\n", $nochange);
echo sprintf("Skipped (Pattern A — TC<2 — historical formula): %d\n", $singleEntry);
echo sprintf("Hand-excluded WOs: %d (ids: %s)\n", $excluded, implode(',', $excludeWos));
echo sprintf("Skipped (missing/no field): %d\n", $skipped);
echo sprintf("Total hours of overcount being corrected: %.2f\n",
  array_sum(array_map(fn($c) => -$c['delta'], $changes)));
echo "\n";

if ($dryRun) {
  echo "DRY-RUN — no changes written.\n";
  exit(0);
}

// Commit mode.
echo "Writing changes...\n\n";
$saved = 0;
$failed = 0;
foreach ($changes as $c) {
  try {
    $wo = $woStorage->loadUnchanged($c['wo_id']);
    if (!$wo) {
      $failed++;
      continue;
    }
    $wo->set('field_total_time', $c['after']);
    $wo->save();
    $saved++;
    \Drupal::logger('backfill_wo_total_time')->notice(
      'WO @id field_total_time corrected: @before -> @after (delta @delta, bundle @bundle)',
      [
        '@id' => $c['wo_id'],
        '@before' => number_format($c['before'], 2),
        '@after' => number_format($c['after'], 2),
        '@delta' => number_format($c['delta'], 2),
        '@bundle' => $c['bundle'],
      ]
    );
  }
  catch (\Throwable $e) {
    $failed++;
    echo "  WO {$c['wo_id']} FAILED: " . $e->getMessage() . "\n";
  }
}

echo "\n";
echo sprintf("Saved: %d\n", $saved);
echo sprintf("Failed: %d\n", $failed);
echo "Each correction logged to watchdog (channel: backfill_wo_total_time).\n";
