<?php

/**
 * @file
 * READ-ONLY: render a real backflow device as each audience and check what
 * comes out. Creates and changes nothing.
 *
 * Rendered HTML is checked, not display config — a display can be perfectly
 * curated and still never be the one used, which is the failure this guards.
 */

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

// A device with a serial and a property, so both the wanted and the unwanted
// values are actually present to find.
$id = (int) \Drupal::database()->query("
  SELECT d.id FROM {property_backflow_device_field_data} d
  JOIN {property_backflow_device__field_serial_number} s ON s.entity_id = d.id AND s.deleted = 0
  JOIN {property_backflow_device__field_property} p ON p.entity_id = d.id AND p.deleted = 0
  LIMIT 1")->fetchField();
if (!$id) { print "no suitable device found\n"; return; }
$device = $etm->getStorage('property_backflow_device')->load($id);
$serial = (string) $device->get('field_serial_number')->value;
$property = $device->get('field_property')->entity;
$propLabel = $property ? $property->label() : '';
printf("device %d — serial %s — property \"%s\"\n\n", $id, $serial, $propLabel);

// All four modes must be registered, or Drupal silently falls back to `default`.
foreach (['admin', 'teammate', 'client', 'public'] as $m) {
  $check("view mode registered: $m",
    (bool) \Drupal\Core\Entity\Entity\EntityViewMode::load("property_backflow_device.$m"));
  $check("  display exists and is enabled: $m",
    (bool) ($d = \Drupal\Core\Entity\Entity\EntityViewDisplay::load("property_backflow_device.device.$m")) && $d->status());
}

$render = function (?int $uid) use ($device) {
  $switcher = \Drupal::service('account_switcher');
  $account = $uid === NULL
    ? new \Drupal\Core\Session\AnonymousUserSession()
    : \Drupal\user\Entity\User::load($uid);
  $switcher->switchTo($account);
  \Drupal::service('entity_type.manager')->getViewBuilder('property_backflow_device')->resetCache([$device]);
  $build = \Drupal::entityTypeManager()->getViewBuilder('property_backflow_device')->view($device, 'full');
  $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
  $switcher->switchBack();
  return $html;
};

// Find one user per audience.
$office = ['supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator'];
$pick = function (string $role) use ($etm, $office) {
  $ids = $etm->getStorage('user')->getQuery()->accessCheck(FALSE)
    ->condition('roles', $role)->condition('status', 1)->range(0, 60)->execute();
  foreach ($ids as $uid) {
    $u = $etm->getStorage('user')->load($uid);
    // Must hold ONLY this audience's role, or a higher tier wins the switch and
    // the test silently measures the wrong thing.
    // Exclude every OTHER audience role, not just the office ones. A user who
    // holds both teammates and client resolves to teammate, so picking them as
    // "the client" silently tests the wrong tier — which is exactly what
    // happened, and showed up as two audiences rendering identical bytes.
    $others = array_diff(array_merge($office, ['teammates', 'client']), [$role]);
    if ($u && !array_intersect($others, $u->getRoles()) && (int) $uid !== 1) {
      return (int) $uid;
    }
  }
  return NULL;
};
$users = [
  'office' => 1,
  'teammate' => $pick('teammates'),
  'client' => $pick('client'),
  'anonymous' => NULL,
];
printf("\nusers: office=1  teammate=%s  client=%s  anonymous=-\n\n", $users['teammate'] ?? 'NONE', $users['client'] ?? 'NONE');

// Assert the view mode each audience resolves to. Content checks alone cannot
// tell teammate from client when their displays overlap.
$expected = ['office' => 'admin', 'teammate' => 'teammate', 'client' => 'client', 'anonymous' => 'public'];
foreach ($expected as $who => $want) {
  $uid = $users[$who] ?? NULL;
  if ($who !== 'anonymous' && $uid === NULL) { continue; }
  $acct = $uid === NULL ? new \Drupal\Core\Session\AnonymousUserSession() : \Drupal\user\Entity\User::load($uid);
  \Drupal::service('account_switcher')->switchTo($acct);
  $mode = 'full';
  backflow_device_entity_view_mode_alter($mode, $device);
  \Drupal::service('account_switcher')->switchBack();
  $check(sprintf('%-10s resolves to the %s view mode', $who, $want), $mode === $want,
    'got ' . $mode . ' (roles: ' . implode(',', $acct->getRoles()) . ')');
}

$html = [];
foreach ($users as $who => $uid) {
  if ($who !== 'anonymous' && $uid === NULL) { printf("SKIP %s — no such user on this environment\n", $who); continue; }
  $html[$who] = $render($uid);
}

// --- What the public must NOT be shown ------------------------------------
if (isset($html['anonymous'])) {
  $h = $html['anonymous'];
  $check('anonymous: the page renders at all', strlen($h) > 200, strlen($h) . ' bytes');
  $check('anonymous: SEES the serial number', str_contains($h, $serial), $serial);
  $check('anonymous: does NOT see the property it belongs to',
    $propLabel === '' || !str_contains($h, $propLabel), $propLabel);
  $loc = trim((string) $device->get('field_physical_location')->value);
  $check('anonymous: does NOT see the physical location',
    $loc === '' || !str_contains($h, $loc), $loc ?: '(none set)');
  $check('anonymous: no repairs / work-order columns',
    !str_contains($h, 'Repairs') && !str_contains($h, 'Work Order'));
  $check('anonymous: no report PDF link', !str_contains($h, 'report_pdf') && !str_contains($h, 'Report PDF'));
}

// --- Each audience gets its own body --------------------------------------
if (isset($html['office'])) {
  $check('office: sees the property', $propLabel === '' || str_contains($html['office'], $propLabel));
  $check('office: sees the serial', str_contains($html['office'], $serial));
}
if (isset($html['teammate'])) {
  $loc = trim((string) $device->get('field_physical_location')->value);
  $check('teammate: sees the physical location', $loc === '' || str_contains($html['teammate'], $loc), $loc ?: '(none set)');
  $check('teammate: sees the serial', str_contains($html['teammate'], $serial));
}
if (isset($html['client'])) {
  $check('client: sees the serial', str_contains($html['client'], $serial));
  $check('client: does NOT see the internal work-order column', !str_contains($html['client'], 'Work Order'));
}

// --- The bodies must actually DIFFER --------------------------------------
if (count($html) >= 3) {
  $lens = array_map('strlen', $html);
  arsort($lens);
  printf("\nrendered sizes: %s\n", implode('  ', array_map(fn($k, $v) => "$k=$v", array_keys($lens), $lens)));
  $check('the four audiences do not all get the same page', count(array_unique($lens)) > 1);
  if (isset($lens['office'], $lens['anonymous'])) {
    $check('office sees more than anonymous', $lens['office'] > $lens['anonymous'],
      $lens['office'] . ' vs ' . $lens['anonymous']);
  }
}

// --- The cache must not be able to cross audiences -------------------------
// hook_ENTITY_TYPE_view_alter runs while the element is RENDERED, not when
// view() hands back a lazy render array — checking before rendering reported a
// missing context that was in fact added moments later.
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(new \Drupal\Core\Session\AnonymousUserSession());
$etm->getViewBuilder('property_backflow_device')->resetCache([$device]);
$build = $etm->getViewBuilder('property_backflow_device')->view($device, 'full');
$context = new \Drupal\Core\Render\RenderContext();
\Drupal::service('renderer')->executeInRenderContext($context, function () use (&$build) {
  return \Drupal::service('renderer')->render($build);
});
$switcher->switchBack();
$contexts = $build['#cache']['contexts'] ?? [];
$check('the render carries a user.roles cache context',
  in_array('user.roles', $contexts, TRUE), implode(', ', $contexts));

printf("\n%d passed, %d failed\n", $pass, $fail);
