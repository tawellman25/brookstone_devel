<?php

declare(strict_types=1);

/**
 * Gate 1 verification — the §11 checklist items that apply to stages 1-3.
 * Creates two real credential records (Todd's ABPA cert per the approved §8
 * migration, plus a General Liability policy) and proves the scope rule and the
 * field-access matrix. Idempotent on the ABPA record.
 *
 *   drush php:script web/scripts/verify_credential_gate1.php
 */

use Drupal\Core\Session\UserSession;

$etm = \Drupal::entityTypeManager();
$storage = $etm->getStorage('credential');
$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok, string $detail = '') use (&$pass, &$fail) {
  printf("  [%s] %-58s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
  $ok ? $pass++ : $fail++;
};

$termByCode = function (string $code) use ($etm) {
  foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']) as $t) {
    if ((string) $t->get('field_credential_code')->value === $code) { return $t; }
  }
  return NULL;
};
$abpa = $termByCode('ABPA_TESTER');
$gl = $termByCode('GL');
$check('credential_types seeded (ABPA_TESTER + GL resolve by code)', $abpa && $gl);

/* Forms render. */
foreach (['default'] as $mode) {
  try {
    $new = $storage->create(['type' => 'credential']);
    $form = \Drupal::service('entity.form_builder')->getForm($new, $mode);
    $check('add form renders', isset($form['field_scope'], $form['field_credential_type']), 'fields present');
  }
  catch (\Throwable $e) {
    $check('add form renders', FALSE, get_class($e) . ': ' . $e->getMessage());
  }
}

/* Scope rule — both directions must FAIL the save. */
try {
  $bad = $storage->create(['type' => 'credential', 'field_credential_type' => $abpa->id(), 'field_scope' => 'teammate']);
  $bad->save();
  $check('scope=teammate with no teammate is refused', FALSE, 'it saved — guard missing');
  $bad->delete();
}
catch (\Throwable $e) {
  $check('scope=teammate with no teammate is refused', TRUE, 'blocked');
}

$todd = $etm->getStorage('user')->load(1);
try {
  $bad2 = $storage->create(['type' => 'credential', 'field_credential_type' => $gl->id(), 'field_scope' => 'company', 'field_teammate' => 1]);
  $bad2->save();
  $check('scope=company with a teammate set is refused', FALSE, 'it saved — guard missing');
  $bad2->delete();
}
catch (\Throwable $e) {
  $check('scope=company with a teammate set is refused', TRUE, 'blocked');
}

/* §8 migration — Todd's ABPA certification, idempotent on number+type. */
$existing = $storage->loadByProperties(['field_credential_number' => '06-2512234']);
if ($existing) {
  $cert = reset($existing);
  print "  (ABPA record already exists — id {$cert->id()})\n";
}
else {
  $cert = $storage->create([
    'type' => 'credential',
    'field_credential_type' => $abpa->id(),
    'field_scope' => 'teammate',
    'field_teammate' => 1,
    'field_credential_number' => '06-2512234',
    'field_issuing_authority' => 'ABPA',
    'field_status' => 'active',
  ]);
  $cert->save();
  print "  created ABPA credential id {$cert->id()}\n";
}
$check('auto-title on the teammate credential', $cert->label() === 'ABPA Backflow Tester — Todd Wellman', '"' . $cert->label() . '"');

/* A company insurance record with a policy number, flagged publishable. */
$glRecords = $storage->loadByProperties(['field_credential_number' => 'GL-TEST-0001']);
if ($glRecords) {
  $pol = reset($glRecords);
}
else {
  $pol = $storage->create([
    'type' => 'credential',
    'field_credential_type' => $gl->id(),
    'field_scope' => 'company',
    'field_credential_number' => 'GL-TEST-0001',
    'field_issuing_authority' => 'Test Carrier',
    'field_coverage_limits' => '$1,000,000 per occurrence / $2,000,000 aggregate',
    'field_publish_publicly' => TRUE,
    'field_status' => 'active',
  ]);
  $pol->save();
  print "  created GL credential id {$pol->id()}\n";
}
$check('auto-title on the company credential', $pol->label() === 'General Liability — Test Carrier', '"' . $pol->label() . '"');

/* Field-access matrix. */
$sw = \Drupal::service('account_switcher');
$pickRole = function (string $role) use ($etm) {
  $ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', $role)->condition('status', 1)->execute();
  foreach ($etm->getStorage('user')->loadMultiple($ids) as $u) {
    if ($u->id() == 1) { continue; }
    if ($role === 'teammates' && array_intersect(['administration','supervisor','site_assistant','site_admin','administrator'], $u->getRoles())) { continue; }
    return $u;
  }
  return NULL;
};
$actors = [
  'anonymous' => new UserSession(['uid' => 0, 'roles' => ['anonymous']]),
  'teammate' => $pickRole('teammates'),
  'office' => $pickRole('administration'),
];

print "\n  field_credential_number / field_internal_notes visibility:\n";
printf("    %-11s %-28s %-28s %s\n", 'ACTOR', 'ABPA number (type=public)', 'GL number (type=NOT public)', 'internal_notes');
$expect = [
  'anonymous' => ['no', 'no', 'no'],
  'teammate' => ['YES', 'no', 'no'],
  'office' => ['YES', 'YES', 'YES'],
];
foreach ($actors as $label => $account) {
  if (!$account) { print "    ($label: no suitable user)\n"; continue; }
  $sw->switchTo($account);
  $a = $cert->get('field_credential_number')->access('view', $account) ? 'YES' : 'no';
  $b = $pol->get('field_credential_number')->access('view', $account) ? 'YES' : 'no';
  $c = $pol->get('field_internal_notes')->access('view', $account) ? 'YES' : 'no';
  $sw->switchBack();
  printf("    %-11s %-28s %-28s %s\n", $label, $a, $b, $c);
  $check("access matrix: $label", [$a, $b, $c] === $expect[$label], 'expected ' . implode('/', $expect[$label]) . ', got ' . implode('/', [$a, $b, $c]));
}

printf("\n%d passed, %d failed.\n", $pass, $fail);
