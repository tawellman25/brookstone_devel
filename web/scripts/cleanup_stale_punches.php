<?php

declare(strict_types=1);

/**
 * One-off cleanup — close the pre-2026 stale wo_time_clock open punches.
 *
 * Operation per record: zero-duration close (end_time = start_time),
 * backfill field_teammate from uid when empty, prepend audit note to
 * field_notes documenting the cleanup. Phase 1 guards bypassed via
 * admin context (covers guards 4 & 5) plus _signoff_reconciliation
 * flag (explicit intent on guard 4 records).
 *
 * Usage:
 *   ddev drush php:script web/scripts/cleanup_stale_punches.php -- --dry-run
 *   ddev drush php:script web/scripts/cleanup_stale_punches.php
 *
 * Skips, per spec:
 *   - records with field_end_time already populated (defensive — query
 *     already filters them out)
 *   - records with any of the 4 Phase 2 audit fields populated (they're
 *     managed by sign-off reconciliation; not in our scope)
 *   - records whose parent WO status is anything other than Invoiced
 *     (1281) or Canceled (1098)
 */

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('CLI only.');
}

$dryRun = in_array('--dry-run', $extra ?? [], TRUE);

$em = \Drupal::entityTypeManager();
$tcStorage = $em->getStorage('wo_time_clock');
$userStorage = $em->getStorage('user');
$woStorage = $em->getStorage('work_order');
$termStorage = $em->getStorage('taxonomy_term');

const STATUS_INVOICED = 1281;
const STATUS_CANCELED = 1098;
const AUDIT_FIELDS = [
  'field_closed_signoff_complete',
  'field_closed_signoff_tasks',
  'field_created_signoff_complete',
  'field_created_signoff_tasks',
];

// ─── Admin context ────────────────────────────────────────────────────────
$accountSwitcher = \Drupal::service('account_switcher');
$adminUser = $userStorage->load(1);
if (!$adminUser instanceof \Drupal\user\UserInterface) {
  fwrite(STDERR, "FATAL: uid 1 not found. Cleanup requires admin context to bypass Phase 1 guards. Aborting.\n");
  exit(1);
}
if (!$adminUser->hasPermission('administer eck entities')) {
  fwrite(STDERR, "FATAL: uid 1 lacks 'administer eck entities' permission. Required to bypass Phase 1 guard 5 (Canceled WO lock). Aborting.\n");
  exit(1);
}
$accountSwitcher->switchTo($adminUser);

