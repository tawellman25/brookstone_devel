<?php

/**
 * @file
 * Backfill "In Progress" on work orders that were worked but never promoted.
 *
 * Between 2026-07-03 and 2026-11-03 the clock button created time entries but
 * no status update: `wo_clock` replaced the legacy flag toggle on the work
 * order page without carrying its status change across. 2,752 work orders were
 * clocked into and only 995 were promoted, leaving 24 showing Open or
 * Scheduled with real hours on them. The code is fixed; this corrects the
 * records left behind.
 *
 * ⚠ SCOPE IS EXACTLY WHAT TODD NAMED, NOTHING INFERRED. He is working through
 * the list by hand and asked only for the **Open** ones, **except 54388 and
 * 54175**, which stay Open deliberately. The *Scheduled* ones are his to do —
 * this script will refuse any work order that is not currently Open, so it
 * cannot quietly widen into them.
 *
 * ⚠ 54520 is deliberately absent. It has a clock-in still OPEN from 20 October
 * — a forgotten clock-out needing a real end time, not a status change.
 *
 * GUARDS, per work order: named explicitly; exists; is currently Open; has at
 * least one time entry (the whole premise); and is not already In Progress or
 * terminal. Anything else is refused rather than changed.
 *
 * Writes a `wo_status_updates` record rather than setting field_status
 * directly, so the correction lands in the append-only timeline with an author
 * and a reason — a status that changed with no explanation is worse than one
 * that is wrong.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/backfill_in_progress_status.php
 */

const IN_PROGRESS = 1092;
const TERMINAL = [1097, 1098, 1281, 1283, 1504];

// Todd's list: the Open ones, minus the two he wants left Open.
const TARGETS = [52070, 53242, 53722, 53925, 53931, 54104, 54209, 54335, 54515, 54572];
const EXCLUDED = [54388 => 'Todd: stays Open', 54175 => 'Todd: stays Open'];

const NOTE = 'Status backfilled to In Progress: time was clocked against this work order but no status update was created, because the clock button did not set status between 07/03/2026 and 11/03/2026. Hours were never affected.';

$apply = getenv('BOS_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

if (mb_strlen(NOTE) > 255) {
  printf("ABORT: note is %d chars; field_status_change_note holds 255.\n", mb_strlen(NOTE));
  return;
}

// Attribute to a real account — not uid 0 "Anonymous".
$actorName = getenv('BOS_ACTOR') ?: 'Todd Wellman';
$actors = $etm->getStorage('user')->loadByProperties(['name' => $actorName]);
$actor = reset($actors);
if (!$actor) {
  printf("ABORT: no account named '%s'.\n", $actorName);
  return;
}
printf("attributing to %s (uid %s)\n", $actor->getDisplayName(), $actor->id());
printf("excluded by instruction: %s\n\n", implode(', ', array_map(
  fn($k, $v) => "$k ($v)", array_keys(EXCLUDED), EXCLUDED)));

$targets = [];
$refused = [];
printf("%-8s %-12s %-22s %-26s %-8s %s\n", 'WO', 'STATUS', 'SERVICE', 'PROPERTY', 'ENTRIES', 'VERDICT');
printf("%s\n", str_repeat('-', 104));

foreach (TARGETS as $id) {
  $wo = $etm->getStorage('work_order')->loadUnchanged($id);
  if (!$wo) {
    $refused[] = "$id: not found";
    printf("%-8d %s\n", $id, 'REFUSED — not found');
    continue;
  }
  $tid = (int) $wo->get('field_status')->target_id;
  $term = $etm->getStorage('taxonomy_term')->load($tid);
  $status = $term ? $term->label() : (string) $tid;
  $entries = (int) $db->query('SELECT COUNT(*) FROM {wo_time_clock__field_work_order}
    WHERE deleted = 0 AND field_work_order_target_id = :id', [':id' => $id])->fetchField();
  $prop = $wo->get('field_property')->entity;

  $verdict = 'will set In Progress';
  if ($tid === IN_PROGRESS) {
    $verdict = 'already In Progress — nothing to do';
  }
  elseif (in_array($tid, TERMINAL, TRUE)) {
    // Must never re-open finished or billed work.
    $verdict = "REFUSED — $status is terminal";
    $refused[] = "$id: $status";
  }
  elseif (strcasecmp($status, 'Open') !== 0) {
    // The instruction was "all the Open ones". Anything else is Todd's to do
    // by hand, so refuse rather than widen the scope.
    $verdict = "REFUSED — is '$status', not Open";
    $refused[] = "$id: $status, not Open";
  }
  elseif ($entries === 0) {
    $verdict = 'REFUSED — no time entries';
    $refused[] = "$id: no time entries";
  }
  else {
    $targets[] = $id;
  }
  printf("%-8d %-12s %-22s %-26s %-8d %s\n", $id, mb_substr($status, 0, 12),
    mb_substr($wo->bundle(), 0, 22), mb_substr($prop ? $prop->label() : '-', 0, 26), $entries, $verdict);
}

printf("\nto change: %d    refused: %d\n", count($targets), count($refused));
foreach ($refused as $r) {
  printf("  REFUSED %s\n", $r);
}
if (!$targets) {
  print "\nNothing to do.\n";
  return;
}
if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($actor);
$done = [];
$failed = [];
try {
  foreach ($targets as $id) {
    try {
      $etm->getStorage('wo_status_updates')->create([
        'type' => 'update',
        'field_status_of_wo' => $id,
        'field_status' => IN_PROGRESS,
        'field_status_change_note' => ['value' => NOTE],
        'uid' => $actor->id(),
      ])->save();
      $done[] = $id;
    }
    catch (\Throwable $e) {
      $failed[] = "$id: " . $e->getMessage();
    }
  }
}
finally {
  $switcher->switchBack();
}

// Verify from the field table, not the entity API — an entity read inside the
// request that wrote is not evidence.
print "\nverify (raw field table):\n";
$wrong = 0;
foreach ($targets as $id) {
  $now = (int) $db->query('SELECT field_status_target_id FROM {work_order__field_status}
    WHERE deleted = 0 AND entity_id = :id', [':id' => $id])->fetchField();
  $okRow = $now === IN_PROGRESS;
  if (!$okRow) {
    $wrong++;
  }
  $term = $etm->getStorage('taxonomy_term')->load($now);
  printf("  WO %-8d %-14s %s\n", $id, $term ? $term->label() : $now, $okRow ? 'OK' : '** WRONG **');
}
printf("\n%d changed, %d wrong, %d failed.\n", count($done) - $wrong, $wrong, count($failed));
foreach ($failed as $f) {
  printf("  FAILED %s\n", $f);
}
print "\nleft Open by instruction: 54388, 54175.  Untouched: all Scheduled ones, and 54520 (open clock-in).\n";
