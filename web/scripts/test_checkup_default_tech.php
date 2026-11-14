<?php

/**
 * @file
 * DEV-ONLY end-to-end test of check-up auto-assignment. Writes, then rolls back.
 *
 *   ddev drush php:script web/scripts/test_checkup_default_tech.php
 *
 * Never run on live: it creates a throwaway check-up WO + scheduling record,
 * flips a zipcode's default tech to a non-teammate and back, and deletes what
 * it made. Read-only live verification is checkup_default_tech_verify.php.
 *
 * Covers: resolveDefaultTech (valid / non-teammate / empty), the scheduling
 * record the generator writes (assignee, notify flag off, reason note), the
 * WO timeline entry wo_schedule adds, the My Check-ups tab rendering a card
 * for the assignee on the admin theme, the office gap pages (200 office / 403
 * crew), the hub "Falling through" counts, and the zipcode form widget.
 */

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  PASS ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
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

const TECH = 2826;          // Russell Akers — active teammate.
const CLIENT_UID = 404;     // a client account: must be refused as a default tech.
const OFFICE_UID = 6740;    // supervisor-only.
const CREW_UID = 77735;
$ADMIN_OFFICE_UID = (int) \Drupal::database()->query("SELECT r.entity_id FROM user__roles r JOIN users_field_data u ON u.uid = r.entity_id WHERE r.roles_target_id = 'administration' AND u.status = 1 ORDER BY r.entity_id LIMIT 1")->fetchField();     // teammates-only.
const SVC_BUNDLE = 'sprinkler_check_up';

$worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('contract_residential_checkup_generator');
$rm = static fn(string $m) => (new \ReflectionMethod($worker, $m))->getClosure($worker);
$resolve = $rm('resolveDefaultTech');
$createSched = $rm('createSchedulingRecord');
$logger = \Drupal::logger('test_checkup_default_tech');

// A zipcode with a default and a property in it.
$zip = $etm->getStorage('zipcodes')->loadByProperties(['title' => '81401']);
$zip = reset($zip);
$pid = $db->query("SELECT pz.entity_id FROM properties__field_zipcode_reference pz JOIN properties__field_nickname n ON n.entity_id = pz.entity_id WHERE pz.field_zipcode_reference_target_id = :z LIMIT 1", [':z' => $zip->id()])->fetchField();
$svc_tid = (int) $db->query("SELECT b.entity_id FROM taxonomy_term__field_service_bundle b WHERE b.field_service_bundle_value = :b LIMIT 1", [':b' => SVC_BUNDLE])->fetchField();
echo "zip {$zip->label()} (id {$zip->id()}), property $pid, service tid $svc_tid\n";

$original_default = $zip->get('field_default_sprinkler_tech')->target_id;
$wo = NULL; $sched_ids = []; $su_ids = [];
try {
  echo "== resolveDefaultTech ==\n";
  $ok((int) $resolve($zip, $logger) === TECH, 'valid default (active teammate) → uid ' . TECH);
  $cache = new \ReflectionProperty($worker, 'defaultTechCache'); $cache->setValue($worker, []);
  $zip->set('field_default_sprinkler_tech', CLIENT_UID)->save();
  $ok($resolve($zip, $logger) === NULL, 'client account as default → NULL (refused, warning logged)');
  $cache->setValue($worker, []);
  $zip->set('field_default_sprinkler_tech', NULL)->save();
  $ok($resolve($zip, $logger) === NULL, 'empty default → NULL');
  $cache->setValue($worker, []);
  $zip->set('field_default_sprinkler_tech', TECH)->save();
  $ok($resolve($zip, $logger) === TECH && $resolve($zip, $logger) === TECH, 'restored → uid ' . TECH . ' (memoised second call)');

  echo "== generator scheduling record ==\n";
  $wo = $etm->getStorage('work_order')->create([
    'type' => SVC_BUNDLE, 'uid' => 1, 'field_property' => $pid, 'field_service' => $svc_tid, 'field_status' => 1089, 'field_invoiced' => 0, 'field_scheduled' => 1,
  ]);
  $wo->save(); $wo->set('title', ''); $wo->save();
  $due = new DrupalDateTime('today -3 days', new \DateTimeZone('America/Denver'));
  $createSched((int) $wo->id(), $due, $resolve($zip, $logger), (string) $zip->label());
  $sched_ids = array_values($etm->getStorage('scheduling')->getQuery()->accessCheck(FALSE)->condition('field_work_order', $wo->id())->execute());
  $ok(count($sched_ids) === 1, 'one scheduling record created');
  $sched = $etm->getStorage('scheduling')->load(reset($sched_ids));
  $ok((int) $sched->get('field_assigned_to')->target_id === TECH, 'assigned to uid ' . TECH);
  $ok((int) $sched->get('field_notify_assigned_teammate')->value === 0, 'notify flag 0 — no assignment email');
  $ok(str_contains((string) $sched->get('field_scheduling_note')->value, 'default sprinkler tech for zip 81401'), 'reason note on the record: ' . $sched->get('field_scheduling_note')->value);
  $ok((int) $sched->get('field_scheduled')->value === 1 && (int) $sched->get('field_scheduled_firm')->value === 0, 'scheduled=1, firm=0 as before');
  $su_ids = array_values($etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)->condition('field_status_of_wo', $wo->id())->execute());
  $notes = array_map(static fn($s) => (string) $s->get('field_status_change_note')->value, $etm->getStorage('wo_status_updates')->loadMultiple($su_ids));
  $ok((bool) array_filter($notes, static fn($n) => str_starts_with($n, 'Assigned to Russell Akers')), 'WO timeline has "Assigned to Russell Akers (Scheduled)" — notes: ' . implode(' | ', $notes));
  $wo = $etm->getStorage('work_order')->load($wo->id());
  $ok((int) $wo->get('field_status')->target_id === 1091 || (int) $wo->get('field_status')->target_id === 1089, 'WO status is Scheduled or Open (tid ' . $wo->get('field_status')->target_id . ')');

  echo "== pages ==\n";
  $ok(\Drupal::service('router.route_provider')->getRouteByName('view.my_check_ups.page_1')->getOption('_admin_route') === TRUE, 'route view.my_check_ups.page_1 carries _admin_route (RouteSubscriber)');
