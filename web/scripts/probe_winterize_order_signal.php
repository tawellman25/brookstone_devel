<?php

/**
 * READ-ONLY probe for the winterizing schedule carry-forward feature (§0.5).
 *
 * Decides whether "carry forward the DRIVEN route order" is worth building, and
 * which signal (sign-off timestamp / clock / status / planned) actually reflects
 * how the route was driven vs the order the office planned. Creates nothing,
 * saves nothing — safe against live.
 *
 *   drush php:script web/scripts/probe_winterize_order_signal.php        (live)
 *   ddev drush php:script web/scripts/probe_winterize_order_signal.php   (local)
 */

use Drupal\Core\Datetime\DrupalDateTime;

$tz = new \DateTimeZone(date_default_timezone_get());
$WINTERIZING = 'sprinkler_winterizing';
$COMPLETE_TID = 1097;
$seasonStart = (new DrupalDateTime('2025-08-15 00:00:00', $tz))->getTimestamp();
$seasonEnd = (new DrupalDateTime('2025-12-31 23:59:59', $tz))->getTimestamp();

$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');

/** Find the entity_reference field on $type/$bundle that targets work_order. */
$woLinkField = function (string $type, string $bundle) use ($efm): ?string {
  foreach ($efm->getFieldDefinitions($type, $bundle) as $n => $d) {
    if ($d->getType() === 'entity_reference' && $d->getSetting('target_type') === 'work_order') {
      return $n;
    }
  }
  return NULL;
};

// ── 2025 winterizing WOs in the season window ──
$woIds = $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
  ->condition('type', $WINTERIZING)
  ->condition('created', $seasonStart, '>=')
  ->condition('created', $seasonEnd, '<=')
  ->sort('id', 'ASC')->execute();
$woIds = array_map('intval', $woIds);
printf("=== 2025 winterizing season (%s → %s): %d WOs ===\n\n",
  date('Y-m-d', $seasonStart), date('Y-m-d', $seasonEnd), count($woIds));

$fmt = fn($ts) => $ts ? DrupalDateTime::createFromTimestamp($ts, $tz)->format('Y-m-d H:i:s') : '-';
$chunks = array_chunk($woIds, 300);

// ── scheduling records: wo => [start_ts, order, assigned_uid, sched_id] ──
$sched = [];
foreach ($chunks as $ch) {
  $ids = $etm->getStorage('scheduling')->getQuery()->accessCheck(FALSE)->condition('field_work_order', $ch, 'IN')->sort('id', 'ASC')->execute();
  foreach ($etm->getStorage('scheduling')->loadMultiple($ids) as $s) {
    if ($s->get('field_work_order')->isEmpty()) { continue; }
    $wid = (int) $s->get('field_work_order')->target_id;
    $start = $s->get('field_date')->isEmpty() ? NULL : (int) $s->get('field_date')->value;
    $assigned = $s->get('field_assigned_to')->isEmpty() ? NULL : (int) $s->get('field_assigned_to')->target_id;
    $order = $s->get('field_scheduled_oder')->isEmpty() ? NULL : (int) $s->get('field_scheduled_oder')->value;
    // Highest-id scheduling record wins (final decision).
    if (!isset($sched[$wid]) || $s->id() > $sched[$wid]['sid']) {
      $sched[$wid] = ['sid' => (int) $s->id(), 'start' => $start, 'assigned' => $assigned, 'order' => $order];
    }
  }
}

// ── sign-offs: wo => [completed_ts, created_ts, signed_uid, bundle] ──
$signoff = [];
foreach ($chunks as $ch) {
  $ids = $etm->getStorage('wo_complete_info')->getQuery()->accessCheck(FALSE)->condition('field_work_order', $ch, 'IN')->sort('id', 'ASC')->execute();
  foreach ($etm->getStorage('wo_complete_info')->loadMultiple($ids) as $c) {
    if ($c->get('field_work_order')->isEmpty()) { continue; }
    $wid = (int) $c->get('field_work_order')->target_id;
    $signoff[$wid] = [
      'completed' => $c->get('field_date_completed')->isEmpty() ? NULL : (int) $c->get('field_date_completed')->value,
      'created' => (int) $c->get('created')->value,
      'uid' => $c->get('field_signed_off_by')->isEmpty() ? NULL : (int) $c->get('field_signed_off_by')->target_id,
      'bundle' => $c->bundle(),
    ];
  }
}

