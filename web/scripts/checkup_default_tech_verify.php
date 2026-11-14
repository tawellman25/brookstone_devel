<?php

/**
 * @file
 * READ-ONLY verification of check-up auto-assignment (safe on live).
 *
 *   drush php:script web/scripts/checkup_default_tech_verify.php
 *
 * Reads config + data and renders pages through the kernel as three accounts
 * (the tech, a crew-only teammate, an office user). No entity is created or
 * saved. Writes-and-rolls-back testing is test_checkup_default_tech.php (dev).
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\User;
use Drupal\views\Entity\View;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$etm = \Drupal::entityTypeManager();
$switcher = \Drupal::service('account_switcher');
$kernel = \Drupal::service('http_kernel');
$get = static function (string $path, int $uid) use ($switcher, $kernel): array {
  $switcher->switchTo(User::load($uid));
  // A drush process resolves the active theme once (the first render, often a
  // token/mail render with no route → the front theme) and keeps it; reset so
  // each routed GET negotiates its own theme like a real request would.
  \Drupal::theme()->resetActiveTheme();
  try {
    $resp = $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::SUB_REQUEST);
    return [$resp->getStatusCode(), (string) $resp->getContent()];
  }
  finally {
    $switcher->switchBack();
  }
};
const TECH = 2826;       // Russell Akers.
const OFFICE_UID = 6740; // supervisor.
const CREW_UID = 77735;
$ADMIN_OFFICE_UID = (int) \Drupal::database()->query("SELECT r.entity_id FROM user__roles r JOIN users_field_data u ON u.uid = r.entity_id WHERE r.roles_target_id = 'administration' AND u.status = 1 ORDER BY r.entity_id LIMIT 1")->fetchField();  // teammates only.

echo "== config ==\n";
$ok((bool) FieldStorageConfig::loadByName('zipcodes', 'field_default_sprinkler_tech'), 'storage zipcodes.field_default_sprinkler_tech');
$ok((bool) FieldConfig::loadByName('zipcodes', 'zipcode', 'field_default_sprinkler_tech'), 'instance zipcode.field_default_sprinkler_tech');
$form = $etm->getStorage('entity_form_display')->load('zipcodes.zipcode.default');
$ok($form && $form->getComponent('field_default_sprinkler_tech'), 'widget on the zipcode form');
foreach (['my_check_ups', 'scheduling_gaps', 'wo_never_scheduled'] as $vid) {
  $v = View::load($vid);
  $ok($v && $v->status(), "view $vid exists and is enabled" . ($v ? ' (/' . ($v->getDisplay('page_1')['display_options']['path'] ?? '?') . ')' : ''));
}

echo "== data ==\n";
$lines = []; $missing = 0;
foreach ($etm->getStorage('zipcodes')->loadMultiple() as $z) {
  $u = $z->hasField('field_default_sprinkler_tech') ? $z->get('field_default_sprinkler_tech')->entity : NULL;
  $lines[] = $z->label() . '→' . ($u ? $u->getDisplayName() : '—');
  if (!$u) { $missing++; }
}
echo '  defaults: ' . implode(' · ', $lines) . "\n";
$ok($missing === 0, 'every zipcode has a default tech');
$tech = User::load(TECH);
$ok($tech && $tech->isActive() && $tech->hasRole('teammates'), 'uid ' . TECH . ' is an active teammate (' . ($tech ? $tech->getDisplayName() : '?') . ')');

echo "== pages (routed GET as a user; nothing written) ==\n";
$ok(\Drupal::service('router.route_provider')->getRouteByName('view.my_check_ups.page_1')->getOption('_admin_route') === TRUE, 'route view.my_check_ups.page_1 carries _admin_route (RouteSubscriber)');
[$code, $html] = $get('/teammates/calendar/my-check-ups', TECH);
$cards = substr_count($html, 'my-schedule-card ');
$ok($code === 200, "My Check-ups as the tech → $code" . ($code === 200 ? " ($cards card(s)" . (str_contains($html, 'No open check-ups are assigned to you') ? ', empty text shown' : '') . ')' : ''));
$ok(str_contains($html, 'brookstone_admin') || str_contains($html, 'claro'), 'admin theme on the tab');
$ok(str_contains($html, 'My Check-ups') && str_contains($html, 'My Schedule'), 'tab row shows My Schedule + My Check-ups');
[$code, $html] = $get('/teammates/calendar/my-check-ups', CREW_UID);
$ok($code === 200, "My Check-ups as crew-only → $code");
[$code, $html] = $get('/admin/office/work-orders/scheduling/gaps', OFFICE_UID);
$ok($code === 200 && str_contains($html, 'dated, nobody assigned'), "gaps page as office → $code");
[$code, $html] = $get('/admin/office/work-orders/scheduling/never-scheduled', OFFICE_UID);
$ok($code === 200 && str_contains($html, 'never scheduled'), "never-scheduled page as office → $code");
[$code] = $get('/admin/office/work-orders/scheduling/gaps', CREW_UID);
$ok($code === 403, "gaps page as crew → $code");
[$code, $html] = $get('/admin/office/work-orders/scheduling', OFFICE_UID);
preg_match('/(\d+) dated, nobody assigned/', $html, $m1); preg_match('/(\d+) open 90\+ days, never scheduled/', $html, $m2);
$ok($code === 200 && $m1 && $m2, "hub as office → $code; falling through: " . ($m1[1] ?? '?') . ' dated-unassigned, ' . ($m2[1] ?? '?') . ' never-scheduled');
$zip = $etm->getStorage('zipcodes')->loadByProperties(['title' => '81401']); $zip = reset($zip);
[$code, $html] = $get($zip->toUrl('edit-form')->toString(), $ADMIN_OFFICE_UID);
$ok($code === 200 && str_contains($html, 'field_default_sprinkler_tech'), "zipcode 81401 edit form as administration uid $ADMIN_OFFICE_UID → $code, widget present");
echo "\n$pass ok, $fail failed\n";
exit($fail ? 1 : 0);
