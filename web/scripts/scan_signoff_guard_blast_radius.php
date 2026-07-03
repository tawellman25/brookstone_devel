<?php

/**
 * @file
 * Pre-deploy blast-radius scan for the Phase B sign-off open-clock-in guard.
 *
 * Reports every not-yet-signed-off work_order that currently has an open
 * wo_time_clock entry (NULL field_end_time) — the WOs whose next sign-off
 * attempt would be blocked the moment the guard activates. READ ONLY.
 *
 * Run: ddev drush php:script web/scripts/scan_signoff_guard_blast_radius.php
 *   (or the Alt-PHP + vendor/drush/drush.php invocation on live)
 */

$etm = \Drupal::entityTypeManager();
$utc = new \DateTimeZone('UTC');
$denver = new \DateTimeZone('America/Denver');
$now = new \DateTime('now', $utc);
$nowTs = $now->getTimestamp();

// Statuses excluded from the blast radius — the guard either doesn't fire
// (already signed off / locked) or has a bypass.
$EXCLUDE = [
  1097 => 'Complete (already signed off)',
  1281 => 'Invoiced (locked)',
  1504 => 'Paid (locked)',
  1098 => 'Canceled (bypass path)',
];

// -- helpers ---------------------------------------------------------------
$statusLabel = function (int $tid) {
  static $cache = [];
  if (!array_key_exists($tid, $cache)) {
    $t = $tid ? \Drupal\taxonomy\Entity\Term::load($tid) : NULL;
    $cache[$tid] = $t ? $t->getName() : "(tid $tid)";
  }
  return $cache[$tid];
};
$userName = function (int $uid) {
  static $cache = [];
  if (!array_key_exists($uid, $cache)) {
    $u = $uid ? \Drupal\user\Entity\User::load($uid) : NULL;
    $cache[$uid] = $u ? $u->getDisplayName() : "uid=$uid";
  }
  return $cache[$uid];
};
$fmtUs = function (?string $s) use ($utc, $denver) {
  if (!$s) {
    return '(no start time)';
  }
  try {
    $d = new \DateTime($s, $utc);
    $d->setTimezone($denver);
    return $d->format('m/d/Y g:i A');
  }
  catch (\Throwable $e) {
    return '(bad date)';
  }
};
$ageSeconds = function (?string $s) use ($utc, $nowTs) {
  if (!$s) {
    return NULL;
  }
  try {
    return $nowTs - (new \DateTime($s, $utc))->getTimestamp();
  }
  catch (\Throwable $e) {
    return NULL;
  }
};
$ageHuman = function (?int $sec) {
  if ($sec === NULL) {
    return '(unknown age)';
  }
  if ($sec < 3600) {
    return max(0, (int) round($sec / 60)) . ' min ago';
  }
  if ($sec < 86400) {
    return (int) round($sec / 3600) . ' hr ago';
  }
  return (int) round($sec / 86400) . ' days ago';
};

// -- gather open clock-in entries ------------------------------------------
$openIds = \Drupal::entityQuery('wo_time_clock')->accessCheck(FALSE)
  ->notExists('field_end_time')
  ->execute();

$entries = [];
$woIds = [];
$orphanNoWo = 0;
foreach ($etm->getStorage('wo_time_clock')->loadMultiple($openIds) as $tc) {
  $wo = $tc->hasField('field_work_order') && !$tc->get('field_work_order')->isEmpty()
    ? (int) $tc->get('field_work_order')->target_id : 0;
  if (!$wo) {
    $orphanNoWo++;
    continue;
  }
  $uid = $tc->hasField('field_teammate') && !$tc->get('field_teammate')->isEmpty()
    ? (int) $tc->get('field_teammate')->target_id : (int) $tc->getOwnerId();
  $start = !$tc->get('field_start_time')->isEmpty() ? $tc->get('field_start_time')->value : NULL;
  $sec = $ageSeconds($start);
  $entries[] = ['id' => (int) $tc->id(), 'wo' => $wo, 'uid' => $uid, 'start' => $start, 'sec' => $sec];
  $woIds[$wo] = 1;
}

