<?php

/**
 * Normalize malformed date-only field_test_date values on backflow tests to a
 * proper datetime. field_test_date is a datetime (date+time) field, but some
 * tests were saved with a bare 'Y-m-d' value (no time) which the datetime_default
 * widget can't render — the form shows blank though the DB has a date. Reinterpret
 * the date as NOON site-local -> UTC storage so it reads back as the same date.
 * Idempotent (only touches values with no 'T'); dry-run unless BOS_APPLY=1.
 */
$APPLY = getenv('BOS_APPLY') === '1';
print $APPLY ? "=== APPLY ===\n" : "=== DRY RUN (BOS_APPLY=1 to write) ===\n";
$etm = \Drupal::entityTypeManager();
$tz = new \DateTimeZone(date_default_timezone_get());
$utc = new \DateTimeZone('UTC');
$ids = $etm->getStorage('wo_tasks_list')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'backflow_testing')->exists('field_test_date')->execute();
$n = 0;
foreach ($etm->getStorage('wo_tasks_list')->loadMultiple($ids) as $t) {
  $v = (string) $t->get('field_test_date')->value;
  if ($v === '' || strpos($v, 'T') !== FALSE) { continue; }         // already datetime
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { continue; }        // not a bare date
  $d = new \DateTime($v . ' 12:00:00', $tz); $d->setTimezone($utc);
  $new = $d->format('Y-m-d\TH:i:s');
  printf("  task %s: [%s] -> [%s] (reads %s 12:00 PM local)\n", $t->id(), $v, $new, $v);
  $n++;
  if ($APPLY) { $t->set('field_test_date', $new)->save(); }
}
print ($APPLY ? "fixed" : "would fix") . ": $n\n";
