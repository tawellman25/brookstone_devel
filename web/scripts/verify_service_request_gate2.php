<?php

/**
 * Gate 2 verifier — service layer (idempotent; BOS verifier standard, not PHPUnit).
 *
 * Read-only checks run on any environment. The conversion test CREATES and then
 * deletes a real Work Order, so it is DEV-ONLY, opt-in via env:
 *   SR_TEST_CONVERT=1 ddev drush php:script web/scripts/verify_service_request_gate2.php
 * Live (read-only):
 *   drush php:script web/scripts/verify_service_request_gate2.php
 */

use Drupal\bos_service_request\Service\EligibilityResult;
use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail) {
  printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
  $cond ? $pass++ : $fail++;
  return $cond;
};
$TERM = 369; $YEAR = 2026;

print "== 1. normalizer extraction + DI parity ==\n";
$norm = \Drupal::service('bos_wo_intake.property_normalizer');
$ok("normalizeText('  Foo, BAR! ') === 'foo bar'", $norm->normalizeText('  Foo, BAR! ') === 'foo bar');
$ok("normalizeStreet non-empty + lowercase", (function () use ($norm) { $s = $norm->normalizeStreet('123 Main St'); return $s !== '' && $s === strtolower($s); })());
$intake = \Drupal::service('bos_wo_intake.intake');
$ok("bos_wo_intake.intake instantiates (normalizer injected)", $intake instanceof \Drupal\bos_wo_intake\Service\WorkOrderIntakeService);
$smoke = $intake->createFromText('winterize for Nobody at 999 Nowhere Rd', \Drupal\user\Entity\User::load(1), []);
$ok("createFromText still returns a structured result (no fatal)", is_array($smoke) && (isset($smoke['status']) || isset($smoke['success'])));

print "== 2. status resolver ==\n";
$resolver = \Drupal::service('bos_service_request.status_resolver');
$ok("tid('New') > 0", $resolver->tid(ServiceRequestStatusResolver::NEW) > 0);
$threw = FALSE;
try { $resolver->tid('Nonexistent Status'); } catch (\Throwable $e) { $threw = TRUE; }
$ok("tid('Nonexistent') throws", $threw);

print "== 3. eligibility ==\n";
$elig = \Drupal::service('bos_service_request.eligibility');
// (a) contract-covered fixture: a 369 section wanting=Yes on a current-year residential contract.
$coveredPid = NULL;
$secIds = \Drupal::entityQuery('contract_sections')->accessCheck(FALSE)
  ->condition('field_service', $TERM)->condition('field_do_you_want', '1')->sort('id', 'ASC')->range(0, 200)->execute();
foreach (\Drupal::entityTypeManager()->getStorage('contract_sections')->loadMultiple($secIds) as $sec) {
  $cid = $sec->get('field_contract')->target_id ?? NULL;
  if (!$cid) { continue; }
  $c = \Drupal::entityTypeManager()->getStorage('contracts')->load($cid);
  if ($c && $c->bundle() === 'residential' && (int) ($c->get('field_contract_year')->value ?? 0) === $YEAR
    && in_array((int) ($c->get('field_contract_status')->target_id ?? 0), \Drupal\bos_service_request\Service\ServiceRequestEligibility::COVERED_CONTRACT_STATUS_TIDS, TRUE)
    && !$c->get('field_property')->isEmpty()) {
    $coveredPid = (int) $c->get('field_property')->target_id; break;
  }
}
if ($coveredPid) {
  $r = $elig->evaluate($coveredPid, $TERM, $YEAR);
  $ok("covered property (pid $coveredPid) → already_covered (path={$r->coveragePath})", $r->outcome === EligibilityResult::ALREADY_COVERED);
} else { print "  [SKIP] no current-year covered fixture found\n"; }

// (b) no_services fixture.
$nsIds = \Drupal::entityQuery('properties')->accessCheck(FALSE)->condition('type', 'property')->condition('field_no_services', 1)->sort('id', 'ASC')->range(0, 1)->execute();
if ($nsIds) {
  $r = $elig->evaluate((int) reset($nsIds), $TERM, $YEAR);
  $ok("no_services property → not_eligible + flag", $r->outcome === EligibilityResult::NOT_ELIGIBLE && in_array('no_services', $r->flags, TRUE));
} else { print "  [SKIP] no field_no_services fixture\n"; }

