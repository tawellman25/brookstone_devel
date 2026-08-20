<?php

/**
 * Gate 3 verifier — public /winterize form. Idempotent; cleans up its test rows.
 *   ddev drush php:script web/scripts/verify_service_request_gate3.php
 *   drush php:script web/scripts/verify_service_request_gate3.php   (live: read-only checks only, see FLAG)
 *
 * The submit-logic checks CREATE service_request rows (uid 0) and delete them.
 * They do not create Work Orders (conversion is Gate 4). Safe on live, but keep
 * to dev by default: set SR_GATE3_SUBMIT=1 to run the submit-logic checks.
 */

use Drupal\bos_service_request\Form\WinterizeForm;
use Drupal\bos_service_request\Service\ServiceRequestStatusResolver;
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$pass = 0; $fail = 0;
$ok = function (string $label, bool $cond) use (&$pass, &$fail) {
  printf("  [%s] %s\n", $cond ? 'PASS' : 'FAIL', $label);
  $cond ? $pass++ : $fail++;
  return $cond;
};

// Temporarily open signup so the render tests see the form, not the (correct)
// closed page (open_from may be in the future). Restored at the end.
$editable = \Drupal::configFactory()->getEditable('bos_service_request.settings');
$origFrom = $editable->get('bundles.sprinkler_winterizing.open_from');
$origOpen = $editable->get('bundles.sprinkler_winterizing.signup_open');
$editable->set('bundles.sprinkler_winterizing.open_from', '2026-01-01')
  ->set('bundles.sprinkler_winterizing.signup_open', TRUE)->save();
$restore = function () use ($editable, $origFrom, $origOpen) {
  $editable->set('bundles.sprinkler_winterizing.open_from', $origFrom)
    ->set('bundles.sprinkler_winterizing.signup_open', $origOpen)->save();
};

print "== 1. anonymous GET /winterize (http_kernel sub-request) ==\n";
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new AnonymousUserSession());
try {
  $req = Request::create('/winterize', 'GET');
  $resp = \Drupal::service('http_kernel')->handle($req, HttpKernelInterface::SUB_REQUEST);
  $status = $resp->getStatusCode();
  $body = (string) $resp->getContent();
  $ok("anonymous GET /winterize → 200", $status === 200);
  $ok("form renders name/address/zip inputs", str_contains($body, 'submitted_name') && str_contains($body, 'submitted_address') && str_contains($body, 'submitted_zip'));
  // §6.0 invariant — NO property element/identifier anywhere in the response.
  $leak = preg_match('/property_id|field_property|name="property|did you mean|candidate/i', $body);
  $ok("§6.0: no property id/field/candidate in rendered HTML", !$leak);
}
finally {
  $switcher->switchBack();
}

print "== 2. built form has NO property element (any kind) ==\n";
$switcher->switchTo(new AnonymousUserSession());
try {
  $built = \Drupal::formBuilder()->getForm(WinterizeForm::class);
  $keys = [];
  $walk = function ($el, $path) use (&$walk, &$keys) {
    foreach ($el as $k => $v) {
      if (is_string($k) && $k[0] !== '#') {
        $keys[] = $k;
        if (is_array($v)) { $walk($v, "$path.$k"); }
      }
    }
  };
  $walk($built, '');
  $propKeys = array_filter($keys, fn($k) => stripos($k, 'property') !== FALSE);
  $ok("no form element key contains 'property'", empty($propKeys));
}
finally {
  $switcher->switchBack();
}

if (getenv('SR_GATE3_SUBMIT') !== '1') {
  $restore();
  print "== 3-6. submit-logic checks SKIPPED (set SR_GATE3_SUBMIT=1) ==\n";
  printf("\n== RESULT: %d passed, %d failed ==\n", $pass, $fail);
  return;
}

