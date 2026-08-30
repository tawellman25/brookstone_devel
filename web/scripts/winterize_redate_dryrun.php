<?php

/**
 * @file
 * READ-ONLY dry-run: recompute 2026 winterizing dates under the CALENDAR-DATE
 * rule (same month/day as the customer's most-recent prior winterize, i.e. one
 * weekday later), and diff against the currently-scheduled (nth-weekday) dates.
 * Writes NOTHING. Shows Gerald Reeves in detail + overall stats + weekend cases.
 *
 * Weekend handling for the proposal: keep Saturday (crews work some Saturdays),
 * roll Sunday -> Monday. (Adjustable — this is a review artifact.)
 *
 * Run: ddev drush php:script web/scripts/winterize_redate_dryrun.php
 *   optional arg: a uid to detail (default 1443 = Gerald Reeves)
 */

$tz = new \DateTimeZone('America/Denver');
$db = \Drupal::database();
$detailUid = 1443;
foreach (($extra['args'] ?? []) as $a) { if (ctype_digit((string) $a)) { $detailUid = (int) $a; } }

$yearStart = fn(int $y) => (new \DateTime("$y-01-01 00:00:00", $tz))->getTimestamp();
$yearEnd   = fn(int $y) => (new \DateTime("$y-12-31 23:59:59", $tz))->getTimestamp();

// All 2026 winterizing scheduling rows.
$rows = $db->query("
  SELECT s.id AS sid, fd.field_date_value AS ts, swo.field_work_order_target_id AS wo_id,
         wop.field_property_target_id AS pid, nick.field_nickname_value AS nickname,
         sat.field_assigned_to_target_id AS uid
  FROM {scheduling_field_data} s
  JOIN {scheduling__field_date} fd ON fd.entity_id = s.id AND fd.deleted=0
    AND fd.field_date_value BETWEEN :a AND :b
  JOIN {scheduling__field_work_order} swo ON swo.entity_id = s.id AND swo.deleted=0
  JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
  LEFT JOIN {scheduling__field_assigned_to} sat ON sat.entity_id = s.id AND sat.deleted=0
  LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
  LEFT JOIN {properties__field_nickname} nick ON nick.entity_id = wop.field_property_target_id AND nick.deleted=0
", [':a' => $yearStart(2026), ':b' => $yearEnd(2026)])->fetchAll();

// Source date per property: latest winterize scheduling BEFORE 2026.
$sourceCache = [];
$sourceDate = function (int $pid) use (&$sourceCache, $db, $tz, $yearStart) {
  if (array_key_exists($pid, $sourceCache)) { return $sourceCache[$pid]; }
  $ts = $db->query("
    SELECT MAX(fd.field_date_value)
    FROM {scheduling__field_date} fd
    JOIN {scheduling__field_work_order} swo ON swo.entity_id = fd.entity_id AND swo.deleted=0
    JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
    LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
    WHERE wop.field_property_target_id = :pid AND fd.deleted=0 AND fd.field_date_value < :y2026
  ", [':pid' => $pid, ':y2026' => $yearStart(2026)])->fetchField();
  return $sourceCache[$pid] = $ts ? (int) $ts : NULL;
};

// Proposed 2026 date = source month/day in 2026, all-day; roll Sunday -> Monday.
$propose = function (int $srcTs) use ($tz): \DateTime {
  $src = (new \DateTime('@' . $srcTs))->setTimezone($tz);
  $d = new \DateTime($src->format('2026-m-d') . ' 00:00:00', $tz);
  if ($d->format('w') === '0') { $d->modify('+1 day'); } // Sun -> Mon
  return $d;
};

$total = 0; $changing = 0; $noSource = 0; $weekend = 0; $detail = [];
foreach ($rows as $r) {
  $total++;
  $cur = (new \DateTime('@' . (int) $r->ts))->setTimezone($tz);
  $srcTs = $sourceDate((int) $r->pid);
  if ($srcTs === NULL) { $noSource++; continue; }
  $new = $propose($srcTs);
  $srcD = (new \DateTime('@' . $srcTs))->setTimezone($tz);
  if ((new \DateTime($srcD->format('2026-m-d'), $tz))->format('w') === '0') { $weekend++; }
  $delta = (int) $cur->diff($new)->format('%r%a');
  if ($cur->format('Y-m-d') !== $new->format('Y-m-d')) { $changing++; }
  if ((int) $r->uid === $detailUid) {
    $detail[] = [
      'nick' => (string) $r->nickname,
      'src' => $srcD->format('Y-m-d D'),
      'cur' => $cur->format('Y-m-d D'),
      'new' => $new->format('Y-m-d D'),
      'delta' => $delta,
    ];
  }
}

print "=== DETAIL: uid $detailUid ===\n";
usort($detail, fn($a, $b) => strcmp($a['new'], $b['new']));
printf("%-34s %-16s %-16s %-16s %s\n", 'PROPERTY', 'PRIOR (source)', 'CURRENT (wrong)', 'PROPOSED', 'Δdays');
print str_repeat('-', 100) . "\n";
foreach ($detail as $d) {
  printf("%-34s %-16s %-16s %-16s %+d\n", mb_substr($d['nick'], 0, 32), $d['src'], $d['cur'], $d['new'], $d['delta']);
}

print "\n=== OVERALL (all techs) ===\n";
print "  2026 winterize scheduling records : $total\n";
print "  would change date                 : $changing\n";
print "  unchanged                         : " . ($total - $changing - $noSource) . "\n";
print "  no prior-year source (new cust.)  : $noSource (left as-is)\n";
print "  prior date whose 2026 M/D = Sunday: $weekend (rolled to Monday in proposal)\n";
print "\n(READ-ONLY — nothing was changed.)\n";
