<?php

/**
 * @file
 * Reversible test: the add-note form is short enough to reach Save, and a note
 * added from a work order still attaches to it.
 */

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0; $made = [];
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

$woId = (int) \Drupal::database()->query(
  "SELECT id FROM {work_order_field_data} WHERE type = :t ORDER BY created DESC LIMIT 1",
  [':t' => 'sprinkler_winterizing'])->fetchField();
$wo = $etm->getStorage('work_order')->load($woId);
printf("work order %d — %s\n\n", $woId, $wo->label());

// --- Coming from a work order: the form is one field + Save -----------------
$note = $etm->getStorage('wo_notes')->create(['type' => 'note', 'field_work_order' => $woId]);
$fo = $etm->getFormObject('wo_notes', 'default');
$fo->setEntity($note);
$fs = new \Drupal\Core\Form\FormState();
$form = \Drupal::formBuilder()->buildForm($fo, $fs);

$visible = [];
foreach (['field_note_text', 'field_work_order', 'created', 'langcode'] as $k) {
  $shown = isset($form[$k]) && ($form[$k]['#access'] ?? TRUE) !== FALSE;
  if ($shown) { $visible[] = $k; }
}
$check('the note text is still on the form', in_array('field_note_text', $visible, TRUE));
$check('the Work Order picker is gone', !in_array('field_work_order', $visible, TRUE));
$check('"Entered on" is gone', !in_array('created', $visible, TRUE));
$check('Language is gone', !in_array('langcode', $visible, TRUE));
$check('only one field is left to fill', $visible === ['field_note_text'], implode(', ', $visible));
$check('it still says which work order the note is for', isset($form['wo_notes_for']),
  isset($form['wo_notes_for']) ? strip_tags((string) $form['wo_notes_for']['#markup']) : 'missing');
$check('the Save button is in the form', isset($form['actions']['submit']));

// --- The value must still save, now that the widget is not shown ------------
$fs2 = new \Drupal\Core\Form\FormState();
$note2 = $etm->getStorage('wo_notes')->create(['type' => 'note', 'field_work_order' => $woId]);
$fo2 = $etm->getFormObject('wo_notes', 'default');
$fo2->setEntity($note2);
$fs2->setValues(['field_note_text' => [['value' => 'TEST all valves at 45, clock off for winter', 'format' => 'full_html']]]);
$fs2->setUserInput(['op' => 'Save']);
\Drupal::formBuilder()->submitForm($fo2, $fs2);
// submitForm builds the entity but does NOT persist it: ::save lives on the
// submit button's #submit, not on the form's. The thing actually at risk here is
// whether field_work_order survives being #access FALSE, so check the built
// entity, then save it the way the button would.
$built = $fo2->getEntity();
$check('the hidden work order survived onto the entity',
  (int) $built->get('field_work_order')->target_id === $woId,
  'entity carries ' . ($built->get('field_work_order')->target_id ?? 'NOTHING'));
$built->save();
$saved = $built;
if ($saved && $saved->id()) {
  $made[] = $saved;
  $etm->getStorage('wo_notes')->resetCache([$saved->id()]);
  $saved = $etm->getStorage('wo_notes')->load($saved->id());
  $check('the note saved', TRUE, 'note ' . $saved->id());
  $check('and it is attached to the right work order',
    (int) $saved->get('field_work_order')->target_id === $woId,
    'attached to ' . ($saved->get('field_work_order')->target_id ?? 'NOTHING'));
  $check('the text saved', str_contains((string) $saved->get('field_note_text')->value, 'all valves at 45'));
  $check('"Entered on" defaulted to now', (int) $saved->get('created')->value > time() - 120);
}
else {
  $check('the note saved', FALSE, implode(' | ', array_map('strval', $fs2->getErrors())) ?: 'no entity');
}

// --- Office creating a note with no work order still gets the picker --------
$loose = $etm->getStorage('wo_notes')->create(['type' => 'note']);
$fo3 = $etm->getFormObject('wo_notes', 'default');
$fo3->setEntity($loose);
$fs3 = new \Drupal\Core\Form\FormState();
$form3 = \Drupal::formBuilder()->buildForm($fo3, $fs3);
$check('with no work order known, the picker is still offered',
  isset($form3['field_work_order']) && ($form3['field_work_order']['#access'] ?? TRUE) !== FALSE);

// --- clean up ---------------------------------------------------------------
foreach ($made as $e) { $e->delete(); }
$left = 0;
foreach ($made as $e) { if ($etm->getStorage('wo_notes')->load($e->id())) { $left++; } }
$check('test note removed', $left === 0, "remaining=$left");

printf("\n%d passed, %d failed\n", $pass, $fail);
