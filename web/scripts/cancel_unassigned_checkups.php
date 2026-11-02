<?php

/**
 * @file
 * Cancel this season's irrigation check-ups that were dated but never assigned.
 *
 * Measured on live 2026-11-02: of **291** `sprinkler_check_up` work orders
 * created in 2026, **135** still sit at status *Scheduled* with a scheduling
 * record carrying a date — every one of them in the past, May through October —
 * and **no assignee on any of their scheduling records**. They never appeared on
 * anyone's My Schedule, because My Schedule is driven by the assignee. The
 * irrigation season is over, so none of them can now be done.
 *
 * Todd's call, 2026-11-02: cancel them, and record WHY in the note — the
 * scheduling system needs to surface dated-but-unassigned work, and check-up
 * volume needs more sprinkler techs than it had. That reason is the point of
 * this script: a bare "Canceled" on 135 records would read, next spring, like
 * 135 jobs nobody wanted.
 *
 * ⚠ CANCELED, NOT DELETED (BOS rule 4). Every record, its history and any time
 * entries survive; a Canceled (1098) work order simply sits outside the billing
 * floor.
 *
 * ⚠ NO EMAIL IS SENT. The cancellation email in `wo_sign_off` fires from a
 * `wo_complete_info` presave where `field_canceled` is set — NOT from a status
 * change and NOT from `MarkWorkOrderCanceledAction`. Checked before running
 * this, because 135 notifications would have been a real side effect. (Its
 * recipient is `wo_notices@brookstoneoutdoors.com`, internal, in any case.)
 *
 * MECHANISM. A `wo_status_updates` record is created per work order — the same
 * entity the site's own cancel action writes — so each cancellation lands in the
 * append-only timeline with an author and a reason. This script writes the
 * status update DIRECTLY rather than calling the action, for one reason only:
 * the action hardcodes its note ("X marked this Work Order as Canceled") and
 * the whole request here is to carry a specific reason.
 *
 * GUARDS. The set is derived, not hand-listed, because 135 ids pasted into an
 * env var is its own failure mode — but the derivation is asserted and every
 * individual record is re-checked:
 *   - bundle is sprinkler_check_up and status is not already closed;
 *   - it has a scheduling record whose date is in the PAST;
 *   - NO assignee on any of its scheduling records;
 *   - `field_invoiced` is NOT set;
 *   - `field_wo_total` is empty or zero;
 *   - no Complete / Invoiced / Paid in its status history.
 * The last three are the lesson of 2026-11-01, when a sweep that trusted
 * `field_status` alone cancelled two paid 2024 invoices. Anything carrying
 * billing evidence is REFUSED here, not cancelled.
 *
 * BOS_EXPECT guards the derivation itself: if the query returns a count that
 * differs from what was measured, the script stops rather than acting on a set
 * nobody has looked at.
 *
 * Idempotent (a cancelled WO drops out of the derivation); DRY-RUN unless
 * BOS_APPLY=1.
 *
 * Run:
 *   drush php:script web/scripts/cancel_unassigned_checkups.php
 *   BOS_APPLY=1 drush php:script web/scripts/cancel_unassigned_checkups.php
 *   BOS_EXPECT=130 …   # if the count has legitimately moved since measuring
 */

const BUNDLE = 'sprinkler_check_up';
const CANCELED = 1098;
// Restricted to Scheduled (1091) ON PURPOSE. The same derivation without a
// status filter returns 136: the extra is WO 50152 (Pavilion - City of
// Montrose), sitting at In Progress (1092) since 06/03/2026 with ZERO time
// punches. "In Progress" is a claim that someone started, and that is a
// different conversation from "nobody ever picked it up" — so it is excluded
// and raised separately rather than swept in on a count that looked close
// enough. The BOS_EXPECT assert is what caught the discrepancy.
const SCHEDULED = 1091;
const CLOSED = [1097, 1098, 1281, 1283, 1504];
const HISTORY_CLOSED = [1097, 1281, 1504];

// 251 chars. field_status_change_note is string(255), and the first draft was
// 261 — the script refuses rather than letting the database truncate the reason
// mid-sentence, which would have cut exactly the part worth keeping.
const NOTE = 'Canceled: irrigation check-up not performed this season. Scheduled date passed with no tech assigned, so it never reached a crew schedule. The system needs to flag dated-but-unassigned work, and check-ups need more sprinkler techs to cover the volume.';

