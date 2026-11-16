<?php

/**
 * @file
 * DEV-ONLY end-to-end test of bos_quickbooks: a fake Web Connector drives the
 * real SOAP endpoint over HTTPS, the mirror / matching / monitor are asserted,
 * then everything the test created is removed and State is restored.
 *
 *   ddev drush php:script web/scripts/test_bos_quickbooks.php
 *
 * Writes: qb_list rows with ListIDs "ZZTEST-…" (deleted at the end), the
 * connector password (restored), State (restored), field_qb_list_id on the
 * customer profiles of the two real clients it links (restored), two Mailpit
 * messages (deleted). Refuses to run outside DDEV.
 */

use Drupal\bos_quickbooks\QbMonitor;
use Drupal\bos_quickbooks\QbSyncRunner;
use Drupal\bos_quickbooks\QbwcSession;
use Drupal\Core\Url;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  echo "Refusing: dev-only test (writes and deletes).\n";
  exit(1);
}
$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$state = \Drupal::state();
$etm = \Drupal::entityTypeManager();
$storage = $etm->getStorage('qb_list');
$session = \Drupal::service('bos_quickbooks.session');
$monitor = \Drupal::service('bos_quickbooks.monitor');
$matcher = \Drupal::service('bos_quickbooks.matcher');
$mirror = \Drupal::service('bos_quickbooks.mirror');
$http = \Drupal::httpClient();

// ── Mailpit ────────────────────────────────────────────────────────────
$api = NULL;
foreach (['http://127.0.0.1:8025', 'http://mailpit:8025', 'http://localhost:8025'] as $base) {
  try {
    if ($http->get($base . '/api/v1/messages?limit=1', ['timeout' => 4])->getStatusCode() === 200) {
      $api = $base;
      break;
    }
  }
  catch (\Throwable) {
  }
}
if (!$api) {
  echo "Mailpit API not reachable — cannot assert mail.\n";
  exit(1);
}
$mail_latest = static function () use ($http, $api): array {
  $list = json_decode((string) $http->get($api . '/api/v1/messages?limit=1')->getBody(), TRUE);
  $id = $list['messages'][0]['ID'] ?? NULL;
  return $id ? json_decode((string) $http->get($api . '/api/v1/message/' . $id)->getBody(), TRUE) : [];
};
$mail_total = static fn(): int => (int) json_decode((string) $http->get($api . '/api/v1/messages?limit=1')->getBody(), TRUE)['total'];
$mail_ids = [];

// ── Snapshot State to restore ──────────────────────────────────────────
$keys = [
  QbwcSession::STATE_SESSION, QbwcSession::STATE_PASSWORD, QbwcSession::STATE_LAST_CONTACT, QbwcSession::STATE_LAST_COMPANY,
  QbSyncRunner::STATE_LAST_FULL_SYNC, QbSyncRunner::STATE_LAST_RUN, QbSyncRunner::STATE_LAST_ERROR, QbSyncRunner::STATE_LAST_LIST_SYNC,
  QbMonitor::STATE_LAST_ALERT, QbMonitor::STATE_ALERT_OPEN, QbMonitor::STATE_CONNECTOR_VERSION,
];
$snapshot = [];
foreach ($keys as $k) {
  $snapshot[$k] = $state->get($k);
}
$profile_restore = [];
$remember_profile = static function (int $uid) use ($etm, &$profile_restore): void {
  if (isset($profile_restore[$uid])) {
    return;
  }
  $profiles = $etm->getStorage('profile')->loadByProperties(['uid' => $uid, 'type' => 'customer_profile']);
  $p = $profiles ? reset($profiles) : NULL;
  $profile_restore[$uid] = $p ? (string) $p->get('field_qb_list_id')->value : NULL;
};
$profile_value = static function (int $uid) use ($etm): ?string {
  $profiles = $etm->getStorage('profile')->loadByProperties(['uid' => $uid, 'type' => 'customer_profile']);
  $p = $profiles ? reset($profiles) : NULL;
  return $p ? (string) $p->get('field_qb_list_id')->value : NULL;
};

