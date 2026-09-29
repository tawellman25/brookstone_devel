<?php

declare(strict_types=1);

/**
 * What a flattened winterizing schedule would cost — REPORT ONLY.
 *
 * ⚠ THIS SCRIPT WRITES NOTHING. No entity is saved, no field is set. It loads
 * the current schedule, simulates flattening it to a crew cap and a
 * stops-per-day target, and reports how many customers would have to move and
 * how far. Run it as often as you like at whatever targets you like.
 *
 * The question it answers is not "can we fit the work" — at 8 stops a day the
 * 580 remaining need 18 rig-days against 39 available. It is "what does the
 * compression cost in phone calls", which is the number that decides whether to
 * do it.
 *
 * Method: walk the season in date order. A day within cap keeps its stops. A day
 * over cap sheds whole TECHNICIAN ROUTES, not individual stops, because a route
 * is a geographic cluster and splitting one costs drive time — the very thing
 * that makes 8 a day possible. Shed routes land on the nearest following day
 * with room. Weekdays only unless told otherwise.
 *
 *   drush php:script web/scripts/plan_winterize_rebalance.php
 *   BOS_TARGET=6 drush php:script web/scripts/plan_winterize_rebalance.php
 *   BOS_TARGET=8 BOS_CREWS=4 BOS_WEEKENDS=1 drush php:script …
 */

use Drupal\Core\Datetime\DrupalDateTime;

$target = (int) (getenv('BOS_TARGET') ?: 8);
$crews = (int) (getenv('BOS_CREWS') ?: 4);
$weekends = getenv('BOS_WEEKENDS') === '1';
$capacity = $target * $crews;

$db = \Drupal::database();
$tz = new \DateTimeZone(date_default_timezone_get());
$today = (new DrupalDateTime('today', $tz))->getTimestamp();
$end = (new DrupalDateTime('2026-12-31 23:59:59', $tz))->getTimestamp();

$rows = $db->query("SELECT d.entity_id sid, d.field_date_value ts, a.field_assigned_to_target_id tech
  FROM {scheduling__field_date} d
  JOIN {scheduling__field_work_order} s ON s.entity_id = d.entity_id
  JOIN {work_order_field_data} w ON w.id = s.field_work_order_target_id AND w.type = 'sprinkler_winterizing'
  LEFT JOIN {scheduling__field_assigned_to} a ON a.entity_id = d.entity_id
  WHERE d.field_date_value BETWEEN :a AND :b", [':a' => $today, ':b' => $end])->fetchAll();

// Group into routes: one (day, tech) block, which moves as a unit.
$routes = [];
foreach ($rows as $r) {
  $day = DrupalDateTime::createFromTimestamp((int) $r->ts, $tz)->format('Y-m-d');
  $routes[$day][$r->tech ?: 'unassigned'][] = (int) $r->sid;
}
ksort($routes);

$isWorkday = static function (string $day) use ($weekends): bool {
  $dow = (int) (new DateTime($day))->format('N');
  return $weekends ? TRUE : $dow <= 5;
};

// Simulate.
$load = [];
$moves = [];
$totalStops = 0;
foreach ($routes as $day => $byTech) {
  // Largest routes first, so the ones that keep their day are the big clusters.
  uasort($byTech, fn($a, $b) => count($b) <=> count($a));
  foreach ($byTech as $tech => $sids) {
    $size = count($sids);
    $totalStops += $size;
    $place = $day;
    // Walk forward to the first workday with room for this whole route.
    $guard = 0;
    while ($guard++ < 120) {
      $crewsUsed = count($load[$place]['routes'] ?? []);
      $stopsUsed = $load[$place]['stops'] ?? 0;
      if ($isWorkday($place) && $crewsUsed < $crews && ($stopsUsed + $size) <= $capacity) {
        break;
      }
      $place = (new DateTime($place . ' +1 day'))->format('Y-m-d');
    }
    $load[$place]['stops'] = ($load[$place]['stops'] ?? 0) + $size;
    $load[$place]['routes'][] = $tech;
    if ($place !== $day) {
      $moves[] = ['from' => $day, 'to' => $place, 'stops' => $size, 'tech' => $tech,
        'days' => (int) (new DateTime($day))->diff(new DateTime($place))->days];
    }
  }
}
ksort($load);

$movedStops = array_sum(array_column($moves, 'stops'));
$dists = array_column($moves, 'days');
sort($dists);

printf("REPORT ONLY — nothing was written.\n\n");
printf("target %d stops/crew/day · %d crews · cap %d stops/day · weekends %s\n\n",
  $target, $crews, $capacity, $weekends ? 'ON' : 'off');
printf("  stops in the season:      %d\n", $totalStops);
printf("  routes (crew-days):       %d\n", count($moves) + count(array_filter($load, fn($l) => TRUE)) );
printf("  days used:                %d   (%s -> %s)\n", count($load), array_key_first($load), array_key_last($load));
printf("  CUSTOMERS WHO MOVE:       %d of %d  (%.0f%%)\n", $movedStops, $totalStops, 100 * $movedStops / max($totalStops, 1));
if ($dists) {
  printf("  how far they move:        median %d days, max %d days\n", $dists[intdiv(count($dists), 2)], end($dists));
}
printf("\n  resulting load per day:\n");
foreach ($load as $day => $l) {
  printf("    %s %-4s %3d stops  %d crew%s%s\n", $day, (new DateTime($day))->format('D'),
    $l['stops'], count($l['routes']), count($l['routes']) === 1 ? ' ' : 's',
    $l['stops'] > $capacity ? '  << still over' : '');
}
