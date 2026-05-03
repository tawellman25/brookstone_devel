<?php

declare(strict_types=1);

/**
 * Diagnostic — wo_time_clock entries with field_end_time IS NULL.
 *
 * Read-only report of the stale-punch backlog. Run via:
 *   ddev drush php:script web/scripts/diagnostic_stale_punches.php
 *
 * Four sections:
 *   1. Audit field population (Phase 2a sign-off audit fields)
 *   2. Parent WO status distribution
 *   3. Date distribution by year-month
 *   4. Per-teammate concentration
 *
 * No data modification. The sole side effect is stdout.
 *
 * Note: spec referenced `field_wo_status` but the actual field on the
 * work_order entity is `field_status`. Section 2 uses field_status.
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

$em = \Drupal::entityTypeManager();
$tcStorage = $em->getStorage('wo_time_clock');
$userStorage = $em->getStorage('user');
$woStorage = $em->getStorage('work_order');
$termStorage = $em->getStorage('taxonomy_term');

$logger = function (string $msg): void {
  fwrite(STDERR, "[warn] $msg\n");
};

// Collect all open punches up front. Single load drives all four sections.
$ids = $tcStorage->getQuery()
  ->accessCheck(FALSE)
  ->exists('field_start_time')
  ->notExists('field_end_time')
  ->execute();

if (empty($ids)) {
  echo "No wo_time_clock entries with empty field_end_time. Nothing to report.\n";
  return;
}

/** @var \Drupal\Core\Entity\EntityInterface[] $entries */
$entries = $tcStorage->loadMultiple($ids);
$total = count($entries);
echo "Total stale wo_time_clock entries (field_end_time IS NULL): $total\n";
echo str_repeat('=', 70) . "\n\n";

// ─────────────────────────────────────────────────────────────────────────
// SECTION 1 — Audit field population
// ─────────────────────────────────────────────────────────────────────────

echo "=== SECTION 1: AUDIT FIELD POPULATION ===\n\n";

$auditFields = [
  'field_closed_signoff_complete',
  'field_closed_signoff_tasks',
  'field_created_signoff_complete',
  'field_created_signoff_tasks',
];

$withAudit = [];
foreach ($entries as $entry) {
  $populated = [];
  foreach ($auditFields as $f) {
    if (!$entry->hasField($f)) continue;
    if ($entry->get($f)->isEmpty()) continue;
    $populated[$f] = (int) ($entry->get($f)->target_id ?? 0);
  }
  if (!empty($populated)) {
    $withAudit[] = ['entry' => $entry, 'populated' => $populated];
  }
}

echo "Records with at least one audit field populated: " . count($withAudit) . " of $total\n\n";

if (!empty($withAudit)) {
  echo sprintf("%-8s %-35s %-15s %-25s %s\n", 'TC#', 'Audit field(s)', 'Signoff#', 'Teammate', 'Start time');
  echo str_repeat('-', 110) . "\n";
  foreach ($withAudit as $item) {
    $entry = $item['entry'];
    $tcId = (string) $entry->id();
    $fieldsJoined = '';
    $signoffIds = [];
    foreach ($item['populated'] as $f => $tid) {
      // Compact label — strip the field_ prefix.
      $short = preg_replace('/^field_/', '', $f);
      $fieldsJoined .= ($fieldsJoined === '' ? '' : ', ') . $short;
      $signoffIds[] = (string) $tid;
    }
    $teammateName = '—';
    if ($entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty()) {
      $u = $entry->get('field_teammate')->entity;
      $teammateName = $u ? $u->getDisplayName() : '(uid ' . $entry->get('field_teammate')->target_id . ')';
    }
    elseif ($entry->getOwnerId()) {
      $u = $userStorage->load($entry->getOwnerId());
      $teammateName = $u ? '[owner] ' . $u->getDisplayName() : '(uid ' . $entry->getOwnerId() . ')';
    }
    $start = (string) ($entry->get('field_start_time')->value ?? '');
    echo sprintf("%-8s %-35s %-15s %-25s %s\n",
      $tcId,
      substr($fieldsJoined, 0, 35),
      implode(',', $signoffIds),
      substr($teammateName, 0, 25),
      $start
    );
  }
  echo "\n";
}
else {
  echo "(none — none of the open punches were touched by Phase 2 sign-off reconciliation)\n\n";
}

// ─────────────────────────────────────────────────────────────────────────
// SECTION 2 — Parent WO status distribution
// ─────────────────────────────────────────────────────────────────────────

echo "=== SECTION 2: PARENT WO STATUS DISTRIBUTION ===\n\n";

$buckets = []; // bucket_key => ['label' => ..., 'tid' => N|null, 'count' => N]
$bumpBucket = function (string $key, ?string $label, ?int $tid) use (&$buckets): void {
  if (!isset($buckets[$key])) {
    $buckets[$key] = ['label' => $label ?? $key, 'tid' => $tid, 'count' => 0];
  }
  $buckets[$key]['count']++;
};

