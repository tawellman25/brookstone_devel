<?php

declare(strict_types=1);

/**
 * Verification — WO 48948 clock-entry state check.
 *
 * Read-only report. Run via:
 *   ddev drush php:script web/scripts/verify_wo_48948_state.php
 *
 * Four parts:
 *   1. Current state of all wo_time_clock entries on WO 48948
 *   2. Anomaly check (via AnomalyDetectionService) per entry
 *   3. WO total_time check (current vs sum vs bug formula)
 *   4. field_notes audit-marker scan
 *
 * No data modification. The sole side effect is stdout.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

const WO_ID = 48948;

$em = \Drupal::entityTypeManager();
$tcStorage = $em->getStorage('wo_time_clock');
$woStorage = $em->getStorage('work_order');
$userStorage = $em->getStorage('user');
$cinfoStorage = $em->getStorage('wo_complete_info');
$anomalySvc = \Drupal::service('bos_teammate_operations.anomaly_detection');

$fmtDt = static function (?string $iso): string {
  if ($iso === NULL || $iso === '') {
    return 'NULL';
  }
  $ts = strtotime($iso);
  return $ts ? date('m/d/Y g:i A', $ts) : $iso;
};

$nameForUid = static function ($uid) use ($userStorage): string {
  if (!$uid) {
    return '(none)';
  }
  $u = $userStorage->load($uid);
  if (!$u) {
    return "uid:$uid (missing)";
  }
  return $u->getDisplayName() . " (uid:$uid)";
};

// =============================================================================
// PART 1 — wo_time_clock entries
// =============================================================================

echo "=========================================================================\n";
echo "PART 1 — wo_time_clock entries for WO " . WO_ID . "\n";
echo "=========================================================================\n\n";

$tcIds = \Drupal::entityQuery('wo_time_clock')
  ->accessCheck(FALSE)
  ->condition('field_work_order', WO_ID)
  ->sort('field_start_time', 'ASC')
  ->execute();

if (empty($tcIds)) {
  echo "(no wo_time_clock entries found for WO " . WO_ID . ")\n\n";
}
else {
  $entries = $tcStorage->loadMultiple($tcIds);
  // Re-sort defensively by start time in case query ordering didn't take.
  uasort($entries, function ($a, $b) {
    $sa = $a->get('field_start_time')->value ?? '';
    $sb = $b->get('field_start_time')->value ?? '';
    return strcmp($sa, $sb);
  });

  foreach ($entries as $tc) {
    $tcId = $tcId = $tc->id();
    $uid = $tc->get('field_teammate')->target_id ?? NULL;
    $startIso = $tc->get('field_start_time')->value ?? NULL;
    $endIso = $tc->get('field_end_time')->value ?? NULL;
    $total = $tc->hasField('field_total_time') ? $tc->get('field_total_time')->value : NULL;
    $notes = $tc->hasField('field_notes') && !$tc->get('field_notes')->isEmpty()
      ? (string) $tc->get('field_notes')->value
      : '';

    $duration = '—';
    if ($startIso && $endIso) {
      $diff = strtotime($endIso) - strtotime($startIso);
      $duration = number_format($diff / 3600, 2) . ' hrs';
    }

    echo "Entry ID: $tcId\n";
    echo "  Teammate:    " . $nameForUid($uid) . "\n";
    echo "  Start:       " . $fmtDt($startIso) . "  (raw: " . ($startIso ?: 'NULL') . ")\n";
    echo "  End:         " . $fmtDt($endIso) . "  (raw: " . ($endIso ?: 'NULL') . ")\n";
    echo "  Total time:  " . ($total !== NULL ? $total : 'NULL') . "\n";
    echo "  Duration:    " . $duration . " (computed from start/end)\n";
    echo "  Notes:       " . ($notes !== '' ? $notes : '(empty)') . "\n";
    echo "\n";
  }
}

// =============================================================================
// PART 2 — Anomaly check per entry
// =============================================================================

echo "=========================================================================\n";
echo "PART 2 — Anomaly check (AnomalyDetectionService criteria)\n";
echo "=========================================================================\n\n";

if (empty($tcIds)) {
  echo "(no entries to check)\n\n";
}
else {
  foreach ($entries as $tc) {
    $tcId = $tc->id();
    $found = $anomalySvc->detectAnomalies($tc);
    if (empty($found)) {
      echo "  Entry $tcId — Clean\n";
    }
    else {
      $types = array_map(fn($a) => $a['type'] . ' (' . $a['message'] . ')', $found);
      echo "  Entry $tcId — " . implode('; ', $types) . "\n";
    }
  }
  echo "\n";
}

// =============================================================================
// PART 3 — WO total_time check
// =============================================================================

echo "=========================================================================\n";
echo "PART 3 — WO " . WO_ID . " total_time check\n";
echo "=========================================================================\n\n";

$wo = $woStorage->load(WO_ID);
if (!$wo) {
  echo "(WO " . WO_ID . " not found)\n\n";
}
else {
  echo "WO Title:         " . $wo->label() . "\n";
  echo "WO Bundle:        " . $wo->bundle() . "\n";

  $woTotalTime = $wo->hasField('field_total_time') && !$wo->get('field_total_time')->isEmpty()
    ? (float) $wo->get('field_total_time')->value
    : NULL;
  echo "WO field_total_time (current): " . ($woTotalTime !== NULL ? number_format($woTotalTime, 2) : 'NULL') . "\n\n";

  $cinfoIds = \Drupal::entityQuery('wo_complete_info')
    ->accessCheck(FALSE)
    ->condition('field_work_order', WO_ID)
    ->execute();

  if (empty($cinfoIds)) {
    echo "wo_complete_info: (none found for WO " . WO_ID . ")\n";
    $crewCount = 0;
  }
  else {
    foreach ($cinfoIds as $cid) {
      $cinfo = $cinfoStorage->load($cid);
      if (!$cinfo) {
        continue;
      }
      echo "wo_complete_info ID: $cid\n";
      echo "  Bundle:    " . $cinfo->bundle() . "\n";
      $crewCount = $cinfo->hasField('field_those_on_crew') ? $cinfo->get('field_those_on_crew')->count() : 0;
      echo "  Crew count (field_those_on_crew): $crewCount\n";
      if ($cinfo->hasField('field_those_on_crew')) {
        echo "  Crew members:\n";
        foreach ($cinfo->get('field_those_on_crew')->referencedEntities() as $crewUser) {
          echo "    - " . $crewUser->getDisplayName() . " (uid:" . $crewUser->id() . ")\n";
        }
      }
      echo "\n";
    }
  }

  // Sum of clock entries.
  $sumHours = 0.0;
  foreach ($entries ?? [] as $tc) {
    $v = $tc->get('field_total_time')->value ?? 0;
    $sumHours += (float) $v;
  }
  $effectiveCrew = max(1, $crewCount ?? 0);
  $bugFormula = $sumHours * $effectiveCrew;

  echo "Sum of field_total_time across all entries:  " . number_format($sumHours, 2) . " (CORRECT formula)\n";
  echo "Sum × crew_count ($effectiveCrew):                          " . number_format($bugFormula, 2) . " (BUGGY formula)\n\n";

  if ($woTotalTime === NULL) {
    echo "Match assessment: WO field_total_time is NULL — neither value.\n";
  }
  elseif (abs($woTotalTime - $sumHours) < 0.01) {
    echo "Match assessment: WO field_total_time matches the CORRECT sum.\n";
  }
  elseif (abs($woTotalTime - $bugFormula) < 0.01) {
    echo "Match assessment: WO field_total_time matches the BUGGY formula (sum × crew).\n";
  }
  else {
    echo "Match assessment: WO field_total_time matches NEITHER value — manual override or stale calc.\n";
    echo "  Delta vs correct sum: " . number_format($woTotalTime - $sumHours, 2) . "\n";
    echo "  Delta vs buggy mult:  " . number_format($woTotalTime - $bugFormula, 2) . "\n";
  }
  echo "\n";
}

// =============================================================================
// PART 4 — field_notes audit-marker scan
// =============================================================================

echo "=========================================================================\n";
echo "PART 4 — field_notes audit-marker scan\n";
echo "=========================================================================\n\n";

$markerPatterns = [
  'auto_closed_hygiene'   => '/\[Auto-closed during data hygiene cleanup[^\]]*\]/i',
  'created_at_signoff'    => '/\[Created by [^\]]*at sign-off[^\]]*\]/i',
  'closed_at_signoff'     => '/\[Closed by [^\]]*at sign-off[^\]]*\]/i',
  'manually_corrected'    => '/\[Manually corrected by[^\]]*\]/i',
];
$bracketAny = '/\[[^\]]+\]/';

if (empty($entries)) {
  echo "(no entries to scan)\n\n";
}
else {
  foreach ($entries as $tc) {
    $tcId = $tc->id();
    $notes = $tc->hasField('field_notes') && !$tc->get('field_notes')->isEmpty()
      ? (string) $tc->get('field_notes')->value
      : '';
    echo "Entry $tcId\n";
    if ($notes === '') {
      echo "  (empty)\n\n";
      continue;
    }

    $hits = [];
    foreach ($markerPatterns as $name => $regex) {
      if (preg_match_all($regex, $notes, $m)) {
        foreach ($m[0] as $match) {
          $hits[] = "[$name] $match";
        }
      }
    }
    // Any other bracketed audit prefix.
    if (preg_match_all($bracketAny, $notes, $m)) {
      $known = [];
      foreach ($markerPatterns as $name => $regex) {
        if (preg_match_all($regex, $notes, $mm)) {
          $known = array_merge($known, $mm[0]);
        }
      }
      foreach ($m[0] as $bracket) {
        if (!in_array($bracket, $known, TRUE)) {
          $hits[] = "[other] $bracket";
        }
      }
    }

    if (empty($hits)) {
      echo "  (no audit markers detected)\n";
    }
    else {
      foreach ($hits as $h) {
        echo "  $h\n";
      }
    }
    echo "\n";
  }
}

echo "=========================================================================\n";
echo "Verification complete. Read-only — no entities modified.\n";
echo "=========================================================================\n";
