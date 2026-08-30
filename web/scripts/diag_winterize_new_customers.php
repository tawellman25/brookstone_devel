<?php

/**
 * @file
 * READ-ONLY: the 2026 winterizing stops with NO prior-year winterize (new
 * customers — the 16 the re-date left as-is), showing date, crew, and whether
 * the property has usable map coordinates. Also reports geo coverage across ALL
 * 2026 winterize stops. Writes nothing.
 */

$tz = new \DateTimeZone('America/Denver');
$db = \Drupal::database();
$yStart = fn(int $y) => (new \DateTime("$y-01-01 00:00:00", $tz))->getTimestamp();
$yEnd   = fn(int $y) => (new \DateTime("$y-12-31 23:59:59", $tz))->getTimestamp();

$rows = $db->query("
  SELECT s.id AS sid, fd.field_date_value AS ts, swo.field_work_order_target_id AS wo_id,
         wop.field_property_target_id AS pid, nick.field_nickname_value AS nickname,
         geo.field_geofield_value AS geofield, sat.field_assigned_to_target_id AS uid
  FROM {scheduling_field_data} s
  JOIN {scheduling__field_date} fd ON fd.entity_id = s.id AND fd.deleted=0
    AND fd.field_date_value BETWEEN :a AND :b
  JOIN {scheduling__field_work_order} swo ON swo.entity_id = s.id AND swo.deleted=0
  JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
  LEFT JOIN {scheduling__field_assigned_to} sat ON sat.entity_id = s.id AND sat.deleted=0
  LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
  LEFT JOIN {properties__field_nickname} nick ON nick.entity_id = wop.field_property_target_id AND nick.deleted=0
  LEFT JOIN {properties__field_geofield} geo ON geo.entity_id = wop.field_property_target_id AND geo.deleted=0
", [':a' => $yStart(2026), ':b' => $yEnd(2026)])->fetchAll();

$hasPrior = function (int $pid) use ($db, $yStart) {
  return (bool) $db->query("
    SELECT 1 FROM {scheduling__field_date} fd
    JOIN {scheduling__field_work_order} swo ON swo.entity_id = fd.entity_id AND swo.deleted=0
    JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
    LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
    WHERE wop.field_property_target_id = :pid AND fd.deleted=0 AND fd.field_date_value < :y LIMIT 1
  ", [':pid' => (int) $pid, ':y' => $yStart(2026)])->fetchField();
};
$userName = function (?int $uid) {
  if (!$uid) { return 'Unassigned'; }
  $u = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
  return $u ? $u->getDisplayName() : "uid $uid";
};

$new = [];
$geoMissingAll = 0;
foreach ($rows as $r) {
  $hasGeo = trim((string) ($r->geofield ?? '')) !== '';
  if (!$hasGeo) { $geoMissingAll++; }
  if (!$hasPrior((int) $r->pid)) {
    $new[] = $r;
  }
}

print "=== NEW CUSTOMERS (no prior-year winterize) — the re-date left these as-is ===\n";
printf("%-34s %-8s %-14s %-20s %s\n", 'PROPERTY', 'PID', 'SCHEDULED', 'CREW', 'MAP PIN');
print str_repeat('-', 92) . "\n";
usort($new, fn($a, $b) => ((int) $a->ts) <=> ((int) $b->ts));
foreach ($new as $r) {
  $d = (new \DateTime('@' . (int) $r->ts))->setTimezone($tz);
  $hasGeo = trim((string) ($r->geofield ?? '')) !== '';
  printf("%-34s %-8s %-14s %-20s %s\n",
    mb_substr(trim((string) ($r->nickname ?? '')) ?: '(no nickname)', 0, 32),
    (int) $r->pid,
    $d->format('D m/d/Y'),
    mb_substr($userName($r->uid ? (int) $r->uid : NULL), 0, 18),
    $hasGeo ? 'yes' : '*** NO PIN ***');
}

print "\n=== TOTALS ===\n";
print "  2026 winterize stops           : " . count($rows) . "\n";
print "  new customers (no prior)       : " . count($new) . "\n";
print "  stops missing a map pin (all)  : $geoMissingAll\n";
