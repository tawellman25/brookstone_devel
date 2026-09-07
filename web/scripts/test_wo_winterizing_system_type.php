<?php

/**
 * @file
 * Reversible test for winterizing WO System Type (pre-fill, crew-override
 * billing, freeze, Option-A write-back). Creates temp WOs, verifies, deletes
 * them, and restores any property it touched. Dev only.
 *
 * Run: ddev drush php:script web/scripts/test_wo_winterizing_system_type.php
 */

use Drupal\Core\Entity\EntityInterface;

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$pass = 0; $fail = 0;
$ok = function ($label, $cond) use (&$pass, &$fail) {
    print ($cond ? "  PASS " : "  FAIL ") . $label . "\n";
    $cond ? $pass++ : $fail++;
};

// Winterizing service term.
$svc = (int) reset($etm->getStorage('taxonomy_term')->getQuery()
    ->condition('field_service_bundle', 'sprinkler_winterizing')->accessCheck(FALSE)->range(0, 1)->execute());

// Helper: first property whose primary sprinkler system == $tid.
$propWithType = function (int $tid) use ($db) {
    $rows = $db->query("SELECT st.entity_id sys FROM {property_sprinkler_system__field_system_type} st WHERE st.field_system_type_target_id=:t LIMIT 50", [':t' => $tid])->fetchCol();
    foreach ($rows as $sysId) {
        $infoId = $db->query("SELECT entity_id FROM {property_sprinkler_info__field_systems} WHERE field_systems_target_id=:s LIMIT 1", [':s' => $sysId])->fetchField();
        if (!$infoId) { continue; }
        $pid = $db->query("SELECT field_property_target_id FROM {property_sprinkler_info__field_property} WHERE entity_id=:e", [':e' => $infoId])->fetchField();
        // single-system only for a clean test
        $count = (int) $db->query("SELECT COUNT(*) FROM {property_sprinkler_info__field_systems} WHERE entity_id=:e", [':e' => $infoId])->fetchField();
        if ($pid && $count === 1) { return [(int) $pid, (int) $sysId]; }
    }
    return [0, 0];
};

$made = [];
$restore = [];

[$dirtyPid, $dirtySys] = $propWithType(15);
[$domPid, $domSys] = $propWithType(13);
print "dirty-water property: $dirtyPid (sys $dirtySys)   domestic property: $domPid (sys $domSys)\n\n";

// ---- Test 1: pre-fill from property on create ----
print "Test 1 — pre-fill on create (dirty property)\n";
$wo1 = $etm->getStorage("work_order")->create(['type' => 'sprinkler_winterizing', 'field_property' => $dirtyPid, 'field_service' => $svc, 'field_status' => 1092]);
$wo1->save();
$made[] = $wo1->id();
$wo1 = $etm->getStorage("work_order")->load($wo1->id());
$ok("field_system_type pre-filled to 15 (Dirty)", !$wo1->get('field_system_type')->isEmpty() && (int) $wo1->get('field_system_type')->target_id === 15);

// ---- Test 2: complete → bills base+pump, frozen ----
print "Test 2 — completion bills base+pump (dirty)\n";
$wo1->set('field_status', 1097)->save();
$wo1 = $etm->getStorage("work_order")->load($wo1->id());
$rate = (float) $wo1->get('field_task_rate')->value;
$ok("field_task_rate = 115 (90 base + 25 pump), got $rate", abs($rate - 115.0) < 0.01);
$ok("field_system_type frozen = 15", (int) $wo1->get('field_system_type')->target_id === 15);

// ---- Test 3: crew override on a domestic property → bills +pump + writes back ----
print "Test 3 — crew corrects Domestic→Dirty on the WO (override + write-back)\n";
$domOrig = (int) $db->query("SELECT field_system_type_target_id FROM {property_sprinkler_system__field_system_type} WHERE entity_id=:s", [':s' => $domSys])->fetchField();
$restore[$domSys] = $domOrig;
$wo2 = $etm->getStorage("work_order")->create(['type' => 'sprinkler_winterizing', 'field_property' => $domPid, 'field_service' => $svc, 'field_status' => 1092]);
$wo2->save();
$made[] = $wo2->id();
$wo2 = $etm->getStorage("work_order")->load($wo2->id());
$ok("pre-filled to 13 (Domestic)", (int) $wo2->get('field_system_type')->target_id === 13);
// Crew corrects it to Dirty (15) on the WO, then completes.
$wo2->set('field_system_type', 15)->set('field_status', 1097)->save();
$wo2 = $etm->getStorage("work_order")->load($wo2->id());
$rate2 = (float) $wo2->get('field_task_rate')->value;
$ok("WO value wins → task_rate = 115, got $rate2", abs($rate2 - 115.0) < 0.01);
$sysNow = (int) $db->query("SELECT field_system_type_target_id FROM {property_sprinkler_system__field_system_type} WHERE entity_id=:s", [':s' => $domSys])->fetchField();
$ok("property system written back to 15 (Option A)", $sysNow === 15);

// ---- Cleanup ----
print "\nCleanup\n";
foreach ($made as $id) {
    if ($wo = $etm->getStorage("work_order")->load($id)) { $wo->set('field_status', 1098); $wo->_skip_invoiced_guard = TRUE; $wo->delete(); }
}
foreach ($restore as $sysId => $tid) {
    $sys = $etm->getStorage('property_sprinkler_system')->load($sysId);
    if ($sys) { $sys->set('field_system_type', $tid ?: NULL)->save(); }
}
$check = (int) $db->query("SELECT field_system_type_target_id FROM {property_sprinkler_system__field_system_type} WHERE entity_id=:s", [':s' => $domSys])->fetchField();
$ok("domestic property restored to $domOrig", $check === $domOrig);
print "  deleted WOs: " . implode(', ', $made) . "\n";

print "\n==== $pass passed, $fail failed ====\n";
