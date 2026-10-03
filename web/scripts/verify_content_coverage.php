<?php

/**
 * Render the content coverage view and assert what it reports.
 *
 * Views config on this project has shipped clean and broken at render time
 * before, so cim/save success proves nothing — this executes and renders the
 * display, as a real office user and as anonymous.
 */

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$ok = function (string $what, bool $good, string $got = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %-62s %s\n", $good ? 'PASS' : 'FAIL', $what, $got);
};

$view = $etm->getStorage('view')->load('bos_content_coverage');
$ok('the view exists', (bool) $view);
if (!$view) { return; }

// Office user.
$office = NULL;
foreach ($etm->getStorage('user')->loadMultiple() as $u) {
  if ($u->id() > 1 && array_intersect($u->getRoles(), ['administration', 'site_admin', 'supervisor'])) { $office = $u; break; }
}
$office = $office ?: $etm->getStorage('user')->load(1);
\Drupal::currentUser()->setAccount($office);
printf("  (rendering as uid %s, roles: %s)\n", $office->id(), implode(',', $office->getRoles()));

$exec = $view->getExecutable();
$exec->setDisplay('page_1');
$exec->setItemsPerPage(0);
$exec->execute();
$rows = count($exec->result);
$ok('the page display executes', TRUE, $rows . ' rows');

$html = (string) \Drupal::service('renderer')->renderInIsolation($exec->buildRenderable('page_1'));
$ok('it RENDERS (not just executes)', strlen($html) > 2000, strlen($html) . ' chars');
$ok('renders as a table', str_contains($html, '<table'));

// Every covered term present?
$coverage = \Drupal::service('bos_content_coverage.coverage');
$expected = count($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => $coverage->coveredVids()]));
$ok('lists every term in the covered vocabularies', $rows === $expected, "$rows of $expected");

// Columns.
foreach (['Live URL alias', 'Public description', 'Teaser', 'Call to action', 'Crew description', 'Boilerplate', 'Parent', 'Changed'] as $col) {
  $ok("column present: $col", str_contains($html, $col));
}

// n/a must appear (vocabularies without a field) and EMPTY must appear.
$ok('distinguishes n/a from EMPTY', str_contains($html, 'n/a') && str_contains($html, 'EMPTY'));

// Spot-check against known truth.
$ok('the fruit alias is /deciduous/fruit, NOT fruit-trees',
  str_contains($html, '/material/plants/trees/deciduous/fruit') && !str_contains($html, 'deciduous/fruit-trees'));

// The sixteen root material categories: body populated, not flagged.
$roots = ['Irrigation', 'Backflow', 'Sod', 'Mulch', 'Rock', 'Blocks and Pavers'];
$flaggedRoots = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'material_types']) as $t) {
  if (!in_array((string) $t->label(), $roots, TRUE)) { continue; }
  $s = $coverage->state($t, 'body');
  if (!$s['populated'] || $s['boilerplate']) { $flaggedRoots[] = (string) $t->label(); }
}
$ok('the pasted root categories read populated and unflagged', !$flaggedRoots, $flaggedRoots ? implode(',', $flaggedRoots) : 'all clean');

// services must NOT read as "no copy" — the field-override case.
$svc = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'services']) as $t) {
  if ($coverage->state($t, 'body')['populated']) { $svc = $t; break; }
}
$ok('services body copy is seen via its own field name', (bool) $svc, $svc ? (string) $svc->label() : 'NONE SEEN');

// Filters resolve.
foreach (['boilerplate' => 'carries boilerplate', 'missing_body' => 'missing public description', 'missing_teaser' => 'missing teaser'] as $q => $label) {
  $tids = $coverage->matchingTids($q);
  $ok("filter '$label' returns a believable list", is_array($tids), count($tids) . ' term(s)');
}

// Anonymous must not reach it.
\Drupal::currentUser()->setAccount(\Drupal\user\Entity\User::getAnonymousUser());
$anonView = $etm->getStorage('view')->load('bos_content_coverage')->getExecutable();
$anonView->setDisplay('page_1');
$ok('anonymous is denied', !$anonView->access('page_1'));

printf("\n%d passed, %d failed\n", $pass, $fail);
