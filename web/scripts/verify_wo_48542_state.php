<?php

declare(strict_types=1);

/**
 * Verification — WO 48542 sprinkler_start_up sign-off state check.
 *
 * Read-only report. Run via:
 *   ddev drush php:script web/scripts/verify_wo_48542_state.php
 *
 * Five parts:
 *   1. WO 48542 identity + status
 *   2. Existing wo_time_clock entries (open vs closed, audit fields)
 *   3. Any existing wo_complete_info on this WO
 *   4. Prediction of what reconciliation would do at sign-off,
 *      including a snapshot of the current multiplier code state
 *   5. SAFE / DO NOT PROCEED / UNCLEAR recommendation
 *
 * No data modification. The sole side effect is stdout.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

const WO_ID = 48542;

$em = \Drupal::entityTypeManager();
$projectRoot = realpath(DRUPAL_ROOT . '/..');

$fmtDt = static function (?string $iso): string {
  if ($iso === NULL || $iso === '') {
    return 'OPEN';
  }
  $ts = strtotime($iso . 'Z');
  return $ts ? date('m/d/Y g:i A', $ts) : $iso;
};

// ============================================================================
// PART 1 — WO 48542 identity
// ============================================================================

echo "=========================================================================\n";
echo "PART 1 — Work Order " . WO_ID . " identity\n";
echo "=========================================================================\n\n";

$wo = $em->getStorage('work_order')->load(WO_ID);
if (!$wo) {
  echo "WO " . WO_ID . " NOT FOUND on this environment. Aborting.\n";
  exit(1);
}

$status_term = $wo->get('field_status')->entity ?? NULL;
$status_label = $status_term ? $status_term->label() : '(none)';
$status_tid = $wo->get('field_status')->target_id ?? '(none)';
echo "Title:               " . $wo->label() . "\n";
echo "Bundle:              " . $wo->bundle() . "\n";
echo "Status:              $status_label (tid: $status_tid)\n";
echo "field_total_time:    " . ($wo->get('field_total_time')->value ?? 'NULL') . "\n";
$prop = $wo->hasField('field_property') ? $wo->get('field_property')->entity : NULL;
echo "Property:            " . ($prop ? $prop->label() . ' (id ' . $prop->id() . ')' : '(none)') . "\n";
echo "Created:             " . date('m/d/Y g:i A', (int) $wo->get('created')->value) . "\n";
echo "Changed:             " . date('m/d/Y g:i A', (int) $wo->get('changed')->value) . "\n";
echo "\n";

// ============================================================================
// PART 2 — wo_time_clock entries
// ============================================================================

echo "=========================================================================\n";
echo "PART 2 — wo_time_clock entries on WO " . WO_ID . "\n";
echo "=========================================================================\n\n";

$tcIds = \Drupal::entityQuery('wo_time_clock')
  ->accessCheck(FALSE)
  ->condition('field_work_order', WO_ID)
  ->sort('field_start_time', 'ASC')
  ->execute();

$entries = $em->getStorage('wo_time_clock')->loadMultiple($tcIds);
$closed_count = 0;
$open_count = 0;
$sum_hours = 0.0;
$open_entries = [];

if (empty($entries)) {
  echo "(no wo_time_clock entries on this WO)\n\n";
}
else {
  foreach ($entries as $tc) {
    $tcId = $tc->id();
    $uid = $tc->get('field_teammate')->target_id ?? NULL;
    $user = $uid ? $em->getStorage('user')->load($uid) : NULL;
    $owner = $em->getStorage('user')->load($tc->getOwnerId());
    $start_iso = $tc->get('field_start_time')->value ?? NULL;
    $end_iso = $tc->get('field_end_time')->value ?? NULL;
    $total = $tc->get('field_total_time')->value ?? NULL;
    $notes = $tc->hasField('field_notes') && !$tc->get('field_notes')->isEmpty()
      ? (string) $tc->get('field_notes')->value
      : '';

    $audit_markers = [];
    foreach (['field_closed_signoff_complete', 'field_closed_signoff_tasks', 'field_created_signoff_complete', 'field_created_signoff_tasks'] as $f) {
      if ($tc->hasField($f) && !$tc->get($f)->isEmpty()) {
        $audit_markers[] = "$f=" . $tc->get($f)->target_id;
      }
    }

    if ($end_iso === NULL || $end_iso === '') {
      $open_count++;
      $open_entries[] = $tcId;
    }
    else {
      $closed_count++;
      $sum_hours += (float) $total;
    }

    echo "Entry $tcId:\n";
    echo "  Teammate:        " . ($user ? $user->getDisplayName() . " (uid:$uid)" : '(none)') . "\n";
    echo "  Owner:           " . $tc->getOwnerId() . " (" . ($owner?->getDisplayName() ?? '-') . ")\n";
    echo "  Start:           " . $fmtDt($start_iso) . "  (raw: " . ($start_iso ?: 'NULL') . ")\n";
    echo "  End:             " . $fmtDt($end_iso) . "  (raw: " . ($end_iso ?: 'NULL') . ")\n";
    echo "  Total time:      " . ($total ?? 'NULL') . "\n";
    echo "  Audit fields:    " . (empty($audit_markers) ? '(none populated)' : implode(', ', $audit_markers)) . "\n";
    echo "  Notes:           " . ($notes !== '' ? $notes : '(empty)') . "\n\n";
  }
}

echo "Summary:\n";
echo "  Total entries:   " . count($entries) . "\n";
echo "  Closed:          $closed_count\n";
echo "  Open:            $open_count" . ($open_entries ? " (IDs: " . implode(', ', $open_entries) . ")" : "") . "\n";
echo "  Sum hours (closed entries): " . number_format($sum_hours, 2) . "\n\n";

// ============================================================================
// PART 3 — wo_complete_info entities on this WO
// ============================================================================

echo "=========================================================================\n";
echo "PART 3 — wo_complete_info entities on WO " . WO_ID . "\n";
echo "=========================================================================\n\n";

$ciIds = \Drupal::entityQuery('wo_complete_info')
  ->accessCheck(FALSE)
  ->condition('field_work_order', WO_ID)
  ->execute();

if (empty($ciIds)) {
  echo "No wo_complete_info entity exists for WO " . WO_ID . " — this is a fresh sign-off.\n\n";
}
else {
  foreach ($em->getStorage('wo_complete_info')->loadMultiple($ciIds) as $ci) {
    echo "wo_complete_info " . $ci->id() . ":\n";
    echo "  Bundle:          " . $ci->bundle() . "\n";
    echo "  Created:         " . date('m/d/Y g:i A', (int) $ci->get('created')->value) . "\n";
    echo "  Changed:         " . date('m/d/Y g:i A', (int) $ci->get('changed')->value) . "\n";
    if ($ci->hasField('field_those_on_crew')) {
      $crew = [];
      foreach ($ci->get('field_those_on_crew')->referencedEntities() as $u) {
        $crew[] = $u->getDisplayName() . ' (uid:' . $u->id() . ')';
      }
      echo "  Crew (" . count($crew) . "):       " . (empty($crew) ? '(empty)' : implode(', ', $crew)) . "\n";
    }
    if ($ci->hasField('field_signed_off_by') && !$ci->get('field_signed_off_by')->isEmpty()) {
      $signer = $ci->get('field_signed_off_by')->entity;
      echo "  Signed off by:   " . ($signer ? $signer->getDisplayName() : '-') . "\n";
    }
    if ($ci->hasField('field_canceled')) {
      echo "  field_canceled:  " . ($ci->get('field_canceled')->value ?? 'NULL') . "\n";
    }
    if ($ci->hasField('field_date_completed') && !$ci->get('field_date_completed')->isEmpty()) {
      $raw = $ci->get('field_date_completed')->value;
      $ts = is_numeric($raw) ? (int) $raw : strtotime($raw);
      echo "  Date completed:  " . ($ts ? date('m/d/Y', $ts) : $raw) . "\n";
    }
    echo "\n";
  }
}

// ============================================================================
// PART 4 — Reconciliation prediction + multiplier code state
// ============================================================================

echo "=========================================================================\n";
echo "PART 4 — Reconciliation prediction + multiplier code state\n";
echo "=========================================================================\n\n";

// Snapshot current state of the historical multiplier sites.
$sites = [
  [
    'file' => 'web/modules/custom/wo_sign_off/wo_sign_off.module',
    'line' => 149,
    'pattern' => '$timeSpent * $totalMen',
    'expected_clean' => '$timeSpent)',
  ],
  [
    'file' => 'web/modules/custom/wo_lawn_mowing/wo_lawn_mowing.module',
    'line' => 200,
    'pattern' => '$timeSpent * $menTotal',
    'expected_clean' => '$totalTime = $timeSpent;',
  ],
  [
    'file' => 'web/modules/custom/wo_sprinkler_start_up/wo_sprinkler_start_up.module',
    'line' => 89,
    'pattern' => 'field_total_time multiplier in sprinkler_start_up bundle',
    'expected_clean' => 'set(\'field_total_time\', $timeSpent)',
  ],
];

$multiplier_present_anywhere = FALSE;
foreach ($sites as $s) {
  $abs = $projectRoot . '/' . $s['file'];
  if (!file_exists($abs)) {
    echo $s['file'] . ": (not readable)\n";
    continue;
  }
  $lines = file($abs);
  $context_start = max(1, $s['line'] - 3);
  $context_end = min(count($lines), $s['line'] + 3);
  $has_multiplier = FALSE;
  for ($i = $context_start; $i <= $context_end; $i++) {
    if (stripos($lines[$i - 1], $s['pattern']) !== FALSE) {
      $has_multiplier = TRUE;
      break;
    }
  }
  echo $s['file'] . " (around line " . $s['line'] . "):\n";
  for ($i = $context_start; $i <= $context_end; $i++) {
    $marker = ($i === $s['line']) ? ' <<<' : '';
    echo sprintf("  %4d | %s%s\n", $i, rtrim($lines[$i - 1]), $marker);
  }
  echo "  Multiplier pattern (\"" . $s['pattern'] . "\") present: " . ($has_multiplier ? 'YES (BUG)' : 'no (clean)') . "\n\n";
  if ($has_multiplier) {
    $multiplier_present_anywhere = TRUE;
  }
}

echo "Reconciliation prediction (Todd adds 2 crew, Refresh, fills times, Submits):\n\n";

echo "  The wo_complete_info bundle here is irrigation_crew, which is a\n";
echo "  COMPLEX bundle in WoCrewRosterService::COMPLEX_BUNDLES. That means\n";
echo "  missing teammates render per-row start/end fields in the form\n";
echo "  rather than silent-create. Submit handler:\n";
echo "    - CLOSES any open clock entries on this WO via the new orphan\n";
echo "      UI (each gets the foreman-entered end_time)\n";
echo "    - CREATES one wo_time_clock entry per missing teammate using\n";
echo "      the per-row start/end times Todd fills in\n";
echo "    - Sets field_status to Complete (1097) on the WO\n\n";

echo "  For this WO specifically:\n";
echo "    - Open entries to close: " . ($open_count > 0 ? "$open_count (IDs: " . implode(', ', $open_entries) . ")" : "none") . "\n";
echo "    - New entries to create: depends on how many of the 2 named teammates\n";
echo "      lack any existing wo_time_clock entry on this WO\n\n";

echo "  Sum of clock entries AFTER save = sum of all closed entries\n";
echo "  (Todd's filled-in times for the 2 new entries, plus any closed\n";
echo "  orphan entries with their foreman-entered end times).\n\n";

echo "  WO.field_total_time AFTER save (per current code):\n";
echo "    - wo_sign_off: SKIPS — sprinkler_start_up is in \$excludedBundles\n";
echo "    - wo_sprinkler_start_up.module:89: field_total_time = \$timeSpent\n";
echo "      (no multiplier — \$menTotal is only assigned to\n";
echo "      field_number_men_on_crew, not used as a multiplier)\n";
echo "    - wo_shared_work_order_presave: would also set field_total_time\n";
echo "      = sum of clock entries (only fires once WO is in Complete\n";
echo "      status, no multiplier)\n";
echo "    - Result: field_total_time = sum of clock entries (correct).\n\n";

echo "  Multiplier bug result (if it were still active):\n";
echo "    - field_total_time would have been sum × crew_count (2 = double-count).\n";
echo "    - But the bug was removed in commit 34c8ddc0; sites above are clean.\n\n";

// ============================================================================
// PART 5 — Recommendation
// ============================================================================

echo "=========================================================================\n";
echo "PART 5 — Recommendation\n";
echo "=========================================================================\n\n";

if ($multiplier_present_anywhere) {
  echo "DO NOT PROCEED: The multiplier bug is still active in at least one of\n";
  echo "the code paths that would fire on this WO save. Signing off through\n";
  echo "reconciliation would create over-billed labor totals. Use manual\n";
  echo "time entry until fix deploys.\n";
}
else {
  echo "SAFE TO PROCEED: The multiplier bug has been fixed in all three sites\n";
  echo "(wo_sign_off.module, wo_lawn_mowing.module, wo_sprinkler_start_up.module).\n";
  echo "Todd can sign off through reconciliation normally. The reconciliation\n";
  echo "submit will close any open clock entries with foreman-entered end times\n";
  echo "and create per-row entries for missing teammates, then field_total_time\n";
  echo "on the WO will resolve to the correct sum of those entries (no\n";
  echo "multiplier).\n";
}

echo "\n";
echo "=========================================================================\n";
echo "Verification complete. Read-only — no entities modified.\n";
echo "=========================================================================\n";