// ── clock: wo => [earliest_start_ts, teammate_uids[]] ──
$clockField = $woLinkField('wo_time_clock', 'entry');
$clock = [];
if ($clockField) {
  foreach ($chunks as $ch) {
    $ids = $etm->getStorage('wo_time_clock')->getQuery()->accessCheck(FALSE)->condition($clockField, $ch, 'IN')->exists('field_start_time')->sort('id', 'ASC')->execute();
    foreach ($etm->getStorage('wo_time_clock')->loadMultiple($ids) as $e) {
      $wid = (int) $e->get($clockField)->target_id;
      $raw = $e->get('field_start_time')->isEmpty() ? NULL : (string) $e->get('field_start_time')->value;
      $st = $raw === NULL ? NULL : (is_numeric($raw) ? (int) $raw : strtotime($raw . ' UTC'));
      $tm = $e->hasField('field_teammate') && !$e->get('field_teammate')->isEmpty() ? (int) $e->get('field_teammate')->target_id : NULL;
      if (!isset($clock[$wid])) { $clock[$wid] = ['start' => $st, 'tms' => []]; }
      if ($st !== NULL && ($clock[$wid]['start'] === NULL || $st < $clock[$wid]['start'])) { $clock[$wid]['start'] = $st; }
      if ($tm) { $clock[$wid]['tms'][$tm] = 1; }
    }
  }
}

// ── status updates into Complete: wo => earliest created_ts ──
$statusComplete = [];
foreach ($chunks as $ch) {
  $ids = $etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)->condition('field_status_of_wo', $ch, 'IN')->condition('field_status', $COMPLETE_TID)->sort('id', 'ASC')->execute();
  foreach ($etm->getStorage('wo_status_updates')->loadMultiple($ids) as $u) {
    if ($u->get('field_status_of_wo')->isEmpty()) { continue; }
    $wid = (int) $u->get('field_status_of_wo')->target_id;
    $ct = (int) $u->get('created')->value;
    if (!isset($statusComplete[$wid]) || $ct < $statusComplete[$wid]) { $statusComplete[$wid] = $ct; }
  }
}

// ── property + WO number ──
$prop = [];
$woEntities = [];
foreach ($chunks as $ch) {
  foreach ($etm->getStorage('work_order')->loadMultiple($ch) as $wo) {
    $woEntities[(int) $wo->id()] = $wo;
    $pid = $wo->get('field_property')->isEmpty() ? NULL : (int) $wo->get('field_property')->target_id;
    $prop[(int) $wo->id()] = $pid;
  }
}
$propIds = array_values(array_unique(array_filter($prop)));
$propInfo = [];
foreach (array_chunk($propIds, 300) as $ch) {
  foreach ($etm->getStorage('properties')->loadMultiple($ch) as $p) {
    $propInfo[(int) $p->id()] = [
      'nick' => (string) ($p->get('field_nickname')->value ?? ''),
      'street' => (string) ($p->get('field_street_address')->value ?? ''),
    ];
  }
}
$userName = function (?int $uid) use ($etm): string {
  if (!$uid) { return '-'; }
  $u = $etm->getStorage('user')->load($uid);
  return $u ? $u->getDisplayName() : "uid $uid (deleted)";
};

// ============ ITEMS 1–9 ============
$withSched = count(array_filter($woIds, fn($w) => isset($sched[$w])));
echo "1. WOs with a scheduling record: $withSched / " . count($woIds) . "\n\n";

