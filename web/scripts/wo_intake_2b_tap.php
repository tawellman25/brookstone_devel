<?php

/**
 * Gate 2B: candidate-tap + create-anyway resubmit logic (the interactive core).
 * Drives the form's submitIntake handler directly. Run: drush php:script.
 */

use Drupal\Core\Form\FormState;
use Drupal\user\Entity\User;

$container = \Drupal::getContainer();
$form = \Drupal\bos_wo_intake\Form\WoIntakeForm::create($container);
$switcher = \Drupal::service('account_switcher');
$ws = \Drupal::entityTypeManager()->getStorage('work_order');
$ns = \Drupal::entityTypeManager()->getStorage('wo_notes');

$uid = \Drupal::database()->query("SELECT u.uid FROM users_field_data u JOIN user__roles r ON r.entity_id=u.uid WHERE r.roles_target_id='administration' AND u.status=1 LIMIT 1")->fetchField();
$switcher->switchTo(User::load($uid));

$del = function ($id) use ($ws, $ns) {
  $wo = $ws->loadUnchanged($id); if (!$wo) return;
  foreach ($ns->getQuery()->accessCheck(FALSE)->condition('field_work_order', $id)->execute() as $nid) { $ns->load($nid)->delete(); }
  $wo->set('field_status', ['target_id' => 1089]); $wo->_skip_invoiced_guard = TRUE;
  try { $wo->save(); $wo->delete(); } catch (\Throwable $e) {}
};
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 28323)->execute() as $id) { $del($id); }
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 77176)->execute() as $id) { $del($id); }

$submit = new \ReflectionMethod($form, 'submitIntake');
$submit->setAccessible(TRUE);

// Drive submitIntake with a given trigger/command/storage; return new storage.
$drive = function (array $trigger, string $command = '', array $storage = []) use ($form, $submit) {
  $fs = new FormState();
  if ($command !== '') { $fs->setValue('command', $command); }
  if ($storage) { $fs->setStorage($storage); }
  $fs->setTriggeringElement($trigger);
  $arr = [];
  $submit->invoke($form, $arr, $fs);
  return $fs->getStorage();
};

print "=============== GATE 2B TAP / RESUBMIT LOGIC ===============\n";

// 1. property tap: create (ambiguous) -> tap Delta McLaughlin (77176) -> created.
$s1 = $drive(['#wo_action' => 'create'], 'repair for mclaughlin');
$amb = $s1['result']['status'];
$s2 = $drive(['#wo_action' => 'pick_property', '#wo_property_id' => 77176], '', $s1);
$r2 = $s2['result'];
$prop = ($r2['status'] === 'created') ? (int) $ws->loadUnchanged($r2['work_order']['id'])->get('field_property')->target_id : 0;
printf("  [%s] property tap: create=%s -> tap 77176 -> %s (property=%d)\n",
  ($amb === 'ambiguous' && $r2['status'] === 'created' && $prop === 77176) ? 'PASS' : 'FAIL', $amb, $r2['status'], $prop);
if (($r2['status'] ?? '') === 'created') { $del($r2['work_order']['id']); }

// 2. service tap: "design for jim lyman" (ambiguous service) -> tap Sprinkler Design (371) -> created.
$s1 = $drive(['#wo_action' => 'create'], 'design for jim lyman on willow dr');
$ambS = $s1['result']['status'] . '/' . ($s1['result']['piece'] ?? '');
$s2 = $drive(['#wo_action' => 'pick_service', '#wo_service_term_id' => 371], '', $s1);
$r2 = $s2['result'];
$bundle = ($r2['status'] === 'created') ? $r2['work_order']['bundle'] : '';
printf("  [%s] service tap: create=%s -> tap 371 -> %s (bundle=%s, expect sprinkler_design)\n",
  ($ambS === 'ambiguous/service' && $r2['status'] === 'created' && $bundle === 'sprinkler_design') ? 'PASS' : 'FAIL', $ambS, $r2['status'], $bundle);
if (($r2['status'] ?? '') === 'created') { $del($r2['work_order']['id']); }

// 3. create-anyway: create (dup) -> blocked -> create anyway -> created.
$s1 = $drive(['#wo_action' => 'create'], 'repair for jim lyman on willow dr');
$firstWo = $s1['result']['work_order']['id'] ?? NULL;
$s2 = $drive(['#wo_action' => 'create'], 'repair for jim lyman on willow dr');   // dup
$blocked = $s2['result']['status'];
$s3 = $drive(['#wo_action' => 'create_anyway'], '', $s2);
$r3 = $s3['result'];
printf("  [%s] create-anyway: 1st=%s dup=%s anyway=%s\n",
  ($s1['result']['status'] === 'created' && $blocked === 'blocked' && $r3['status'] === 'created') ? 'PASS' : 'FAIL',
  $s1['result']['status'], $blocked, $r3['status']);
if ($firstWo) { $del($firstWo); }
if (($r3['status'] ?? '') === 'created') { $del($r3['work_order']['id']); }

$switcher->switchBack();
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 28323)->execute() as $id) { $del($id); }
foreach ($ws->getQuery()->accessCheck(FALSE)->condition('field_property', 77176)->execute() as $id) { $del($id); }
print "=============== done ===============\n";
