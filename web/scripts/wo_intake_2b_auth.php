<?php

/**
 * Gate 2B: authoring + two-locks access gate. Run: drush php:script.
 * Simulates the form (which passes current_user to createFromText) via the
 * account switcher. Creates/cleans on the local synced DB.
 */

use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

$svc = \Drupal::service('bos_wo_intake.intake');
$switcher = \Drupal::service('account_switcher');
$etm = \Drupal::entityTypeManager();
$ws = $etm->getStorage('work_order');
$ns = $etm->getStorage('wo_notes');
$cu = \Drupal::service('current_user');

$del = function ($id) use ($ws, $ns) {
  $wo = $ws->loadUnchanged($id); if (!$wo) return;
  foreach ($ns->getQuery()->accessCheck(FALSE)->condition('field_work_order', $id)->execute() as $nid) { $ns->load($nid)->delete(); }
  $wo->set('field_status', ['target_id' => 1089]); $wo->_skip_invoiced_guard = TRUE;
  try { $wo->save(); $wo->delete(); } catch (\Throwable $e) {}
};
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 28323)->execute() as $id) { $del($id); }

print "=============== GATE 2B AUTHORING + ACCESS GATE ===============\n";

// --- Test 2: authoring by the logged-in human ---
$uid = \Drupal::database()->query("SELECT u.uid FROM users_field_data u JOIN user__roles r ON r.entity_id=u.uid WHERE r.roles_target_id='administration' AND u.status=1 LIMIT 1")->fetchField();
$office = User::load($uid);
$switcher->switchTo($office);
$r = $svc->createFromText('repair for jim lyman on willow dr. leaking head.', $cu);
$switcher->switchBack();

if (($r['status'] ?? '') === 'created') {
  $wo = $ws->loadUnchanged($r['work_order']['id']);
  $woUid = (int) $wo->getOwnerId();
  // Complaint now lands in the WO's "Work To Be Done" description, not a note.
  $desc = strip_tags((string) $wo->get('field_work_todo_description')->value);
  $inDesc = str_contains($desc, 'leaking head');
  $noNotes = (int) $ns->getQuery()->accessCheck(FALSE)->condition('field_work_order', $wo->id())->count()->execute() === 0;
  printf("  [%s] authoring: office uid=%d  WO owner=%d  complaint-in-description=%s  zero-notes=%s\n",
    ($woUid === (int) $uid && $inDesc && $noNotes) ? 'PASS' : 'FAIL', $uid, $woUid, $inDesc ? 'Y' : 'N', $noNotes ? 'Y' : 'N');
  printf("       description tail: \"%s\"\n", trim(substr($desc, -60)));
  $del($wo->id());
}
else {
  print "  [FAIL] authoring: expected created, got " . ($r['status'] ?? '?') . "\n";
}

// --- Test 7: page perm but NO entity perms -> access_denied, zero entities ---
if (!Role::load('wo_intake_test_tmp')) {
  $role = Role::create(['id' => 'wo_intake_test_tmp', 'label' => 'WO Intake Test (page only)']);
  $role->grantPermission('use work order intake');
  $role->save();
}
$tmp = User::create(['name' => 'wo-intake-gate-tmp', 'status' => 1, 'mail' => 'wo-gate-tmp@example.com']);
$tmp->addRole('wo_intake_test_tmp');
$tmp->save();

$before = (int) $ws->getQuery()->accessCheck(FALSE)->count()->execute();
$switcher->switchTo($tmp);
$hasPage = $cu->hasPermission('use work order intake');
$hasEntity = $cu->hasPermission('create work_order entities');
$r = $svc->createFromText('repair for jim lyman on willow dr', $cu);
$switcher->switchBack();
$after = (int) $ws->getQuery()->accessCheck(FALSE)->count()->execute();

printf("  [%s] two-locks: page_perm=%s entity_perm=%s -> status=%s code=%s; wo_count %d==%d\n",
  (($r['status'] ?? '') === 'error' && ($r['error']['code'] ?? '') === 'access_denied' && $before === $after) ? 'PASS' : 'FAIL',
  $hasPage ? 'Y' : 'n', $hasEntity ? 'Y' : 'n', $r['status'] ?? '?', $r['error']['code'] ?? '?', $before, $after);

$tmp->delete();
Role::load('wo_intake_test_tmp')?->delete();
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 28323)->execute() as $id) { $del($id); }
print "=============== done ===============\n";
