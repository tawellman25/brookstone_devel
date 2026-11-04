<?php

/**
 * @file
 * Stop crew editing each other's time entries.
 *
 * Measured 2026-11-03: the `teammates` role held
 * `edit any wo_time_clock entities of bundle entry`, and the access handler
 * confirmed it for real — a crew-only account could open ANOTHER teammate's
 * entry and change it. Self-editing is wanted and long-standing (254 entries
 * are tagged `source=manual`); editing colleagues' hours is not.
 *
 * ⚠ SCOPE: `teammates` ONLY. Supervisors, office staff (`administration`) and
 * `site_admin` KEEP edit-any — Todd, 2026-11-03: *"office staff and admin
 * should be able to edit anyone else's time entries"*. Fixing a forgotten
 * clock-out is their job.
 *
 * ⚠ An earlier revision of this script also stripped `administration` and
 * `site_admin`. That came from misreading "Office and admin too", it was never
 * applied, and it must not be reinstated: with those two excluded, nobody but
 * supervisors and the superuser could correct a crew member's time, which
 * breaks month-end.
 *
 * ⚠ `edit own` is LEFT IN PLACE for teammates. Removing it would break the
 * long-standing self-service this is meant to preserve, and that distinction
 * is the entire point of the change.
 *
 * `delete own` is also left alone; teammates already cannot delete another
 * person's entry, which was verified rather than assumed.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/narrow_time_clock_edit_any.php
 */

const ROLE = 'teammates';
const DROP = 'edit any wo_time_clock entities of bundle entry';
const KEEP = 'edit own wo_time_clock entities of bundle entry';
const MUST_RETAIN = ['supervisor', 'administration', 'site_admin'];

$apply = getenv('BOS_APPLY') === '1';
$storage = \Drupal::entityTypeManager()->getStorage('user_role');
$role = $storage->load(ROLE);
if (!$role) {
  printf("ABORT: no '%s' role.\n", ROLE);
  return;
}

printf("role: %s\n", $role->label());
printf("  %-50s %s\n", DROP, $role->hasPermission(DROP) ? 'HELD -> will remove' : 'not held');
printf("  %-50s %s\n", KEEP, $role->hasPermission(KEEP) ? 'held -> KEPT' : '** NOT HELD **');

print "\nroles that must KEEP edit-any:\n";
$broken = [];
foreach (MUST_RETAIN as $rid) {
  $r = $storage->load($rid);
  $has = $r && $r->hasPermission(DROP);
  printf("  %-18s %s\n", $rid, $has ? 'yes' : '** NO — they should have it **');
  if (!$has) {
    $broken[] = $rid;
  }
}
if ($broken) {
  printf("\n⚠ %s lack edit-any. This script never removes it from them; if it is\n"
    . "missing, something else took it and that is worth looking at.\n", implode(', ', $broken));
}

if (!$role->hasPermission(KEEP)) {
  print "\nABORT: teammates do not hold 'edit own'. Removing 'edit any' would leave\n"
    . "crew unable to edit their own time at all — the opposite of the intent.\n";
  return;
}
if (!$role->hasPermission(DROP)) {
  print "\nNothing to do.\n";
  return;
}
if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

$role->revokePermission(DROP);
$role->save();

$fresh = $storage->load(ROLE);
printf("\nsaved.\n  %-50s %s\n", DROP, $fresh->hasPermission(DROP) ? '** STILL HELD **' : 'removed');
printf("  %-50s %s\n", KEEP, $fresh->hasPermission(KEEP) ? 'still held' : '** LOST **');

// Verify against the access handler, not the permission row — reading
// permission names is exactly what misled this investigation twice.
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$crew = NULL;
foreach ($etm->getStorage('user')->loadMultiple(
  \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('status', 1)
    ->condition('roles', 'teammates')->condition('uid', 1, '<>')->execute()
) as $u) {
  if (!array_intersect(['administrator', 'site_admin', 'site_assistant', 'administration', 'supervisor'], $u->getRoles())) {
    $crew = $u;
    break;
  }
}
if (!$crew) {
  print "\n(no crew-only account to verify against)\n";
  return;
}
$own = $db->query('SELECT entity_id FROM {wo_time_clock__field_teammate}
  WHERE deleted = 0 AND field_teammate_target_id = :u ORDER BY entity_id DESC LIMIT 1', [':u' => $crew->id()])->fetchField();
$other = $db->query('SELECT entity_id FROM {wo_time_clock__field_teammate}
  WHERE deleted = 0 AND field_teammate_target_id <> :u ORDER BY entity_id DESC LIMIT 1', [':u' => $crew->id()])->fetchField();
$ach = $etm->getAccessControlHandler('wo_time_clock');
printf("\nverified as %s (uid %s):\n", $crew->getDisplayName(), $crew->id());
foreach ([[$own, 'their OWN entry', TRUE], [$other, "another teammate's entry", FALSE]] as [$id, $label, $want]) {
  if (!$id) {
    continue;
  }
  $ach->resetCache();
  $can = $etm->getStorage('wo_time_clock')->load($id)->access('update', $crew);
  printf("  %-28s update=%-8s %s\n", $label, $can ? 'ALLOWED' : 'denied',
    $can === $want ? 'correct' : '** WRONG **');
}
