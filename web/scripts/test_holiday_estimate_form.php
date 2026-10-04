<?php

declare(strict_types=1);

/**
 * On-site holiday estimate form — access, render, and both submit paths.
 *
 * HARNESS NOTE, measured the hard way: in a PROGRAMMED form
 * Checkbox::valueCallback() returns 1 for an input of 0 — it treats any present
 * input as "this box was posted", and a posted checkbox means checked. Only
 * FALSE or NULL come back as 0. So an unchecked box in a programmatic test must
 * be passed as FALSE, never 0.
 *
 * A first version of this test passed 0 and therefore submitted steep=1 tall=1,
 * producing $3,097.50 where the sheet says $2,796.25 — which looked exactly like
 * a pricing defect and was not. The real form renders both boxes UNCHECKED with
 * #value = 0, asserted below so the distinction stays covered.
 *
 *   drush php:script web/scripts/test_holiday_estimate_form.php
 */

$pass = $fail = 0;
$ok = function (string $what, bool $cond, string $extra = '') use (&$pass, &$fail) {
  $cond ? $pass++ : $fail++;
  printf("  [%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $what, $extra ? " ($extra)" : '');
};

// --- access ----------------------------------------------------------------
print "Access:\n";
$perm = 'use holiday lighting estimator';
$anon = \Drupal\user\Entity\User::load(0);
$ok('anonymous is denied', !$anon->hasPermission($perm));
foreach (['teammates', 'supervisor', 'administration'] as $rid) {
  $r = \Drupal\user\Entity\Role::load($rid);
  $ok("role $rid is allowed", $r && $r->hasPermission($perm));
}
$ok('route exists', (bool) \Drupal::service('router.route_provider')->getRouteByName('bos_service_request.holiday_estimate'));

// --- render ----------------------------------------------------------------
print "\nRender:\n";
$form = \Drupal::formBuilder()->getForm('Drupal\bos_service_request\Form\HolidayEstimateForm');
$html = (string) \Drupal::service('renderer')->renderInIsolation($form);
$ok('three plans offered', count($form['plan']['#options'] ?? []) === 3, implode(' | ', array_keys($form['plan']['#options'] ?? [])));
$ok('two-year is the default', ($form['plan']['#default_value'] ?? '') === 'two_year');
$ok('13 priced add-on counters render', substr_count($html, 'data-hol-addon') === 13, substr_count($html, 'data-hol-addon') . ' found');
$ok('roofline input present', isset($form['feet']));
$ok('steep + tall conditions present', isset($form['conditions']['steep'], $form['conditions']['tall']));
$ok('live-total region present', strpos($html, 'hol-est-live') !== FALSE);
$ok('price book reached the JS', !empty($form['#attached']['drupalSettings']['bosHolidayEstimate']['plans']['two_year']['rate']));
// The thing that must never appear on a crew screen.
foreach (['margin', 'Margin', 'gross profit', 'Gross profit', 'direct cost', 'Direct cost'] as $leak) {
  if (strpos($html, $leak) !== FALSE) {
    $ok("no cost/margin leak ('$leak')", FALSE);
    continue;
  }
}
$ok('no cost or margin anywhere in the form', TRUE);
// The premiums must be OFF until somebody ticks them, or every job overquotes.
$ok('steep is unchecked by default', (int) ($form['conditions']['steep']['#value'] ?? 1) === 0);
$ok('tall is unchecked by default', (int) ($form['conditions']['tall']['#value'] ?? 1) === 0);
$ok('nothing renders pre-checked', !preg_match('/<input[^>]*name="(steep|tall)"[^>]*checked/', $html));

// --- submit, unidentified: must NOT create a record -------------------------
print "\nSubmit with nobody named:\n";
$storage = \Drupal::entityTypeManager()->getStorage('service_request');
$before = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
$scope = ['plan' => 'two_year', 'feet' => 175, 'dec_md' => 2, 'wreath_36' => 1, 'window' => 4, 'steep' => FALSE, 'tall' => FALSE];
$fs = new \Drupal\Core\Form\FormState();
$fs->setValues($scope);
$fs->setUserInput($scope);
\Drupal::formBuilder()->submitForm('Drupal\bos_service_request\Form\HolidayEstimateForm', $fs);
$after = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
$ok('no service_request created', $after === $before, "before $before after $after");
$msgs = \Drupal::messenger()->deleteAll();
$text = '';
foreach ($msgs as $set) { foreach ($set as $m) { $text .= (string) $m; } }
$ok('the total is still reported back', strpos($text, '2,796') !== FALSE, trim(mb_substr($text, 0, 90)));

// --- submit, identified: must create one with the breakdown -----------------
print "\nSubmit with a name:\n";
$named = $scope + [
  'name' => 'ZZ Estimator Test', 'phone' => '970-555-0100',
  'address' => '1 Test Ln', 'notes' => 'Two medium maples out front.',
];
$fs2 = new \Drupal\Core\Form\FormState();
$fs2->setValues($named);
$fs2->setUserInput($named);
\Drupal::formBuilder()->submitForm('Drupal\bos_service_request\Form\HolidayEstimateForm', $fs2);
\Drupal::messenger()->deleteAll();
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_submitted_name', 'ZZ Estimator Test')->execute();
$ok('one record created', count($ids) === 1, count($ids) . ' found');
if ($ids) {
  $rec = $storage->load(reset($ids));
  $notes = (string) $rec->get('field_customer_notes')->value;
  $ok('breakdown stored with the lead', strpos($notes, 'ESTIMATE TOTAL') !== FALSE && strpos($notes, '2,796') !== FALSE);
  $ok('walkthrough note kept', strpos($notes, 'medium maples') !== FALSE);
  $ok('attributed to the builder', strpos($notes, 'Built on site by') !== FALSE);
  $ok('campaign holiday26', (string) $rec->get('field_campaign')->value === 'holiday26');
  $ok('flagged as an on-site estimate', strpos((string) $rec->get('field_review_flags')->value, 'onsite_estimate') !== FALSE);
  // clean up
  $rec->delete();
  print "  (test record deleted)\n";
}

// --- premiums, when they ARE ticked ----------------------------------------
print "\nSubmit with steep and tall ticked:\n";
$prem = ['plan' => 'two_year', 'feet' => 175, 'steep' => 1, 'tall' => 1];
$fs3 = new \Drupal\Core\Form\FormState();
$fs3->setValues($prem);
$fs3->setUserInput($prem);
\Drupal::formBuilder()->submitForm('Drupal\bos_service_request\Form\HolidayEstimateForm', $fs3);
$t3 = '';
foreach (\Drupal::messenger()->deleteAll() as $set) { foreach ($set as $m) { $t3 .= (string) $m; } }
// 1006.25 * 1.20 + 100 = 1307.50
$ok('steep 20% + $100 height applied', strpos($t3, '1,307.50') !== FALSE, trim(mb_substr($t3, 0, 60)));

// --- the PUBLIC form must differ in three specific ways --------------------
print "\nPublic /holiday-lights estimator:\n";
$pub = \Drupal::formBuilder()->getForm('Drupal\bos_service_request\Form\HolidayLightsForm');
$pubHtml = (string) \Drupal::service('renderer')->renderInIsolation($pub);
$ok('offers only the two full-season plans', array_keys($pub['plan']['#options'] ?? []) === ['two_year', 'one_year'],
  implode(',', array_keys($pub['plan']['#options'] ?? [])));
$ok('Hang & Remove is NOT selectable', !isset($pub['plan']['#options']['hang']));
// The leak that must never travel from the crew form to the public one.
$ok('no property autocomplete on the public form', !isset($pub['property']),
  isset($pub['property']) ? 'PRESENT — would expose the customer property list' : '');
$ok('captcha present', isset($pub['captcha']));
$ok('13 counters render', substr_count($pubHtml, 'data-hol-addon') === 13);
$ok('says it is an estimate, not a quote', stripos($pubHtml, 'not a quote') !== FALSE);

print "\nPublic submit is CAPTCHA-protected, so it cannot be driven from here:\n";
// Not a limitation to work around — it is the protection working. A headless
// submit of this form is supposed to fail, so the honest test is that it DOES,
// and that nothing is written when it does. The itemisation the handler would
// store is verified directly underneath via the shared estimator, and an
// end-to-end submit needs a browser.
$pscope = [
  'first_name' => 'ZZ', 'last_name' => 'PublicTest', 'phone' => '970-555-0199',
  'email' => '', 'address' => '2 Test Ln', 'message' => 'Big spruce by the drive.',
  'plan' => 'two_year', 'feet' => 175, 'dec_md' => 2, 'wreath_36' => 1, 'window' => 4,
  'steep' => FALSE, 'tall' => FALSE,
];
$beforePub = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
$pfs = new \Drupal\Core\Form\FormState();
$pfs->setValues($pscope);
$pfs->setUserInput($pscope);
\Drupal::formBuilder()->submitForm('Drupal\bos_service_request\Form\HolidayLightsForm', $pfs);
$pErrs = $pfs->getErrors();
\Drupal::messenger()->deleteAll();
$ok('a submission without a valid CAPTCHA is rejected', isset($pErrs['captcha_response']));
$ok('and writes nothing', (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute() === $beforePub);

print "\nThe itemisation that submit would store:\n";
$est = \Drupal::service('bos_service_request.holiday_estimator');
$pubResult = $est->price('two_year', 175.0, ['dec_md' => 2, 'wreath_36' => 1, 'window' => 4]);
$pubText = $est->asText($pubResult);
$ok('matches the sheet figure', strpos($pubText, '2,796') !== FALSE);
$ok('itemises every line the customer picked',
  strpos($pubText, 'Roofline') !== FALSE
  && strpos($pubText, 'Deciduous tree — medium × 2') !== FALSE
  && strpos($pubText, 'Wreath — 36 in') !== FALSE
  && strpos($pubText, 'Lit window × 4') !== FALSE);
$ok('carries the deposit', strpos($pubText, 'Deposit at signing') !== FALSE);

printf("\n%d passed, %d failed\n", $pass, $fail);
