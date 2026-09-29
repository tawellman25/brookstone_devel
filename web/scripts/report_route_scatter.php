<?php

declare(strict_types=1);

/**
 * Which days have routes that are all over the place — REPORT ONLY.
 *
 * ⚠ WRITES NOTHING. No entity is saved, no field is set. It reads the schedule
 * and the property coordinates and reports geography.
 *
 * Two different problems, reported separately because they have different fixes:
 *
 *   SCATTER — one route whose stops are far apart. Fix is Optimize in the Route
 *   Editor (reorder within the day), or moving a stray stop to a day that is
 *   already going that way.
 *
 *   OVERLAP — two routes on the same day covering the same ground with
 *   different crews. Fix is merging them, which needs a rig and a crew-day
 *   fewer. This is where the 141-routes-averaging-3-stops problem shows up as
 *   money.
 *
 * Distances are straight-line (haversine), not driving miles — good enough to
 * rank routes against each other, and it never pretends otherwise.
 *
 *   drush php:script web/scripts/report_route_scatter.php
 */

use Drupal\Core\Datetime\DrupalDateTime;

$db = \Drupal::database();
$tz = new \DateTimeZone(date_default_timezone_get());
$today = (new DrupalDateTime('today', $tz))->getTimestamp();
$end = (new DrupalDateTime('2026-12-31 23:59:59', $tz))->getTimestamp();

/** Straight-line miles between two lat/lon points. */
$miles = static function (array $a, array $b): float {
  $r = 3958.8;
  $dLat = deg2rad($b[0] - $a[0]);
  $dLon = deg2rad($b[1] - $a[1]);
  $h = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLon / 2) ** 2;
  return $r * 2 * asin(min(1.0, sqrt($h)));
};

