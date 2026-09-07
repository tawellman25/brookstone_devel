<?php

/**
 * @file
 * Reversible test of the winterizing discount engine. Creates a temp property +
 * fixtures, runs winterizing WOs through completion, checks field_wo_total +
 * field_winterize_discount/_reason, then deletes everything. Dev only.
 *
 * Run: ddev drush php:script web/scripts/test_wo_winterize_discounts.php
 */

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $got = '') use (&$pass, &$fail) {
  print ($cond ? "  PASS " : "  FAIL ") . $label . ($got !== '' ? " [$got]" : '') . "\n";
  $cond ? $pass++ : $fail++;
};
$svc = (int) reset($etm->getStorage('taxonomy_term')->getQuery()->condition('field_service_bundle', 'sprinkler_winterizing')->accessCheck(FALSE)->range(0, 1)->execute());
$made = ['wo' => [], 'cs' => [], 'contract' => [], 'prop' => [], 'own' => [], 'user' => []];

// Temp property with no systems / no history.
$prop = $etm->getStorage('properties')->create(['type' => 'property', 'field_nickname' => 'ZZ Discount Test']);
$prop->save(); $pid = (int) $prop->id(); $made['prop'][] = $pid;
print "temp property: $pid  (winterizing service term $svc)\n";

// Helper: complete a fresh winterizing WO on $pid, force domestic ($95 base), return reloaded WO.
$completeWo = function () use ($etm, $pid, $svc, &$made) {
  $wo = $etm->getStorage('work_order')->create(['type' => 'sprinkler_winterizing', 'field_property' => $pid, 'field_service' => $svc, 'field_status' => 1092, 'field_system_type' => 13]);
  $wo->save(); $made['wo'][] = $wo->id();
  $wo = $etm->getStorage('work_order')->load($wo->id());
  $wo->set('field_status', 1097)->set('field_system_type', 13)->save();
  return $etm->getStorage('work_order')->load($wo->id());
};

// ---- A. No discounts → base $95 ----
print "\nA. No discounts (domestic, no history/contract/HOA)\n";
$wo = $completeWo();
$ok('task_rate = 95', abs((float) $wo->get('field_task_rate')->value - 95) < 0.01, $wo->get('field_task_rate')->value);
$ok('discount = 0', abs((float) $wo->get('field_winterize_discount')->value) < 0.01, $wo->get('field_winterize_discount')->value);
$ok('wo_total = 95', abs((float) $wo->get('field_wo_total')->value - 95) < 0.01, $wo->get('field_wo_total')->value);

// ---- B. HOA contracted → -$35 ----
print "\nB. HOA contracted flag → -\$35\n";
$prop = $etm->getStorage('properties')->load($pid); $prop->set('field_hoa_contracted', TRUE)->save();
$wo = $completeWo();
$ok('discount = 35', abs((float) $wo->get('field_winterize_discount')->value - 35) < 0.01, $wo->get('field_winterize_discount')->value);
$ok('reason = HOA Contracted', $wo->get('field_winterize_discount_reason')->value === 'HOA Contracted', $wo->get('field_winterize_discount_reason')->value);
$ok('wo_total = 60', abs((float) $wo->get('field_wo_total')->value - 60) < 0.01, $wo->get('field_wo_total')->value);
$prop = $etm->getStorage('properties')->load($pid); $prop->set('field_hoa_contracted', FALSE)->save();

// ---- C. Current-year contract with 5 services incl winterizing → -$10 (beats -$5) ----
print "\nC. Contract w/ 5 services incl winterizing → -\$10 (largest of 10/5)\n";
$contract = $etm->getStorage('contracts')->create(['type' => 'residential', 'field_property' => $pid, 'field_contract_year' => (int) date('Y')]);
$contract->save(); $cid = (int) $contract->id(); $made['contract'][] = $cid;
$bundles = ['irrigation_shut_down', 'lawn_mowing_and_trimming', 'fall_cleanup', 'spring_cleanup', 'pre_emergent'];
foreach ($bundles as $b) {
  $cs = $etm->getStorage('contract_sections')->create(['type' => $b, 'field_contract' => $cid, 'field_do_you_want' => '1']);
  $cs->save(); $made['cs'][] = $cs->id();
}
$wo = $completeWo();
$ok('discount = 10', abs((float) $wo->get('field_winterize_discount')->value - 10) < 0.01, $wo->get('field_winterize_discount')->value);
$ok('reason = Contract with 4+ services', $wo->get('field_winterize_discount_reason')->value === 'Contract with 4+ services', $wo->get('field_winterize_discount_reason')->value);
$ok('wo_total = 85', abs((float) $wo->get('field_wo_total')->value - 85) < 0.01, $wo->get('field_wo_total')->value);

// ---- C2. Same contract but only winterizing selected (1 service) → -$5 (auto/contract) ----
print "\nC2. Contract w/ ONLY winterizing (1 service) → -\$5 (contract/auto-list)\n";
foreach (array_slice($made['cs'], 1) as $csId) { // keep the winterizing one, remove the other 4
  if ($cs = $etm->getStorage('contract_sections')->load($csId)) { $cs->delete(); }
}
$made['cs'] = array_slice($made['cs'], 0, 1);
$wo = $completeWo();
$ok('discount = 5', abs((float) $wo->get('field_winterize_discount')->value - 5) < 0.01, $wo->get('field_winterize_discount')->value);
$ok('reason = Signed contract / Automatic list', $wo->get('field_winterize_discount_reason')->value === 'Signed contract / Automatic list', $wo->get('field_winterize_discount_reason')->value);

// ---- D. Auto-list via a prior-year winterizing WO (no contract) → -$5 ----
print "\nD. Prior-year winterizing WO → -\$5 (automatic list)\n";
foreach ($made['cs'] as $csId) { if ($cs = $etm->getStorage('contract_sections')->load($csId)) { $cs->delete(); } }
$made['cs'] = [];
foreach ($made['contract'] as $ctId) { if ($ct = $etm->getStorage('contracts')->load($ctId)) { $ct->delete(); } }
$made['contract'] = [];
$prior = $etm->getStorage('work_order')->create(['type' => 'sprinkler_winterizing', 'field_property' => $pid, 'field_service' => $svc, 'field_status' => 1092, 'created' => strtotime('2025-06-01')]);
$prior->save(); $made['wo'][] = $prior->id();
$wo = $completeWo();
$ok('discount = 5', abs((float) $wo->get('field_winterize_discount')->value - 5) < 0.01, $wo->get('field_winterize_discount')->value);
$ok('reason = auto list', $wo->get('field_winterize_discount_reason')->value === 'Signed contract / Automatic list', $wo->get('field_winterize_discount_reason')->value);

// ---- Cleanup ----
print "\nCleanup\n";
foreach ($made['wo'] as $id) { if ($w = $etm->getStorage('work_order')->load($id)) { $w->_skip_invoiced_guard = TRUE; $w->delete(); } }
foreach ($made['cs'] as $id) { if ($e = $etm->getStorage('contract_sections')->load($id)) { $e->delete(); } }
foreach ($made['contract'] as $id) { if ($e = $etm->getStorage('contracts')->load($id)) { $e->delete(); } }
foreach ($made['prop'] as $id) { if ($e = $etm->getStorage('properties')->load($id)) { $e->delete(); } }
$ok('cleanup done', TRUE);

print "\n==== $pass passed, $fail failed ====\n";
