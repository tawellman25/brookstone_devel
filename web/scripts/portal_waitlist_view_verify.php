<?php

/**
 * @file
 * Read-only: the portal waitlist lead list renders for the office and is
 * refused to crew. drush php:script web/scripts/portal_waitlist_view_verify.php
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$ok = static fn(bool $b): string => $b ? 'ok' : '** FAIL **';
$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();
$sw = \Drupal::service('account_switcher');
$pick = static function (array $need, array $forbid) use ($db): int {
  $q = "SELECT u.uid FROM {users_field_data} u WHERE u.status = 1 AND u.uid > 1";
  foreach ($need as $r) {
    $q .= " AND EXISTS (SELECT 1 FROM {user__roles} r WHERE r.entity_id = u.uid AND r.roles_target_id = '$r')";
  }
  foreach ($forbid as $r) {
    $q .= " AND NOT EXISTS (SELECT 1 FROM {user__roles} r WHERE r.entity_id = u.uid AND r.roles_target_id = '$r')";
  }
  return (int) $db->query($q . ' ORDER BY u.uid LIMIT 1')->fetchField();
};
$admins = ['administrator', 'site_admin', 'site_assistant', 'administration', 'supervisor'];
$office = $pick(['administration'], ['administrator', 'site_admin']);
$crew = $pick(['teammates'], $admins);
$get = static function (int $uid, string $path) use ($sw, $etm): array {
  $sw->switchTo($etm->getStorage('user')->load($uid));
  try {
    $req = Request::create($path, 'GET');
    $req->setSession(new Session(new MockArraySessionStorage()));
    $res = \Drupal::service('http_kernel')->handle($req, HttpKernelInterface::SUB_REQUEST);
    return [$res->getStatusCode(), (string) $res->getContent()];
  }
  finally {
    $sw->switchBack();
  }
};
$leads = (int) $db->query('SELECT COUNT(*) FROM {contacts__field_portal_interest} WHERE field_portal_interest_value = 1 AND deleted = 0')->fetchField();
echo "waitlist contacts on this environment: $leads\n";
[$code, $html] = $get($office, '/admin/office/portal-waitlist');
printf("office-role uid %d: %d %s | title %s | columns %s | header note %s\n", $office, $code, $ok($code === 200), $ok(str_contains($html, 'Portal waitlist')), $ok(str_contains($html, 'Signed up') && str_contains($html, 'Marketing email OK')), $ok(str_contains($html, 'OFF-ADM-WTL-001')));
[$code2] = $get($crew, '/admin/office/portal-waitlist');
printf("crew-only uid %d: %d %s\n", $crew, $code2, $ok($code2 === 403));
$menu = \Drupal::service('plugin.manager.menu.link')->loadLinksByRoute('view.portal_waitlist_leads.page_1');
$l = $menu ? reset($menu) : NULL;
echo 'menu link: ' . ($l ? $l->getTitle() . ' under ' . $l->getParent() . ' ' . $ok(str_starts_with($l->getParent(), 'menu_link_content:')) : '** none **') . "\n";
echo "done\n";