// Schedule + property coordinates in one pass.
$rows = $db->query("SELECT d.field_date_value ts, a.field_assigned_to_target_id tech,
    o.field_scheduled_oder_value ord, p.field_property_target_id pid,
    g.field_geofield_lat lat, g.field_geofield_lon lon, pr.field_nickname_value nick
  FROM {scheduling__field_date} d
  JOIN {scheduling__field_work_order} s ON s.entity_id = d.entity_id
  JOIN {work_order_field_data} w ON w.id = s.field_work_order_target_id AND w.type = 'sprinkler_winterizing'
  LEFT JOIN {work_order__field_property} p ON p.entity_id = w.id
  LEFT JOIN {properties__field_geofield} g ON g.entity_id = p.field_property_target_id
  LEFT JOIN {properties__field_nickname} pr ON pr.entity_id = p.field_property_target_id
  LEFT JOIN {scheduling__field_assigned_to} a ON a.entity_id = d.entity_id
  LEFT JOIN {scheduling__field_scheduled_oder} o ON o.entity_id = d.entity_id
  WHERE d.field_date_value BETWEEN :a AND :b", [':a' => $today, ':b' => $end])->fetchAll();

$routes = [];
$noCoords = 0;
foreach ($rows as $r) {
  $day = DrupalDateTime::createFromTimestamp((int) $r->ts, $tz)->format('Y-m-d');
  $key = $day . '|' . ($r->tech ?: 'unassigned');
  if ($r->lat === NULL || $r->lon === NULL) {
    $noCoords++;
    $routes[$key]['blind'] = ($routes[$key]['blind'] ?? 0) + 1;
    continue;
  }
  $routes[$key]['stops'][] = [
    'ord' => $r->ord === NULL ? PHP_INT_MAX : (int) $r->ord,
    'pt' => [(float) $r->lat, (float) $r->lon],
    'nick' => (string) ($r->nick ?? ''),
  ];
}

// Per route: path length in current order, and the spread.
$report = [];
foreach ($routes as $key => $data) {
  $stops = $data['stops'] ?? [];
  if (count($stops) < 2) {
    continue;
  }
  usort($stops, fn($a, $b) => $a['ord'] <=> $b['ord']);
  $path = 0.0;
  for ($i = 1; $i < count($stops); $i++) {
    $path += $miles($stops[$i - 1]['pt'], $stops[$i]['pt']);
  }
  // Widest separation between any two stops = how spread the route is.
  $spread = 0.0;
  foreach ($stops as $i => $a) {
    foreach ($stops as $j => $b) {
      if ($j <= $i) { continue; }
      $spread = max($spread, $miles($a['pt'], $b['pt']));
    }
  }
  // Nearest-neighbour path from the first stop, as a floor to compare against.
  $todo = $stops; $cur = array_shift($todo); $nn = 0.0;
  while ($todo) {
    $best = 0; $bestD = INF;
    foreach ($todo as $k => $c) {
      $d = $miles($cur['pt'], $c['pt']);
      if ($d < $bestD) { $bestD = $d; $best = $k; }
    }
    $nn += $bestD; $cur = $todo[$best]; unset($todo[$best]); $todo = array_values($todo);
  }
  [$day, $tech] = explode('|', $key);
  $report[] = [
    'day' => $day, 'tech' => $tech, 'n' => count($stops),
    'path' => $path, 'nn' => $nn, 'spread' => $spread,
    'blind' => $data['blind'] ?? 0,
    'centroid' => [
      array_sum(array_column(array_column($stops, 'pt'), 0)) / count($stops),
      array_sum(array_column(array_column($stops, 'pt'), 1)) / count($stops),
    ],
  ];
}

printf("REPORT ONLY — nothing was written.\n");
printf("%d routes with 2+ located stops · %d stops have no coordinates\n\n", count($report), $noCoords);

// ---- scatter: worst routes by miles per stop -------------------------------
usort($report, fn($a, $b) => ($b['path'] / $b['n']) <=> ($a['path'] / $a['n']));
print "WORST SCATTER — miles travelled per stop, current order\n";
printf("  %-12s %-10s %-5s %-9s %-9s %-9s %s\n", 'DAY', 'TECH', 'STOP', 'PATH mi', 'BEST mi', 'SPREAD', 'note');
foreach (array_slice($report, 0, 12) as $r) {
  printf("  %-12s %-10s %-5d %-9.1f %-9.1f %-9.1f %s\n", $r['day'], $r['tech'], $r['n'],
    $r['path'], $r['nn'], $r['spread'],
    $r['path'] > $r['nn'] * 1.5 ? 'reorder would help' : '');
}

// ---- overlap: routes on the same day covering the same ground --------------
$byDay = [];
foreach ($report as $r) { $byDay[$r['day']][] = $r; }
ksort($byDay);
// ---- per-day verdict: how tight is the whole day, and how many crews does
// the work actually need at 8 stops a crew? ------------------------------------
print "\nPER-DAY VERDICT — how many crews the work needs vs how many are booked\n";
printf("  %-12s %-6s %-7s %-9s %-9s %s\n", 'DAY', 'STOPS', 'CREWS', 'AT 8/DAY', 'DIAMETER', 'verdict');
$saveable = 0;
foreach ($byDay as $day => $rs) {
  $stops = array_sum(array_column($rs, 'n'));
  $crews = count($rs);
  $need = (int) ceil($stops / 8);
  // Widest gap between any two route centroids = how spread the DAY is.
  $diam = 0.0;
  for ($i = 0; $i < count($rs); $i++) {
    for ($j = $i + 1; $j < count($rs); $j++) {
      $diam = max($diam, $miles($rs[$i]['centroid'], $rs[$j]['centroid']));
    }
  }
  $spare = $crews - $need;
  if ($spare > 0 && $diam <= 10) { $saveable += $spare; }
  printf("  %-12s %-6d %-7d %-9d %-9.1f %s\n", $day, $stops, $crews, $need, $diam,
    $spare > 0 ? ($diam <= 10
      ? sprintf('%d crew-day%s spare, day is only %.0f mi wide', $spare, $spare === 1 ? '' : 's', $diam)
      : sprintf('%d spare but %.0f mi wide — check before merging', $spare, $diam))
      : 'at capacity');
}
printf("\n  crew-days that look genuinely spare on tight days: %d\n", $saveable);

print "\nOVERLAPPING ROUTES — same day, centroids within 6 miles (merge candidates)\n";
$found = 0;
foreach ($byDay as $day => $rs) {
  for ($i = 0; $i < count($rs); $i++) {
    for ($j = $i + 1; $j < count($rs); $j++) {
      $d = $miles($rs[$i]['centroid'], $rs[$j]['centroid']);
      if ($d > 6) { continue; }
      $found++;
      printf("  %-12s %-10s (%d stops) + %-10s (%d stops) — centroids %.1f mi apart → %d stops for one crew\n",
        $day, $rs[$i]['tech'], $rs[$i]['n'], $rs[$j]['tech'], $rs[$j]['n'], $d, $rs[$i]['n'] + $rs[$j]['n']);
    }
  }
}
if (!$found) { print "  none\n"; }
