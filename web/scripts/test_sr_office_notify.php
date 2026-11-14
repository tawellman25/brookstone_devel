<?php

/**
 * @file
 * DEV-ONLY test of the office email on customer-created service requests.
 * Creates three requests, reads what Mailpit received, deletes everything.
 *
 *   ddev drush php:script web/scripts/test_sr_office_notify.php
 *
 * Needs DDEV's Mailpit (HTTP API on 127.0.0.1:8025 inside the web container).
 * Never run on live. Read-only live verification: sr_office_notify_verify.php.
 */

$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  PASS ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$etm = \Drupal::entityTypeManager();
$http = \Drupal::httpClient();
$notifier = \Drupal::service('bos_service_request.office_notifier');
$recipient = $notifier->recipient();
echo "recipient resolves to: $recipient\n";

$api = NULL;
foreach (['http://127.0.0.1:8025', 'http://mailpit:8025', 'http://localhost:8025'] as $base) {
  try {
    $r = $http->get($base . '/api/v1/messages?limit=1', ['timeout' => 4]);
    if ($r->getStatusCode() === 200) { $api = $base; break; }
  }
  catch (\Throwable $e) {}
}
if (!$api) { echo "Mailpit API not reachable from the container — cannot assert mail.\n"; exit(1); }
$count = static fn() => (int) json_decode((string) $http->get($api . '/api/v1/messages?limit=1')->getBody(), TRUE)['total'];
$newest = static function () use ($http, $api): array {
  $list = json_decode((string) $http->get($api . '/api/v1/messages?limit=1')->getBody(), TRUE);
  $id = $list['messages'][0]['ID'] ?? NULL;
  return $id ? json_decode((string) $http->get($api . '/api/v1/message/' . $id)->getBody(), TRUE) : [];
};

$pid = \Drupal::database()->query("SELECT n.entity_id FROM properties__field_nickname n JOIN properties__field_full_address a ON a.entity_id = n.entity_id WHERE n.field_nickname_value <> '' LIMIT 1")->fetchField();
$property = $etm->getStorage('properties')->load($pid);
$nick = trim((string) $property->get('field_nickname')->value);
$status_new = \Drupal::service('bos_service_request.status_resolver')->tid('New');
$zone_label = (string) (\Drupal::service('entity_field.manager')->getFieldDefinitions('service_request', 'sprinkler_winterizing')['field_zone_count']->getSetting('allowed_values')['6_10'] ?? '6_10');

