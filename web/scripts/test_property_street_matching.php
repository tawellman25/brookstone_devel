<?php

/**
 * @file
 * READ-ONLY test of property street matching. Creates and changes nothing.
 *
 * The case that produced a duplicate property on 2026-09-22 is the first one:
 * a signup typed the whole address into the street box, the match failed, and a
 * second property was created for a house that already had two contracts and
 * three work orders.
 */

$n = \Drupal::service('bos_wo_intake.property_normalizer');
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

// --- streetCore: what counts as the street ---------------------------------
$cores = [
  '1774 Trappers Ct. Delta, Co' => '1774 trappers court',
  '1774 Trappers Ct'            => '1774 trappers court',
  '1774 Trappers Ct.'           => '1774 trappers court',
  '1774 trappers court'         => '1774 trappers court',
];
$got = [];
foreach ($cores as $in => $want) { $got[$in] = $n->streetCore($in); }
$check('every spelling of the duplicate address reduces to one core',
  count(array_unique($got)) === 1, implode(' | ', array_unique($got)));

// --- the pairs that must match --------------------------------------------
$should = [
  ['1774 Trappers Ct. Delta, Co', '1774 Trappers Ct', 'whole address typed into the street box'],
  ['1774 Trappers Ct.', '1774 Trappers Ct', 'trailing period'],
  ['1774 trappers court', '1774 Trappers Ct', 'spelled-out suffix vs abbreviation'],
  ['1774 Trappers Ct, Delta, CO 81416', '1774 Trappers Ct', 'city, state and ZIP appended'],
  ['1774 Trappers Court Delta Colorado', '1774 Trappers Ct', 'everything spelled out'],
  ['680 Willow Wood Ln', '680 Willow Wood Lane', 'abbreviation vs spelled out'],
  ['N Hillcrest Dr. and Locust St.', 'N Hillcrest Dr. and Locust St.', 'compound address survives'],
  ['Hwy 50 and 1250 Rd.', 'Hwy 50 and 1250 Rd', 'no leading house number'],
  ['123 Main St Apt 4', '123 Main St', 'unit number appended'],
];
foreach ($should as [$a, $b, $why]) {
  $check(sprintf('MATCH  %-34s ~ %-30s', substr($a, 0, 34), substr($b, 0, 30)),
    $n->streetsMatch($a, $b), $why);
}

// --- the pairs that must NOT match ----------------------------------------
$shouldNot = [
  ['1774 Trappers Ct', '1776 Trappers Ct', 'a different house on the same street'],
  ['1774 Trappers Ct', '1774 Willow Wood Ln', 'same number, different street'],
  ['', '1774 Trappers Ct', 'empty submission never matches'],
  ['1774 Trappers Ct', '', 'empty stored value never matches'],
];
foreach ($shouldNot as [$a, $b, $why]) {
  $check(sprintf('NO     %-34s ≁ %-30s', substr($a, 0, 34) ?: '(empty)', substr($b, 0, 30) ?: '(empty)'),
    !$n->streetsMatch($a, $b), $why);
}

// --- and end to end through the real matcher, against live data -----------
$m = \Drupal::service('bos_service_request.property_matcher');
$cases = [
  ['Monk', '1774 Trappers Ct. Delta, Co', '81416', 'the exact string that created the duplicate'],
  ['Monk', '1774 Trappers Ct', '81416', 'the clean form'],
];
foreach ($cases as [$last, $street, $zip, $why]) {
  $r = $m->match($last, $street, $zip);
  $ids = array_map(fn($c) => $c['id'], $r['candidates']);
  $check(sprintf('matcher finds the property for "%s"', $street),
    $r['status'] !== 'unmatched', $why . ' -> ' . $r['status'] . ' candidates=' . implode(',', $ids));
  // 1776 is a DIFFERENT house and must never be offered.
  $check('1776 is not offered as a candidate', !in_array(71792, $ids, TRUE));
}

printf("\n%d passed, %d failed\n", $pass, $fail);

// --- The provisioning form must refuse a second property at a known address ---
$etm = \Drupal::entityTypeManager();
\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$sr = $etm->getStorage('service_request')->load(65);
if ($sr) {
  foreach ([FALSE => 'unconfirmed', TRUE => 'confirmed'] as $confirm => $label) {
    $fo = \Drupal::classResolver('Drupal\bos_service_request\Form\CreateCustomerForm');
    $fs = new \Drupal\Core\Form\FormState();
    $fs->addBuildInfo('args', [$sr]);
    $fs->setValues([
      'nickname' => 'Test — do not save',
      'street_address' => '1774 Trappers Ct. Delta, Co',
      'zip' => '81416',
      'last_name' => 'Monk',
      'first_name' => 'Alan',
      'client_type' => 0,
    ]);
    $fs->setUserInput($confirm ? ['duplicate_confirm' => '1'] : []);
    // Call the form's own validateForm directly. Going through the form builder
    // died on the CSRF token before reaching the guard, which made BOTH cases
    // look like passes — a vacuous result. Nothing is submitted, so no property
    // is created.
    $fs->setProgrammed(TRUE);
    $form = \Drupal::formBuilder()->buildForm($fo, $fs);
    // buildForm on a programmed state completes validation, which locks the
    // error bag. Reopen it so the guard can record its error.
    $fs->setValidationComplete(FALSE);
    $fs->clearErrors();
    $fo->validateForm($form, $fs);
    $errs = implode(' ', array_map('strval', $fs->getErrors()));
    $dupErr = str_contains($errs, 'already a property at this address');
    $check("provisioning form, $label: " . ($confirm ? 'allowed through' : 'refused with the existing property named'),
      $confirm ? !$dupErr : $dupErr, $confirm ? ($errs ?: 'no duplicate error') : substr($errs, 0, 120));
  }
}
else { print "NOTE service_request 65 not on this environment; form guard not exercised\n"; }

printf("\nfinal: %d passed, %d failed\n", $pass, $fail);
