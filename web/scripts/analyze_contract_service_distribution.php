<?php

/**
 * @file
 * READ-ONLY analysis: services per residential contract distribution.
 *
 * Service attachment is read from the 26 SERVICE SLOT FIELDS on the residential
 * contract (authoritative — anchored to the contract), NOT the field_contract
 * back-ref (14k+ sections lack it). A "service" = a slotted section with
 * field_do_you_want IN (1 Yes, 4 Accepted). Season = contracts.field_contract_year.
 * Winterizing = the field_irrigation_shut_down slot. Writes NOTHING to the DB.
 *
 * Run on live: drush php:script web/scripts/analyze_contract_service_distribution.php
 */

use Drupal\Core\Database\Database;

$db = Database::getConnection();
$CSV = getenv('BOS_CSV_OUT') ?: (getenv('HOME') . '/tmp/contract_service_distribution_2026.csv');
@mkdir(dirname($CSV), 0775, TRUE);

// 26 service slot fields on contracts.residential (table = contracts__<field>).
$SLOTS = [
  'field_aerating_of_lawn', 'field_aspen_twig_gall_control', 'field_christmas_decorations',
  'field_cooley_spruce_gall_treatme', 'field_deciduous_bore_treatment', 'field_deer_protection_wire_for_t',
  'field_dethatching_of_lawn_areas', 'field_dormant_oil_spray', 'field_fall_cleanup',
  'field_fertilizing_trees_shrubs', 'field_grub_prevention_on_lawn', 'field_ips_beetle_on_pinion_pine',
  'field_irrigation_check_ups', 'field_irrigation_shut_down', 'field_irrigation_start_up',
  'field_lawn_fertilizing_broadleaf', 'field_lawn_mowing_and_trimming', 'field_misc_services',
  'field_pre_emergent', 'field_spring_cleanup', 'field_summer_hedge_shrub_pruning',
  'field_trunk_bore_prevention', 'field_weed_spraying_of_landscape', 'field_weed_spraying_of_misc_area',
  'field_weeds_in_shrubs_removal', 'field_winter_pruning',
];
$WINTER_SLOT = 'field_irrigation_shut_down';
// The 6 tree/shrub spray slots.
$TREE_SLOTS = array_flip([
  'field_cooley_spruce_gall_treatme', 'field_dormant_oil_spray', 'field_aspen_twig_gall_control',
  'field_deciduous_bore_treatment', 'field_ips_beetle_on_pinion_pine', 'field_trunk_bore_prevention',
]);

