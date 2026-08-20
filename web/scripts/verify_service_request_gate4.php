<?php

/**
 * Gate 4 verifier — office workflow. Idempotent; cleans up.
 * DEV: SR_GATE4_CONVERT=1 runs the WO-creating end-to-end convert.
 *   ddev exec bash -c 'SR_GATE4_CONVERT=1 drush php:script web/scripts/verify_service_request_gate4.php'
 * LIVE (read-only access checks only): drush php:script web/scripts/verify_service_request_gate4.php
 */

use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\user\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail) {
  printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
  $cond ? $pass++ : $fail++;
};
$resolver = \Drupal::service('bos_service_request.status_resolver');
$srStorage = \Drupal::entityTypeManager()->getStorage('service_request');
$switcher = \Drupal::service('account_switcher');
$kernel = \Drupal::service('http_kernel');
$get = function (string $path, $account) use ($switcher, $kernel): int {
  $switcher->switchTo($account);
  try {
    // catch=TRUE → the kernel turns 403/404 exceptions into real responses.
    return $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::SUB_REQUEST, TRUE)->getStatusCode();
  } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    return $e->getStatusCode();
  } catch (\Throwable $e) {
    return 500;
  } finally {
    $switcher->switchBack();
  }
};

// Fixture: an eligible property + a test request pointing at it.
$pid = NULL;
$elig = \Drupal::service('bos_service_request.eligibility');
foreach (\Drupal::entityQuery('properties')->accessCheck(FALSE)->condition('type', 'property')->condition('id', 1000, '>')->sort('id', 'ASC')->range(0, 300)->execute() as $c) {
  if ($elig->evaluate((int) $c, 369, 2026)->isEligible()) { $pid = (int) $c; break; }
}
$sr = $srStorage->create([
  'type' => 'sprinkler_winterizing', 'uid' => 0, 'field_property' => $pid, 'field_service' => 369,
  'field_service_year' => 2026, 'field_source' => 'office', 'field_submitted_name' => 'GATE4 TEST',
  'field_request_status' => $resolver->tid(ServiceRequestStatusResolver::NEEDS_REVIEW),
]);
$sr->save();
$id = (int) $sr->id();

print "== 1. admin queue view access ==\n";
$ok("admin GET /admin/operations/service-requests → 200", $get('/admin/operations/service-requests', User::load(1)) === 200);
$ok("anon GET queue → 403", $get('/admin/operations/service-requests', new AnonymousUserSession()) === 403);

print "== 2. convert + action route access ==\n";
$ok("admin GET convert confirm → 200", $get("/admin/operations/service-requests/$id/convert", User::load(1)) === 200);
$ok("anon GET convert → 403", $get("/admin/operations/service-requests/$id/convert", new AnonymousUserSession()) === 403);
$ok("admin GET reject confirm → 200", $get("/admin/operations/service-requests/$id/reject", User::load(1)) === 200);
$ok("bad op → 404", $get("/admin/operations/service-requests/$id/frobnicate", User::load(1)) === 404);

print "== 3. operations links ==\n";
$switcher->switchTo(User::load(1));
$ops = bos_service_request_entity_operation($srStorage->loadUnchanged($id));
$switcher->switchBack();
$ok("non-converted request exposes Convert + 3 secondary ops", isset($ops['sr_convert'], $ops['sr_already_covered'], $ops['sr_duplicate'], $ops['sr_reject']));

print "== 4. presave guard: no Converted without a WO ==\n";
$threw = FALSE;
try {
  $g = $srStorage->loadUnchanged($id);
  $g->set('field_request_status', $resolver->tid(ServiceRequestStatusResolver::CONVERTED));
  $g->save();
} catch (\Throwable $e) { $threw = TRUE; }
$ok("marking Converted with empty field_work_order is blocked", $threw);

print "== 5. end-to-end convert (DEV opt-in) ==\n";
if (getenv('SR_GATE4_CONVERT') === '1') {
  $res = \Drupal::service('bos_service_request.converter')->convert($srStorage->loadUnchanged($id), User::load(1));
  $ok("converter → converted with WO", ($res['status'] ?? '') === 'converted' && !empty($res['work_order_id']));
  $woId = $res['work_order_id'] ?? 0;
  $switcher->switchTo(User::load(1));
  $opsAfter = bos_service_request_entity_operation($srStorage->loadUnchanged($id));
  $switcher->switchBack();
  $ok("converted request exposes NO convert op", empty($opsAfter));
  if ($woId) {
    foreach (\Drupal::entityQuery('wo_notes')->accessCheck(FALSE)->condition('field_work_order', $woId)->execute() as $n) {
      \Drupal::entityTypeManager()->getStorage('wo_notes')->load($n)->delete();
    }
    $wo = \Drupal::entityTypeManager()->getStorage('work_order')->loadUnchanged($woId);
    if ($wo) { $wo->delete(); print "    cleaned WO $woId\n"; }
  }
}
else { print "  [SKIP] set SR_GATE4_CONVERT=1 on DEV for the WO-creating convert\n"; }

// cleanup
$srStorage->loadUnchanged($id)->delete();
print "  cleaned test request $id\n";
printf("\n== RESULT: %d passed, %d failed ==\n", $pass, $fail);