$created = []; $mail_ids = [];
try {
  echo "== 1. winterize request from a customer ==\n";
  $before = $count();
  $sr = $etm->getStorage('service_request')->create([
    'type' => 'sprinkler_winterizing', 'uid' => 0,
    'field_submitted_name' => 'Test Notify Winterize', 'field_submitted_phone' => '970-555-0101', 'field_submitted_email' => 'notify-test@example.com',
    'field_submitted_address' => '123 Test Ln', 'field_submitted_zip' => '81416',
    'field_customer_notes' => "Gate code 1234\nDog in the yard", 'field_access_notes' => 'Side gate',
    'field_zone_count' => '6_10', 'field_water_supply' => 'city', 'field_wants_recurring' => 1, 'field_wants_startup' => 0,
    'field_source' => 'website', 'field_campaign' => 'test26', 'field_property' => $pid, 'field_service' => 369, 'field_service_year' => 2026,
    'field_match_candidates' => '[{"a":1},{"b":2}]',
  ] + ($status_new ? ['field_request_status' => $status_new] : []));
  $sr->save(); $created[] = $sr;
  $ok($count() === $before + 1, 'exactly one email arrived');
  $m = $newest(); $mail_ids[] = $m['ID'] ?? NULL;
  $to = implode(',', array_map(static fn($t) => $t['Address'], $m['To'] ?? []));
  $ok($to === $recipient, "To = $to");
  $ok(($m['Subject'] ?? '') === '[BOS] New Sprinkler Winterizing request — Test Notify Winterize, 123 Test Ln', 'subject: ' . ($m['Subject'] ?? '?'));
  $reply = implode(',', array_map(static fn($t) => $t['Address'], $m['ReplyTo'] ?? []));
  $ok($reply === 'notify-test@example.com', "Reply-To = $reply");
  $text = str_replace("\r\n", "\n", (string) ($m['Text'] ?? ''));
  foreach ([
    'open link' => 'https://brookstoneoutdoors.com/service_request/' . $sr->id(),
    'queue link' => "\nhttps://brookstoneoutdoors.com/admin/office/service-requests\n",
    'report link' => "\nhttps://brookstoneoutdoors.com/admin/office/service-requests/report\n",
    'reference' => 'Reference: ' . $sr->get('field_public_ref')->value,
    'name' => 'Test Notify Winterize', 'phone' => '970-555-0101', 'zip' => '81416',
    'matched property nickname' => $nick,
    'zone label' => $zone_label,
    'boolean Yes' => 'Wants automatic winterizing each fall: Yes',
    'boolean No' => 'Wants spring start-up contact: No',
    'notes line 1' => 'Gate code 1234', 'notes line 2' => 'Dog in the yard',
    'match candidates count' => '2 considered',
    'channel' => 'Website · campaign test26',
  ] as $what => $needle) {
    $ok(str_contains($text, $needle), "body has $what");
  }
  $ok(!str_contains($text, '[{"a":1}') && !str_contains($text, 'Notice version'), 'raw JSON / notice hash not in the body');
  echo "--- body preview ---\n" . implode("\n", array_slice(explode("\n", $text), 0, 14)) . "\n--------------------\n";

  echo "== 2. contact-form problem report ==\n";
  $before = $count();
  $sr2 = $etm->getStorage('service_request')->create([
    'type' => 'general_inquiry', 'uid' => 0, 'field_topic' => 'problem',
    'field_submitted_name' => 'Test Notify Problem', 'field_submitted_phone' => '970-555-0102', 'field_submitted_email' => 'bad email',
    'field_submitted_address' => '9 Broken Head Ct', 'field_customer_notes' => 'Zone 3 geysers every morning', 'field_source' => 'website', 'field_campaign' => 'website', 'field_review_flags' => 'problem_report',
  ] + ($status_new ? ['field_request_status' => $status_new] : []));
  $sr2->save(); $created[] = $sr2;
  $ok($count() === $before + 1, 'one email for the contact problem report');
  $m = $newest(); $mail_ids[] = $m['ID'] ?? NULL;
  $ok(str_starts_with((string) ($m['Subject'] ?? ''), '[BOS] New ⚠ PROBLEM report — Test Notify Problem, 9 Broken Head Ct'), 'subject: ' . ($m['Subject'] ?? '?'));
  $ok(empty($m['ReplyTo']), 'no Reply-To when the submitted email is invalid');
  $ok(str_contains((string) ($m['Text'] ?? ''), 'Zone 3 geysers'), 'message text in body');

  echo "== 3. office-created request (uid 1) ==\n";
  $before = $count();
  $sr3 = $etm->getStorage('service_request')->create([
    'type' => 'sprinkler_winterizing', 'uid' => 1, 'field_submitted_name' => 'Office Entered', 'field_source' => 'phone', 'field_campaign' => 'office',
  ] + ($status_new ? ['field_request_status' => $status_new] : []));
  $sr3->save(); $created[] = $sr3;
  $ok($count() === $before, 'no email for an office-created request');
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
  $fail++;
}
finally {
  echo "== rollback ==\n";
  foreach ($created as $e) { $id = $e->id(); $etm->getStorage('service_request')->load($id)?->delete(); }
  $ids = array_values(array_filter($mail_ids));
  if ($ids) {
    try { $http->delete($api . '/api/v1/messages', ['json' => ['IDs' => $ids]]); } catch (\Throwable $e) { echo "  (mailpit delete failed: {$e->getMessage()})\n"; }
  }
  $left = $etm->getStorage('service_request')->getQuery()->accessCheck(FALSE)->condition('field_submitted_name', ['Test Notify Winterize', 'Test Notify Problem', 'Office Entered'], 'IN')->count()->execute();
  $ok((int) $left === 0, 'test requests deleted');
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
