<?php

/**
 * @file
 * READ-ONLY verification of bos_quickbooks + the qb_list mirror (safe on live).
 *
 *   drush php:script web/scripts/bos_quickbooks_verify.php
 *
 * Reads config, state and the mirror; renders anonymous GETs of the SOAP
 * endpoint (GET only — no SOAP call, nothing authenticates) through the
 * kernel. Nothing is created, saved or synced. The write-and-rollback test
 * is test_bos_quickbooks.php (dev only).
 */

use Drupal\bos_quickbooks\QbwcSession;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$pass = 0; $fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
  echo ($cond ? '  ok   ' : '  FAIL ') . $label . "\n";
  $cond ? $pass++ : $fail++;
};
$mh = \Drupal::moduleHandler();
$etm = \Drupal::entityTypeManager();

echo "== module + entity type ==\n";
$ok($mh->moduleExists('bos_quickbooks'), 'bos_quickbooks enabled');
$ok($etm->hasDefinition('qb_list'), 'qb_list entity type');
$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('qb_list'));
sort($bundles);
$ok($bundles === ['class', 'customer', 'item'], 'bundles class / customer / item (' . implode(', ', $bundles) . ')');
$instances = 0;
foreach (['customer' => 18, 'item' => 13, 'class' => 9] as $bundle => $expected) {
  $n = count(array_filter(array_keys(\Drupal::service('entity_field.manager')->getFieldDefinitions('qb_list', $bundle)), static fn($f) => str_starts_with($f, 'field_')));
  $ok($n === $expected, "$bundle has $expected configurable fields ($n)");
}
$ok((bool) FieldConfig::loadByName('qb_list', 'customer', 'field_bos_client'), 'customer.field_bos_client (user reference, role client)');
$ok((bool) FieldConfig::loadByName('profile', 'customer_profile', 'field_qb_list_id'), 'profile.customer_profile.field_qb_list_id (the ListID landing field)');
foreach (['customer', 'item', 'class'] as $b) {
  $ok((bool) $etm->getStorage('entity_form_display')->load("qb_list.$b.default") && (bool) $etm->getStorage('entity_view_display')->load("qb_list.$b.default"), "$b form + view display saved");
}

echo "== services + wiring ==\n";
foreach (['session', 'qbxml', 'mirror', 'monitor', 'sync', 'soap_handler', 'matcher'] as $s) {
  $ok(\Drupal::hasService("bos_quickbooks.$s"), "service bos_quickbooks.$s");
}
$ok($mh->hasImplementations('cron', 'bos_quickbooks'), 'hook_cron');
$ok($mh->hasImplementations('runtime_requirements', 'bos_quickbooks'), 'hook_runtime_requirements');
$ok($mh->hasImplementations('mail', 'bos_quickbooks'), 'hook_mail');
$ok($mh->hasImplementations('qb_list_presave', 'bos_quickbooks'), 'hook_qb_list_presave');
$ok($mh->hasImplementations('form_alter', 'bos_quickbooks'), 'hook_form_alter');
$ok(extension_loaded('soap') && class_exists('SoapServer'), 'PHP ext-soap present');
$settings = \Drupal::config('bos_quickbooks.settings');
$ok($settings->get('stale_hours') !== NULL, 'bos_quickbooks.settings present (stale ' . $settings->get('stale_hours') . ' h, poll ' . $settings->get('run_every_minutes') . ' min, user ' . $settings->get('username') . ')');
$session = \Drupal::service('bos_quickbooks.session');
$ok(preg_match('/^\{[0-9A-F-]{36}\}$/', $session->ownerId()) === 1, 'OwnerID is a braces GUID ' . $session->ownerId());
$ok(preg_match('/^\{[0-9A-F-]{36}\}$/', $session->fileId()) === 1, 'FileID is a braces GUID ' . $session->fileId());
echo '  password set: ' . ($session->hasPassword() ? 'yes' : 'NO (set it on the settings form before the shop visit)') . "\n";

