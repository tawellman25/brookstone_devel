<?php

declare(strict_types=1);

/**
 * Does HolidayEstimator reproduce the figures printed in the Rev 2 field sheet?
 *
 * The sheet is the authority a salesperson has in hand, so any disagreement
 * between it and the website is a defect in one of them. These cases are taken
 * verbatim from its roofline table and its worked "never quote the roofline
 * alone" example.
 *
 *   drush php:script web/scripts/test_holiday_estimator.php
 */

$est = \Drupal::service('bos_service_request.holiday_estimator');
$pass = $fail = 0;
$check = function (string $what, float $got, float $want, float $tol = 0.51) use (&$pass, &$fail) {
  $ok = abs($got - $want) <= $tol;
  $ok ? $pass++ : $fail++;
  printf("  [%s] %-52s got $%s  sheet $%s\n", $ok ? 'PASS' : 'FAIL', $what,
    number_format($got, 2), number_format($want, 2));
};

print "Roofline table, 2-year at \$5.75 (section 02):\n";
foreach ([135 => 776, 150 => 863, 175 => 1006, 200 => 1150, 250 => 1438, 300 => 1725] as $ft => $want) {
  $check("$ft ft", $est->price('two_year', (float) $ft)['total'], (float) $want);
}

print "\nRoofline table, 1-year at \$7.50:\n";
foreach ([150 => 1125, 175 => 1313, 200 => 1500, 300 => 2250] as $ft => $want) {
  $check("$ft ft", $est->price('one_year', (float) $ft)['total'], (float) $want);
}

print "\nHang & Remove at \$3.00, including the \$450 floor:\n";
foreach ([135 => 450, 150 => 450, 175 => 525, 200 => 600, 300 => 900] as $ft => $want) {
  $check("$ft ft", $est->price('hang', (float) $ft)['total'], (float) $want);
}

print "\nThe worked example from the rule box (section 03):\n";
// "A 175 ft house with two medium trees, one 36in wreath and four lit windows
//  is $2,796"
$r = $est->price('two_year', 175.0, ['dec_md' => 2, 'wreath_36' => 1, 'window' => 4]);
$check('175 ft + 2 medium + 36" wreath + 4 windows', $r['total'], 2796.0);
$addonShare = $r['total'] > 0 ? (($r['total'] - 175 * 5.75) / $r['total']) * 100 : 0;
printf("  [%s] %-52s %.0f%%  sheet 64%%\n", abs($addonShare - 64) <= 1 ? 'PASS' : 'FAIL',
  'add-ons as a share of the ticket', $addonShare);
abs($addonShare - 64) <= 1 ? $pass++ : $fail++;

print "\nRules that are not arithmetic:\n";
$min = $est->price('two_year', 60.0);
printf("  [%s] a short roofline is lifted to the \$775 minimum (got $%s, flagged %s)\n",
  ($min['minimum_applied'] && abs($min['total'] - 775) < 0.51) ? 'PASS' : 'FAIL',
  number_format($min['total'], 2), $min['minimum_applied'] ? 'yes' : 'NO');
($min['minimum_applied'] && abs($min['total'] - 775) < 0.51) ? $pass++ : $fail++;

$shrub = $est->price('two_year', 175.0, ['shrub' => 1]);
$nudged = abs($shrub['total'] - (1006.25 + 255)) < 0.51;
printf("  [%s] one shrub is nudged to the 3 minimum, not rejected (got $%s)\n",
  $nudged ? 'PASS' : 'FAIL', number_format($shrub['total'], 2));
$nudged ? $pass++ : $fail++;

$steep = $est->price('two_year', 175.0, [], TRUE);
$steepOk = abs($steep['total'] - (1006.25 * 1.20)) < 0.51;
printf("  [%s] steep adds 20%% of the ROOFLINE only (got $%s)\n", $steepOk ? 'PASS' : 'FAIL', number_format($steep['total'], 2));
$steepOk ? $pass++ : $fail++;

// Steep must not take a cut of the add-ons.
$steepWithAddon = $est->price('two_year', 175.0, ['window' => 4], TRUE);
$noCut = abs($steepWithAddon['total'] - (1006.25 * 1.20 + 440)) < 0.51;
printf("  [%s] steep does NOT inflate add-ons (got $%s)\n", $noCut ? 'PASS' : 'FAIL', number_format($steepWithAddon['total'], 2));
$noCut ? $pass++ : $fail++;

$dep = $est->price('two_year', 175.0);
$depOk = abs(($dep['deposit'] ?? -1) - $dep['total'] / 2) < 0.01;
printf("  [%s] full-season shows a 50%% deposit\n", $depOk ? 'PASS' : 'FAIL');
$depOk ? $pass++ : $fail++;

$hang = $est->price('hang', 175.0);
printf("  [%s] hang & remove shows NO deposit (we supply no material)\n", $hang['deposit'] === NULL ? 'PASS' : 'FAIL');
$hang['deposit'] === NULL ? $pass++ : $fail++;

printf("\n%d passed, %d failed\n", $pass, $fail);