$bundleDist = [];
foreach ($signoff as $s) { $bundleDist[$s['bundle']] = ($bundleDist[$s['bundle']] ?? 0) + 1; }
echo "2. WOs with a sign-off: " . count($signoff) . "  bundle dist: " . json_encode($bundleDist) . "\n\n";

$realTime = 0; $midnight = 0; $nullCompleted = 0;
foreach ($signoff as $s) {
  if ($s['completed'] === NULL) { $nullCompleted++; continue; }
  $hms = DrupalDateTime::createFromTimestamp($s['completed'], $tz)->format('H:i:s');
  if ($hms === '00:00:00') { $midnight++; } else { $realTime++; }
}
echo "3. field_date_completed — real time component: $realTime · exact midnight (date-only tell): $midnight · null: $nullCompleted\n\n";

$delta = ['same_min' => 0, 'lt_1h' => 0, 'same_day' => 0, 'next_day' => 0, 'later' => 0, 'n/a' => 0];
foreach ($signoff as $s) {
  if ($s['completed'] === NULL) { $delta['n/a']++; continue; }
  $d = abs($s['created'] - $s['completed']);
  $cd = DrupalDateTime::createFromTimestamp($s['completed'], $tz)->format('Y-m-d');
  $rd = DrupalDateTime::createFromTimestamp($s['created'], $tz)->format('Y-m-d');
  if ($d <= 60) { $delta['same_min']++; }
  elseif ($d < 3600) { $delta['lt_1h']++; }
  elseif ($cd === $rd) { $delta['same_day']++; }
  elseif ($d < 172800) { $delta['next_day']++; }
  else { $delta['later']++; }
}
echo "4. field_date_completed vs sign-off entity created: " . json_encode($delta) . "\n";
echo "   (large 'next_day/later' => office signs off after the fact; then 'created' isn't the tech-left-job time either)\n\n";

// 5. batch detection — group by (completed-date, signed_uid)
$groups = [];
foreach ($signoff as $wid => $s) {
  if ($s['completed'] === NULL || $s['uid'] === NULL) { continue; }
  $day = DrupalDateTime::createFromTimestamp($s['completed'], $tz)->format('Y-m-d');
  $groups["$day|{$s['uid']}"][] = $s['completed'];
}
$multi = 0; $mostlyBatch = 0; $sampleLines = [];
foreach ($groups as $key => $times) {
  if (count($times) < 2) { continue; }
  $multi++;
  sort($times);
  $gaps = [];
  for ($i = 1; $i < count($times); $i++) { $gaps[] = $times[$i] - $times[$i - 1]; }
  sort($gaps);
  $median = $gaps[intdiv(count($gaps), 2)];
  $under2 = count(array_filter($gaps, fn($g) => $g < 120)) / count($gaps);
  if ($under2 >= 0.5) { $mostlyBatch++; }
  if (count($sampleLines) < 8) {
    [$day, $uid] = explode('|', $key);
    $sampleLines[] = sprintf("     %s %s: n=%d median_gap=%ds under2min=%d%%", $day, $userName((int) $uid), count($times) + 1, $median, round($under2 * 100));
  }
}
printf("5. BATCH DETECTION — %d day-tech groups with ≥2 sign-offs; %d (%d%%) are 'mostly sub-2-min gaps' (data-entry order, NOT route order)\n",
  $multi, $mostlyBatch, $multi ? round($mostlyBatch / $multi * 100) : 0);
foreach ($sampleLines as $l) { echo $l . "\n"; }
echo "\n";

$withClock = count(array_filter($woIds, fn($w) => isset($clock[$w]) && $clock[$w]['start'] !== NULL));
$clockTechMatch = 0;
foreach ($woIds as $w) {
  if (isset($clock[$w], $sched[$w]) && $sched[$w]['assigned'] && isset($clock[$w]['tms'][$sched[$w]['assigned']])) { $clockTechMatch++; }
}
echo "6. WOs with ≥1 clock entry (field_start_time): $withClock" . ($clockField ? " (via $clockField)" : " (NO wo->work_order field found!)") . "\n";
echo "   of those, clock teammate matches scheduled assignee: $clockTechMatch\n\n";