echo "== routes ==\n";
$rp = \Drupal::service('router.route_provider');
foreach (['bos_quickbooks.soap', 'bos_quickbooks.support', 'bos_quickbooks.status', 'bos_quickbooks.settings', 'bos_quickbooks.qwc', 'bos_quickbooks.match', 'view.qb_customers.page_1', 'view.qb_customers.page_2', 'view.qb_items.page_1', 'view.qb_classes.page_1'] as $name) {
  try {
    $route = $rp->getRouteByName($name);
    $ok(TRUE, "$name → " . $route->getPath());
  }
  catch (\Throwable) {
    $ok(FALSE, "$name registered");
  }
}
$tasks = \Drupal::service('plugin.manager.menu.local_task')->getLocalTasksForRoute('bos_quickbooks.status');
$ok(count($tasks[0] ?? []) >= 5, 'tab bar on the status page (' . count($tasks[0] ?? []) . ' tabs)');

echo "== permissions ==\n";
$perms = \Drupal::service('user.permissions')->getPermissions();
$ok(isset($perms['administer quickbooks integration']) && isset($perms['view quickbooks lists']), 'module permissions defined');
foreach (['administration', 'site_assistant', 'site_admin'] as $rid) {
  $role = \Drupal\user\Entity\Role::load($rid);
  $ok($role && $role->hasPermission('view quickbooks lists') && $role->hasPermission('edit any qb_list entities of bundle customer'), "$rid: view lists + edit customers");
}
$ok(\Drupal\user\Entity\Role::load('site_admin')?->hasPermission('administer quickbooks integration') ?? FALSE, 'site_admin: administer');

echo "== status report row ==\n";
$req = bos_quickbooks_runtime_requirements()['bos_quickbooks'];
echo '  ' . $req['title'] . ': ' . strip_tags((string) $req['value']) . ' [' . $req['severity']->name . "]\n";
$ok(in_array($req['severity']->name, ['OK', 'Warning'], TRUE), 'severity OK or Warning (Warning = never synced / no password yet)');

echo "== anonymous GETs through the kernel (nothing written) ==\n";
$kernel = \Drupal::service('http_kernel');
$get = static function (string $path) use ($kernel): array {
  \Drupal::theme()->resetActiveTheme();
  $response = $kernel->handle(Request::create($path, 'GET'), HttpKernelInterface::SUB_REQUEST);
  return [$response->getStatusCode(), (string) $response->getContent(), $response->headers->get('Cache-Control', '')];
};
[$code, $body, $cc] = $get('/qbwc/soap');
$ok($code === 200 && str_contains($body, 'Web Connector endpoint'), "/qbwc/soap GET → $code (description)");
$ok(str_contains($cc, 'no-store'), "  …and Cache-Control no-store ($cc)");
[$code, $body] = $get('/qbwc/soap?wsdl');
preg_match('/<soap:address location="([^"]*)"/', $body, $m);
// Under drush the absolute URL is built from drush's request context
// (http://default/…); over real HTTP it is this site's https URL.
$ok($code === 200 && str_contains($body, 'QBWebConnectorSvc') && !empty($m[1]) && str_ends_with($m[1], '/qbwc/soap'), "/qbwc/soap?wsdl → $code (WSDL, location " . ($m[1] ?? '?') . ')');
[$code, $body] = $get('/qbwc/support');
$ok($code === 200, "/qbwc/support → $code");
[$code] = $get('/admin/office/quickbooks');
$ok($code === 403, "/admin/office/quickbooks anonymous → $code (403)");

echo "== mirror ==\n";
$facts = \Drupal::service('bos_quickbooks.monitor')->inspect();
foreach ($facts['counts'] as $bundle => $c) {
  echo sprintf("  %-9s total %d · active %d · missing %d\n", $bundle, $c['total'], $c['active'], $c['missing']);
}
echo '  last complete sync: ' . ($facts['last_full_sync'] ? date('m/d/Y g:i A T', (int) $facts['last_full_sync']['at']) : 'never') . ' · last contact: ' . ($facts['last_contact'] ? date('m/d/Y g:i A T', $facts['last_contact']) : 'never') . "\n";

echo "\n$pass ok, $fail failed\n";
exit($fail ? 1 : 0);
