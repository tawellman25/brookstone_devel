<?php

/**
 * P1.6 — coverage-signal disagreement report (read-only; safe on live).
 *
 * Lists every property where the THREE winterizing coverage signals disagree:
 *   A = standing flag  (property_sprinkler_info.field_ss_shut_down_contract)
 *   B = current-year term-369 contract section wanted (residential)
 *   C = existing sprinkler_winterizing WO in the service-year window (non-Canceled)
 *
 * The most valuable output is "standing flag only" (A && !B && !C): a lapsed
 * customer worth winning back, or a stale record worth clearing — next year's
 * variant-B mailing list, produced as a by-product.
 *
 *   drush php:script web/scripts/report_winterize_coverage_disagreement.php
 *   (YEAR=2026 env var overrides the service year; writes a CSV to the scratch path)
 */

use Drupal\Core\Datetime\DrupalDateTime;

$year = (int) (getenv('YEAR') ?: 2026);
$etm = \Drupal::entityTypeManager();
$cfg = \Drupal::config('bos_service_request.settings');
$tz = new \DateTimeZone(date_default_timezone_get());
$startStr = $cfg->get('bundles.sprinkler_winterizing.service_year_start') ?: "$year-08-01";
$endStr = $cfg->get('bundles.sprinkler_winterizing.service_year_end') ?: (($year + 1) . '-01-31');
$winStart = (new DrupalDateTime($startStr . ' 00:00:00', $tz))->getTimestamp();
$winEnd = (new DrupalDateTime($endStr . ' 23:59:59', $tz))->getTimestamp();

// A — standing flag.
$A = [];
$infoStorage = $etm->getStorage('property_sprinkler_info');
$infoIds = $infoStorage->getQuery()->accessCheck(FALSE)->condition('field_ss_shut_down_contract', 1)->execute();
foreach ($infoStorage->loadMultiple($infoIds) as $info) {
  if ($pid = $info->get('field_property')->target_id) {
    $A[(int) $pid] = TRUE;
  }
}

// B — current-year residential contract with a wanted term-369 section.
$B = [];
$contractStorage = $etm->getStorage('contracts');
$cIds = $contractStorage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'residential')->condition('field_contract_year', $year)->execute();
if ($cIds) {
  $secStorage = $etm->getStorage('contract_sections');
  foreach ($contractStorage->loadMultiple($cIds) as $c) {
    $pid = (int) $c->get('field_property')->target_id;
    if (!$pid || isset($B[$pid])) {
      continue;
    }
    $s = $secStorage->getQuery()->accessCheck(FALSE)
      ->condition('field_contract', $c->id())->condition('field_service', 369)
      ->condition('field_do_you_want', ['1', '4'], 'IN')->range(0, 1)->execute();
    if ($s) {
      $B[$pid] = TRUE;
    }
  }
}

// C — sprinkler_winterizing WO in the window, non-Canceled (1098).
$C = [];
$woStorage = $etm->getStorage('work_order');
$woIds = $woStorage->getQuery()->accessCheck(FALSE)
  ->condition('type', 'sprinkler_winterizing')
  ->condition('created', $winStart, '>=')->condition('created', $winEnd, '<=')->execute();
foreach ($woStorage->loadMultiple($woIds) as $wo) {
  $st = (!$wo->get('field_status')->isEmpty()) ? (int) $wo->get('field_status')->target_id : 0;
  if ($st === 1098) {
    continue;
  }
  if ($pid = $wo->get('field_property')->target_id) {
    $C[(int) $pid] = TRUE;
  }
}

$union = array_unique(array_merge(array_keys($A), array_keys($B), array_keys($C)));

$categorize = function (bool $a, bool $b, bool $c): string {
  if ($a && !$b && !$c) {
    return '1_standing_flag_only';
  }
  if ($a && $b && $c) {
    return '4_full_agreement';
  }
  if (!$a && ($b || $c)) {
    return '2_covered_no_standing_flag';
  }
  return '3_partial';
};

$rows = [];
$counts = [];
$propStorage = $etm->getStorage('properties');
foreach ($union as $pid) {
  $a = !empty($A[$pid]); $b = !empty($B[$pid]); $c = !empty($C[$pid]);
  $cat = $categorize($a, $b, $c);
  $counts[$cat] = ($counts[$cat] ?? 0) + 1;
  if ($cat === '4_full_agreement') {
    continue;
  }
  $prop = $propStorage->load($pid);
  $rows[] = [
    'category' => $cat,
    'property_id' => $pid,
    'nickname' => $prop ? (string) $prop->get('field_nickname')->value : '',
    'standing_flag' => $a ? 'Y' : '',
    'has_section' => $b ? 'Y' : '',
    'has_wo' => $c ? 'Y' : '',
  ];
}
usort($rows, fn($x, $y) => [$x['category'], $x['nickname']] <=> [$y['category'], $y['nickname']]);

$path = "/tmp/winterize_coverage_disagreement_$year.csv";
$fh = fopen($path, 'w');
fputcsv($fh, ['category', 'property_id', 'nickname', 'standing_flag', 'has_section', 'has_wo']);
foreach ($rows as $r) {
  fputcsv($fh, $r);
}
fclose($fh);

echo "== Winterize coverage-signal disagreement ($year) ==\n";
echo "  window: $startStr → $endStr\n";
echo "  signals: A standing-flag=" . count($A) . " · B current-section=" . count($B) . " · C winterizing-WO=" . count($C) . "\n";
ksort($counts);
foreach ($counts as $cat => $n) {
  echo "  $cat: $n\n";
}
echo "  → " . count($rows) . " disagreement rows written to $path\n";
echo "  (category 1_standing_flag_only is next year's variant-B list.)\n";
