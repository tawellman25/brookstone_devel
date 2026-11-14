<?php

/**
 * @file
 * Verify the office email on customer-created service requests. READ-ONLY by
 * default (safe on live); --send-test sends ONE real email.
 *
 *   drush php:script web/scripts/sr_office_notify_verify.php
 *   drush php:script web/scripts/sr_office_notify_verify.php -- --send-test
 *
 * Read-only: the service resolves, the recipient, the mail hook, the default
 * transport, and a full preview of the email BOS would send for the newest
 * customer-created request (nothing is created or saved).
 * --send-test: sends that preview to the configured recipient with a
 * "[TEST] " subject prefix — one outbound email, no entity change.
 */

$args = $extra ?? [];
$send = in_array('--send-test', $args, TRUE);
$etm = \Drupal::entityTypeManager();
$ok = static fn(bool $c, string $l) => print(($c ? '  ok   ' : '  FAIL ') . $l . "\n");

echo "== wiring ==\n";
$notifier = \Drupal::service('bos_service_request.office_notifier');
$ok($notifier instanceof \Drupal\bos_service_request\Service\OfficeNotifier, 'bos_service_request.office_notifier service');
$to = $notifier->recipient();
$ok(filter_var($to, FILTER_VALIDATE_EMAIL) !== FALSE, "recipient: $to" . (\Drupal::config('bos_service_request.settings')->get('office_notify_email') ? ' (settings)' : ' (site mail fallback)'));
$ok(\Drupal::moduleHandler()->hasImplementations('mail', 'bos_service_request'), 'hook_mail implemented by bos_service_request');
$transport = \Drupal::config('symfony_mailer.settings')->get('default_transport');
echo "  default mail transport: " . ($transport ?: '(core mail)') . "\n";
$ok(!method_exists(\Drupal\bos_service_request\Form\ContactForm::class, 'sendBackstop'), 'contact-form-only backstop retired (single path)');

echo "== preview: newest customer-created request ==\n";
$ids = $etm->getStorage('service_request')->getQuery()->accessCheck(FALSE)->condition('uid', 0)->sort('id', 'DESC')->range(0, 1)->execute();
if (!$ids) {
  echo "  no customer-created request on this environment\n";
  exit(0);
}
$sr = $etm->getStorage('service_request')->load(reset($ids));
$built = $notifier->build($sr);
echo "  request #{$sr->id()} ({$sr->bundle()}), owner uid {$sr->getOwnerId()}\n";
echo "  Subject:  {$built['subject']}\n  Reply-To: " . ($built['reply_to'] ?: '(none)') . "\n  ---\n";
foreach (explode("\n", $built['body']) as $l) { echo "  | $l\n"; }
echo "  ---\n";
$ok(str_contains($built['body'], '/service_request/' . $sr->id()) && str_contains($built['body'], '/admin/office/service-requests'), 'body carries the request link + queue link');

if ($send) {
  echo "== send test ==\n";
  $r = $notifier->notifyNew($sr, NULL, '[TEST] ');
  $ok($r, "sent to $to with a [TEST] prefix — check the inbox");
}
else {
  echo "  (no email sent; add --send-test to send this preview to $to)\n";
}