// ── Two real clients for the matcher ───────────────────────────────────
$db = \Drupal::database();
$clients = $db->query("SELECT u.uid, u.name, fn.field_first_name_value f, ln.field_last_name_value l, sp.field_spouse_name_value s
  FROM {users_field_data} u JOIN {user__roles} r ON r.entity_id=u.uid AND r.roles_target_id='client'
  JOIN {profile} p ON p.uid=u.uid AND p.type='customer_profile'
  JOIN {profile__field_first_name} fn ON fn.entity_id=p.profile_id JOIN {profile__field_last_name} ln ON ln.entity_id=p.profile_id
  LEFT JOIN {profile__field_spouse_name} sp ON sp.entity_id=p.profile_id
  WHERE u.status=1 AND sp.field_spouse_name_value IS NOT NULL AND sp.field_spouse_name_value <> '' ORDER BY u.uid LIMIT 2")->fetchAll();
if (count($clients) < 2) {
  echo "Need two active clients with spouse names on dev.\n";
  exit(1);
}
[$c1, $c2] = $clients;
$remember_profile((int) $c1->uid);
$remember_profile((int) $c2->uid);
$qb1 = trim($c1->l) . ', ' . trim($c1->f) . ' & ' . preg_replace('/\s+\w\.?$/', '', trim($c1->s)) . ' EMAIL';
$qb1_dup = trim($c1->l) . ', ' . trim($c1->f) . ' & ' . preg_replace('/\s+\w\.?$/', '', trim($c1->s)) . ' NO SERVICES';
$qb2 = trim($c2->l) . ', ' . trim($c2->f) . ' & ' . preg_replace('/\s+\w\.?$/', '', trim($c2->s));
echo "  using clients uid {$c1->uid} \"$qb1\" and uid {$c2->uid} \"$qb2\"\n";

// ── Fixtures ───────────────────────────────────────────────────────────
$e = static fn(string $s) => htmlspecialchars($s, ENT_XML1);
$common = static fn(string $id, string $name, string $full, bool $active = TRUE, int $sub = 0, string $parent = '') =>
  "<ListID>ZZTEST-$id</ListID><TimeCreated>2026-01-05T10:00:00-07:00</TimeCreated><TimeModified>2026-11-01T08:30:00-07:00</TimeModified><EditSequence>17$id</EditSequence>"
  . '<Name>' . $e($name) . '</Name><FullName>' . $e($full) . '</FullName><IsActive>' . ($active ? 'true' : 'false') . '</IsActive>'
  . ($parent ? "<ParentRef><ListID>ZZTEST-$parent</ListID></ParentRef>" : '') . "<Sublevel>$sub</Sublevel>";
$customer = static fn(string $id, string $name, string $first, string $last, string $company = '', bool $active = TRUE, int $sub = 0, string $parent = '') =>
  '<CustomerRet>' . $common($id, $sub ? $name : $name, $sub ? "$parent:$name" : $name, $active, $sub, $parent)
  . ($company ? '<CompanyName>' . $e($company) . '</CompanyName>' : '') . '<FirstName>' . $e($first) . '</FirstName><LastName>' . $e($last) . '</LastName>'
  . '<BillAddress><Addr1>' . $e($name) . '</Addr1><Addr2>123 Test St</Addr2><City>Grand Junction</City><State>CO</State><PostalCode>81501</PostalCode></BillAddress>'
  . '<Balance>0.00</Balance><CustomerTypeRef><FullName>Residential</FullName></CustomerTypeRef><TermsRef><FullName>Net 15</FullName></TermsRef></CustomerRet>';
$classes_rs = static fn(bool $with_third) => '<?xml version="1.0" ?><QBXML><QBXMLMsgsRs><ClassQueryRs requestID="0-1" statusCode="0" statusSeverity="Info" statusMessage="Status OK">'
  . '<ClassRet>' . $common('C1', 'Maintenance Department', 'Maintenance Department') . '</ClassRet>'
  . '<ClassRet>' . $common('C2', 'Irrigation Department', 'Irrigation Department') . '</ClassRet>'
  . ($with_third ? '<ClassRet>' . $common('C3', '3', '3', FALSE) . '</ClassRet>' : '')
  . '</ClassQueryRs></QBXMLMsgsRs></QBXML>';
$customers_p1 = '<?xml version="1.0" ?><QBXML><QBXMLMsgsRs><CustomerQueryRs requestID="1-1" statusCode="0" statusSeverity="Info" statusMessage="Status OK" iteratorRemainingCount="3" iteratorID="{ZZ-ITER-1}">'
  . $customer('U1', $qb1, trim($c1->f), trim($c1->l))
  . $customer('U2', $qb2, trim($c2->f), trim($c2->l))
  . $customer('U3', 'ZZ Nobody, Test', 'Test', 'ZZ Nobody', 'ZZ Nobody Holdings LLC')
  . '</CustomerQueryRs></QBXMLMsgsRs></QBXML>';
$customers_p2 = '<?xml version="1.0" ?><QBXML><QBXMLMsgsRs><CustomerQueryRs requestID="1-2" statusCode="0" statusSeverity="Info" statusMessage="Status OK" iteratorRemainingCount="0" iteratorID="{ZZ-ITER-1}">'
  . $customer('U4', $qb1_dup, trim($c1->f), trim($c1->l))
  . $customer('U5', 'ZZ Inactive, Old', 'Old', 'ZZ Inactive', '', FALSE)
  . $customer('U6', 'Back Yard Job', 'Test', 'ZZ Nobody', '', TRUE, 1, 'U3')
  . '</CustomerQueryRs></QBXMLMsgsRs></QBXML>';
$items_rs = '<?xml version="1.0" ?><QBXML><QBXMLMsgsRs><ItemQueryRs requestID="2-1" statusCode="0" statusSeverity="Info" statusMessage="Status OK" iteratorRemainingCount="0" iteratorID="{ZZ-ITER-2}">'
  . '<ItemServiceRet>' . $common('I1', 'Maintenance', 'Maintenance') . '<SalesOrPurchase><Desc>Maintenance services</Desc><Price>0.00</Price><AccountRef><FullName>Maintenance Services</FullName></AccountRef></SalesOrPurchase></ItemServiceRet>'
  . '<ItemServiceRet>' . $common('I2', 'Mowing', 'Maintenance:Mowing', TRUE, 1, 'I1') . '<SalesOrPurchase><Desc>Mowing lawn and weed whipping</Desc><Price>45.00</Price><AccountRef><FullName>Maintenance Services</FullName></AccountRef></SalesOrPurchase></ItemServiceRet>'
  . '<ItemOtherChargeRet>' . $common('I3', 'Fuel Surcharge', 'Fuel Surcharge') . '<SalesOrPurchase><Desc>Fuel Surcharge</Desc><Price>3.00</Price><AccountRef><FullName>Maintenance Services</FullName></AccountRef></SalesOrPurchase></ItemOtherChargeRet>'
  . '<ItemInventoryRet>' . $common('I4', 'ZZ Widget', 'ZZ Widget') . '<SalesDesc>A stocked widget</SalesDesc><SalesPrice>12.50</SalesPrice><IncomeAccountRef><FullName>Installation Services</FullName></IncomeAccountRef></ItemInventoryRet>'
  . '<ItemSubtotalRet>' . $common('I5', 'Subtotal', 'Subtotal') . '<ItemDesc>Subtotal line</ItemDesc></ItemSubtotalRet>'
  . '</ItemQueryRs></QBXMLMsgsRs></QBXML>';
$hcp = '<?xml version="1.0" ?><QBXML><QBXMLMsgsRs><HostQueryRs statusCode="0"><HostRet><ProductName>QuickBooks Accountant Desktop 2024</ProductName><MajorVersion>34</MajorVersion><MinorVersion>0</MinorVersion><SupportedQBXMLVersion>16.0</SupportedQBXMLVersion></HostRet></HostQueryRs>'
  . '<CompanyQueryRs statusCode="0"><CompanyRet><CompanyName>ZZ Test Company (restored copy)</CompanyName></CompanyRet></CompanyQueryRs></QBXMLMsgsRs></QBXML>';
$company_file = 'C:\\QB\\ZZ Test Company.qbw';

// ── The fake connector ─────────────────────────────────────────────────
$endpoint = Url::fromRoute('bos_quickbooks.soap', [], ['absolute' => TRUE])->toString();
$endpoint = preg_replace('#^https?://[^/]+#', 'https://brookstone.ddev.site', $endpoint);
$client = new \SoapClient($endpoint . '?wsdl', [
  'location' => $endpoint,
  'trace' => 1,
  'exceptions' => TRUE,
  'cache_wsdl' => WSDL_CACHE_NONE,
  'stream_context' => stream_context_create(['ssl' => ['verify_peer' => FALSE, 'verify_peer_name' => FALSE]]),
]);
$strings = static function ($result): array {
  // ArrayOfString arrives as an array of strings or an object with ->string.
  $v = is_object($result) && isset($result->string) ? $result->string : $result;
  return array_values(array_map('strval', (array) $v));
};
$call = static function (string $op, array $args) use ($client, $state, $storage) {
  $r = $client->__soapCall($op, [(object) $args]);
  // The endpoint ran in the web process; this drush process caches State and
  // loaded entities, so drop both before asserting what the call wrote.
  $state->resetCache();
  $storage->resetCache();
  $prop = $op . 'Result';
  return is_object($r) && property_exists($r, $prop) ? $r->$prop : $r;
};
$cleanup_customers = static function () use ($storage): int {
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('field_list_id', 'ZZTEST-', 'STARTS_WITH')->execute();
  foreach ($storage->loadMultiple($ids) as $e) {
    $e->_bos_qb_sync = TRUE;
    $e->delete();
  }
  return count($ids);
};
$cleanup_customers();

try {
  echo "== protocol over HTTPS ==\n";
  $session->setPassword('zz-test-password-123456');
  $state->delete(QbwcSession::STATE_SESSION);
  $ok(str_starts_with((string) $call('serverVersion', ['strVersion' => '']), 'BOS QuickBooks Sync'), 'serverVersion');
  $ok((string) $call('clientVersion', ['strVersion' => '34.0.10010.76']) === '', 'clientVersion → "" (accepted)');
  $ok($state->get(QbMonitor::STATE_CONNECTOR_VERSION) === '34.0.10010.76', 'connector version recorded');
  $bad = $strings($call('authenticate', ['strUserName' => 'bos', 'strPassword' => 'wrong']));
  $ok($bad === ['', 'nvu'], 'bad password → ["", "nvu"] (' . json_encode($bad) . ')');
  $auth = $strings($call('authenticate', ['strUserName' => 'bos', 'strPassword' => 'zz-test-password-123456']));
  $ticket = $auth[0] ?? '';
  $ok(strlen($ticket) === 32 && ($auth[1] ?? 'x') === '', 'good login → [ticket, ""] (' . json_encode([substr($ticket, 0, 8) . '…', $auth[1] ?? NULL]) . ')');
  $second = $strings($call('authenticate', ['strUserName' => 'bos', 'strPassword' => 'zz-test-password-123456']));
  $ok($second === ['', 'none'], 'a second login while the session is live → ["", "none"]');
  $ok($state->get(QbwcSession::STATE_LAST_CONTACT) >= \Drupal::time()->getRequestTime() - 60, 'last contact stamped');

  $rq = (string) $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => $hcp, 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $ok(str_contains($rq, '<?qbxml version="16.0"?>') && str_contains($rq, '<ClassQueryRq requestID="0-1"><ActiveStatus>All</ActiveStatus></ClassQueryRq>'), 'request 1 = ClassQueryRq, qbXML 16.0, no iterator');
  $company = $session->lastCompany();
  $ok(($company['name'] ?? '') === 'ZZ Test Company (restored copy)' && ($company['file'] ?? '') === $company_file && str_contains($company['product'] ?? '', 'Accountant'), 'company name / file / product recorded from HCP + strCompanyFileName');
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $classes_rs(TRUE), 'hresult' => '', 'message' => '']);
  $ok($p === 33, "classes received → 33 % ($p)");
  $rq = (string) $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $ok(str_contains($rq, '<CustomerQueryRq requestID="1-1" iterator="Start"><MaxReturned>500</MaxReturned><ActiveStatus>All</ActiveStatus>'), 'request 2 = CustomerQueryRq iterator Start');
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $customers_p1, 'hresult' => '', 'message' => '']);
  $ok($p > 33 && $p < 66, "customer page 1 (3 of 6) → between 33 and 66 % ($p)");
  $rq = (string) $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $ok(str_contains($rq, 'iterator="Continue" iteratorID="{ZZ-ITER-1}"'), 'request 3 = CustomerQueryRq iterator Continue with the iteratorID');
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $customers_p2, 'hresult' => '', 'message' => '']);
  $ok($p === 66, "customer page 2 → 66 % ($p)");
  $rq = (string) $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $ok(str_contains($rq, '<ItemQueryRq requestID="2-1" iterator="Start">'), 'request 4 = ItemQueryRq');
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $items_rs, 'hresult' => '', 'message' => '']);
  $ok($p === 100, "items received → 100 % ($p)");
  $ok((string) $call('getLastError', ['ticket' => $ticket]) === 'NoOp', 'getLastError → NoOp (nothing wrong)');
  $ok((string) $call('closeConnection', ['ticket' => $ticket]) === 'OK', 'closeConnection → OK');
  $ok($state->get(QbwcSession::STATE_SESSION) === NULL, 'session cleared');
  $ok((string) $call('getLastError', ['ticket' => $ticket]) === 'Bad ticket.', 'the ticket is dead afterwards');

  echo "== mirror ==\n";
  $counts = $mirror->counts();
  $ok($counts['class']['total'] === 3 && $counts['customer']['total'] === 6 && $counts['item']['total'] === 5, 'counts class 3 / customer 6 / item 5 (' . json_encode(array_map(static fn($c) => $c['total'], $counts)) . ')');
  $ok($counts['class']['active'] === 2 && $counts['customer']['active'] === 5, 'inactive class + inactive customer counted inactive');
  $u1 = $mirror->findByListId('customer', 'ZZTEST-U1');
  $ok($u1 && $u1->label() === $qb1 && $u1->get('field_first_name')->value === trim($c1->f) && str_contains((string) $u1->get('field_bill_address')->value, "123 Test St\nGrand Junction, CO 81501") && $u1->get('field_terms')->value === 'Net 15' && $u1->get('field_match_status')->value === 'unmatched', 'customer U1 fields (title, first, Bill To, terms, status unmatched)');
  $u6 = $mirror->findByListId('customer', 'ZZTEST-U6');
  $ok($u6 && $u6->label() === 'U3:Back Yard Job' && (int) $u6->get('field_sublevel')->value === 1 && $u6->get('field_parent_list_id')->value === 'ZZTEST-U3', 'sub-customer: FullName title, sublevel 1, parent ListID');
  $i2 = $mirror->findByListId('item', 'ZZTEST-I2');
  $ok($i2 && $i2->label() === 'Maintenance:Mowing' && $i2->get('field_item_type')->value === 'Service' && $i2->get('field_income_account')->value === 'Maintenance Services' && (float) $i2->get('field_price')->value === 45.0 && $i2->get('field_description')->value === 'Mowing lawn and weed whipping', 'item Maintenance:Mowing (Service, account, price, description)');
  $i4 = $mirror->findByListId('item', 'ZZTEST-I4');
  $ok($i4 && $i4->get('field_item_type')->value === 'Inventory' && $i4->get('field_income_account')->value === 'Installation Services' && (float) $i4->get('field_price')->value === 12.5, 'inventory item parsed (SalesPrice / IncomeAccountRef)');
  $i5 = $mirror->findByListId('item', 'ZZTEST-I5');
  $ok($i5 && $i5->get('field_item_type')->value === 'Subtotal' && $i5->get('field_description')->value === 'Subtotal line', 'subtotal item parsed (ItemDesc)');
  $ok($u1->get('field_time_modified')->value === '2026-11-01T15:30:00', 'QuickBooks local time stored as UTC (08:30 -07:00 → 15:30)');
  $full = $state->get(QbSyncRunner::STATE_LAST_FULL_SYNC);
  $ok(is_array($full) && $full['status'] === 'ok' && $full['lists']['customer']['returned'] === 6 && $full['lists']['customer']['pages'] === 2, 'last full sync recorded (ok, 6 customers over 2 pages)');
  $ok(!$monitor->isStale(), 'mirror is current');
  $row = bos_quickbooks_runtime_requirements()['bos_quickbooks'];
  $ok($row['severity']->name === 'OK', 'Status report row OK: ' . strip_tags((string) $row['value']));

  echo "== second sync: a class disappears, nothing else changed ==\n";
  $auth = $strings($call('authenticate', ['strUserName' => 'bos', 'strPassword' => 'zz-test-password-123456']));
  $ticket = $auth[0];
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => $hcp, 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $classes_rs(FALSE), 'hresult' => '', 'message' => '']);
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $customers_p1, 'hresult' => '', 'message' => '']);
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $customers_p2, 'hresult' => '', 'message' => '']);
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $items_rs, 'hresult' => '', 'message' => '']);
  $call('closeConnection', ['ticket' => $ticket]);
  $full = $state->get(QbSyncRunner::STATE_LAST_FULL_SYNC);
  $ok($p === 100 && $full['lists']['class']['missing'] === 1 && $full['lists']['customer']['unchanged'] === 6 && $full['lists']['customer']['updated'] === 0, 'second run: 1 class missing, 6 customers unchanged (no saves)');
  $c3 = $mirror->findByListId('class', 'ZZTEST-C3');
  $ok($c3 && (int) $c3->get('field_missing')->value === 1, 'class "3" flagged Missing');
  $counts = $mirror->counts();
  $ok($counts['class']['missing'] === 1 && $counts['class']['active'] === 2, 'counts reflect the missing class');

  echo "== failure paths ==\n";
  $auth = $strings($call('authenticate', ['strUserName' => 'bos', 'strPassword' => 'zz-test-password-123456']));
  $ticket = $auth[0];
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => '', 'hresult' => '0x80040400', 'message' => 'QuickBooks found an error when parsing the provided XML text stream.']);
  $ok($p === -1, "connector hresult → -1 ($p)");
  $err = (string) $call('getLastError', ['ticket' => $ticket]);
  $ok(str_contains($err, '0x80040400'), 'getLastError carries the connector message');
  $ok((string) $call('closeConnection', ['ticket' => $ticket]) === 'OK', 'closeConnection after the error');
  $run = $state->get(QbSyncRunner::STATE_LAST_RUN);
  $ok(is_array($run) && $run['status'] === 'aborted', 'last run recorded as ABORTED; the full-sync record is untouched (' . $run['status'] . ')');
  $ok($state->get(QbSyncRunner::STATE_LAST_FULL_SYNC)['status'] === 'ok', '…and last full sync still ok');
  $auth = $strings($call('authenticate', ['strUserName' => 'bos', 'strPassword' => 'zz-test-password-123456']));
  $ticket = $auth[0];
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $refused = '<?xml version="1.0" ?><QBXML><QBXMLMsgsRs><ClassQueryRs requestID="0-1" statusCode="3250" statusSeverity="Error" statusMessage="This feature is not enabled or not available in this version of QuickBooks."></ClassQueryRs></QBXMLMsgsRs></QBXML>';
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $refused, 'hresult' => '', 'message' => '']);
  $ok($p === 33, "a refused list query fails that list and moves on → 33 % ($p)");
  $rq = (string) $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $ok(str_contains($rq, 'CustomerQueryRq'), '…next request is the customer list');
  $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $customers_p1, 'hresult' => '', 'message' => '']);
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $customers_p2, 'hresult' => '', 'message' => '']);
  $call('sendRequestXML', ['ticket' => $ticket, 'strHCPResponse' => '', 'strCompanyFileName' => $company_file, 'qbXMLCountry' => 'US', 'qbXMLMajorVers' => 16, 'qbXMLMinorVers' => 0]);
  $p = (int) $call('receiveResponseXML', ['ticket' => $ticket, 'response' => $items_rs, 'hresult' => '', 'message' => '']);
  $call('closeConnection', ['ticket' => $ticket]);
  $run = $state->get(QbSyncRunner::STATE_LAST_RUN);
  $ok($p === 100 && $run['status'] === 'partial', 'run with one failed list = PARTIAL, not a full sync');
  $ok($state->get(QbSyncRunner::STATE_LAST_ERROR)['message'] !== '' && str_contains($state->get(QbSyncRunner::STATE_LAST_ERROR)['message'], '3250'), 'last error recorded with the QuickBooks status code');

  echo "== matching pass ==\n";
  $dry = $matcher->run(TRUE);
  // The two ZZ Nobody rows are already Unmatched with no note → "unchanged".
  $ok($dry['dry_run'] && $dry['scanned'] === 5 && $dry['auto'] === 2 && $dry['duplicate'] === 1 && $dry['unmatched'] === 0 && $dry['unchanged'] === 2, 'dry run: 5 scanned (inactive skipped), 2 auto, 1 duplicate, 2 already unmatched (' . json_encode(array_intersect_key($dry, array_flip(['scanned', 'auto', 'ambiguous', 'unmatched', 'duplicate', 'unchanged']))) . ')');
  $ok($mirror->findByListId('customer', 'ZZTEST-U1')->get('field_bos_client')->isEmpty(), 'dry run wrote nothing');
  $real = $matcher->run();
  $ok($real['auto'] === 2 && $real['duplicate'] === 1, 'real run: 2 linked, 1 duplicate');
  $u1 = $mirror->findByListId('customer', 'ZZTEST-U1');
  $ok((int) $u1->get('field_bos_client')->target_id === (int) $c1->uid && $u1->get('field_match_status')->value === 'auto' && str_starts_with((string) $u1->get('field_match_note')->value, 'Matched on'), "U1 → uid {$c1->uid}, auto, note");
  $ok($profile_value((int) $c1->uid) === 'ZZTEST-U1', "uid {$c1->uid} customer profile field_qb_list_id = ZZTEST-U1");
  $u2 = $mirror->findByListId('customer', 'ZZTEST-U2');
  $ok((int) $u2->get('field_bos_client')->target_id === (int) $c2->uid && $profile_value((int) $c2->uid) === 'ZZTEST-U2', "U2 → uid {$c2->uid} and its profile");
  $u4 = $mirror->findByListId('customer', 'ZZTEST-U4');
  $ok($u4->get('field_bos_client')->isEmpty() && $u4->get('field_match_status')->value === 'duplicate' && str_contains((string) $u4->get('field_match_note')->value, 'already linked'), 'U4 (same person, NO SERVICES) → duplicate, not linked');
  $u3 = $mirror->findByListId('customer', 'ZZTEST-U3');
  $ok($u3->get('field_match_status')->value === 'unmatched', 'ZZ Nobody → unmatched');
  $again = $matcher->run();
  $ok($again['unchanged'] === 3 && $again['auto'] === 0, 're-run changes nothing (' . $again['unchanged'] . ' unchanged)');

  echo "== the office resolves a row ==\n";
  $u3->set('field_bos_client', (int) $c2->uid);
  $u3->save();
  $u3 = $storage->loadUnchanged($u3->id());
  $ok($u3->get('field_match_status')->value === 'manual', 'picking a client by hand → Matched by the office');
  $ok($profile_value((int) $c2->uid) === 'ZZTEST-U3', "…and uid {$c2->uid}'s profile now carries U3 (the latest link wins)");
  $u3->set('field_bos_client', NULL);
  $u3->save();
  $u3 = $storage->loadUnchanged($u3->id());
  $ok($u3->get('field_match_status')->value === 'unmatched' && $profile_value((int) $c2->uid) === NULL || $profile_value((int) $c2->uid) === '', 'clearing the client → Unmatched, profile cleared');
  $u3->set('field_match_status', 'ignored');
  $u3->save();
  $ok($matcher->run()['scanned'] === 2, 'an Ignored row is never scanned again');

  echo "== staleness + emails ==\n";
  $before = $mail_total();
  $full = $state->get(QbSyncRunner::STATE_LAST_FULL_SYNC);
  $full['at'] = \Drupal::time()->getRequestTime() - 30 * 3600;
  $state->set(QbSyncRunner::STATE_LAST_FULL_SYNC, $full);
  $state->delete(QbMonitor::STATE_LAST_ALERT);
  $state->delete(QbMonitor::STATE_ALERT_OPEN);
  $ok($monitor->isStale(), '30 h old full sync → stale');
  $row = bos_quickbooks_runtime_requirements()['bos_quickbooks'];
  $ok($row['severity']->name === 'Error' && str_contains((string) $row['value'], 'STALE'), 'Status report row Error: ' . strip_tags((string) $row['value']));
  $monitor->cron();
  $m = $mail_latest();
  $ok($mail_total() === $before + 1 && str_contains($m['Subject'] ?? '', 'QuickBooks mirror is stale'), 'cron emailed the stale alert: ' . ($m['Subject'] ?? '?'));
  $mail_ids[] = $m['ID'] ?? NULL;
  $ok(str_contains($m['Text'] ?? '', 'Update Selected') && str_contains($m['Text'] ?? '', '/admin/office/quickbooks'), '…with the fix steps and the status URL');
  $monitor->cron();
  $ok($mail_total() === $before + 1, 'a second cron within the alert interval sends nothing');
  $ok((bool) $state->get(QbMonitor::STATE_ALERT_OPEN), 'alert marked open');
  $monitor->recordFullSync(['at' => \Drupal::time()->getRequestTime(), 'text' => 'class done; customer done; item done']);
  $m = $mail_latest();
  $ok($mail_total() === $before + 2 && str_contains($m['Subject'] ?? '', 'current again'), 'a clean run emails the all-clear: ' . ($m['Subject'] ?? '?'));
  $mail_ids[] = $m['ID'] ?? NULL;
  $ok(!$state->get(QbMonitor::STATE_ALERT_OPEN), 'alert closed');
  $monitor->recordFullSync(['at' => \Drupal::time()->getRequestTime(), 'text' => 'x']);
  $ok($mail_total() === $before + 2, 'no all-clear when no alert was open');

  echo "== .qwc + admin pages (as uid 1) ==\n";
  $account_switcher = \Drupal::service('account_switcher');
  $account_switcher->switchTo(\Drupal\user\Entity\User::load(1));
  $kernel = \Drupal::service('http_kernel');
  $get = static function (string $path) use ($kernel): array {
    \Drupal::theme()->resetActiveTheme();
    $r = $kernel->handle(\Symfony\Component\HttpFoundation\Request::create($path, 'GET'), \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
    return [$r->getStatusCode(), (string) $r->getContent()];
  };
  [$code, $body] = $get('/admin/office/quickbooks/bos-quickbooks.qwc');
  $ok($code === 200 && str_contains($body, '<QBWCXML>') && str_contains($body, '<UserName>bos</UserName>') && str_contains($body, '<OwnerID>' . $session->ownerId() . '</OwnerID>') && str_contains($body, '<RunEveryNMinutes>60</RunEveryNMinutes>') && str_contains($body, '<UnattendedModePref>umpRequired</UnattendedModePref>') && str_contains($body, '/qbwc/soap</AppURL>'), '.qwc download carries user, OwnerID, interval, unattended mode, endpoint');
  [$code, $body] = $get('/admin/office/quickbooks');
  $ok($code === 200 && str_contains($body, 'Mirrored lists') && str_contains($body, 'Setting up the Web Connector'), 'status page renders with the setup steps');
  [$code, $body] = $get('/admin/office/quickbooks/review');
  $ok($code === 200 && str_contains($body, 'ZZTEST') === FALSE && str_contains($body, $e($qb1_dup)), 'Review list shows the duplicate row (' . $qb1_dup . ')');
  $ok(!str_contains($body, $e($qb1)), '…and not the auto-matched one');
  [$code, $body] = $get('/admin/office/quickbooks/customers');
  $ok($code === 200 && str_contains($body, $e($qb1)) && str_contains($body, 'Matched automatically'), 'Customers list shows the matched row with its status');
  [$code, $body] = $get('/admin/office/quickbooks/items');
  $ok($code === 200 && str_contains($body, 'Maintenance:Mowing') && str_contains($body, 'OtherCharge'), 'Items list renders');
  [$code, $body] = $get('/admin/office/quickbooks/classes');
  $ok($code === 200 && str_contains($body, 'Irrigation Department') && str_contains($body, '⚠ Missing'), 'Classes list renders with the Missing flag');
  [$code, $body] = $get('/qb_list/' . $u1->id() . '/edit');
  $ok($code === 200 && str_contains($body, 'The QuickBooks record') && str_contains($body, 'field_bos_client') && !str_contains($body, 'name="field_first_name'), 'customer edit form: read-only summary + client widget, synced fields not editable');
  [$code, $body] = $get('/qb_list/' . $i2->id() . '/edit');
  $ok($code === 200 && str_contains($body, 'Nothing on a QuickBooks item is edited in BOS'), 'item edit form says read-only');
  $account_switcher->switchBack();
}
catch (\Throwable $e) {
  echo "  EXCEPTION: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
  if (isset($client)) {
    echo "  last request: " . substr((string) $client->__getLastRequest(), 0, 600) . "\n  last response: " . substr((string) $client->__getLastResponse(), 0, 600) . "\n";
  }
  $fail++;
}
finally {
  echo "== cleanup ==\n";
  $n = $cleanup_customers();
  echo "  deleted $n ZZTEST qb_list rows\n";
  foreach ($profile_restore as $uid => $value) {
    $profiles = $etm->getStorage('profile')->loadByProperties(['uid' => $uid, 'type' => 'customer_profile']);
    if ($profiles) {
      $p = reset($profiles);
      $p->set('field_qb_list_id', $value === '' ? NULL : $value);
      $p->_bos_qb_sync = TRUE;
      $p->save();
    }
  }
  echo '  restored field_qb_list_id on ' . count($profile_restore) . " profile(s)\n";
  foreach ($snapshot as $k => $v) {
    $v === NULL ? $state->delete($k) : $state->set($k, $v);
  }
  echo "  state restored (" . count($snapshot) . " keys)\n";
  $ids = array_values(array_filter($mail_ids));
  if ($ids) {
    try {
      $http->delete($api . '/api/v1/messages', ['json' => ['IDs' => $ids]]);
      echo '  deleted ' . count($ids) . " Mailpit message(s)\n";
    }
    catch (\Throwable $e) {
      echo "  (mailpit delete failed: {$e->getMessage()})\n";
    }
  }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