// WO status/title/property.
$wos = $woIds ? $etm->getStorage('work_order')->loadMultiple(array_keys($woIds)) : [];
$woStatus = [];
$woInfo = [];
foreach ($wos as $wo) {
  $wid = (int) $wo->id();
  $woStatus[$wid] = (int) ($wo->get('field_status')->target_id ?? 0);
  $prop = ($wo->hasField('field_property') && !$wo->get('field_property')->isEmpty()) ? $wo->get('field_property')->entity : NULL;
  $crew = '(via scheduling — not on WO)';
  foreach (['field_supervisor', 'field_assigned_to', 'field_crew'] as $cf) {
    if ($wo->hasField($cf) && !$wo->get($cf)->isEmpty()) {
      $nm = [];
      foreach ($wo->get($cf) as $it) {
        if ($it->target_id) {
          $nm[] = $userName((int) $it->target_id);
        }
      }
      if ($nm) {
        $crew = implode(', ', $nm) . " ($cf)";
        break;
      }
    }
  }
  $woInfo[$wid] = [
    'title' => $wo->label(),
    'prop' => $prop ? $prop->label() : '(no property)',
    'addr' => ($prop && $prop->hasField('field_full_address') && !$prop->get('field_full_address')->isEmpty())
      ? trim(strip_tags((string) $prop->get('field_full_address')->value)) : '',
    'nick' => ($prop && $prop->hasField('field_nickname') && !$prop->get('field_nickname')->isEmpty())
      ? $prop->get('field_nickname')->value : '',
    'crew' => $crew,
  ];
}

$isBlast = fn(int $wo) => !isset($EXCLUDE[$woStatus[$wo] ?? 0]);
$blastEntries = array_values(array_filter($entries, fn($e) => $isBlast($e['wo'])));
$blastWoIds = [];
foreach ($blastEntries as $e) {
  $blastWoIds[$e['wo']] = 1;
}

// ==========================================================================
// SUMMARY
// ==========================================================================
$teammates = [];
$oldestSec = 0;
$newestSec = NULL;
foreach ($blastEntries as $e) {
  $teammates[$e['uid']] = 1;
  if ($e['sec'] !== NULL) {
    $oldestSec = max($oldestSec, $e['sec']);
    $newestSec = $newestSec === NULL ? $e['sec'] : min($newestSec, $e['sec']);
  }
}
print str_repeat('=', 78) . "\n";
print "SIGN-OFF GUARD — PRE-DEPLOY BLAST RADIUS\n";
print "generated " . (clone $now)->setTimezone($denver)->format('m/d/Y g:i A T') . " | READ ONLY\n";
print str_repeat('=', 78) . "\n";
printf("Not-yet-signed-off WOs with >=1 open clock-in : %d\n", count($blastWoIds));
printf("Total open clock-in entries on those WOs      : %d\n", count($blastEntries));
printf("Teammates affected                            : %d\n", count($teammates));
printf("Oldest open entry                             : %.1f days\n", $oldestSec / 86400);
printf("Newest open entry                             : %.1f hours\n", $newestSec !== NULL ? $newestSec / 3600 : 0);
printf("(context: %d total open entries; %d orphan open entries with no WO ref, not counted above)\n",
  count($entries) + $orphanNoWo, $orphanNoWo);

// ==========================================================================
// REPORT 1 — grouped by WO status
// ==========================================================================
print "\n" . str_repeat('=', 78) . "\nREPORT 1 — open clock-ins grouped by parent WO status\n" . str_repeat('=', 78) . "\n";
$woByStatus = [];
$entryByStatus = [];
foreach ($entries as $e) {
  $st = $woStatus[$e['wo']] ?? 0;
  $woByStatus[$st][$e['wo']] = 1;
  $entryByStatus[$st] = ($entryByStatus[$st] ?? 0) + 1;
}
// Blast-radius statuses first, then excluded.
uksort($woByStatus, function ($a, $b) use ($EXCLUDE, $entryByStatus) {
  $ab = isset($EXCLUDE[$a]) ? 1 : 0;
  $bb = isset($EXCLUDE[$b]) ? 1 : 0;
  return $ab <=> $bb ?: ($entryByStatus[$b] <=> $entryByStatus[$a]);
});
printf("  %-34s %5s  %5s   %s\n", 'Status (tid)', 'WOs', 'Open', 'Guard');
foreach ($woByStatus as $st => $woset) {
  $tag = isset($EXCLUDE[$st]) ? 'excluded — ' . $EXCLUDE[$st] : '*** BLAST RADIUS ***';
  printf("  %-34s %5d  %5d   %s\n",
    $statusLabel($st) . " ($st)", count($woset), $entryByStatus[$st], $tag);
}