// (c) an eligible property (iterate until one is eligible).
$eligiblePid = NULL;
// Skip very-low ids (template/edge-case properties) so the conversion fixture is realistic.
$scan = \Drupal::entityQuery('properties')->accessCheck(FALSE)->condition('type', 'property')->condition('id', 1000, '>')->sort('id', 'ASC')->range(0, 300)->execute();
foreach ($scan as $pid) {
  $r = $elig->evaluate((int) $pid, $TERM, $YEAR);
  if ($r->isEligible()) { $eligiblePid = (int) $pid; break; }
}
$ok("found an eligible property to convert (pid " . ($eligiblePid ?? 'none') . ")", $eligiblePid !== NULL);

print "== 4. property matcher ==\n";
$matcher = \Drupal::service('bos_service_request.property_matcher');
// Round-trip a real property's own street+zip → should match itself.
$rt = \Drupal::entityQuery('properties')->accessCheck(FALSE)->condition('type', 'property')
  ->exists('field_street_address')->exists('field_zipcode_reference')->sort('id', 'ASC')->range(0, 1)->execute();
if ($rt) {
  $p = \Drupal::entityTypeManager()->getStorage('properties')->load(reset($rt));
  $street = (string) $p->get('field_street_address')->value;
  $zipEntity = $p->get('field_zipcode_reference')->entity;
  $zip = $zipEntity ? (string) $zipEntity->get('field_zipcode')->value : '';
  $m = $matcher->match('', $street, $zip);
  $ok("self street+zip → matched to itself (pid {$p->id()})", $m['status'] === 'matched' && $m['property_id'] === (int) $p->id());
}
$mg = $matcher->match('Nobody', '99999 Nonexistent Parkway', '00000');
$ok("garbage address → unmatched + flag", $mg['status'] === 'unmatched' && in_array('unmatched_property', $mg['flags'], TRUE));

print "== 5. converter (DEV-ONLY, opt-in) ==\n";
if (getenv('SR_TEST_CONVERT') === '1' && $eligiblePid) {
  $srStorage = \Drupal::entityTypeManager()->getStorage('service_request');
  $sr = $srStorage->create([
    'type' => 'sprinkler_winterizing', 'uid' => 0,
    'field_property' => $eligiblePid, 'field_service' => $TERM, 'field_service_year' => $YEAR,
    'field_source' => 'office', 'field_submitted_name' => 'GATE2 TEST', 'field_customer_notes' => 'Test conversion — please delete.',
    'field_request_status' => $resolver->tid(ServiceRequestStatusResolver::NEW),
  ]);
  $sr->save();
  $conv = \Drupal::service('bos_service_request.converter');
  $res1 = $conv->convert($srStorage->loadUnchanged($sr->id()), \Drupal\user\Entity\User::load(1));
  if (($res1['status'] ?? '') !== 'converted') { print "    convert result: " . json_encode($res1) . "\n"; }
  $ok("convert → status=converted with WO id", ($res1['status'] ?? '') === 'converted' && !empty($res1['work_order_id']));
  $woId = $res1['work_order_id'] ?? 0;
  if ($woId) {
    $wo = \Drupal::entityTypeManager()->getStorage('work_order')->loadUnchanged($woId);
    $ok("WO bundle=sprinkler_winterizing, status Open(1089)", $wo->bundle() === 'sprinkler_winterizing' && (int) $wo->get('field_status')->target_id === 1089);
    $noteIds = \Drupal::entityQuery('wo_notes')->accessCheck(FALSE)->condition('field_work_order', $woId)->execute();
    $ok("trace note attached to WO", count($noteIds) >= 1);
    // idempotency
    $res2 = $conv->convert($srStorage->loadUnchanged($sr->id()), \Drupal\user\Entity\User::load(1));
    $ok("re-convert → already_converted (no 2nd WO)", ($res2['status'] ?? '') === 'already_converted');
    $woCount = \Drupal::entityQuery('work_order')->accessCheck(FALSE)->condition('type', 'sprinkler_winterizing')->condition('field_property', $eligiblePid)->count()->execute();
    // cleanup
    if ($noteIds) { \Drupal::entityTypeManager()->getStorage('wo_notes')->delete(\Drupal::entityTypeManager()->getStorage('wo_notes')->loadMultiple($noteIds)); }
    $wo->{'_skip_invoiced_guard'} = TRUE; $wo->delete();
    print "    cleaned WO $woId + note(s)\n";
  }
  $sr->delete();
  print "    cleaned test service_request {$sr->id()}\n";
}
else { print "  [SKIP] set SR_TEST_CONVERT=1 on DEV to run the WO-creating conversion test\n"; }

printf("\n== RESULT: %d passed, %d failed ==\n", $pass, $fail);
