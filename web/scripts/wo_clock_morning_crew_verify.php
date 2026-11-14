<?php

/**
 * @file
 * Morning-crew pre-select — read-only verification (safe on live).
 *
 *   drush php:script web/scripts/wo_clock_morning_crew_verify.php
 *
 * Checks: field_clocked_in_by installed; a work order page renders for a
 * crew-only account with the crew panel, the pre-select hint element and the
 * roster endpoint; then shows what the roster would propose RIGHT NOW for the
 * work orders that have time entries today (same-job arm only — the CLI has no
 * foreman identity). Writes nothing. The write test is
 * test_crew_morning_preselect.php (dev; rolls back).
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$ok = static fn(bool $b): string => $b ? 'ok' : '** FAIL **';
$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$svc = \Drupal::service('wo_clock.clock_service');

echo "== field ==\n";
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('wo_time_clock', 'entry');
$d = $defs['field_clocked_in_by'] ?? NULL;
echo '  field_clocked_in_by: ' . $ok((bool) $d) . ($d ? sprintf(' type=%s target=%s label="%s"', $d->getType(), $d->getSetting('target_type'), $d->getLabel()) : '') . "\n";
echo '  table: ' . $ok($db->schema()->tableExists('wo_time_clock__field_clocked_in_by')) . "\n";
echo '  service: getMorningCrewForWo ' . $ok(method_exists($svc, 'getMorningCrewForWo')) . "\n";
$stamped = (int) $db->query('SELECT COUNT(*) FROM {wo_time_clock__field_clocked_in_by}')->fetchField();
echo "  entries stamped so far: $stamped (fills as the clock is used from now on)\n";

echo "== work order page as a crew-only account ==\n";
$wo = (int) $db->query("SELECT wo.id FROM {work_order_field_data} wo
  JOIN {work_order__field_status} st ON st.entity_id = wo.id AND st.deleted = 0
  WHERE wo.type = 'fall_cleanup' AND st.field_status_target_id NOT IN (1097, 1098, 1281, 1504)
  ORDER BY wo.id DESC LIMIT 1")->fetchField();
$uid = (int) $db->query("SELECT u.uid FROM {users_field_data} u
  JOIN {user__roles} r ON r.entity_id = u.uid AND r.roles_target_id = 'teammates'
  WHERE u.status = 1 AND NOT EXISTS (SELECT 1 FROM {user__roles} x WHERE x.entity_id = u.uid
    AND x.roles_target_id IN ('administrator', 'site_admin', 'site_assistant', 'administration', 'supervisor'))
  ORDER BY u.uid LIMIT 1")->fetchField();
if ($wo && $uid) {
  $sw = \Drupal::service('account_switcher');
  $sw->switchTo($etm->getStorage('user')->load($uid));
  try {
    $req = Request::create("/work_order/$wo", 'GET');
    $req->setSession(new Session(new MockArraySessionStorage()));
    $res = \Drupal::service('http_kernel')->handle($req, HttpKernelInterface::SUB_REQUEST);
    $html = (string) $res->getContent();
    printf("  WO %d as uid %d: %d %s | crew panel %s | pre-select hint element %s | roster endpoint %s | wo-clock.js %s\n",
      $wo, $uid, $res->getStatusCode(), $ok($res->getStatusCode() === 200),
      $ok(str_contains($html, 'data-wo-clock-crew-panel')),
      $ok(str_contains($html, 'data-wo-clock-crew-preselect')),
      // drupalSettings is JSON, so the URL's slashes are escaped — match the key.
      $ok(str_contains($html, 'crewRoster')),
      $ok(str_contains($html, 'wo-clock.js')));
  }
  finally {
    $sw->switchBack();
  }
}
else {
  echo "  (no open fall_cleanup WO or crew-only account to render)\n";
}

echo "== what the roster would propose right now (same-job arm; today, site timezone) ==\n";
$tz = new \DateTimeZone(date_default_timezone_get());
$dayStart = (new \DateTime('now', $tz))->setTime(0, 0, 0);
$from = gmdate('Y-m-d\TH:i:s', $dayStart->getTimestamp());
$rows = $db->query('SELECT w.field_work_order_target_id wo, COUNT(*) n,
    SUM(CASE WHEN e.field_end_time_value IS NULL THEN 1 ELSE 0 END) open_n
  FROM {wo_time_clock__field_start_time} s
  JOIN {wo_time_clock__field_work_order} w ON w.entity_id = s.entity_id AND w.deleted = 0
  LEFT JOIN {wo_time_clock__field_end_time} e ON e.entity_id = s.entity_id AND e.deleted = 0
  WHERE s.deleted = 0 AND s.field_start_time_value >= :from
  GROUP BY w.field_work_order_target_id ORDER BY n DESC LIMIT 8', [':from' => $from])->fetchAll();
if (!$rows) {
  echo "  no time entries started today (" . $dayStart->format('m/d/Y') . ") — nothing to propose, as designed\n";
}
foreach ($rows as $r) {
  $morning = $svc->getMorningCrewForWo((int) $r->wo);
  $names = [];
  foreach ($morning as $u => $g) {
    $acc = $etm->getStorage('user')->load($u);
    $names[] = ($acc ? $acc->getDisplayName() : "uid $u") . ($g['last_out'] ? ' (out ' . (new \DateTime('@' . $g['last_out']))->setTimezone($tz)->format('g:i A') . ')' : '');
  }
  printf("  WO %-6d entries today %d (open %d) → would pre-tick %d: %s\n", $r->wo, $r->n, $r->open_n, count($morning), $names ? implode(', ', $names) : '-');
}
echo "done\n";
