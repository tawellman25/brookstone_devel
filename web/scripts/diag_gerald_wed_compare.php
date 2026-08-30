<?php

/**
 * @file
 * READ-ONLY: side-by-side of Gerald Reeves' (uid 1443) winterizing stops on the
 * 1st Wednesday of Oct 2025 (10-01) vs the 1st Wednesday of Oct 2026 (10-07),
 * by property, so we can confirm the SAME customers carried to the new date.
 * Writes NOTHING.
 */

$tz = new \DateTimeZone('America/Denver');
$db = \Drupal::database();
$uid = 1443;

$listFor = function (string $ymd) use ($db, $tz, $uid): array {
  $start = (new \DateTime($ymd . ' 00:00:00', $tz))->getTimestamp();
  $end   = (new \DateTime($ymd . ' 23:59:59', $tz))->getTimestamp();
  $rows = $db->query("
    SELECT swo.field_work_order_target_id AS wo_id,
           wop.field_property_target_id AS pid,
           nick.field_nickname_value AS nickname,
           sord.field_scheduled_oder_value AS ord
    FROM {scheduling_field_data} s
    JOIN {scheduling__field_assigned_to} sat ON sat.entity_id = s.id AND sat.deleted=0 AND sat.field_assigned_to_target_id = :uid
    JOIN {scheduling__field_date} fd ON fd.entity_id = s.id AND fd.deleted=0 AND fd.field_date_value BETWEEN :a AND :b
    JOIN {scheduling__field_work_order} swo ON swo.entity_id = s.id AND swo.deleted=0
    JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
    LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
    LEFT JOIN {properties__field_nickname} nick ON nick.entity_id = wop.field_property_target_id AND nick.deleted=0
    LEFT JOIN {scheduling__field_scheduled_oder} sord ON sord.entity_id = s.id AND sord.deleted=0
    ORDER BY sord.field_scheduled_oder_value ASC
  ", [':uid' => $uid, ':a' => $start, ':b' => $end])->fetchAll();
  $out = [];
  foreach ($rows as $r) {
    $out[(int) $r->pid] = ['nickname' => (string) $r->nickname, 'wo' => (int) $r->wo_id, 'ord' => (int) $r->ord];
  }
  return $out;
};

$a = $listFor('2025-10-01'); // 1st Wed 2025
$b = $listFor('2026-10-07'); // 1st Wed 2026

print "Gerald Reeves — 1st Wednesday winterizing route\n";
print "  2025-10-01: " . count($a) . " stops   |   2026-10-07: " . count($b) . " stops\n\n";

$allPids = array_unique(array_merge(array_keys($a), array_keys($b)));
sort($allPids);
printf("%-40s  %-10s  %-10s\n", 'PROPERTY (pid)', '2025-10-01', '2026-10-07');
print str_repeat('-', 64) . "\n";
foreach ($allPids as $pid) {
  $name = $a[$pid]['nickname'] ?? $b[$pid]['nickname'] ?? '(unknown)';
  $in25 = isset($a[$pid]) ? ('WO ' . $a[$pid]['wo']) : '—';
  $in26 = isset($b[$pid]) ? ('WO ' . $b[$pid]['wo']) : '—';
  $flag = (isset($a[$pid]) && !isset($b[$pid])) ? '  <- not carried (rebook/new-status?)'
        : ((!isset($a[$pid]) && isset($b[$pid])) ? '  <- new on this date' : '');
  printf("%-40s  %-10s  %-10s%s\n", mb_substr($name, 0, 38) . " ($pid)", $in25, $in26, $flag);
}

$carried = count(array_intersect(array_keys($a), array_keys($b)));
print "\nSAME property on both dates: $carried\n";
