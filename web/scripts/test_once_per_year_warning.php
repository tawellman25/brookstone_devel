<?php

/**
 * @file
 * Reversible test of the once-per-property-per-year duplicate warning.
 * Every work order and note created here is deleted before exit.
 */

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$etm = \Drupal::entityTypeManager();
$woStorage = $etm->getStorage('work_order');
$pass = 0; $fail = 0; $made = [];
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

// A property with no winterizing WO this year, so the test starts clean.
$tz = new \DateTimeZone(date_default_timezone_get());
$yr = (new \DateTime('now', $tz))->format('Y');
$candidates = $etm->getStorage('properties')->getQuery()->accessCheck(FALSE)->range(0, 400)->execute();
$pid = NULL;
foreach ($candidates as $c) {
  if (!wo_shared_same_year_duplicates('sprinkler_winterizing', (int) $c)) { $pid = (int) $c; break; }
}
if (!$pid) { print "No clean property found.\n"; return; }
printf("using property %d, year %s\n\n", $pid, $yr);

$svc = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'services', 'field_service_bundle' => 'sprinkler_winterizing']);
$svc = $svc ? reset($svc) : NULL;
$check('a winterizing service term exists to build with', (bool) $svc);
if (!$svc) { return; }

$makeWo = function (array $extra = []) use ($woStorage, $pid, $svc, &$made) {
  $wo = $woStorage->create([
    'type' => 'sprinkler_winterizing',
    'field_property' => $pid,
    'field_service' => $svc->id(),
    'field_status' => 1091,
  ] + $extra);
  $wo->save();
  $made[] = $wo;
  return $wo;
};

// 1. First one: no duplicate, nothing flagged.
$first = $makeWo();
$check('first work order reports no duplicate',
  count(wo_shared_same_year_duplicates('sprinkler_winterizing', $pid, (int) $first->id())) === 0);

// 2. Second one: the helper must see the first.
$dups = wo_shared_same_year_duplicates('sprinkler_winterizing', $pid);
$check('a second entry would be detected', count($dups) === 1, 'found WO ' . implode(',', array_keys($dups)));

// 3. A programmatic second one is ALLOWED (a warning, not a gate) and noted.
$second = $makeWo();
$check('the second one saved (warning, not a gate)', (bool) $second->id());
$notes = $etm->getStorage('wo_notes')->getQuery()->accessCheck(FALSE)
  ->condition('field_work_order', $second->id())->execute();
$check('a system note was recorded on the second work order', count($notes) >= 1);
if ($notes) {
  $n = $etm->getStorage('wo_notes')->load(reset($notes));
  $txt = (string) $n->get('field_change_summary')->value;
  $check('the note names the other work order', str_contains($txt, 'WO ' . $first->id()), $txt);
  $check('the note says it had no form warning', str_contains($txt, 'without a form warning'));
}

// 4. Canceled work orders must NOT count — cancel-and-recreate is normal.
$second->set('field_status', 1098);
$second->_skip_invoiced_guard = TRUE;
$second->save();
$first->set('field_status', 1098);
$first->_skip_invoiced_guard = TRUE;
$first->save();
$check('canceled work orders are ignored',
  count(wo_shared_same_year_duplicates('sprinkler_winterizing', $pid)) === 0,
  'both set to Canceled');

// 5. Other bundles are untouched.
$check('an unlisted bundle is never flagged',
  wo_shared_same_year_duplicates('sprinkler_repair', $pid) === []);

// 6. Both target bundles are covered.
$check('both winterizing and start-up are in scope',
  WO_SHARED_ONCE_PER_YEAR_BUNDLES === ['sprinkler_winterizing', 'sprinkler_start_up']);

// --- clean up -------------------------------------------------------------
foreach ($made as $wo) {
  foreach ($etm->getStorage('wo_notes')->loadByProperties(['field_work_order' => $wo->id()]) as $n) { $n->delete(); }
  foreach ($etm->getStorage('wo_status_updates')->loadByProperties(['field_status_of_wo' => $wo->id()]) as $s) { $s->delete(); }
  $wo->_skip_invoiced_guard = TRUE;
  $wo->delete();
}
$left = $woStorage->getQuery()->accessCheck(FALSE)->condition('id', array_map(fn($w) => $w->id(), $made), 'IN')->count()->execute();
$check('all test work orders removed', (int) $left === 0, "remaining=$left");

printf("\n%d passed, %d failed\n", $pass, $fail);
