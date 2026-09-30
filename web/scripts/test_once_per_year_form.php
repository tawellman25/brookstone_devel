<?php

/**
 * @file
 * Reversible test of the FORM half: does the warning actually fire for someone
 * entering a second work order, and does confirming let it through?
 *
 * Driven through \Drupal::formBuilder()->submitForm(), which runs real
 * validation — a handler can look correctly registered and never be called.
 */

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0; $made = [];
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

$auto = fn($e) => \Drupal\Core\Entity\Element\EntityAutocomplete::getEntityLabels([$e]);
$props = $etm->getStorage('properties')->getQuery()->accessCheck(FALSE)->range(0, 400)->execute();
$pid = NULL;
foreach ($props as $c) {
  if (!wo_shared_same_year_duplicates('sprinkler_winterizing', (int) $c)) { $pid = (int) $c; break; }
}
if (!$pid) { print "no clean property\n"; return; }
$svc = reset($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'services', 'field_service_bundle' => 'sprinkler_winterizing']));
printf("property %d\n\n", $pid);

$submit = function (bool $confirm) use ($etm, $pid, $svc, $auto) {
  $wo = $etm->getStorage('work_order')->create(['type' => 'sprinkler_winterizing']);
  $form_object = $etm->getFormObject('work_order', 'default');
  $form_object->setEntity($wo);
  $form_state = new \Drupal\Core\Form\FormState();
  $form_state->setValues([
    'field_property' => [['target_id' => $auto($etm->getStorage('properties')->load($pid))]],
    'field_service' => [['target_id' => $auto($svc)]],
    'field_status' => 1091,
    'wo_shared_duplicate_confirm' => $confirm ? 1 : 0,
    'op' => 'Save',
  ]);
  $input = ['op' => 'Save'];
  if ($confirm) { $input['wo_shared_duplicate_confirm'] = '1'; }
  $form_state->setUserInput($input);
  \Drupal::formBuilder()->submitForm($form_object, $form_state);
  return [$form_state, $form_object->getEntity()];
};

// Seed the first one directly.
$first = $etm->getStorage('work_order')->create([
  'type' => 'sprinkler_winterizing', 'field_property' => $pid,
  'field_service' => $svc->id(), 'field_status' => 1091,
]);
$first->save();
$made[] = $first;
printf("seeded WO %s\n\n", $first->id());

// 1. Unconfirmed second entry must be stopped with a readable warning.
[$fs] = $submit(FALSE);
$errors = $fs->getErrors();
$msg = implode(' ', array_map('strval', $errors));
$check('an unconfirmed second entry is stopped', !empty($errors), count($errors) . ' error(s)');
$check('the warning names the service', str_contains($msg, 'Sprinkler Winterizing'), substr($msg, 0, 110));
$check('the warning names the existing work order', str_contains($msg, 'WO ' . $first->id()));
$check('the warning asks before proceeding', str_contains(strtolower($msg), 'are you sure'));
$check('it tells them how to proceed', str_contains($msg, 'tick'));
$check('the confirm box is revealed for the retry', (bool) $fs->get('wo_shared_duplicate_warned'));

// 2. Confirmed: goes through.
$before = (int) $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'sprinkler_winterizing')->condition('field_property', $pid)->count()->execute();
[$fs2, $ent2] = $submit(TRUE);
$check('a confirmed second entry has no duplicate error',
  empty(array_filter(array_map('strval', $fs2->getErrors()), fn($e) => str_contains($e, 'already has'))),
  implode(' | ', array_map('strval', $fs2->getErrors())) ?: 'no errors');
if ($ent2 && $ent2->id()) {
  $made[] = $ent2;
  $after = (int) $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
    ->condition('type', 'sprinkler_winterizing')->condition('field_property', $pid)->count()->execute();
  $check('the confirmed one saved', $after === $before + 1, "count $before -> $after");
  $notes = $etm->getStorage('wo_notes')->loadByProperties(['field_work_order' => $ent2->id()]);
  $n = $notes ? reset($notes) : NULL;
  $check('the confirmed one is noted as deliberate',
    $n && str_contains((string) $n->get('field_change_summary')->value, 'Confirmed'),
    $n ? (string) $n->get('field_change_summary')->value : 'no note');
}

// 3. A FIRST entry on a clean property must sail through untouched.
$clean = NULL;
foreach ($props as $c) {
  if ((int) $c !== $pid && !wo_shared_same_year_duplicates('sprinkler_winterizing', (int) $c)) { $clean = (int) $c; break; }
}
if ($clean) {
  $wo = $etm->getStorage('work_order')->create(['type' => 'sprinkler_winterizing']);
  $fo = $etm->getFormObject('work_order', 'default');
  $fo->setEntity($wo);
  $fsc = new \Drupal\Core\Form\FormState();
  $fsc->setValues([
    'field_property' => [['target_id' => $auto($etm->getStorage('properties')->load($clean))]],
    'field_service' => [['target_id' => $auto($svc)]],
    'field_status' => 1091,
    'op' => 'Save',
  ]);
  $fsc->setUserInput(['op' => 'Save']);
  \Drupal::formBuilder()->submitForm($fo, $fsc);
  $e = $fo->getEntity();
  if ($e && $e->id()) { $made[] = $e; }
  $check('a first entry is never warned about',
    empty(array_filter(array_map('strval', $fsc->getErrors()), fn($x) => str_contains($x, 'already has'))));
}

// --- clean up -------------------------------------------------------------
foreach ($made as $wo) {
  if (!$wo->id()) { continue; }
  foreach ($etm->getStorage('wo_notes')->loadByProperties(['field_work_order' => $wo->id()]) as $n) { $n->delete(); }
  foreach ($etm->getStorage('wo_status_updates')->loadByProperties(['field_status_of_wo' => $wo->id()]) as $s) { $s->delete(); }
  $wo->_skip_invoiced_guard = TRUE;
  $wo->delete();
}
$ids = array_values(array_filter(array_map(fn($w) => $w->id(), $made)));
$left = $ids ? (int) $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)->condition('id', $ids, 'IN')->count()->execute() : 0;
$check('all test work orders removed', $left === 0, "remaining=$left");

printf("\n%d passed, %d failed\n", $pass, $fail);
