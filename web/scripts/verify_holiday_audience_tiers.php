<?php

declare(strict_types=1);

/**
 * Does the Holiday Decorations service page show the right body to each
 * audience? Read-only — renders, asserts, creates nothing. Safe on live.
 *
 * The question that matters is the margin copy. It is gated by DISPLAY, not by
 * field access, so the only honest test is to render the page as each audience
 * and look at the markup — not to read the display config and assume.
 *
 *   drush php:script web/scripts/verify_holiday_audience_tiers.php
 */

$pass = $fail = 0;
$ok = function (string $what, bool $cond, string $extra = '') use (&$pass, &$fail) {
  $cond ? $pass++ : $fail++;
  printf("  [%s] %s%s\n", $cond ? 'PASS' : 'FAIL', $what, $extra ? " ($extra)" : '');
};

$path = \Drupal::service('path_alias.manager')->getPathByAlias('/services/christmas-decorations');
preg_match('#(\d+)$#', $path, $m);
$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load((int) $m[1]);
$builder = \Drupal::entityTypeManager()->getViewBuilder('taxonomy_term');
$renderer = \Drupal::service('renderer');
$switcher = \Drupal::service('account_switcher');

// Distinctive strings, one per tier.
$PUBLIC = 'Can we hang lights you already own?';
$CREW = 'Sizing a tree without a tape';
$OFFICE = 'Read this before quoting any figure below';

/** Render the term as a given account, through the real view-mode routing. */
$renderAs = function ($account) use ($term, $builder, $renderer, $switcher): string {
  $switcher->switchTo($account);
  try {
    // Ask for 'full', the mode the canonical page asks for, and let Drupal
    // invoke hook_entity_view_mode_alter during the build exactly as a real
    // request does. Calling the alter by hand needs by-reference arguments and
    // tests my imitation of the routing rather than the routing.
    $html = (string) $renderer->renderInIsolation($builder->view($term, 'full'));
  }
  finally {
    $switcher->switchBack();
  }
  return $html;
};

$findUser = function (array $roles, array $without = []) {
  $q = \Drupal::entityTypeManager()->getStorage('user')->getQuery()->accessCheck(FALSE)->condition('status', 1);
  foreach ($roles as $r) {
    $q->condition('roles', $r);
  }
  foreach (\Drupal::entityTypeManager()->getStorage('user')->loadMultiple($q->range(0, 50)->execute()) as $u) {
    if (!array_intersect($without, $u->getRoles())) {
      return $u;
    }
  }
  return NULL;
};

// --- public ----------------------------------------------------------------
print "Anonymous / public:\n";
$anonHtml = $renderAs(\Drupal\user\Entity\User::load(0));
$ok('sees the public body', str_contains($anonHtml, $PUBLIC));
$ok('does NOT see crew copy', !str_contains($anonHtml, $CREW));
$ok('does NOT see margins', !str_contains($anonHtml, $OFFICE));
$ok('no margin vocabulary at all', !preg_match('/gross margin|renewal GM|burdened lab/i', $anonHtml));
$ok('no literal price tokens', !preg_match('/\[holiday-[a-z-]*\]/', $anonHtml));

// --- crew ------------------------------------------------------------------
print "\nCrew (teammates, no office role):\n";
$crew = $findUser(['teammates'], ['supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator']);
if (!$crew) {
  print "  [skip] no crew-only account on this environment\n";
}
else {
  printf("  using uid %s (%s)\n", $crew->id(), implode(',', $crew->getRoles()));
  $crewHtml = $renderAs($crew);
  $ok('sees the crew copy', str_contains($crewHtml, $CREW));
  $ok('does NOT see margins', !str_contains($crewHtml, $OFFICE));
  $ok('no margin vocabulary at all', !preg_match('/gross margin|renewal GM|burdened lab/i', $crewHtml));
  $ok('no literal price tokens', !preg_match('/\[holiday-[a-z-]*\]/', $crewHtml));
  $ok('crew rate token resolved to money', (bool) preg_match('/\$[0-9]/', $crewHtml));
}

// --- office ----------------------------------------------------------------
print "\nOffice:\n";
$office = $findUser(['administration']) ?: $findUser(['supervisor']);
if (!$office) {
  print "  [skip] no office account found\n";
}
else {
  printf("  using uid %s (%s)\n", $office->id(), implode(',', $office->getRoles()));
  $officeHtml = $renderAs($office);
  $ok('sees the margins', str_contains($officeHtml, $OFFICE));
  $ok('sees the crew copy too', str_contains($officeHtml, $CREW));
  $ok('margins carry the unverified warning', str_contains($officeHtml, 'not verified'));
  $ok('no literal price tokens', !preg_match('/\[holiday-[a-z-]*\]/', $officeHtml));
}

// --- the field is on exactly one display -----------------------------------
print "\nDisplay wiring:\n";
$on = [];
foreach (\Drupal::entityTypeManager()->getStorage('entity_view_display')->loadMultiple() as $id => $vd) {
  if (strpos($id, 'taxonomy_term.services.') === 0 && $vd->getComponent('field_service_office_desc')) {
    $on[] = $id;
  }
}
$ok('office notes render on admin_view and nowhere else', $on === ['taxonomy_term.services.admin_view'], implode(', ', $on) ?: 'none');

// --- field ACCESS, which is what display tiering cannot give ---------------
print "\nField access (the guarantee a display cannot make):\n";
$matrix = [
  'anonymous' => \Drupal\user\Entity\User::load(0),
  'crew' => $crew ?? NULL,
  'office' => $office ?? NULL,
];
foreach ($matrix as $label => $acct) {
  if (!$acct) {
    printf("  [skip] no %s account\n", $label);
    continue;
  }
  $can = $term->get('field_service_office_desc')->access('view', $acct);
  $expected = $label === 'office';
  $ok(sprintf('%-9s %s read the office notes', $label, $expected ? 'CAN' : 'cannot'), $can === $expected,
    $can ? 'allowed' : 'denied');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
