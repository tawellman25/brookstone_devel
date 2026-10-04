<?php

declare(strict_types=1);

/**
 * Footer link to the material catalogue.
 *
 * Placed in footer-COMPANY rather than footer-services, because /material is not
 * a service - it is a transparency item, the same family as "Our Credentials"
 * and "Customer Reviews". Its own live header says so: "when a material shows up
 * on your estimate or your invoice, you should be able to look it up."
 *
 * Weight 2, which TIES with "Customer Reviews". Menu ties break alphabetically,
 * and "Customer Reviews" < "Materials We Use", so it lands directly after it -
 * grouping the three evidence links together WITHOUT reweighting any existing
 * link. Inserting at weight 3 would have meant shifting four links down.
 *
 * Links by ROUTE, not a stored path, so it cannot go stale the way the footer
 * lighting link did.
 *
 *   drush php:script web/scripts/add_footer_materials_link.php
 *   BOS_FOOT_APPLY=1 drush php:script web/scripts/add_footer_materials_link.php
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;

$apply = getenv('BOS_FOOT_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$ROUTE = 'view.material_types_landing.page_1';
$URI = 'route:' . $ROUTE;
$TITLE = 'Materials We Use';
$MENU = 'footer-company';
$WEIGHT = 2;

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_FOOT_APPLY=1 to write)\n\n";

try { \Drupal::service('router.route_provider')->getRouteByName($ROUTE); }
catch (\Throwable $e) { print "ABORT — route $ROUTE does not exist.\n"; return; }
$acct = \Drupal::service('account_switcher');
$acct->switchTo(new \Drupal\Core\Session\UserSession(['uid' => 0, 'roles' => ['anonymous']]));
$ok = \Drupal::service('access_manager')->checkNamedRoute($ROUTE, [], \Drupal::currentUser());
$acct->switchBack();
if (!$ok) { print "ABORT — anonymous users cannot reach $ROUTE.\n"; return; }
print "✓ route exists and is reachable by anonymous visitors\n";

foreach ($etm->getStorage('menu_link_content')->loadMultiple() as $l) {
  if ($l->getMenuName() === $MENU && $l->getUrlObject()->toUriString() === $URI) {
    printf("✓ already present: \"%s\" weight %s — nothing to do\n", $l->getTitle(), $l->getWeight());
    return;
  }
}

// Show the resulting order, resolved the way the menu tree resolves it:
// weight first, then title.
$rows = [];
foreach ($etm->getStorage('menu_link_content')->loadMultiple() as $l) {
  if ($l->getMenuName() !== $MENU || !$l->isEnabled()) { continue; }
  $rows[] = [$l->getWeight(), $l->getTitle(), ''];
}
$rows[] = [$WEIGHT, $TITLE, '   <- new'];
usort($rows, fn($a, $b) => $a[0] <=> $b[0] ?: strcmp($a[1], $b[1]));
print "\nresulting footer-company order:\n";
foreach ($rows as [$w, $t, $tag]) { printf("  %-3s %s%s\n", $w, $t, $tag); }
print "\n(no existing link is reweighted — the tie at 2 breaks alphabetically)\n";

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
printf("\nCreated link %s: \"%s\" -> %s\n", $re->id(), $re->getTitle(), $re->getUrlObject()->toString());