[$code, $html] = $get('/teammates/calendar/my-check-ups', TECH);
  $ok($code === 200, "My Check-ups as the tech → $code");
  $ok(str_contains($html, 'my-schedule-card') && str_contains($html, 'my-checkups-overdue') && str_contains($html, '3 days overdue'), 'renders the card with a "3 days overdue" badge');
  $ok(str_contains($html, 'brookstone_admin') || str_contains($html, 'claro'), 'admin theme on the tab');
  $ok(str_contains($html, 'My Schedule') && str_contains($html, 'My Check-ups'), 'sibling tabs present (My Schedule + My Check-ups)');
  [$code, $html] = $get('/teammates/calendar/my-check-ups', CREW_UID);
  $ok($code === 200 && str_contains($html, 'No open check-ups are assigned to you'), "crew-only user with nothing assigned → $code + empty text");
  [$code, $html] = $get('/admin/office/work-orders/scheduling/gaps', OFFICE_UID);
  $ok($code === 200 && str_contains($html, 'dated, nobody assigned'), "gaps page as office → $code");
  [$code, $html] = $get('/admin/office/work-orders/scheduling/never-scheduled', OFFICE_UID);
  $ok($code === 200 && str_contains($html, 'never scheduled'), "never-scheduled page as office → $code");
  [$code] = $get('/admin/office/work-orders/scheduling/gaps', CREW_UID);
  $ok($code === 403, "gaps page as crew → $code");
  [$code, $html] = $get('/admin/office/work-orders/scheduling', OFFICE_UID);
  $ok($code === 200 && str_contains($html, 'Falling through') && preg_match('/\d+ dated, nobody assigned/', $html) && preg_match('/\d+ open 90\+ days, never scheduled/', $html), "hub as office → $code with both gap counts");
  [$code, $html] = $get($zip->toUrl('edit-form')->toString(), $ADMIN_OFFICE_UID);
  $ok($code === 200 && str_contains($html, 'field_default_sprinkler_tech') && str_contains($html, 'Russell Akers'), "zipcode edit form as administration uid $ADMIN_OFFICE_UID → $code, shows the default tech widget prefilled");
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
  $fail++;
}
finally {
  echo "== rollback ==\n";
  foreach ($etm->getStorage('wo_status_updates')->loadMultiple($su_ids) as $s) { $s->delete(); }
  foreach ($etm->getStorage('scheduling')->loadMultiple($sched_ids) as $s) { $s->delete(); }
  if ($wo) {
    $left = $etm->getStorage('wo_status_updates')->getQuery()->accessCheck(FALSE)->condition('field_status_of_wo', $wo->id())->execute();
    foreach ($etm->getStorage('wo_status_updates')->loadMultiple($left) as $s) { $s->delete(); }
    $etm->getStorage('work_order')->load($wo->id())?->delete();
  }
  $zip = $etm->getStorage('zipcodes')->load($zip->id());
  if ((int) $zip->get('field_default_sprinkler_tech')->target_id !== (int) $original_default) {
    $zip->set('field_default_sprinkler_tech', $original_default)->save();
  }
  $ok($wo ? !$etm->getStorage('work_order')->load($wo->id()) : TRUE, 'throwaway WO gone');
  $ok(empty($etm->getStorage('scheduling')->getQuery()->accessCheck(FALSE)->condition('field_work_order', $wo ? $wo->id() : 0)->execute()), 'its scheduling record gone');
  $ok((int) $etm->getStorage('zipcodes')->load($zip->id())->get('field_default_sprinkler_tech')->target_id === (int) $original_default, 'zipcode default restored to ' . $original_default);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