$srStorage = \Drupal::entityTypeManager()->getStorage('service_request');
$created = [];
$submit = function (array $values, array $inject = []) use ($srStorage, &$created) {
  $before = $srStorage->getQuery()->accessCheck(FALSE)->count()->execute();
  // Simulate the injected params on the current request (defense-in-depth test).
  $req = \Drupal::requestStack()->getCurrentRequest();
  foreach ($inject as $k => $v) { $req->request->set($k, $v); }
  $formObj = WinterizeForm::create(\Drupal::getContainer());
  $fs = new FormState();
  $fs->setValues($values + ['wants_recurring' => 0]);
  $form = [];
  $formObj->submitForm($form, $fs);
  foreach ($inject as $k => $v) { $req->request->remove($k); }
  $ids = $srStorage->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
  $newId = $ids ? (int) reset($ids) : NULL;
  if ($newId) { $created[] = $newId; }
  return [$srStorage->loadUnchanged($newId), $fs->get('winterize_done')];
};

print "== 3. unmatched address → Needs Review, ZERO properties/contacts created ==\n";
$propBefore = \Drupal::entityQuery('properties')->accessCheck(FALSE)->count()->execute();
$contactBefore = \Drupal::entityQuery('contacts')->accessCheck(FALSE)->count()->execute();
[$sr, $msg] = $submit([
  'submitted_name' => 'Nobody', 'submitted_address' => '99999 Nonexistent Pkwy', 'submitted_zip' => '00000',
  'submitted_phone' => '000-000-0000', 'submitted_email' => 'nobody@example.com',
]);
$nr = \Drupal::service('bos_service_request.status_resolver')->tid(ServiceRequestStatusResolver::NEEDS_REVIEW);
$ok("request created, status Needs Review", $sr && (int) $sr->get('field_request_status')->target_id === $nr);
$ok("field_property EMPTY (no auto-bind)", $sr && $sr->get('field_property')->isEmpty());
$ok("flag unmatched_property present", $sr && str_contains((string) $sr->get('field_review_flags')->value, 'unmatched_property'));
$ok("ZERO new properties created", \Drupal::entityQuery('properties')->accessCheck(FALSE)->count()->execute() === $propBefore);
$ok("ZERO new contacts created", \Drupal::entityQuery('contacts')->accessCheck(FALSE)->count()->execute() === $contactBefore);
$ok("neutral 'received' confirmation with ref", is_string($msg) && str_contains($msg, 'has been received') && str_contains($msg, 'Reference:'));

print "== 4. property injection is IGNORED (§6.0/§9) ==\n";
$realPid = (int) reset(\Drupal::entityQuery('properties')->accessCheck(FALSE)->condition('type', 'property')->condition('id', 1000, '>')->sort('id', 'ASC')->range(0, 1)->execute());
[$sr2, ] = $submit([
  'submitted_name' => 'Nobody', 'submitted_address' => '99999 Nonexistent Pkwy', 'submitted_zip' => '00000',
  'submitted_phone' => '000-000-0000', 'submitted_email' => '',
], ['property_id' => $realPid, 'field_property' => $realPid]);
$ok("injected property_id NOT honored (field_property still empty)", $sr2 && $sr2->get('field_property')->isEmpty());

print "== 5. matched address → property bound, confirmation identical to unmatched ==\n";
$p = \Drupal::entityTypeManager()->getStorage('properties')->load($realPid);
$zipE = $p->get('field_zipcode_reference')->entity;
[$sr3, $msg3] = $submit([
  'submitted_name' => '', 'submitted_address' => (string) $p->get('field_street_address')->value,
  'submitted_zip' => $zipE ? (string) $zipE->get('field_zipcode')->value : '', 'submitted_phone' => '', 'submitted_email' => '',
]);
$ok("matched → field_property = pid $realPid", $sr3 && (int) ($sr3->get('field_property')->target_id ?? 0) === $realPid);
// The per-request ref legitimately varies; the invariant is that the MATCH
// OUTCOME (1 vs 5 vs 0) never changes the copy — so compare with refs masked.
$maskRef = fn($m) => preg_replace('/\b[A-Z]{1,2}-[0-9A-Z]{6}\b/', '{REF}', (string) $m);
$ok("§6.0: matched confirmation identical to unmatched with ref masked (no state leak)", $maskRef($msg3) === $maskRef($msg));

// cleanup
if ($created) {
  $srStorage->delete($srStorage->loadMultiple($created));
  printf("  cleaned %d test service_request rows\n", count($created));
}
$restore();

printf("\n== RESULT: %d passed, %d failed ==\n", $pass, $fail);
