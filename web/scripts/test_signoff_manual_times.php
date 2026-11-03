<?php

/**
 * @file
 * The sign-off reconciliation must stop inventing start times.
 *
 * Before 2026-11-02, a sign-off on a SIMPLE bundle silently created missing
 * crew entries from "start = earliest clock-in on the WO, else the WO's
 * created timestamp; end = now". When nobody had clocked in, both fallbacks
 * fired. WO 54225 (fall_cleanup) was created 10/07 and signed off 11/02 →
 * a 623-hour entry per crew member, rejected by the entry-hours guard, so the
 * sign-off failed and four people's time went unrecorded. 315 open work orders
 * had no clock-ins and sat on the same trap.
 *
 * The fix is one predicate — requiresManualTimes() — used by the form build,
 * the validate handler and the submit handler alike. What this asserts:
 *
 *   1. No anchor clock-in  -> manual entry REQUIRED (the fix).
 *   2. An anchor exists    -> still silent-create (one-click preserved;
 *                             inheriting a real colleague's shift is good data
 *                             and the common case).
 *   3. Complex bundles     -> always manual, anchor or not (unchanged).
 *   4. The three call sites agree, since a form that renders inputs the
 *      validator ignores is its own bug.
 *
 * READ-ONLY. Writes nothing.
 *
 * Run: drush php:script web/scripts/test_signoff_manual_times.php
 */

$pass = 0;
$fail = 0;
$ok = function (string $what, bool $good, string $detail = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $detail !== '' ? "  [$detail]" : '');
};

$svc = \Drupal::service('wo_sign_off.crew_roster');
$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();

$ok('the service exposes requiresManualTimes()', method_exists($svc, 'requiresManualTimes'));
$ok('the service exposes hasAnchorClockIn()', method_exists($svc, 'hasAnchorClockIn'));
if (!method_exists($svc, 'requiresManualTimes')) {
  printf("\n%d passed, %d failed\n", $pass, $fail);
  return;
}

// --- find real work orders in each shape ------------------------------------
$closed = [1097, 1098, 1281, 1283, 1504];

$noClockIn = (int) $db->query('SELECT wo.id FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE st.field_status_target_id NOT IN (:c[])
    AND NOT EXISTS (SELECT 1 FROM {wo_time_clock__field_work_order} w
                    WHERE w.deleted = 0 AND w.field_work_order_target_id = wo.id)
  ORDER BY wo.created ASC LIMIT 1', [':c[]' => $closed])->fetchField();

$withClockIn = (int) $db->query('SELECT w.field_work_order_target_id
  FROM {wo_time_clock__field_work_order} w
  JOIN {wo_time_clock__field_start_time} s ON s.entity_id = w.entity_id AND s.deleted = 0
  WHERE w.deleted = 0 LIMIT 1')->fetchField();

printf("\nsample WOs: no clock-in = %d, with clock-in = %d\n\n", $noClockIn, $withClockIn);

// --- 1. the fix --------------------------------------------------------------
$ok('a WO with NO clock-in has no anchor', $svc->hasAnchorClockIn($noClockIn) === FALSE);
$ok('simple bundle + no anchor -> manual times REQUIRED',
  $svc->requiresManualTimes('wo_complete_info', 'clean_up_crew', $noClockIn) === TRUE,
  'this is what stops the 623-hour entry');

// --- 2. the common case must not regress ------------------------------------
$ok('a WO WITH a clock-in has an anchor', $svc->hasAnchorClockIn($withClockIn) === TRUE);
$ok('simple bundle + anchor -> still silent-create',
  $svc->requiresManualTimes('wo_complete_info', 'clean_up_crew', $withClockIn) === FALSE,
  'one-click sign-off preserved');

// --- 3. complex bundles unchanged -------------------------------------------
foreach (['landscape_crew', 'irrigation_crew', 'lighting_crew'] as $b) {
  $ok("complex bundle $b -> manual regardless of anchor",
    $svc->requiresManualTimes('wo_complete_info', $b, $withClockIn) === TRUE
    && $svc->requiresManualTimes('wo_complete_info', $b, $noClockIn) === TRUE);
}

// --- 4. defensive --------------------------------------------------------------
$ok('a bogus WO id has no anchor (so it asks rather than invents)',
  $svc->hasAnchorClockIn(0) === FALSE
  && $svc->requiresManualTimes('wo_complete_info', 'clean_up_crew', 0) === TRUE);

// --- 5. the three handlers use the same predicate ----------------------------
$module = file_get_contents(DRUPAL_ROOT . '/modules/custom/wo_sign_off/wo_sign_off.module');
$ok('no decision point still calls isComplexBundle() directly',
  !preg_match('/\$svc->isComplexBundle\(/', $module),
  'build, validate and submit must agree');
$ok('all three handlers call requiresManualTimes()',
  substr_count($module, '$svc->requiresManualTimes(') >= 3,
  substr_count($module, '$svc->requiresManualTimes(') . ' call sites');

// --- 6. the WO that actually failed ------------------------------------------
$wo = $etm->getStorage('work_order')->load(54225);
if ($wo) {
  $anchor = $svc->hasAnchorClockIn(54225);
  $ok('WO 54225 (the one that failed) would now ask for times',
    $anchor === FALSE && $svc->requiresManualTimes('wo_complete_info', 'clean_up_crew', 54225) === TRUE,
    'bundle ' . $wo->bundle());
}
else {
  printf("  SKIP  WO 54225 not present in this environment\n");
}

// --- scale -------------------------------------------------------------------
$exposed = (int) $db->query('SELECT COUNT(*) FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE st.field_status_target_id NOT IN (:c[])
    AND NOT EXISTS (SELECT 1 FROM {wo_time_clock__field_work_order} w
                    WHERE w.deleted = 0 AND w.field_work_order_target_id = wo.id)',
  [':c[]' => $closed])->fetchField();
printf("\nopen WOs with no clock-in that will now ask instead of inventing: %d\n", $exposed);

printf("\n%d passed, %d failed\n", $pass, $fail);