$apply = getenv('BOS_APPLY') === '1';
$rawExpect = getenv('BOS_EXPECT');
$expect = ($rawExpect === FALSE || trim($rawExpect) === '') ? 135 : (int) $rawExpect;

if (mb_strlen(NOTE) > 255) {
  printf("ABORT: the note is %d characters; field_status_change_note holds 255.\n", mb_strlen(NOTE));
  return;
}

$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$now = \Drupal::time()->getRequestTime();
$midnight = strtotime('today midnight');

// Attribute to a real account — not uid 0 / "Anonymous".
$actorName = getenv('BOS_ACTOR') ?: 'Todd Wellman';
$actors = $etm->getStorage('user')->loadByProperties(['name' => $actorName]);
$actor = reset($actors);
if (!$actor) {
  printf("ABORT: no account named '%s'. Set BOS_ACTOR.\n", $actorName);
  return;
}

// --- derive the set ----------------------------------------------------------
$rows = $db->query('SELECT DISTINCT wo.id, wo.created, st.field_status_target_id tid,
    (SELECT MAX(d.field_date_value)
       FROM {scheduling__field_work_order} s2
       JOIN {scheduling__field_date} d ON d.entity_id = s2.entity_id AND d.deleted = 0
      WHERE s2.deleted = 0 AND s2.field_work_order_target_id = wo.id) sched,
    nick.field_nickname_value nick
  FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  JOIN {scheduling__field_work_order} swo ON swo.field_work_order_target_id = wo.id AND swo.deleted = 0
  JOIN {scheduling__field_date} sd ON sd.entity_id = swo.entity_id AND sd.deleted = 0
  LEFT JOIN {work_order__field_property} wp ON wp.entity_id = wo.id AND wp.deleted = 0
  LEFT JOIN {properties__field_nickname} nick ON nick.entity_id = wp.field_property_target_id AND nick.deleted = 0
  WHERE wo.type = :bundle
    AND st.field_status_target_id = :scheduled
    AND st.field_status_target_id NOT IN (:closed[])
    AND NOT EXISTS (
      SELECT 1 FROM {scheduling__field_work_order} s3
      JOIN {scheduling__field_assigned_to} a ON a.entity_id = s3.entity_id AND a.deleted = 0
      WHERE s3.deleted = 0 AND s3.field_work_order_target_id = wo.id)
  ORDER BY wo.created ASC',
  [':bundle' => BUNDLE, ':scheduled' => SCHEDULED, ':closed[]' => CLOSED])->fetchAll();

// Only past-dated ones. A future date is live work, not a miss.
$candidates = array_values(array_filter($rows, fn($r) => (int) $r->sched > 0 && (int) $r->sched < $midnight));
$future = count($rows) - count($candidates);

printf("derived: %d unassigned, past-dated, open %s work orders", count($candidates), BUNDLE);
printf("%s\n", $future ? sprintf("  (%d future-dated excluded)", $future) : '');
printf("expected: %d\n", $expect);
if (count($candidates) !== $expect) {
  printf("\nABORT: derived %d but expected %d. The set has moved since it was measured —\n"
    . "look at it before acting. Re-run with BOS_EXPECT=%d if the new count is right.\n",
    count($candidates), $expect, count($candidates));
  return;
}
printf("note (%d chars): %s\n\n", mb_strlen(NOTE), NOTE);

