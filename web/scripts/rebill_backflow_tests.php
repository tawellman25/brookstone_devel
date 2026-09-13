<?php

/**
 * Re-bill Complete backflow certification WOs under the current billing model
 * (base fee + repair addition, no trip — `cdf1d0ed`), for WOs entered before it
 * shipped. Re-saves each Complete (1097) work_order:backflow_testing that has a
 * real test child, which re-runs the billing presave. Idempotent (recompute is
 * deterministic; device write-back appends no log row on an unchanged re-save,
 * PDF is skip-if-present). Only touches Complete WOs — never Invoiced/Paid.
 *
 * Dry-run by default; BOS_APPLY=1 to write.
 */

$APPLY = getenv('BOS_APPLY') === '1';
print $APPLY ? "=== APPLY ===\n" : "=== DRY RUN (BOS_APPLY=1 to write) ===\n";
$etm = \Drupal::entityTypeManager();

$ids = $etm->getStorage('wo_tasks_list')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'backflow_testing')->exists('field_work_order')->execute();
$woids = [];
foreach ($etm->getStorage('wo_tasks_list')->loadMultiple($ids) as $t) {
  if ($t->get('field_test_date')->isEmpty() && $t->get('field_pass_fail')->isEmpty() && $t->get('field_tester')->isEmpty()) {
    continue;
  }
  $woids[$t->get('field_work_order')->target_id] = TRUE;
}

$n = 0;
foreach (array_keys($woids) as $woid) {
  $wo = $etm->getStorage('work_order')->load($woid);
  if (!$wo) { continue; }
  if ((int) ($wo->get('field_status')->target_id ?? 0) !== 1097) {
    print "  WO $woid: skipped (not Complete)\n";
    continue;
  }
  $old = (float) ($wo->get('field_wo_total')->value ?? 0);
  if ($APPLY) {
    $wo->save();
    $wo = $etm->getStorage('work_order')->load($woid);
    $new = (float) ($wo->get('field_wo_total')->value ?? 0);
    printf("  WO %s: wo_total \$%s -> \$%s (labor \$%s, trip \$%s)\n", $woid,
      number_format($old, 2), number_format($new, 2),
      number_format((float) $wo->get('field_labor_total')->value, 2),
      number_format((float) $wo->get('field_trip_fee')->value, 2));
  }
  else {
    printf("  WO %s: current wo_total \$%s (total_time %s) — would re-bill\n", $woid,
      number_format($old, 2), $wo->get('field_total_time')->value ?? '0');
  }
  $n++;
}
print ($APPLY ? "re-billed" : "would re-bill") . ": $n WO(s)\n";
