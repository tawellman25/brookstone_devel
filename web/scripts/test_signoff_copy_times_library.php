<?php

/**
 * @file
 * The "Copy these times" button only worked after a failed save. Guard it.
 *
 * REPORTED 2026-10-30: clicking Refresh showed the rows fine, but "Copy these
 * times" did nothing until you attempted a save and got an error — after which
 * it worked.
 *
 * CAUSE: the button is pure client-side (data-* attributes + a JS behavior in
 * wo_total_time/copy_from_first). That library was attached to
 * `$form['#attached']` at the END of the fieldset builder. Two things made the
 * JS never reach the browser except on a full page render:
 *
 *   1. The ajax callback returns ONLY `$form['signoff_reconciliation']`, and
 *      Drupal collects #attached from the RETURNED array — a library on the
 *      root form is dropped from an ajax response.
 *   2. The attach sat AFTER four early returns, and a freshly-opened sign-off
 *      has an empty roster, which takes the first of them — so it never ran on
 *      initial page load either.
 *
 * Only a full POST render reached it, which is exactly the reported behaviour.
 *
 * FIX: attach on the wrapper subtree, before the early returns.
 *
 * ⚠ The load-bearing assertion is the AJAX one. Asserting the library is
 * somewhere on the built form would pass against the old broken code too.
 *
 * Read-only. Run: drush php:script web/scripts/test_signoff_copy_times_library.php
 */

use Drupal\Core\Form\FormState;

const LIB = 'wo_total_time/copy_from_first';

$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

$etm = \Drupal::entityTypeManager();
$ids = $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'sprinkler_winterizing')->sort('id', 'DESC')->range(0, 1)->execute();
$woId = (int) reset($ids);
$ok('found a winterizing work order', $woId > 0, "WO $woId");

$entity = $etm->getStorage('wo_complete_info')->create([
  'type' => 'irrigation_crew',
  'field_work_order' => $woId,
]);
$formObject = $etm->getFormObject('wo_complete_info', 'default');
$formObject->setEntity($entity);
$formState = new FormState();
$formState->setFormObject($formObject);
$form = \Drupal::formBuilder()->buildForm($formObject, $formState);

$ok('the reconciliation wrapper exists', isset($form['signoff_reconciliation']));

// --- THE ONE THAT MATTERS ---------------------------------------------------
// Exactly what _wo_sign_off_reconciliation_ajax_rebuild() hands back.
$ajaxReturn = $form['signoff_reconciliation'] ?? [];
$subtreeLibs = $ajaxReturn['#attached']['library'] ?? [];
$ok('the AJAX-returned subtree carries the copy-times library',
  in_array(LIB, $subtreeLibs, TRUE),
  $subtreeLibs ? implode(', ', $subtreeLibs) : 'NONE — the js would never load on refresh');

// --- and it is attached even on an EMPTY roster (initial page load) ----------
// This form was built with no crew selected, which is the early-return path.
$ok('  attached even with an empty roster (before the early returns)',
  in_array(LIB, $subtreeLibs, TRUE),
  'a fresh sign-off opens with no crew — the old attach never ran here');

// The library itself must exist and actually contain the behavior.
$libs = \Drupal::service('library.discovery')->getLibrariesByExtension('wo_total_time');
$ok('the library is defined', isset($libs['copy_from_first']));
if (isset($libs['copy_from_first'])) {
  $js = $libs['copy_from_first']['js'][0]['data'] ?? '';
  $ok('  its JS file exists on disk', $js !== '' && file_exists($js), basename((string) $js));
  if ($js && file_exists($js)) {
    $src = (string) file_get_contents($js);
    $ok('  it registers a Drupal behavior (so AJAX-inserted buttons get wired)',
      str_contains($src, 'Drupal.behaviors'));
    $ok('  and scopes once() to the passed context, not the document',
      (bool) preg_match('/once\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"][^\'"]+[\'"]\s*,\s*context\s*\)/', $src));
  }
}

// Regression guard: the old root-level attach must not come back on its own,
// because it silently does nothing for the ajax path.
$modulePath = \Drupal::service('extension.list.module')->getPath('wo_sign_off');
$src = (string) file_get_contents($modulePath . '/wo_sign_off.module');
$ok('the module no longer attaches it to the ROOT form only',
  !str_contains($src, "\$form['#attached']['library'][] = '" . LIB . "'"),
  'root attach is invisible to the ajax response');
$ok('it attaches on the wrapper subtree instead',
  str_contains($src, "\$form['signoff_reconciliation']['#attached']['library'][] = '" . LIB . "'"));

printf("\n%d passed, %d failed\n", $pass, $fail);
