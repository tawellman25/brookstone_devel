<?php

/**
 * @file
 * Reversible test of the HOA GPS point-in-polygon membership engine.
 * Creates a discounted test HOA with a boundary enclosing two known homes,
 * runs the scan, verifies they're flagged + an outside home isn't, tests removal
 * (un-discount), then deletes/ restores everything. Dev only.
 *
 * Run: ddev drush php:script web/scripts/test_hoa_geo_membership.php
 */

$etm = \Drupal::entityTypeManager();
$svc = \Drupal::service('bos_hoa.membership');
$store = $etm->getStorage('properties');
$pass = 0; $fail = 0;
$ok = function ($label, $cond, $got = '') use (&$pass, &$fail) {
  print ($cond ? "  PASS " : "  FAIL ") . $label . ($got !== '' ? " [$got]" : '') . "\n";
  $cond ? $pass++ : $fail++;
};

$INSIDE = [144260, 28899]; // lat ~38.3783/38.3789, lon ~-107.8162/-107.8172
$OUTSIDE = 38164;          // lat 38.302, lon -107.769
$all = array_merge($INSIDE, [$OUTSIDE]);

// Snapshot original state to restore.
$orig = [];
foreach ($all as $pid) {
  $p = $store->load($pid);
  $orig[$pid] = [
    'hoa' => ($p && !$p->get('field_hoa')->isEmpty()) ? (int) $p->get('field_hoa')->target_id : NULL,
    'disc' => ($p && !$p->get('field_hoa_contracted')->isEmpty()) ? (int) $p->get('field_hoa_contracted')->value : NULL,
  ];
}

// Box: lon -107.818..-107.815, lat 38.377..38.380 (encloses the 2 inside homes).
$wkt = 'POLYGON((-107.818 38.377, -107.815 38.377, -107.815 38.380, -107.818 38.380, -107.818 38.377))';
$hoa = $store->create(['type' => 'hoa', 'field_nickname' => 'ZZ Geo Test HOA', 'field_hoa_contracted' => TRUE, 'field_boundary' => ['value' => $wkt]]);
$hoa->_bos_hoa_scan = TRUE; // suppress the insert auto-sync so we can test the scan directly
$hoa->save();
$hoaId = (int) $hoa->id();

// geofield should have computed the bbox on save.
$bbox = \Drupal::database()->query("SELECT field_boundary_left l, field_boundary_right r, field_boundary_top t, field_boundary_bottom b FROM {properties__field_boundary} WHERE entity_id = :id", [':id' => $hoaId])->fetchAssoc();
$ok('boundary bbox computed by geofield', $bbox && $bbox['l'] !== NULL, $bbox ? "l={$bbox['l']} r={$bbox['r']} t={$bbox['t']} b={$bbox['b']}" : 'none');

// Scan (dry run) — inside should be exactly the 2.
$dry = $svc->syncHoa($hoaId, FALSE);
$ok('scan finds the 2 inside homes', $dry['inside'] === 2 && in_array(144260, $dry['add_ids']) && in_array(28899, $dry['add_ids']), 'inside=' . $dry['inside']);
$ok('outside home NOT found', !in_array($OUTSIDE, $dry['add_ids']));

// Apply.
$svc->syncHoa($hoaId, TRUE);
foreach ($INSIDE as $pid) {
  $p = $store->load($pid);
  $ok("home $pid flagged + linked", (int) $p->get('field_hoa_contracted')->value === 1 && (int) $p->get('field_hoa')->target_id === $hoaId);
}
$po = $store->load($OUTSIDE);
$ok("outside home $OUTSIDE untouched", $po->get('field_hoa')->isEmpty());

// Removal: un-discount the HOA → scan releases members.
$hoa = $store->load($hoaId);
$hoa->set('field_hoa_contracted', FALSE)->save(); // triggers update hook → auto re-sync
foreach ($INSIDE as $pid) {
  $p = $store->load($pid);
  $ok("home $pid released after HOA un-discounted", $p->get('field_hoa')->isEmpty() && (int) $p->get('field_hoa_contracted')->value === 0);
}

// Cleanup: delete test HOA + restore the 3 homes.
$store->load($hoaId)->delete();
foreach ($all as $pid) {
  $p = $store->load($pid);
  $p->_bos_hoa_scan = TRUE; // skip presave auto-assign during restore
  $p->set('field_hoa', $orig[$pid]['hoa']);
  $p->set('field_hoa_contracted', $orig[$pid]['disc']);
  $p->save();
}
$ok('cleanup + restore done', TRUE);

print "\n==== $pass passed, $fail failed ====\n";
