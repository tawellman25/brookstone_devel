<?php

declare(strict_types=1);

/**
 * Surface Credentials + Customer Reviews from the About Us area.
 *
 * Both pages already live at URL children of /about-us but were reachable only
 * by typing the address (Credentials also from the footer trust line). This
 * gives them the two entry points a visitor actually uses:
 *
 *   1. main menu  — as children of "About", so the dropdown exposes them to
 *      someone who never opens the About page. Olivero's primary nav renders
 *      two levels, and the block is already set to depth 2, so no block change.
 *   2. footer Company menu — labelled links beside About Us / Careers / Contact.
 *
 * The in-text mentions on the About page itself are a separate script.
 *
 * Menu links are content, not config: run per environment.
 * Idempotent — matched on (menu, url), so a re-run updates rather than doubles.
 *
 *   drush php:script web/scripts/add_about_trust_links.php
 */

$storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

/** Find a link in $menu whose rendered URL is $path. */
$find = function (string $menu, string $path) use ($storage): ?\Drupal\menu_link_content\MenuLinkContentInterface {
  foreach ($storage->loadByProperties(['menu_name' => $menu]) as $link) {
    try {
      if ($link->getUrlObject()->toString() === $path) {
        return $link;
      }
    }
    catch (\Throwable $e) {
      // Unroutable link — skip.
    }
  }
  return NULL;
};

// --- 1. main menu: nest under "About" -------------------------------------
$about = $find('main', '/about-us');
if (!$about) {
  print "SKIP main: no /about-us link in the main menu\n";
}
else {
  $parent = 'menu_link_content:' . $about->uuid();
  print "main parent: '" . $about->getTitle() . "' -> $parent\n";

  $children = [
    ['/about-us/credentials', 'Our Credentials', 1],
    ['/about-us/reviews',     'Customer Reviews', 2],
  ];
  foreach ($children as [$path, $title, $weight]) {
    // Refuse to advertise a route that does not exist in this environment.
    try {
      \Drupal\Core\Url::fromUserInput($path)->toString();
    }
    catch (\Throwable $e) {
      print "  SKIP $path — not routable here\n";
      continue;
    }
    $existing = $find('main', $path);
    if ($existing) {
      $existing->set('title', $title)->set('parent', $parent)->set('weight', $weight)
        ->set('enabled', TRUE)->save();
      print "  updated  $title  ($path)\n";
    }
    else {
      $storage->create([
        'title' => $title,
        'link' => ['uri' => 'internal:' . $path],
        'menu_name' => 'main',
        'parent' => $parent,
        'weight' => $weight,
        'expanded' => FALSE,
      ])->save();
      print "  created  $title  ($path)\n";
    }
  }
}

// --- 2. footer Company menu ------------------------------------------------
// Sits directly under About Us; Careers/Contact/Estimate/Login shift down.
$footer = [
  ['/about-us/credentials', 'Our Credentials', 1],
  ['/about-us/reviews',     'Customer Reviews', 2],
];
$shift = ['/careers' => 3, '/contact' => 4, '/request-estimate' => 5, '/user/login' => 6];

print "footer-company:\n";
foreach ($footer as [$path, $title, $weight]) {
  try {
    \Drupal\Core\Url::fromUserInput($path)->toString();
  }
  catch (\Throwable $e) {
    print "  SKIP $path — not routable here\n";
    continue;
  }
  $existing = $find('footer-company', $path);
  if ($existing) {
    $existing->set('title', $title)->set('weight', $weight)->set('enabled', TRUE)->save();
    print "  updated  $title\n";
  }
  else {
    $storage->create([
      'title' => $title,
      'link' => ['uri' => 'internal:' . $path],
      'menu_name' => 'footer-company',
      'weight' => $weight,
    ])->save();
    print "  created  $title\n";
  }
}
foreach ($shift as $path => $weight) {
  if ($link = $find('footer-company', $path)) {
    if ((int) $link->getWeight() !== $weight) {
      $link->set('weight', $weight)->save();
      print "  reweighted " . $link->getTitle() . " -> $weight\n";
    }
  }
}

\Drupal::service('plugin.manager.menu.link')->rebuild();
print "DONE.\n";