// ---- Residential contracts by year ----
$contracts = [];
foreach ($db->query("SELECT c.id, y.field_contract_year_value yr, p.field_property_target_id pid
  FROM {contracts_field_data} c
  LEFT JOIN {contracts__field_contract_year} y ON y.entity_id = c.id
  LEFT JOIN {contracts__field_property} p ON p.entity_id = c.id
  WHERE c.type='residential' AND y.field_contract_year_value IN (2024,2025,2026)")->fetchAll() as $r) {
  $contracts[(int) $r->id] = ['year' => (int) $r->yr, 'pid' => (int) $r->pid, 'slots' => [], 'sec2slot' => []];
}

// ---- Read each slot: contract -> section id ----
$secIds = [];
foreach ($SLOTS as $slot) {
  $tbl = 'contracts__' . $slot;
  $col = $slot . '_target_id';
  try {
    $rows = $db->query("SELECT entity_id cid, {$col} sid FROM {{$tbl}}")->fetchAll();
  }
  catch (\Exception $e) { print "  (skip missing table $tbl)\n"; continue; }
  foreach ($rows as $r) {
    $cid = (int) $r->cid;
    if (!isset($contracts[$cid]) || !$r->sid) { continue; }
    $sid = (int) $r->sid;
    $contracts[$cid]['sec2slot'][$sid] = $slot;
    $secIds[$sid] = TRUE;
  }
}

// ---- Batch-load section attributes (do_you_want, service name, estimate) ----
$secAttr = [];
foreach (array_chunk(array_keys($secIds), 1000) as $chunk) {
  $rows = $db->query("SELECT cs.id, dw.field_do_you_want_value dw, t.name sname, es.field_estimate_value est
    FROM {contract_sections_field_data} cs
    LEFT JOIN {contract_sections__field_do_you_want} dw ON dw.entity_id = cs.id
    LEFT JOIN {contract_sections__field_service} sv ON sv.entity_id = cs.id
    LEFT JOIN {taxonomy_term_field_data} t ON t.tid = sv.field_service_target_id
    LEFT JOIN {contract_sections__field_estimate} es ON es.entity_id = cs.id
    WHERE cs.id IN (:ids[])", [':ids[]' => $chunk])->fetchAll();
  foreach ($rows as $r) {
    $secAttr[(int) $r->id] = ['dw' => $r->dw === NULL ? NULL : (int) $r->dw, 'sname' => $r->sname, 'est' => $r->est];
  }
}

// ---- Per-contract metrics ----
$data = [];
foreach ($contracts as $cid => $c) {
  $count = 0; $tree = 0; $winter = FALSE; $names = []; $est = 0.0; $estRows = 0;
  foreach ($c['sec2slot'] as $sid => $slot) {
    $a = $secAttr[$sid] ?? NULL;
    if (!$a || !in_array($a['dw'], [1, 4], TRUE)) { continue; } // selected only
    $count++;
    $names[] = $a['sname'] ?: $slot;
    if (isset($TREE_SLOTS[$slot])) { $tree++; }
    if ($slot === $WINTER_SLOT) { $winter = TRUE; }
    if ($a['est'] !== NULL && $a['est'] !== '') { $est += (float) $a['est']; $estRows++; }
  }
  $collapsed = $count - $tree + ($tree > 0 ? 1 : 0);
  $data[$cid] = compact('count', 'tree', 'winter', 'names', 'est', 'estRows') + ['year' => $c['year'], 'pid' => $c['pid'], 'collapsed' => $collapsed];
}

$median = function (array $v) { if (!$v) { return 0; } sort($v); $n = count($v); $m = intdiv($n, 2); return $n % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2; };
$byYear = fn($yr) => array_filter($data, fn($d) => $d['year'] === $yr);

// ==== 3a. Histogram ====
foreach ([2026, 2025] as $yr) {
  $set = $byYear($yr); $counts = array_map(fn($d) => $d['count'], $set);
  $hist = array_fill(1, 11, 0); $zero = 0;
  foreach ($counts as $c) { if ($c === 0) { $zero++; } elseif ($c >= 11) { $hist[11]++; } else { $hist[$c]++; } }
  $total = count($set);
  print "\n=== 3a. Service-count histogram — $yr (n=$total residential contracts) [SLOT-based] ===\n";
  printf("%-14s %8s %7s %8s\n", 'Services', 'Contracts', '%', 'Cumul%'); $cum = 0;
  $p0 = $total ? 100 * $zero / $total : 0;
  printf("%-14s %8d %6.1f%% %7.1f%%\n", '0 (none Yes)', $zero, $p0, $cum += $p0);
  for ($i = 1; $i <= 11; $i++) { $label = $i === 11 ? '11+' : (string) $i; $pct = $total ? 100 * $hist[$i] / $total : 0; printf("%-14s %8d %6.1f%% %7.1f%%\n", $label, $hist[$i], $pct, $cum += $pct); }
  $nz = array_filter($counts, fn($c) => $c > 0);
  printf("median(all): %.1f | mean(all): %.2f | median(>0): %.1f | mean(>0): %.2f\n",
    $median($counts), $counts ? array_sum($counts) / count($counts) : 0, $median(array_values($nz)), $nz ? array_sum($nz) / count($nz) : 0);
}

// ==== 3b. Threshold decision table (2026 winterizing) ====
$win26 = array_filter($byYear(2026), fn($d) => $d['winter']); $nWin = count($win26);
print "\n=== 3b. Threshold decision table — 2026 contracts INCLUDING winterizing (n=$nWin) ===\n";
printf("%-14s %10s %8s %14s %18s\n", 'Threshold', 'Qualify', '%', 'NOT qualify', 'Cost @ $5/contract');
foreach ([3, 4, 5, 6] as $th) {
  $q = count(array_filter($win26, fn($d) => $d['count'] >= $th));
  printf("%-14s %10d %6.1f%% %14d %17s\n", "{$th}+ services", $q, $nWin ? 100 * $q / $nWin : 0, $nWin - $q, '$' . number_format($q * 5));
}

// ==== 3c. Tree-collapse ====
$flip = array_filter($win26, fn($d) => $d['count'] >= 4 && $d['collapsed'] < 4);
print "\n=== 3c. Reach 4+ raw but < 4 when the 6 tree treatments collapse to one — 2026 winterizing (n=" . count($flip) . ") ===\n";
printf("%-9s %-9s %-5s %-10s %s\n", 'contract', 'property', 'raw', 'collapsed', 'selected services');
foreach ($flip as $cid => $d) { printf("%-9d %-9d %-5d %-10d %s\n", $cid, $d['pid'], $d['count'], $d['collapsed'], implode(', ', $d['names'])); }

// ==== 3d. Value by service count ====
print "\n=== 3d. Total estimated cost by service count — 2026 ===\n";
$set26 = $byYear(2026);
$estPop = array_filter($set26, fn($d) => $d['estRows'] > 0);
printf("contracts with any populated field_estimate: %d of %d (%.0f%%)\n", count($estPop), count($set26), count($set26) ? 100 * count($estPop) / count($set26) : 0);
$nonzeroEst = array_filter($set26, fn($d) => $d['est'] > 0);
printf("contracts with a NON-zero estimate total: %d (%.0f%%)\n", count($nonzeroEst), count($set26) ? 100 * count($nonzeroEst) / count($set26) : 0);
if (count($nonzeroEst) >= 20) {
  $buckets = [];
  foreach ($set26 as $d) { if ($d['count'] > 0 && $d['est'] > 0) { $buckets[$d['count']][] = $d['est']; } }
  ksort($buckets);
  printf("%-10s %10s %14s %12s  (non-zero-estimate contracts only)\n", 'Services', 'Contracts', 'Median $', 'Mean $');
  foreach ($buckets as $sc => $v) { printf("%-10d %10d %14s %12s\n", $sc, count($v), '$' . number_format($median($v), 0), '$' . number_format(array_sum($v) / count($v), 0)); }
}
else { print "field_estimate totals are overwhelmingly zero/blank — value-by-count is not meaningful (NOT substituting invoiced totals).\n"; }

// ==== §2.2 governance flag ====
print "\n=== §2.2 flag — winterizing WOs vs re-signed contract lines ===\n";
$winWoByYear = [];
foreach ([2024, 2025, 2026] as $yr) {
  $a = strtotime("$yr-01-01 MST"); $b = strtotime(($yr + 1) . "-01-01 MST");
  $pids = $db->query("SELECT DISTINCT p.field_property_target_id pid FROM {work_order_field_data} w
    JOIN {work_order__field_property} p ON p.entity_id = w.id
    WHERE w.type='sprinkler_winterizing' AND w.created>=:a AND w.created<:b", [':a' => $a, ':b' => $b])->fetchCol();
  $winWoByYear[$yr] = array_flip(array_map('intval', $pids));
}
$allPids = array_unique(array_merge(...array_map('array_keys', $winWoByYear)));
$recurring = 0;
foreach ($allPids as $pid) { $s = 0; foreach ($winWoByYear as $set) { if (isset($set[$pid])) { $s++; } } if ($s >= 2) { $recurring++; } }
$contractWinterPids26 = array_flip(array_map(fn($d) => $d['pid'], array_filter($byYear(2026), fn($d) => $d['winter'])));
$wo26 = array_keys($winWoByYear[2026]);
$noLine = array_filter($wo26, fn($pid) => !isset($contractWinterPids26[$pid]));
printf("properties with a winterizing WO: 2024=%d 2025=%d 2026=%d\n", count($winWoByYear[2024]), count($winWoByYear[2025]), count($winWoByYear[2026]));
printf("properties winterized in 2+ of those seasons (recurring): %d\n", $recurring);
printf("properties with a 2026 winterizing WO but NO 2026 residential contract line selecting winterizing: %d of %d (%.0f%%)\n",
  count($noLine), count($wo26), count($wo26) ? 100 * count($noLine) / count($wo26) : 0);

// ==== CSV ====
$cityByPid = [];
$pids26 = array_unique(array_map(fn($d) => $d['pid'], $set26));
foreach (array_chunk($pids26, 500) as $chunk) {
  foreach ($db->query("SELECT zr.entity_id pid, ct.name city FROM {properties__field_zipcode_reference} zr
    JOIN {zipcodes__field_city} zc ON zc.entity_id = zr.field_zipcode_reference_target_id
    JOIN {taxonomy_term_field_data} ct ON ct.tid = zc.field_city_target_id
    WHERE zr.entity_id IN (:p[])", [':p[]' => $chunk])->fetchAll() as $r) { $cityByPid[(int) $r->pid] = $r->city; }
}
$fh = fopen($CSV, 'w');
fputcsv($fh, ['contract_id', 'property_id', 'city', 'service_count', 'service_count_tree_collapsed', 'includes_winterizing', 'total_estimated_cost', 'selected_services']);
$dup = []; $zeroSvc = 0;
foreach ($set26 as $cid => $d) {
  fputcsv($fh, [$cid, $d['pid'], $cityByPid[$d['pid']] ?? '', $d['count'], $d['collapsed'], $d['winter'] ? 'Y' : 'N', $d['estRows'] ? number_format($d['est'], 2, '.', '') : '', implode(' | ', $d['names'])]);
  $dup[$d['pid']] = ($dup[$d['pid']] ?? 0) + 1; if ($d['count'] === 0) { $zeroSvc++; }
}
fclose($fh);

print "\n=== Data quality ===\n";
printf("2026 contracts: %d | zero-service contracts: %d | properties with >1 2026 residential contract: %d\n", count($set26), $zeroSvc, count(array_filter($dup, fn($n) => $n > 1)));
$orphans = $db->query("SELECT COUNT(*) FROM {contract_sections_field_data} cs WHERE NOT EXISTS (SELECT 1 FROM {contract_sections__field_contract} fc WHERE fc.entity_id = cs.id)")->fetchField();
printf("contract_sections with NO field_contract back-ref: %s (slot-based counting is unaffected by this)\n", $orphans);
print "\nCSV written: $CSV\n";
