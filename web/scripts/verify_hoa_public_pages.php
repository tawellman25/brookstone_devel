<?php
/**
 * An HOA common-area page is a showcase. It must never show a customer.
 *
 * On 2026-10-10 all 14 of them were serving, to anonymous visitors, the owner's
 * name and phone number, nine spray-application EVAs, fertilizing and check-up
 * history, service instructions and the staff author's name -- because the
 * `public` view display had collected 24 components it was never meant to have.
 * The view-mode routing was fine; the display was not.
 *
 * So this checks the DISPLAY, which is where the regression actually happens,
 * and renders every HOA as an anonymous visitor to confirm it. Read-only and
 * safe on production.
 */
$etm = \Drupal::entityTypeManager();
$switcher = \Drupal::service('account_switcher');
$renderer = \Drupal::service('renderer');
$pass = 0; $fail = 0;
$ok = function (string $what, bool $good, string $got = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $got !== '' ? " [$got]" : '');
};

// Only these belong on the showcase. Anything else is a regression.
$ALLOWED = [
  'title', 'field_neighborhood', 'field_hoa_public_desc',
  'field_service_start', 'field_service_end', 'property_photos_public_entity_view_1',
];

print "1. the public display carries only showcase components\n";
$display = $etm->getStorage('entity_view_display')->load('properties.hoa.public');
$ok('properties.hoa.public exists and is enabled', $display && $display->status());
if ($display) {
  $extra = array_diff(array_keys($display->getComponents()), $ALLOWED);
  $ok('no component beyond the showcase set', !$extra, $extra ? implode(', ', $extra) : count($display->getComponents()) . ' components');
}
$staff = $etm->getStorage('entity_view_display')->load('properties.hoa.default');
$ok('the STAFF display is still full', $staff && count($staff->getComponents()) > 20,
  $staff ? count($staff->getComponents()) . ' components' : 'missing');

print "\n2. every HOA page, rendered as an anonymous visitor\n";
// Markers that mean a customer is on the page.
$FORBIDDEN = ['Current Owner', 'Fertilizing Information', 'Last System Check',
  'Add Another System', 'Weed Pulling', 'Snow Removal Information', 'Instructions'];
$anon = new \Drupal\Core\Session\AnonymousUserSession();
$switcher->switchTo($anon);
$ids = $etm->getStorage('properties')->getQuery()->accessCheck(FALSE)->condition('type', 'hoa')->sort('id')->execute();
$leaky = [];
foreach ($etm->getStorage('properties')->loadMultiple($ids) as $h) {
  $build = $etm->getViewBuilder('properties')->view($h, 'public');
  $text = strip_tags((string) $renderer->renderPlain($build));
  $hits = [];
  foreach ($FORBIDDEN as $marker) {
    if (stripos($text, $marker) !== FALSE) { $hits[] = $marker; }
  }
  // Any 10-digit number that is not the office line.
  if (preg_match_all('/\b\d{10}\b/', $text, $m)) {
    foreach (array_unique($m[0]) as $num) { if ($num !== '9708359661') { $hits[] = "phone $num"; } }
  }
  if ($hits) { $leaky[$h->id()] = $h->label() . ': ' . implode(', ', $hits); }
}
$switcher->switchBack();
$ok(sprintf('all %d HOA pages free of customer data', count($ids)), !$leaky);
foreach ($leaky as $id => $why) { printf("        LEAK %s %s\n", $id, $why); }

printf("\n%d passed, %d failed\n", $pass, $fail);
