<?php

declare(strict_types=1);

/**
 * Diagnostic — Mowing WO labor double-counting investigation.
 *
 * Read-only report. Run via:
 *   ddev drush php:script web/scripts/diagnostic_mowing_labor_overcounting.php
 *
 * Four parts:
 *   1. Multiplier code locations (with surrounding context).
 *   2. Scope of wo_complete_info entities since 2026-04-25, with overcount
 *      computed vs corrected formula.
 *   3. Bundle distribution of overcounts.
 *   4. Git history of multiplier additions.
 *
 * No data modification. The sole side effect is stdout.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

$em = \Drupal::entityTypeManager();
$db = \Drupal::database();
$termStorage = $em->getStorage('taxonomy_term');

$projectRoot = realpath(DRUPAL_ROOT . '/..');
$cutoffStr = '2026-04-25';
$cutoff = strtotime($cutoffStr . ' 00:00:00');

// =============================================================================
// PART 1 — Multiplier code locations
// =============================================================================

echo "=========================================================================\n";
echo "PART 1 — Multiplier code locations\n";
echo "=========================================================================\n\n";

$locations = [
  [
    'file' => 'web/modules/custom/wo_sign_off/wo_sign_off.module',
    'range' => [127, 155],
    'key_lines' => [134, 149],
    'description' => "wo_complete_info presave (any bundle EXCEPT those listed in \$excludedBundles).\n" .
                     "  - \$totalMen = wo_complete_info.field_those_on_crew->count()\n" .
                     "  - \$timeSpent = sum of wo_time_clock.field_total_time across this WO's entries\n" .
                     "  - Sets WO.field_total_time = \$timeSpent * \$totalMen (BUG when multi-clock entries exist).",
  ],
  [
    'file' => 'web/modules/custom/wo_lawn_mowing/wo_lawn_mowing.module',
    'range' => [186, 271],
    'key_lines' => [194, 196, 270],
    'description' => "work_order:lawn_mowing presave (lawn_mowing IS excluded from wo_sign_off's multiplier path).\n" .
                     "  - \$timeSpent = get_hours_for_lawn_mowing() — sum of wo_time_clock entries\n" .
                     "  - \$menTotal  = get_lawn_mowing_task_data()['men_on_site']\n" .
                     "                  -> wo_tasks_list.lawn_mowing.field_mowing_who_on_site->count()\n" .
                     "  - Sets WO.field_total_time = \$timeSpent * \$menTotal (BUG, same shape as wo_sign_off).",
  ],
  [
    'file' => 'web/modules/custom/wo_total_time/wo_total_time.module',
    'range' => [30, 70],
    'key_lines' => [],
    'description' => "Per-entry roll-up only — turns start/end timestamps into per-entry field_total_time on\n" .
                     "  individual wo_time_clock records. Does NOT multiply by crew size. Not a source of the bug.",
  ],
];

foreach ($locations as $loc) {
  echo "FILE: {$loc['file']}\n";
  echo "KEY LINES: " . (empty($loc['key_lines']) ? '(none)' : implode(',', $loc['key_lines'])) . "\n";
  echo "WHAT IT DOES:\n  {$loc['description']}\n\n";
  echo "CONTEXT (lines {$loc['range'][0]}-{$loc['range'][1]}):\n";

  $abs = $projectRoot . '/' . $loc['file'];
  if (!file_exists($abs)) {
    echo "  (file not readable at $abs)\n\n";
    continue;
  }
  $lines = file($abs);
  for ($i = $loc['range'][0]; $i <= $loc['range'][1] && $i <= count($lines); $i++) {
    $marker = in_array($i, $loc['key_lines']) ? '  <<< MULTIPLIER >>>' : '';
    echo sprintf("  %4d | %s%s\n", $i, rtrim($lines[$i - 1]), $marker);
  }
  echo "\n";
}

// =============================================================================
// PART 2 — Scope of affected WOs
// =============================================================================

echo "=========================================================================\n";
echo "PART 2 — wo_complete_info entities since $cutoffStr\n";
echo "=========================================================================\n\n";

$cinfoIds = $db->select('wo_complete_info_field_data', 'c')
  ->fields('c', ['id'])
  ->condition('c.changed', $cutoff, '>=')
  ->execute()->fetchCol();

$cinfoStorage = $em->getStorage('wo_complete_info');
$woStorage = $em->getStorage('work_order');
$tcStorage = $em->getStorage('wo_time_clock');
$tlStorage = $em->getStorage('wo_tasks_list');

$signOffExcludedBundles = [
  'dethatching', 'lawn_mowing', 'landscaping', 'sprinkler_repair', 'sprinkler_check_up',
  'sprinkler_start_up', 'sprinkler_winterizing', 'sprinkler_installation', 'sprinkler_design',
  'in_house_tasks', 'christmas_decorations', 'misc_services',
];

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

$rows = [];
foreach ($cinfoIds as $cid) {
  $cinfo = $cinfoStorage->load($cid);
  if (!$cinfo) {
    continue;
  }
  $cinfoBundle = $cinfo->bundle();
  $woId = $cinfo->hasField('field_work_order') ? ($cinfo->get('field_work_order')->target_id ?? NULL) : NULL;
  $wo = $woId ? $woStorage->load($woId) : NULL;
  if (!$wo) {
    continue;
  }
  $woBundle = $wo->bundle();
  $woTitle = $wo->label();
  $woStatus = $statusName($wo->get('field_status')->target_id ?? NULL);

  if ($cinfoBundle === 'lawn_mowing') {
    $tasksIds = \Drupal::entityQuery('wo_tasks_list')
      ->accessCheck(FALSE)
      ->condition('field_work_order', $woId)
      ->condition('type', 'lawn_mowing')
      ->execute();
    $crewCount = 0;
    if ($tasksIds) {
      $tl = $tlStorage->load(reset($tasksIds));
      if ($tl && $tl->hasField('field_mowing_who_on_site')) {
        $crewCount = $tl->get('field_mowing_who_on_site')->count();
      }
    }
    $multiplierSource = 'wo_tasks_list.field_mowing_who_on_site';
    $multiplierApplied = TRUE;
  }
  else {
    $crewCount = $cinfo->hasField('field_those_on_crew') ? $cinfo->get('field_those_on_crew')->count() : 0;
    $multiplierSource = 'wo_complete_info.field_those_on_crew';
    $multiplierApplied = !in_array($woBundle, $signOffExcludedBundles);
  }

  $tcIds = \Drupal::entityQuery('wo_time_clock')
    ->accessCheck(FALSE)
    ->condition('field_work_order', $woId)
    ->condition('field_end_time', NULL, 'IS NOT NULL')
    ->execute();
  $tcCount = count($tcIds);
  $sumHours = 0.0;
  if ($tcCount) {
    foreach ($tcStorage->loadMultiple($tcIds) as $tc) {
      $sumHours += (float) ($tc->get('field_total_time')->value ?? 0);
    }
  }

  $currentTotal = $wo->hasField('field_total_time') ? (float) ($wo->get('field_total_time')->value ?? 0) : 0.0;
  $bugFormula = $multiplierApplied ? ($sumHours * $crewCount) : $sumHours;
  $correctFormula = $sumHours;
  $overcount = $bugFormula - $correctFormula;

  $rows[] = [
    'cid' => $cid,
    'cinfo_bundle' => $cinfoBundle,
    'wo_id' => $woId,
    'wo_title' => $woTitle,
    'wo_bundle' => $woBundle,
    'wo_status' => $woStatus,
    'crew_count' => $crewCount,
    'tc_count' => $tcCount,
    'sum_hours' => $sumHours,
    'current_total' => $currentTotal,
    'bug_formula' => $bugFormula,
    'correct_formula' => $correctFormula,
    'overcount' => $overcount,
    'multiplier_applied' => $multiplierApplied,
    'multiplier_source' => $multiplierSource,
  ];
}

usort($rows, fn($a, $b) => $b['overcount'] <=> $a['overcount']);

$total = count($rows);
$over = array_filter($rows, fn($r) => $r['overcount'] > 0.001);
$invoiced = array_filter($over, fn($r) => in_array($r['wo_status'], ['Invoiced', 'Paid']));
$completeOnly = array_filter($over, fn($r) => $r['wo_status'] === 'Complete');

echo "SUMMARY\n";
echo "  Cutoff: $cutoffStr (changed >= this date)\n";
echo "  Total wo_complete_info entities since cutoff: $total\n";
echo "  With potential overcount > 0: " . count($over) . "\n";
echo "  Total overcount in hours (all affected): " . number_format(array_sum(array_column($over, 'overcount')), 2) . "\n";
echo "  Subtotal on Invoiced/Paid WOs (customer impact): " . number_format(array_sum(array_column($invoiced, 'overcount')), 2) . "\n";
echo "  Subtotal on Complete (not yet invoiced — fixable):  " . number_format(array_sum(array_column($completeOnly, 'overcount')), 2) . "\n\n";

echo "DETAIL — sorted by overcount descending (top 100)\n";
echo str_repeat('-', 142) . "\n";
echo sprintf("%-6s %-16s %-7s %-22s %-12s %-9s %-5s %-5s %-8s %-8s %-8s %-8s %-8s\n",
  "CI_ID", "CI_BUNDLE", "WO_ID", "WO_BUNDLE", "WO_STATUS", "MULT_APPL", "CREW", "TC", "SUMHRS", "CURTOT", "BUG", "CORRECT", "OVER");
echo str_repeat('-', 142) . "\n";
$shown = 0;
foreach ($rows as $r) {
  if ($shown >= 100) {
    break;
  }
  if ($r['overcount'] <= 0.001) {
    continue;
  }
  $shown++;
  echo sprintf("%-6d %-16s %-7d %-22s %-12s %-9s %-5d %-5d %-8.2f %-8.2f %-8.2f %-8.2f %-8.2f\n",
    $r['cid'],
    substr($r['cinfo_bundle'], 0, 16),
    $r['wo_id'],
    substr($r['wo_bundle'], 0, 22),
    substr($r['wo_status'], 0, 12),
    $r['multiplier_applied'] ? 'yes' : 'no',
    $r['crew_count'],
    $r['tc_count'],
    $r['sum_hours'],
    $r['current_total'],
    $r['bug_formula'],
    $r['correct_formula'],
    $r['overcount']);
}
if ($shown === 0) {
  echo "  (no rows with overcount > 0)\n";
}
echo "\n";

// =============================================================================
// PART 3 — Bundle distribution
// =============================================================================

echo "=========================================================================\n";
echo "PART 3 — Distribution by wo_complete_info bundle\n";
echo "=========================================================================\n\n";

$byBundle = [];
foreach ($rows as $r) {
  $b = $r['cinfo_bundle'];
  $byBundle[$b] ??= ['count' => 0, 'affected' => 0, 'overcount' => 0.0];
  $byBundle[$b]['count']++;
  if ($r['overcount'] > 0.001) {
    $byBundle[$b]['affected']++;
    $byBundle[$b]['overcount'] += $r['overcount'];
  }
}

uasort($byBundle, fn($a, $b) => $b['overcount'] <=> $a['overcount']);

echo sprintf("%-22s %-10s %-12s %-15s\n", "CI_BUNDLE", "TOTAL", "AFFECTED", "OVERCOUNT_HRS");
echo str_repeat('-', 65) . "\n";
foreach ($byBundle as $b => $info) {
  echo sprintf("%-22s %-10d %-12d %-15.2f\n", $b, $info['count'], $info['affected'], $info['overcount']);
}
echo "\n";

// =============================================================================
// PART 4 — Git history of multiplier additions
// =============================================================================

echo "=========================================================================\n";
echo "PART 4 — Git history of multiplier additions\n";
echo "=========================================================================\n\n";

$modules = [
  'wo_sign_off'    => 'web/modules/custom/wo_sign_off/wo_sign_off.module',
  'wo_lawn_mowing' => 'web/modules/custom/wo_lawn_mowing/wo_lawn_mowing.module',
  'wo_total_time'  => 'web/modules/custom/wo_total_time/wo_total_time.module',
];

$gitAvailable = trim((string) shell_exec("git -C " . escapeshellarg($projectRoot) . " rev-parse --is-inside-work-tree 2>&1")) === 'true';

if (!$gitAvailable) {
  echo "  (git not available or not inside a working tree — skipping)\n\n";
}
else {
  foreach ($modules as $label => $file) {
    echo "--- $label ($file) ---\n";
    $cmd = "git -C " . escapeshellarg($projectRoot)
         . " log --pretty=format:'%h %ad %s' --date=short"
         . " -S 'totalMen' -S 'menTotal' -S 'those_on_crew' -S 'men_on_site'"
         . " --all -- " . escapeshellarg($file) . " 2>&1";
    $out = shell_exec($cmd);
    echo $out ? rtrim($out) . "\n" : "  (no matching commits)\n";
    echo "\n";
  }

  // Try to surface the introduction of the bug formula specifically.
  echo "--- Commits that touched the multiplier line in wo_sign_off.module ---\n";
  $cmd = "git -C " . escapeshellarg($projectRoot)
       . " log --pretty=format:'%h %ad %s' --date=short -L '/totalMen/,/totalMen/:" . escapeshellarg($modules['wo_sign_off']) . "' 2>&1 | head -40";
  $out = shell_exec($cmd);
  echo $out ? rtrim($out) . "\n" : "  (no output)\n";
  echo "\n";
}

echo "=========================================================================\n";
echo "Diagnostic complete. Read-only — no entities modified.\n";
echo "=========================================================================\n";
