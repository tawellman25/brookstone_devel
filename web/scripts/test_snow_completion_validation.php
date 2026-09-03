<?php

/**
 * @file
 * Reversible test of the snow completion rules (Phase 2). Builds a property +
 * ice-required snow contract + snow WO, then evaluates
 * _wo_sign_off_snow_completion_errors_from_entity() against in-memory
 * wo_tasks_list:snow_removal entities for each scenario. Cleans up.
 */

$etm = \Drupal::entityTypeManager();

// Find the Icy term + a valid plow-portion term.
$icy = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'snow_levels']) as $t) {
  if (stripos($t->label(), 'icy') !== FALSE) { $icy = $t; break; }
}
$portion = current($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'snow_plows']));
print 'Icy term: ' . ($icy ? $icy->id() . ' ' . $icy->label() : 'NONE') . "\n";

// Minimal property + ice-required snow contract + snow WO.
$prop = $etm->getStorage('properties')->create(['type' => 'property', 'title' => 'ZZ Snow Test Property']);
$prop->save();
$contract = $etm->getStorage('contracts')->create([
  'type' => 'snow_removal',
  'field_property' => ['target_id' => $prop->id()],
  'field_requires_ice_treatment' => TRUE,
]);
$contract->save();
$svc = current($etm->getStorage('taxonomy_term')->loadByProperties(['field_service_bundle' => 'snow_removal']));
$wo = $etm->getStorage('work_order')->create([
  'type' => 'snow_removal',
  'field_property' => ['target_id' => $prop->id()],
] + ($svc ? ['field_service' => ['target_id' => $svc->id()]] : []));
$wo->save();
print "Built property {$prop->id()}, ice-required contract {$contract->id()}, WO {$wo->id()}\n\n";

$make = function (array $fields) use ($etm, $wo) {
  return $etm->getStorage('wo_tasks_list')->create([
    'type' => 'snow_removal',
    'field_work_order' => ['target_id' => $wo->id()],
    'field_completed' => FALSE,
  ] + $fields);
};

$scenarios = [
  'plowed, no portion (expect: portion error)' => $make(['field_snow_plowed' => TRUE]),
  'plowed + portion (expect: OK)' => $make(['field_snow_plowed' => TRUE, 'field_snow_plowed_pushes' => ['target_id' => $portion->id()]]),
  'not plowed, no portion (expect: OK)' => $make(['field_snow_plowed' => FALSE]),
  'Icy + ice-required + no salt/mag (expect: ice error)' => $make(['field_snow_plowed' => FALSE, 'field_snow_level' => ['target_id' => $icy->id()]]),
  'Icy + ice-required + salt>0 (expect: OK)' => $make(['field_snow_plowed' => FALSE, 'field_snow_level' => ['target_id' => $icy->id()], 'field_pounds_of_salt' => 50]),
  'Icy + ice-required + mag>0 (expect: OK)' => $make(['field_snow_plowed' => FALSE, 'field_snow_level' => ['target_id' => $icy->id()], 'field_snow_mag_gallons' => 5]),
];
foreach ($scenarios as $label => $e) {
  $errs = _wo_sign_off_snow_completion_errors_from_entity($e);
  print sprintf("%-55s -> %s\n", $label, $errs ? ('BLOCK: ' . implode(' | ', array_map('strval', $errs))) : 'OK');
}

// Cleanup.
$wo->delete();
$contract->delete();
$prop->delete();
print "\ncleaned up\nDONE\n";
