<?php

/**
 * @file
 * Re-date 2026 sprinkler_winterizing scheduling to the CALENDAR-DATE rule:
 * each stop takes the same month/day as the customer's most-recent prior
 * winterize (so it lands one weekday later than last year), with weekend
 * handling = Sat & Sun roll forward to the next Monday (Mon–Fri only).
 *
 * Only field_date is written (all-day smartdate, matching ScheduleWriter), plus
 * field_notify_assigned_teammate = FALSE so no assignment emails fire. Crew
 * assignment and stop order are left untouched. Records with no prior-year
 * source (new customers) are skipped. wo_schedule logs a Rescheduled note.
 *
 * SAFE BY DEFAULT: dry-run unless env WINTERIZE_REDATE_APPLY=1.
 * Run: WINTERIZE_REDATE_APPLY=1 drush php:script web/scripts/winterize_redate_apply.php
 */

$apply = getenv('WINTERIZE_REDATE_APPLY') === '1';
$tz = new \DateTimeZone('America/Denver');
$db = \Drupal::database();
$storage = \Drupal::entityTypeManager()->getStorage('scheduling');

$yStart = fn(int $y) => (new \DateTime("$y-01-01 00:00:00", $tz))->getTimestamp();
$yEnd   = fn(int $y) => (new \DateTime("$y-12-31 23:59:59", $tz))->getTimestamp();

$rows = $db->query("
  SELECT s.id AS sid, fd.field_date_value AS ts, swo.field_work_order_target_id AS wo_id,
         wop.field_property_target_id AS pid
  FROM {scheduling_field_data} s
  JOIN {scheduling__field_date} fd ON fd.entity_id = s.id AND fd.deleted=0
    AND fd.field_date_value BETWEEN :a AND :b
  JOIN {scheduling__field_work_order} swo ON swo.entity_id = s.id AND swo.deleted=0
  JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
  LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
", [':a' => $yStart(2026), ':b' => $yEnd(2026)])->fetchAll();

$srcCache = [];
$sourceTs = function (int $pid) use (&$srcCache, $db, $yStart) {
  if (array_key_exists($pid, $srcCache)) { return $srcCache[$pid]; }
  $ts = $db->query("
    SELECT MAX(fd.field_date_value)
    FROM {scheduling__field_date} fd
    JOIN {scheduling__field_work_order} swo ON swo.entity_id = fd.entity_id AND swo.deleted=0
    JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
    LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
    WHERE wop.field_property_target_id = :pid AND fd.deleted=0 AND fd.field_date_value < :y
  ", [':pid' => $pid, ':y' => $yStart(2026)])->fetchField();
  return $srcCache[$pid] = $ts ? (int) $ts : NULL;
};

// Same M/D in 2026; Sat(6)/Sun(0) -> next Monday.
$propose = function (int $srcTs) use ($tz): \DateTime {
  $s = (new \DateTime('@' . $srcTs))->setTimezone($tz);
  $d = new \DateTime($s->format('2026-m-d') . ' 00:00:00', $tz);
  $w = (int) $d->format('w');
  if ($w === 6) { $d->modify('+2 days'); }
  elseif ($w === 0) { $d->modify('+1 day'); }
  return $d;
};

$changed = 0; $already = 0; $nosrc = 0; $errs = []; $dist = [];
foreach ($rows as $r) {
  $srcTs = $sourceTs((int) $r->pid);
  if ($srcTs === NULL) { $nosrc++; continue; }
  $new = $propose($srcTs);
  $cur = (new \DateTime('@' . (int) $r->ts))->setTimezone($tz);
  $dist[$new->format('D')] = ($dist[$new->format('D')] ?? 0) + 1;
  if ($cur->format('Y-m-d') === $new->format('Y-m-d')) { $already++; continue; }
  if (!$apply) { $changed++; continue; }
  try {
    $e = $storage->load($r->sid);
    if (!$e || $e->bundle() !== 'work_order') { $already++; continue; }
    $startTs = $new->getTimestamp();
    $e->set('field_date', ['value' => $startTs, 'end_value' => $startTs + 86340, 'duration' => 1439]);
    if ($e->hasField('field_notify_assigned_teammate')) { $e->set('field_notify_assigned_teammate', FALSE); }
    $e->save();
    $changed++;
  }
  catch (\Throwable $ex) {
    $errs[] = "#{$r->sid}: " . $ex->getMessage();
  }
}

print ($apply ? "*** APPLIED ***" : "--- DRY-RUN (set WINTERIZE_REDATE_APPLY=1 to write) ---") . "\n";
print "  total 2026 winterize records : " . count($rows) . "\n";
print "  " . ($apply ? 're-dated' : 'would re-date') . "            : $changed\n";
print "  already correct              : $already\n";
print "  no prior-year source (skip)  : $nosrc\n";
print "  errors                       : " . count($errs) . "\n";
foreach ($errs as $x) { print "    ERR $x\n"; }
ksort($dist);
print "  proposed weekday spread      : " . json_encode($dist) . "\n";
