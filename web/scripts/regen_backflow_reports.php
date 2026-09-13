<?php

/**
 * Regenerate frozen backflow report PDFs so they adopt the current template
 * (letterhead/logo, QR, footer, gauge/assembly/etc.), and backfill
 * field_certification_association from the tester's profile so existing reports
 * show "number · ABPA". Targets backflow_testing tasks that already have a
 * generated report_pdf. Dry-run unless BOS_APPLY=1.
 */
$APPLY = getenv('BOS_APPLY') === '1';
print $APPLY ? "=== APPLY ===\n" : "=== DRY RUN (BOS_APPLY=1) ===\n";
$etm = \Drupal::entityTypeManager();
$ids = $etm->getStorage('wo_tasks_list')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'backflow_testing')->exists('field_report_pdf')->execute();
print "reports to regenerate: " . count($ids) . "\n";
$n = 0;
foreach ($etm->getStorage('wo_tasks_list')->loadMultiple($ids) as $t) {
  // Backfill association from tester's profile (existing tests predate the field).
  if ($t->hasField('field_certification_association') && $t->get('field_certification_association')->isEmpty()
      && !$t->get('field_tester')->isEmpty()) {
    $profs = $etm->getStorage('profile')->loadByProperties(['uid' => $t->get('field_tester')->target_id, 'type' => 'teammate_profile']);
    if ($profs) {
      $p = reset($profs);
      if ($p->hasField('field_certification_association') && !$p->get('field_certification_association')->isEmpty()) {
        $t->set('field_certification_association', $p->get('field_certification_association')->value);
      }
    }
  }
  if (!$APPLY) { print "  would regen task {$t->id()} ({$t->label()})\n"; $n++; continue; }
  // Drop the old frozen PDF so the generator rebuilds it.
  if (!$t->get('field_report_pdf')->isEmpty() && ($old = $t->get('field_report_pdf')->entity)) {
    $t->set('field_report_pdf', NULL);
    $t->save();
    try { $old->delete(); } catch (\Exception $e) {}
  }
  else { $t->save(); }
  $t = $etm->getStorage('wo_tasks_list')->load($t->id());
  _wo_backflow_testing_generate_report_pdf($t);
  print "  regenerated task {$t->id()}\n";
  $n++;
}
print ($APPLY ? "regenerated" : "would regenerate") . ": $n\n";
