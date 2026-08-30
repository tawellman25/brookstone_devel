<?php

/**
 * @file
 * READ-ONLY: for winterize WOs, show distribution of WO created-month vs
 * scheduled-month, and list the ones scheduled Jan–Mar (off-season catch-ups),
 * with their WO created date — to decide whether "created before Apr 1" vs
 * "scheduled before Apr 1" is the right filter. Writes nothing.
 */

$tz = new \DateTimeZone('America/Denver');
$db = \Drupal::database();

$rows = $db->query("
  SELECT wo.id AS wo_id, wo.created AS wo_created, fd.field_date_value AS sched_ts,
         nick.field_nickname_value AS nickname
  FROM {work_order_field_data} wo
  JOIN {scheduling__field_work_order} swo ON swo.field_work_order_target_id = wo.id AND swo.deleted=0
  JOIN {scheduling__field_date} fd ON fd.entity_id = swo.entity_id AND fd.deleted=0
  LEFT JOIN {work_order__field_property} wop ON wop.entity_id = wo.id AND wop.deleted=0
  LEFT JOIN {properties__field_nickname} nick ON nick.entity_id = wop.field_property_target_id AND nick.deleted=0
  WHERE wo.type = 'sprinkler_winterizing'
")->fetchAll();

$byCreatedMonth = [];
$bySchedMonth = [];
$janMar = [];
foreach ($rows as $r) {
  $cm = (new \DateTime('@' . (int) $r->wo_created))->setTimezone($tz)->format('m');
  $sd = (new \DateTime('@' . (int) $r->sched_ts))->setTimezone($tz);
  $sm = $sd->format('m');
  $byCreatedMonth[$cm] = ($byCreatedMonth[$cm] ?? 0) + 1;
  $bySchedMonth[$sm] = ($bySchedMonth[$sm] ?? 0) + 1;
  if (in_array($sm, ['01', '02', '03'], TRUE)) {
    $janMar[] = $r;
  }
}
ksort($byCreatedMonth);
ksort($bySchedMonth);

print "WO created-month (all winterize, all years):\n";
foreach ($byCreatedMonth as $m => $n) { print "  month $m: $n\n"; }
print "\nScheduled-month (field_date, all years):\n";
foreach ($bySchedMonth as $m => $n) { print "  month $m: $n\n"; }

print "\nWinterize stops SCHEDULED in Jan–Mar (off-season catch-ups): " . count($janMar) . "\n";
usort($janMar, fn($a, $b) => ((int) $a->sched_ts) <=> ((int) $b->sched_ts));
printf("  %-32s %-14s %-14s\n", 'PROPERTY', 'SCHEDULED', 'WO CREATED');
foreach ($janMar as $r) {
  printf("  %-32s %-14s %-14s\n",
    mb_substr(trim((string) ($r->nickname ?? '')) ?: '(none)', 0, 30),
    (new \DateTime('@' . (int) $r->sched_ts))->setTimezone($tz)->format('m/d/Y'),
    (new \DateTime('@' . (int) $r->wo_created))->setTimezone($tz)->format('m/d/Y'));
}