// Anything below MUST run inside try/finally so we always switch back.
$exitCode = 0;
try {

// ─── Header ───────────────────────────────────────────────────────────────
$mode = $dryRun ? 'DRY-RUN (no writes)' : 'LIVE (writing changes)';
$startedAt = new \DateTime('now', new \DateTimeZone(date_default_timezone_get()));
echo "=== Stale wo_time_clock cleanup — $mode ===\n";
echo "Started:        " . $startedAt->format('m/d/Y g:i:s A') . "\n";
echo "Acting user:    " . $adminUser->getDisplayName() . " (uid " . $adminUser->id() . ")\n";
echo "Bypass:         admin permission (covers Phase 1 guards 4 & 5) + _signoff_reconciliation flag (explicit intent)\n";
echo str_repeat('=', 78) . "\n\n";

// ─── Pre-cleanup baselines for post-run verification ──────────────────────
$baselineOpenCount = (int) $tcStorage->getQuery()
  ->accessCheck(FALSE)
  ->exists('field_start_time')
  ->notExists('field_end_time')
  ->count()
  ->execute();
$baselineNoTeammateCount = (int) $tcStorage->getQuery()
  ->accessCheck(FALSE)
  ->notExists('field_teammate')
  ->count()
  ->execute();
echo "Pre-cleanup baseline: open punches = $baselineOpenCount, records with empty field_teammate = $baselineNoTeammateCount\n\n";

// ─── Load every open punch ────────────────────────────────────────────────
$ids = $tcStorage->getQuery()
  ->accessCheck(FALSE)
  ->exists('field_start_time')
  ->notExists('field_end_time')
  ->execute();

if (empty($ids)) {
  echo "No open punches to clean up. Nothing to do.\n";
  return;
}
$entries = $tcStorage->loadMultiple($ids);
$totalCandidates = count($entries);
echo "Candidate records (open punches): $totalCandidates\n\n";

// Per-record processing.
$saved = 0;
$savedInvoiced = 0;
$savedCanceled = 0;
$skipped = 0;
$errors = 0;
$teammateBackfilled = 0;
$notesAudited = 0;
$skippedReasons = [];
$errorDetails = [];

$now = new \DateTime('now', new \DateTimeZone(date_default_timezone_get()));
$nowLabel = $now->format('m/d/Y g:i A');
$adminLabel = $adminUser->getDisplayName();

$termLabelCache = [];
$resolveTermLabel = function (int $tid) use ($termStorage, &$termLabelCache): string {
  if (isset($termLabelCache[$tid])) return $termLabelCache[$tid];
  try {
    $t = $termStorage->load($tid);
    return $termLabelCache[$tid] = ($t ? $t->label() : "(term $tid)");
  } catch (\Throwable $e) {
    return $termLabelCache[$tid] = "(load error)";
  }
};

$index = 0;
foreach ($entries as $entry) {
  $index++;
  $tcId = (int) $entry->id();

  // Defensive: end_time already populated.
  if ($entry->hasField('field_end_time') && !$entry->get('field_end_time')->isEmpty()) {
    echo "[$index/$totalCandidates] tc#$tcId — SKIP (field_end_time already populated; query inconsistency)\n";
    $skipped++;
    $skippedReasons['end_time_populated'] = ($skippedReasons['end_time_populated'] ?? 0) + 1;
    continue;
  }

  // Skip records with audit fields populated (managed by sign-off reconciliation).
  $auditPopulated = [];
  foreach (AUDIT_FIELDS as $f) {
    if ($entry->hasField($f) && !$entry->get($f)->isEmpty()) {
      $auditPopulated[] = $f;
    }
  }
  if (!empty($auditPopulated)) {
    echo "[$index/$totalCandidates] tc#$tcId — SKIP (audit field(s) populated: " . implode(', ', $auditPopulated) . ")\n";
    $skipped++;
    $skippedReasons['audit_field_populated'] = ($skippedReasons['audit_field_populated'] ?? 0) + 1;
    continue;
  }

  // Capture original state for the report.
  $startIso = (string) ($entry->get('field_start_time')->value ?? '');
  $startLabel = $startIso !== '' ? this_fmt($startIso) : '—';
  $ownerId = (int) ($entry->getOwnerId() ?? 0);
  $ownerName = '—';
  if ($ownerId > 0) {
    $u = $userStorage->load($ownerId);
    $ownerName = $u ? $u->getDisplayName() : "(uid $ownerId)";
  }
  $teammateId = ($entry->hasField('field_teammate') && !$entry->get('field_teammate')->isEmpty())
    ? (int) $entry->get('field_teammate')->target_id : 0;

  // Resolve parent WO + status.
  if (!$entry->hasField('field_work_order') || $entry->get('field_work_order')->isEmpty()) {
    echo "[$index/$totalCandidates] tc#$tcId — SKIP (no field_work_order)\n";
    $skipped++;
    $skippedReasons['no_wo'] = ($skippedReasons['no_wo'] ?? 0) + 1;
    continue;
  }
  $woId = (int) $entry->get('field_work_order')->target_id;
  $wo = NULL;
  try {
    $wo = $woStorage->load($woId);
  } catch (\Throwable $e) {
    fwrite(STDERR, "[warn] WO load failed: tc#$tcId wo=$woId — " . $e->getMessage() . "\n");
  }
  if (!$wo) {
    echo "[$index/$totalCandidates] tc#$tcId — SKIP (parent WO #$woId not loadable)\n";
    $skipped++;
    $skippedReasons['wo_deleted'] = ($skippedReasons['wo_deleted'] ?? 0) + 1;
    continue;
  }
  $woTitle = $wo->label() ?: ('WO #' . $woId);
  $statusTid = ($wo->hasField('field_status') && !$wo->get('field_status')->isEmpty())
    ? (int) $wo->get('field_status')->target_id : 0;
  $statusLabel = $statusTid > 0 ? $resolveTermLabel($statusTid) : 'NO_STATUS';

  if ($statusTid !== STATUS_INVOICED && $statusTid !== STATUS_CANCELED) {
    echo "[$index/$totalCandidates] tc#$tcId — SKIP (parent WO status $statusLabel ($statusTid) not in [Invoiced, Canceled])\n";
    $skipped++;
    $skippedReasons['unexpected_status'] = ($skippedReasons['unexpected_status'] ?? 0) + 1;
    continue;
  }

  // ─── Build the change set ─────────────────────────────────────────────
  $willBackfillTeammate = ($teammateId === 0 && $ownerId > 0);
  $auditNote = "[Auto-closed during data hygiene cleanup $nowLabel by $adminLabel] "
    . "Parent WO $statusLabel, original end time unrecoverable. Zero-duration close to remove from active queries."
    . ($willBackfillTeammate ? " field_teammate backfilled from uid." : "");
  $existingNote = (string) ($entry->get('field_notes')->value ?? '');
  $newNote = trim($auditNote . ($existingNote !== '' ? "\n" . $existingNote : ''));

  // ─── Report what we're about to do ────────────────────────────────────
  echo "[$index/$totalCandidates] wo_time_clock id $tcId\n";
  echo "  Original: start=$startLabel, uid=$ownerName ($ownerId), WO=#$woId $woTitle ($statusLabel)\n";
  $actionParts = ["Closed (zero duration; end_time := start_time)"];
  if ($willBackfillTeammate) $actionParts[] = "Backfilled field_teammate=$ownerId";
  $actionParts[] = "Notes audited";
  echo "  Action:   " . implode('. ', $actionParts) . ".\n";

  if ($dryRun) {
    echo "  Status:   Would save (dry-run)\n\n";
    if ($statusTid === STATUS_INVOICED) $savedInvoiced++; else $savedCanceled++;
    $saved++;
    if ($willBackfillTeammate) $teammateBackfilled++;
    $notesAudited++;
    continue;
  }

  // ─── Apply changes ────────────────────────────────────────────────────
  $entry->set('field_end_time', $startIso);
  if ($willBackfillTeammate) {
    $entry->set('field_teammate', $ownerId);
  }
  $entry->set('field_notes', $newNote);
  $entry->_signoff_reconciliation = TRUE;
  try {
    $entry->save();
    $saved++;
    if ($statusTid === STATUS_INVOICED) $savedInvoiced++; else $savedCanceled++;
    if ($willBackfillTeammate) $teammateBackfilled++;
    $notesAudited++;
    echo "  Status:   Saved\n\n";
  } catch (\Throwable $e) {
    $errors++;
    $errorDetails[] = "tc#$tcId: " . $e->getMessage();
    echo "  Status:   ERROR — " . $e->getMessage() . "\n\n";
  }
}

// ─── Summary ──────────────────────────────────────────────────────────────
$endedAt = new \DateTime('now', new \DateTimeZone(date_default_timezone_get()));
$durationSec = $endedAt->getTimestamp() - $startedAt->getTimestamp();

echo str_repeat('=', 78) . "\n";
echo "SUMMARY ($mode)\n";
echo str_repeat('=', 78) . "\n";
echo "Total candidate records:    $totalCandidates\n";
echo "Successfully closed:        $saved" . ($dryRun ? " (would close)" : "") . "\n";
echo "  via _signoff_reconciliation flag (Invoiced WO):  $savedInvoiced\n";
echo "  via admin permission (Canceled WO):              $savedCanceled\n";
echo "field_teammate backfilled:  $teammateBackfilled\n";
echo "Notes audited:              $notesAudited\n";
echo "Skipped:                    $skipped\n";
foreach ($skippedReasons as $reason => $count) {
  echo "  - $reason: $count\n";
}
echo "Errors:                     $errors\n";
foreach (array_slice($errorDetails, 0, 50) as $line) {
  echo "  - $line\n";
}
echo "Started:                    " . $startedAt->format('m/d/Y g:i:s A') . "\n";
echo "Ended:                      " . $endedAt->format('m/d/Y g:i:s A') . "\n";
echo "Duration:                   {$durationSec}s\n";

// ─── Post-cleanup verification (live runs only) ───────────────────────────
if (!$dryRun) {
  echo "\n" . str_repeat('=', 78) . "\n";
  echo "POST-CLEANUP VERIFICATION\n";
  echo str_repeat('=', 78) . "\n";
  $postOpenCount = (int) $tcStorage->getQuery()
    ->accessCheck(FALSE)
    ->exists('field_start_time')
    ->notExists('field_end_time')
    ->count()
    ->execute();
  $postNoTeammateCount = (int) $tcStorage->getQuery()
    ->accessCheck(FALSE)
    ->notExists('field_teammate')
    ->count()
    ->execute();
  echo "Open punches: $baselineOpenCount → $postOpenCount (delta " . ($baselineOpenCount - $postOpenCount) . ", expected $saved)\n";
  echo "Empty field_teammate: $baselineNoTeammateCount → $postNoTeammateCount (delta " . ($baselineNoTeammateCount - $postNoTeammateCount) . ", expected $teammateBackfilled)\n";

  $expectedOpenAfter = $baselineOpenCount - $saved;
  $expectedNoTeammateAfter = $baselineNoTeammateCount - $teammateBackfilled;
  if ($postOpenCount !== $expectedOpenAfter) {
    echo "[warn] Open-punch delta mismatch — expected end count $expectedOpenAfter, got $postOpenCount\n";
  } else {
    echo "✓ Open-punch count matches expectation\n";
  }
  if ($postNoTeammateCount !== $expectedNoTeammateAfter) {
    echo "[warn] field_teammate delta mismatch — expected end count $expectedNoTeammateAfter, got $postNoTeammateCount\n";
  } else {
    echo "✓ field_teammate count matches expectation\n";
  }
}

echo "\n=== Done ===\n";

} finally {
  $accountSwitcher->switchBack();
}
exit($exitCode);

/**
 * Helper — render UTC stored datetime as MM/DD/YYYY h:i AM/PM in site TZ.
 */
function this_fmt(string $isoUtc): string {
  try {
    $dt = new \DateTime($isoUtc, new \DateTimeZone('UTC'));
    $dt->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return $dt->format('m/d/Y g:i A');
  } catch (\Throwable $e) {
    return $isoUtc;
  }
}