$termLabelCache = [];
$resolveTermLabel = function (int $tid) use ($termStorage, &$termLabelCache, $logger): string {
  if (isset($termLabelCache[$tid])) return $termLabelCache[$tid];
  try {
    $t = $termStorage->load($tid);
    $label = $t ? $t->label() : "(term $tid not found)";
  }
  catch (\Throwable $e) {
    $logger("term load failed: tid=$tid — " . $e->getMessage());
    $label = "(load error)";
  }
  return $termLabelCache[$tid] = $label;
};

foreach ($entries as $entry) {
  if (!$entry->hasField('field_work_order') || $entry->get('field_work_order')->isEmpty()) {
    $bumpBucket('NO_WO', 'NO_WO (entry has no field_work_order)', NULL);
    continue;
  }
  $woId = (int) $entry->get('field_work_order')->target_id;
  if ($woId <= 0) {
    $bumpBucket('NO_WO', 'NO_WO (entry has no field_work_order)', NULL);
    continue;
  }
  $wo = NULL;
  try {
    $wo = $woStorage->load($woId);
  }
  catch (\Throwable $e) {
    $logger("WO load failed for entry " . $entry->id() . " wo=$woId — " . $e->getMessage());
  }
  if (!$wo) {
    $bumpBucket('WO_DELETED', "WO_DELETED (entry references non-existent WO)", NULL);
    continue;
  }
  if (!$wo->hasField('field_status') || $wo->get('field_status')->isEmpty()) {
    $bumpBucket('NO_STATUS', 'NO_STATUS (WO has empty field_status)', NULL);
    continue;
  }
  $tid = (int) $wo->get('field_status')->target_id;
  if ($tid <= 0) {
    $bumpBucket('NO_STATUS', 'NO_STATUS (WO has empty field_status)', NULL);
    continue;
  }
  $label = $resolveTermLabel($tid);
  $bumpBucket((string) $tid, $label, $tid);
}

uasort($buckets, fn($a, $b) => $b['count'] <=> $a['count']);

echo sprintf("%-50s %-8s %s\n", 'Status label', 'TID', 'Count');
echo str_repeat('-', 70) . "\n";
foreach ($buckets as $b) {
  echo sprintf("%-50s %-8s %d\n",
    substr($b['label'], 0, 50),
    $b['tid'] === NULL ? '—' : (string) $b['tid'],
    $b['count']
  );
}
echo "\n";

// ─────────────────────────────────────────────────────────────────────────
// SECTION 3 — Date distribution by year-month
// ─────────────────────────────────────────────────────────────────────────

echo "=== SECTION 3: DATE DISTRIBUTION (year-month of field_start_time) ===\n\n";

$months = [];
foreach ($entries as $entry) {
  $start = (string) ($entry->get('field_start_time')->value ?? '');
  if ($start === '') {
    // Should be impossible — query required exists('field_start_time').
    $months['(empty start_time)'] = ($months['(empty start_time)'] ?? 0) + 1;
    continue;
  }
  $ym = substr($start, 0, 7); // YYYY-MM
  $months[$ym] = ($months[$ym] ?? 0) + 1;
}
ksort($months);

echo sprintf("%-12s %s\n", 'Year-Month', 'Count');
echo str_repeat('-', 30) . "\n";
$sumCheck = 0;
foreach ($months as $ym => $count) {
  echo sprintf("%-12s %d\n", $ym, $count);
  $sumCheck += $count;
}
echo str_repeat('-', 30) . "\n";
echo sprintf("%-12s %d\n", 'TOTAL', $sumCheck);
echo "\n";

// ─────────────────────────────────────────────────────────────────────────
// SECTION 4 — Per-teammate concentration
// ─────────────────────────────────────────────────────────────────────────

echo "=== SECTION 4: PER-TEAMMATE CONCENTRATION (by field_teammate) ===\n\n";

$byTeammate = []; // uid => ['name' => ..., 'count' => N]
foreach ($entries as $entry) {
  $uid = 0;
  if ($entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty()) {
    $uid = (int) $entry->get('field_teammate')->target_id;
  }
  if ($uid <= 0) {
    $key = 0;
    $name = '(no field_teammate)';
  }
  else {
    $key = $uid;
    if (!isset($byTeammate[$key])) {
      try {
        $u = $userStorage->load($uid);
        $name = $u ? $u->getDisplayName() : "(uid $uid not found)";
      }
      catch (\Throwable $e) {
        $logger("user load failed: uid=$uid — " . $e->getMessage());
        $name = "(load error: uid $uid)";
      }
    }
    else {
      $name = $byTeammate[$key]['name'];
    }
  }
  $byTeammate[$key] = ['name' => $name, 'count' => ($byTeammate[$key]['count'] ?? 0) + 1];
}

uasort($byTeammate, fn($a, $b) => $b['count'] <=> $a['count']);

echo sprintf("%-35s %s\n", 'Teammate', 'Count');
echo str_repeat('-', 50) . "\n";
foreach ($byTeammate as $row) {
  echo sprintf("%-35s %d\n", substr($row['name'], 0, 35), $row['count']);
}
echo "\n";
echo "Distinct teammates with stale punches: " . count($byTeammate) . "\n";
echo "\n=== Done ===\n";
