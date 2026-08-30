<?php

/**
 * @file
 * READ-ONLY diagnostic: list Gerald's sprinkler_winterizing scheduled stops
 * with tz-correct dates + weekday + which nth-weekday-of-month, so we can tell
 * whether the 2026 carry-forward dates are a bug or the intended nth-weekday
 * mapping. Writes NOTHING.
 *
 * Run: ddev drush php:script web/scripts/diag_gerald_winterize_dates.php
 *   (or scp to live and run with the alt-php drush invocation).
 */

$tz = new \DateTimeZone('America/Denver');
$db = \Drupal::database();

// 1) Find Gerald(s).
$uids = $db->query("
  SELECT DISTINCT u.uid, u.name,
    TRIM(CONCAT(COALESCE(fn.field_first_name_value,''),' ',COALESCE(ln.field_last_name_value,''))) AS full
  FROM {users_field_data} u
  LEFT JOIN {profile} p ON p.uid = u.uid AND p.type='teammate_profile'
  LEFT JOIN {profile__field_first_name} fn ON fn.entity_id = p.profile_id AND fn.deleted=0
  LEFT JOIN {profile__field_last_name} ln ON ln.entity_id = p.profile_id AND ln.deleted=0
  WHERE u.name LIKE '%Gerald%'
     OR fn.field_first_name_value LIKE '%Gerald%'
     OR ln.field_last_name_value LIKE '%Gerald%'
")->fetchAll();
if (!$uids) { print "No user matching 'Gerald' found.\n"; return; }
foreach ($uids as $u) {
  print "MATCH uid={$u->uid}  name='{$u->name}'  full='{$u->full}'\n";
}
$targetUids = array_map(fn($u) => (int) $u->uid, $uids);

// nth-weekday-of-month helper.
$nthOf = function (\DateTime $d): string {
  $dow = $d->format('D');
  $nth = intdiv((int) $d->format('j') - 1, 7) + 1;
  return "{$nth}th {$dow}"; // e.g. "1th Wed" (crude ordinal, readable enough)
};

// 2) Gerald's winterizing scheduling rows (any year), with date + order.
foreach ($targetUids as $uid) {
  $rows = $db->query("
    SELECT s.id AS sid, fd.field_date_value AS ts, sord.field_scheduled_oder_value AS ord,
           swo.field_work_order_target_id AS wo_id, nick.field_nickname_value AS nickname,
           s.changed AS changed
    FROM {scheduling_field_data} s
    JOIN {scheduling__field_assigned_to} sat ON sat.entity_id = s.id AND sat.deleted=0
    JOIN {scheduling__field_date} fd ON fd.entity_id = s.id AND fd.deleted=0
    JOIN {scheduling__field_work_order} swo ON swo.entity_id = s.id AND swo.deleted=0
    JOIN {work_order_field_data} wo ON wo.id = swo.field_work_order_target_id AND wo.type='sprinkler_winterizing'
    LEFT JOIN {scheduling__field_scheduled_oder} sord ON sord.entity_id = s.id AND sord.deleted=0
    LEFT JOIN {work_order__field_property} wop ON wop.entity_id = swo.field_work_order_target_id AND wop.deleted=0
    LEFT JOIN {properties__field_nickname} nick ON nick.entity_id = wop.field_property_target_id AND nick.deleted=0
    WHERE sat.field_assigned_to_target_id = :uid
    ORDER BY fd.field_date_value ASC
  ", [':uid' => $uid])->fetchAll();

  print "\n=== uid $uid — " . count($rows) . " winterizing stops ===\n";
  $byDate = [];
  foreach ($rows as $r) {
    $d = (new \DateTime('@' . (int) $r->ts))->setTimezone($tz);
    $key = $d->format('Y-m-d');
    $byDate[$key] = ($byDate[$key] ?? 0) + 1;
  }
  ksort($byDate);
  foreach ($byDate as $day => $cnt) {
    $d = new \DateTime($day, $tz);
    print sprintf("  %s  %-3s  (%s of month)  x%d stops\n", $day, $d->format('D'), $nthOf($d), $cnt);
  }
}

// 3) Sanity anchor: 1st Wednesday of Oct 2025 vs 2026.
$firstWed = function (int $year) use ($tz): string {
  $d = new \DateTime("$year-10-01", $tz);
  while ($d->format('D') !== 'Wed') { $d->modify('+1 day'); }
  return $d->format('Y-m-d');
};
print "\nANCHOR: 1st Wednesday of Oct 2025 = " . $firstWed(2025) . " ; of Oct 2026 = " . $firstWed(2026) . "\n";
print "(a 10-01-2025 → 10-07-2026 shift is the correct nth-weekday mapping)\n";
