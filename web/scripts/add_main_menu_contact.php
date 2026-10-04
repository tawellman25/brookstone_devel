<?php

declare(strict_types=1);

/**
 * Add Contact to the public main menu.
 *
 * /contact works and always has - HTTP 200, access TRUE for anonymous and
 * office alike - but the only link to it anywhere on the site was in the
 * FOOTER company menu, so it was reachable by typing the address or scrolling
 * to the bottom and by no other route.
 *
 * Placed at weight 7, immediately after About (6), so the public top level
 * reads Home / Services / About / Contact. Uses the same route as the footer
 * link rather than a stored path, so it cannot go stale.
 *
 * menu_link_content is CONTENT, so this runs per environment.
 *
 *   drush php:script web/scripts/add_main_menu_contact.php
 *   BOS_MENU_APPLY=1 drush php:script web/scripts/add_main_menu_contact.php
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;

$apply = getenv('BOS_MENU_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$ROUTE = 'bos_service_request.contact';
$URI = 'route:' . $ROUTE;
$TITLE = 'Contact';
$MENU = 'main';
$WEIGHT = 7;

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_MENU_APPLY=1 to write)\n\n";

// The destination must resolve, and it must be reachable by the public.
try { \Drupal::service('router.route_provider')->getRouteByName($ROUTE); }
catch (\Throwable $e) { print "ABORT — route $ROUTE does not exist.\n"; return; }
$anon = new \Drupal\Core\Session\UserSession(['uid' => 0, 'roles' => ['anonymous']]);
$acct = \Drupal::service('account_switcher');
$acct->switchTo($anon);
$ok = \Drupal::service('access_manager')->checkNamedRoute($ROUTE, [], \Drupal::currentUser());
$acct->switchBack();
if (!$ok) { print "ABORT — anonymous users cannot reach $ROUTE. A public menu item would be a dead end.\n"; return; }
print "✓ route exists and is reachable by anonymous visitors\n";

// Idempotent: match on menu + uri, not on title.
foreach ($etm->getStorage('menu_link_content')->loadMultiple() as $l) {
  if ($l->getMenuName() === $MENU && $l->getUrlObject()->toUriString() === $URI) {
    printf("✓ already present: \"%s\" weight %s, enabled %s — nothing to do\n",
      $l->getTitle(), $l->getWeight(), $l->isEnabled() ? 'yes' : 'NO');
    return;
  }
}

// Show where it will sit.
$params = (new \Drupal\Core\Menu\MenuTreeParameters())->setMaxDepth(1)->onlyEnabledLinks();
$siblings = [];
foreach (\Drupal::menuTree()->load($MENU, $params) as $el) {
  $siblings[] = [$el->link->getWeight(), $el->link->getTitle()];
}
$siblings[] = [$WEIGHT, $TITLE . '   <- new'];
usort($siblings, fn($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
print "\nresulting top level (enabled links; some are access-gated and invisible to the public):\n";
foreach ($siblings as [$w, $t]) { printf("  %-3s %s\n", $w, $t); }

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }

$link = MenuLinkContent::create([
  'title' => $TITLE,
  'link' => ['uri' => $URI],
  'menu_name' => $MENU,
  'weight' => $WEIGHT,
  'expanded' => FALSE,
  'enabled' => TRUE,
]);
$link->save();
drupal_flush_all_caches();

$re = $etm->getStorage('menu_link_content')->load($link->id());
printf("\nCreated link %s: \"%s\" -> %s (menu %s, weight %s)\n",
  $re->id(), $re->getTitle(), $re->getUrlObject()->toString(), $re->getMenuName(), $re->getWeight());
