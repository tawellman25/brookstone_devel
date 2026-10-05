<?php

declare(strict_types=1);

/**
 * Menu placement for the on-site holiday estimator, plus the teammate
 * "Estimates" landing page it sits under.
 *
 *   Admin menu     Office → Estimates → Holiday Lighting Estimate
 *   Teammate menu  Office → Estimates → Holiday Lighting Estimate
 *
 * The admin "Estimates" parent already exists and points at the OFFICE
 * estimates landing page (site_landing_page 11, bundle office_administration,
 * /admin/operations/system_content/estimates). Teammates cannot use that one,
 * so this creates a teammate-bundle landing page at /teammates/estimates and
 * hangs the teammate branch off that — the same shape as the Employment page
 * built on 2026-08-26.
 *
 * Menu links and landing pages are CONTENT, not config: run per environment
 * (dev, then live). Idempotent — matched on title within the menu, and on the
 * destination URI, so a re-run finds and updates rather than duplicating.
 *
 *   drush php:script web/scripts/setup_holiday_estimate_menu.php
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;

$ESTIMATOR_ROUTE = 'bos_service_request.holiday_estimate';
$ESTIMATOR_URI = 'route:' . $ESTIMATOR_ROUTE;
$TEAMMATE_OFFICE_UUID = 'ddd2ad6c-1b2a-4eff-8ef7-4c2965909353';

$etm = \Drupal::entityTypeManager();
$aliasRepo = \Drupal::service('path_alias.repository');

// Guard: never create a menu item pointing at a route that does not exist.
try {
  \Drupal::service('router.route_provider')->getRouteByName($ESTIMATOR_ROUTE);
}
catch (\Throwable $e) {
  print "ERROR: route $ESTIMATOR_ROUTE does not exist. Enable the module first. Aborting.\n";
  return;
}

/** Find a menu_link_content by menu + destination URI, else by title. */
$find = function (string $menu, string $uri, string $title) use ($etm): ?MenuLinkContent {
  foreach ($etm->getStorage('menu_link_content')->loadByProperties(['menu_name' => $menu]) as $l) {
    if ($l->getUrlObject()->toUriString() === $uri) {
      return $l;
    }
  }
  foreach ($etm->getStorage('menu_link_content')->loadByProperties(['menu_name' => $menu, 'title' => $title]) as $l) {
    return $l;
  }
  return NULL;
};

// ---------------------------------------------------------------- 1. landing
$slp = $etm->getStorage('site_landing_page');
$ids = $slp->getQuery()->accessCheck(FALSE)
  ->condition('type', 'teammate')->condition('title', 'Estimates')->execute();

$body = <<<HTML
<h2>Estimates</h2>
<p>Tools for pricing work in front of the customer. Build the estimate while you are standing there, and it lands in BOS for the office with your name on it.</p>

<h3>Holiday Lighting</h3>
<p>Measure the roofline, count the trees and wreaths, and the price comes off the rate card &mdash; no arithmetic at the curb. Quote the <strong>two-year plan first</strong>: it is cheaper for the customer and it is the one that pays for the lights across two seasons.</p>
<p><a href="/teammates/holiday-estimate"><strong>Open the Holiday Lighting Estimator &rarr;</strong></a></p>
<p>Two things that are easy to forget and are pure margin: tick <em>steep roof</em> and <em>work above 25 feet</em> when they apply. And never quote the roofline on its own &mdash; the add-ons are routinely more than half the ticket on the same truck roll.</p>
<p><em>Hang &amp; Remove</em> uses the customer's own lights. Look at them before you price it: condition varies, and clips and detangling are chargeable.</p>
HTML;

if ($ids) {
  $page = $slp->load(reset($ids));
  print "  landing page    already exists (id {$page->id()})\n";
}
else {
  $page = $slp->create([
    'type' => 'teammate',
    'title' => 'Estimates',
    'field_description' => ['value' => $body, 'format' => 'full_html'],
    'status' => 1,
    'path' => ['alias' => '/teammates/estimates', 'pathauto' => 0],
  ]);
  $page->save();
  print "+ landing page    created (id {$page->id()}) at /teammates/estimates\n";
}
$pageUri = 'entity:site_landing_page/' . $page->id();