// ==========================================================================
// REPORT 2 — blast-radius detail (oldest first)
// ==========================================================================
print "\n" . str_repeat('=', 78) . "\nREPORT 2 — blast-radius WO detail (oldest open entry first)\n" . str_repeat('=', 78) . "\n";
// Group blast entries by WO; track each WO's oldest entry age for sorting.
$byWo = [];
foreach ($blastEntries as $e) {
  $byWo[$e['wo']][] = $e;
}
uksort($byWo, function ($a, $b) use ($byWo) {
  $maxA = max(array_map(fn($e) => $e['sec'] ?? -1, $byWo[$a]));
  $maxB = max(array_map(fn($e) => $e['sec'] ?? -1, $byWo[$b]));
  return $maxB <=> $maxA;
});
if (!$byWo) {
  print "  (no blast-radius WOs — nothing would be blocked)\n";
}
foreach ($byWo as $wo => $es) {
  $info = $woInfo[$wo] ?? ['title' => '?', 'prop' => '?', 'addr' => '', 'nick' => '', 'crew' => '?'];
  print "\n  WO $wo — " . $info['title'] . "  [" . $statusLabel($woStatus[$wo] ?? 0) . "]\n";
  print "    Property: " . ($info['nick'] ? $info['nick'] . ' — ' : '') . $info['prop'] . ($info['addr'] ? ' (' . $info['addr'] . ')' : '') . "\n";
  print "    Crew:     " . $info['crew'] . "\n";
  print "    Open entries: " . count($es) . "\n";
  usort($es, fn($a, $b) => ($b['sec'] ?? -1) <=> ($a['sec'] ?? -1));
  foreach ($es as $e) {
    printf("      entry #%-7d %-22s start %s  (%s)\n",
      $e['id'], $userName($e['uid']), $fmtUs($e['start']), $ageHuman($e['sec']));
  }
}

// ==========================================================================
// REPORT 3 — teammate concentration
// ==========================================================================
print "\n" . str_repeat('=', 78) . "\nREPORT 3 — open entries by teammate (blast radius)\n" . str_repeat('=', 78) . "\n";
$byTm = [];
foreach ($blastEntries as $e) {
  $byTm[$e['uid']]['n'] = ($byTm[$e['uid']]['n'] ?? 0) + 1;
  $byTm[$e['uid']]['oldest'] = max($byTm[$e['uid']]['oldest'] ?? 0, $e['sec'] ?? 0);
  $byTm[$e['uid']]['oldestStart'] = ($e['sec'] ?? 0) >= ($byTm[$e['uid']]['oldest'] ?? 0) ? $e['start'] : ($byTm[$e['uid']]['oldestStart'] ?? $e['start']);
  $byTm[$e['uid']]['wos'][$e['wo']] = 1;
}
uasort($byTm, fn($a, $b) => $b['n'] <=> $a['n']);
if (!$byTm) {
  print "  (none)\n";
}
foreach ($byTm as $uid => $d) {
  printf("  %-22s %2d open | oldest %s (%s) | WOs: %s\n",
    $userName($uid), $d['n'], $fmtUs($d['oldestStart'] ?? NULL), $ageHuman($d['oldest'] ?? NULL),
    implode(', ', array_keys($d['wos'])));
}

// ==========================================================================
// REPORT 4 — staleness distribution
// ==========================================================================
print "\n" . str_repeat('=', 78) . "\nREPORT 4 — staleness distribution (blast radius)\n" . str_repeat('=', 78) . "\n";
$buckets = [
  'Same-day (< 24h)' => [0, 86400],
  'This week (1-7d)' => [86400, 7 * 86400],
  'Last week (7-14d)' => [7 * 86400, 14 * 86400],
  'Older (> 14d)' => [14 * 86400, PHP_INT_MAX],
];
foreach ($buckets as $label => [$lo, $hi]) {
  $n = 0;
  $wset = [];
  foreach ($blastEntries as $e) {
    $s = $e['sec'];
    if ($s !== NULL && $s >= $lo && $s < $hi) {
      $n++;
      $wset[$e['wo']] = 1;
    }
  }
  printf("  %-20s entries=%-4d WOs=%d\n", $label, $n, count($wset));
}
$noAge = count(array_filter($blastEntries, fn($e) => $e['sec'] === NULL));
if ($noAge) {
  printf("  %-20s entries=%d (no parseable start time)\n", 'Unknown age', $noAge);
}

print "\n" . str_repeat('=', 78) . "\nEND OF SCAN\n" . str_repeat('=', 78) . "\n";