// --- per-record guards -------------------------------------------------------
$targets = [];
$refused = [];
$statusTally = [];
$monthTally = [];
foreach ($candidates as $r) {
  $id = (int) $r->id;
  $wo = $etm->getStorage('work_order')->load($id);
  if (!$wo) {
    $refused[] = "$id: will not load";
    continue;
  }
  $invoiced = (int) ($wo->get('field_invoiced')->value ?? 0) === 1;
  $total = $wo->hasField('field_wo_total') ? $wo->get('field_wo_total')->value : NULL;
  $costed = $total !== NULL && $total !== '' && (float) $total != 0.0;
  $histClosed = (int) $db->query('SELECT COUNT(*) FROM {wo_status_updates__field_status_of_wo} sw
    JOIN {wo_status_updates__field_status} s ON s.entity_id = sw.entity_id AND s.deleted = 0
    WHERE sw.deleted = 0 AND sw.field_status_of_wo_target_id = :id
      AND s.field_status_target_id IN (:c[])', [':id' => $id, ':c[]' => HISTORY_CLOSED])->fetchField();

  if ($invoiced) {
    $refused[] = "$id: field_invoiced is set (total " . ($total ?? 'empty') . ')';
    continue;
  }
  if ($costed) {
    $refused[] = "$id: carries a WO total of $total";
    continue;
  }
  if ($histClosed) {
    $refused[] = "$id: status history contains Complete/Invoiced/Paid";
    continue;
  }
  $targets[$id] = $r;
  $term = $etm->getStorage('taxonomy_term')->load((int) $r->tid);
  $lbl = $term ? $term->label() : (string) $r->tid;
  $statusTally[$lbl] = ($statusTally[$lbl] ?? 0) + 1;
  $monthTally[date('Y-m', (int) $r->sched)] = ($monthTally[date('Y-m', (int) $r->sched)] ?? 0) + 1;
}

printf("to cancel: %d    refused: %d\n", count($targets), count($refused));
foreach ($refused as $why) {
  printf("  REFUSED %s\n", $why);
}
print "\ncurrent status of the targets:\n";
foreach ($statusTally as $k => $v) {
  printf("    %-16s %3d\n", $k, $v);
}
print "scheduled-for month:\n";
ksort($monthTally);
foreach ($monthTally as $k => $v) {
  printf("    %-10s %3d\n", $k, $v);
}

if (!$targets) {
  print "\nNothing to cancel.\n";
  return;
}
if (!$apply) {
  print "\nfirst 10 targets:\n";
  $i = 0;
  foreach ($targets as $id => $r) {
    printf("    WO %-8d sched %s  %4dd old  %s\n", $id, date('m/d/Y', (int) $r->sched),
      (int) floor(($now - (int) $r->created) / 86400), mb_substr(trim((string) $r->nick) ?: '-', 0, 34));
    if (++$i >= 10) {
      break;
    }
  }
  printf("\nDRY RUN — %d would be cancelled, attributed to %s. Re-run with BOS_APPLY=1.\n",
    count($targets), $actor->getDisplayName());
  return;
}

// --- apply -------------------------------------------------------------------
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo($actor);
$failed = [];
$done = 0;
try {
  foreach (array_keys($targets) as $id) {
    try {
      $etm->getStorage('wo_status_updates')->create([
        'type' => 'update',
        'field_status_of_wo' => $id,
        'field_status' => CANCELED,
        'field_status_change_note' => NOTE,
        'uid' => $actor->id(),
      ])->save();
      $done++;
      if ($done % 25 === 0) {
        printf("  … %d/%d\n", $done, count($targets));
      }
    }
    catch (\Throwable $e) {
      $failed[] = "$id: " . $e->getMessage();
    }
  }
}
finally {
  $switcher->switchBack();
}
printf("\nwrote %d status update(s); failures: %d\n", $done, count($failed));
foreach (array_slice($failed, 0, 10) as $f) {
  printf("  FAILED %s\n", $f);
}

// --- verify, from the field table rather than the entity cache ---------------
$ids = array_keys($targets);
$stillOpen = (int) $db->query('SELECT COUNT(*) FROM {work_order__field_status}
  WHERE deleted = 0 AND entity_id IN (:i[]) AND field_status_target_id <> :c',
  [':i[]' => $ids, ':c' => CANCELED])->fetchField();
$noted = (int) $db->query('SELECT COUNT(DISTINCT sw.field_status_of_wo_target_id)
  FROM {wo_status_updates__field_status_of_wo} sw
  JOIN {wo_status_updates__field_status_change_note} n ON n.entity_id = sw.entity_id AND n.deleted = 0
  WHERE sw.deleted = 0 AND sw.field_status_of_wo_target_id IN (:i[])
    AND n.field_status_change_note_value = :note',
  [':i[]' => $ids, ':note' => NOTE])->fetchField();
printf("\nverify (raw field tables):\n  now Canceled: %d of %d\n  carrying the reason note: %d\n",
  count($ids) - $stillOpen, count($ids), $noted);
print $stillOpen === 0 ? "\nAll cancelled with the reason recorded.\n"
  : "\n** $stillOpen did NOT reach Canceled — investigate before re-running. **\n";