// -------------------------------------------------- 2. teammate Estimates
$tEst = $find('teammate-navigation', $pageUri, 'Estimates');
if ($tEst) {
  print "  teammate link   'Estimates' already exists (id {$tEst->id()})\n";
}
else {
  $tEst = MenuLinkContent::create([
    'title' => 'Estimates',
    'link' => ['uri' => $pageUri],
    'menu_name' => 'teammate-navigation',
    'parent' => 'menu_link_content:' . $TEAMMATE_OFFICE_UUID,
    'weight' => 2,
    'expanded' => TRUE,
  ]);
  $tEst->save();
  print "+ teammate link   'Estimates' created under Office\n";
}

// ------------------------------------- 3. teammate Holiday, under Estimates
$tHol = $find('teammate-navigation', $ESTIMATOR_URI, 'Holiday Lighting Estimate');
if ($tHol) {
  print "  teammate link   'Holiday Lighting Estimate' already exists (id {$tHol->id()})\n";
}
else {
  MenuLinkContent::create([
    'title' => 'Holiday Lighting Estimate',
    'link' => ['uri' => $ESTIMATOR_URI],
    'menu_name' => 'teammate-navigation',
    'parent' => 'menu_link_content:' . $tEst->uuid(),
    'weight' => 0,
  ])->save();
  print "+ teammate link   'Holiday Lighting Estimate' created under Estimates\n";
}

// ------------------------------------- 4. admin Holiday, under Estimates
// Resolve the parent by WHERE IT SITS, not by its title. There are TWO links
// titled "Estimates" in the admin menu and they are in different branches:
//
//   Office        -> Estimates   = estimate_board.board, a MODULE-DEFINED link,
//                                  the one the office actually uses (it carries
//                                  "+ Estimate Request" and "Missing WOs")
//   System Content-> Estimates   = a menu_link_content pointing at the office
//                                  estimates landing page
//
// A first version matched menu_link_content by title, found the second, and
// filed the holiday link under System Content where nobody was looking
// (reported 2026-10-04). Titles are not addresses — this walks every admin-menu
// link called Estimates and takes the one whose parent is Office.
$mlm = \Drupal::service('plugin.manager.menu.link');
$titleOf = function (string $pluginId) use ($mlm): string {
  try {
    return (string) $mlm->createInstance($pluginId)->getTitle();
  }
  catch (\Throwable $e) {
    return '';
  }
};

$adminEstId = NULL;
$candidates = [];
foreach ($mlm->getDefinitions() as $id => $def) {
  if (($def['menu_name'] ?? '') !== 'admin' || strcasecmp((string) $def['title'], 'Estimates') !== 0) {
    continue;
  }
  $parentTitle = !empty($def['parent']) ? $titleOf($def['parent']) : '(root)';
  $candidates[] = "$id under \"$parentTitle\"";
  if (strcasecmp($parentTitle, 'Office') === 0) {
    $adminEstId = $id;
  }
}
printf("  admin 'Estimates' candidates: %s\n", $candidates ? implode(' | ', $candidates) : 'none');

if (!$adminEstId) {
  print "! admin link      no 'Estimates' under Office — skipped rather than guessing.\n";
}
else {
  printf("  chose parent    %s (the one under Office)\n", $adminEstId);
  $aHol = $find('admin', $ESTIMATOR_URI, 'Holiday Lighting Estimate');
  if ($aHol && $aHol->getParentId() !== $adminEstId) {
    printf("~ admin link      moving from %s to %s\n", $aHol->getParentId(), $adminEstId);
    $aHol->set('parent', $adminEstId)->save();
  }
  elseif ($aHol) {
    print "  admin link      already in the right place\n";
  }
  else {
    \Drupal\menu_link_content\Entity\MenuLinkContent::create([
      'title' => 'Holiday Lighting Estimate',
      'link' => ['uri' => $ESTIMATOR_URI],
      'menu_name' => 'admin',
      'parent' => $adminEstId,
      'weight' => 10,
    ])->save();
    print "+ admin link      created under Office -> Estimates\n";
  }
}

\Drupal::service('plugin.manager.menu.link')->rebuild();
print "\nDone.\n";