echo "7. WOs with a status-update into Complete (1097): " . count($statusComplete) . "\n\n";

$withOrder = count(array_filter($sched, fn($s) => $s['order'] !== NULL));
echo "8. scheduling records with field_scheduled_oder populated: $withOrder / " . count($sched) . "\n\n";

// 9. planned tech vs actual tech
$match = 0; $mismatch = 0; $pairs = [];
foreach ($woIds as $w) {
  if (!isset($sched[$w], $signoff[$w]) || !$sched[$w]['assigned'] || !$signoff[$w]['uid']) { continue; }
  if ($sched[$w]['assigned'] === $signoff[$w]['uid']) { $match++; }
  else {
    $mismatch++;
    $k = $sched[$w]['assigned'] . '→' . $signoff[$w]['uid'];
    $pairs[$k] = ($pairs[$k] ?? 0) + 1;
  }
}
arsort($pairs);
printf("9. planned tech (scheduled) vs actual tech (signed-off): match=%d mismatch=%d (%d%% mismatch)\n",
  $match, $mismatch, ($match + $mismatch) ? round($mismatch / ($match + $mismatch) * 100) : 0);
$i = 0;
foreach ($pairs as $k => $c) {
  [$a, $b] = explode('→', $k);
  printf("     %s → %s : %d\n", $userName((int) $a), $userName((int) $b), $c);
  if (++$i >= 10) { break; }
}
echo "\n";

// ============ ITEM 10 — 4-way ordering for the 3 busiest scheduled days ============
echo "10. Four-way ordering on the 3 busiest scheduled days (per assigned tech):\n";
$dayGroups = [];
foreach ($woIds as $w) {
  if (!isset($sched[$w]) || $sched[$w]['start'] === NULL) { continue; }
  $day = DrupalDateTime::createFromTimestamp($sched[$w]['start'], $tz)->format('Y-m-d');
  $dayGroups[$day][] = $w;
}
uasort($dayGroups, fn($a, $b) => count($b) <=> count($a));
$topDays = array_slice(array_keys($dayGroups), 0, 3);
foreach ($topDays as $day) {
  $wos = $dayGroups[$day];
  printf("\n  --- %s (%d WOs) ---\n", $day, count($wos));
  $byTech = [];
  foreach ($wos as $w) { $byTech[$sched[$w]['assigned'] ?? 0][] = $w; }
  foreach ($byTech as $tech => $tw) {
    printf("   tech %s (%d):\n", $userName($tech ?: NULL), count($tw));
    $rank = function (array $arr, callable $key) {
      $x = $arr;
      usort($x, fn($a, $b) => ($key($a) <=> $key($b)));
      return $x;
    };
    $BIG = PHP_INT_MAX;
    $byPlan = $rank($tw, fn($w) => $sched[$w]['order'] ?? $BIG);
    $bySignoff = $rank($tw, fn($w) => $signoff[$w]['completed'] ?? $BIG);
    $byClock = $rank($tw, fn($w) => $clock[$w]['start'] ?? $BIG);
    $byStatus = $rank($tw, fn($w) => $statusComplete[$w] ?? $BIG);
    $label = fn($w) => substr(($propInfo[$prop[$w]]['nick'] ?? '') ?: ('WO' . $w), 0, 22);
    printf("     %-24s | %-24s | %-24s | %-24s\n", 'by planned order', 'by sign-off ts', 'by earliest clock', 'by Complete status');
    for ($r = 0; $r < count($tw); $r++) {
      printf("     %-24s | %-24s | %-24s | %-24s\n",
        $label($byPlan[$r]), $label($bySignoff[$r]), $label($byClock[$r]), $label($byStatus[$r]));
    }
  }
}
echo "\nDONE.\n";
