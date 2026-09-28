<?php

declare(strict_types=1);

/**
 * Nest the Credentials and Reviews menu links under their containers.
 *
 * Both were created with `parent: ''`, which puts a Views menu link at the ROOT of
 * the admin menu rather than inside the section its path implies — Drupal nests the
 * admin menu by PARENT, not by path, so a correct URL does not imply a correct menu
 * position. Every working sibling sets parent explicitly, e.g.
 * city_administration -> menu_link_content:<Service Areas>.
 *
 *   credentials.page_admin  -> System Content   (Operations > System Content)
 *   testimonials.page_all   -> Office
 *
 * Container UUIDs are resolved BY URI at runtime, not hardcoded, because
 * menu_link_content is content and its UUIDs differ per environment.
 *
 * Idempotent.
 *   drush php:script web/scripts/fix_admin_menu_parents.php
 */

use Drupal\views\Entity\View;

/** Find a container menu link's plugin id by its internal URI. */
$container = function (string $uri): ?string {
  foreach (\Drupal::entityTypeManager()->getStorage('menu_link_content')->loadMultiple() as $link) {
    if ($link->get('link')->uri === $uri) {
      return 'menu_link_content:' . $link->uuid();
    }
  }
  return NULL;
};

$targets = [
  ['credentials', 'page_admin', 'internal:/admin/operations/system_content', 'System Content'],
  ['testimonials', 'page_all', 'internal:/admin/office', 'Office'],
];

foreach ($targets as [$vid, $did, $uri, $label]) {
  $parent = $container($uri);
  if (!$parent) {
    printf("  SKIP %-14s no menu link found for %s — resolve by hand\n", $vid, $uri);
    continue;
  }
  $view = View::load($vid);
  if (!$view) {
    printf("  SKIP %-14s view missing\n", $vid);
    continue;
  }
  $display = $view->get('display');
  if (empty($display[$did]['display_options']['menu'])) {
    printf("  SKIP %-14s display %s has no menu entry\n", $vid, $did);
    continue;
  }
  $was = $display[$did]['display_options']['menu']['parent'] ?? '';
  if ($was === $parent) {
    printf("  ok   %-14s already under %s\n", $vid, $label);
    continue;
  }
  $display[$did]['display_options']['menu']['parent'] = $parent;
  $view->set('display', $display);
  $view->save();
  printf("  set  %-14s %s -> under %s (%s)\n", $vid, $was === '' ? "(root)" : $was, $label, $parent);
}
print "DONE.\n";
