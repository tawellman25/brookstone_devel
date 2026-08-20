<?php

/**
 * Move the public "Lighting" service menu link under the Services tab.
 *
 * The public main-menu service links are taxonomy_menu-derived and hand-arranged
 * (the menu doesn't purely mirror the taxonomy — e.g. "Holiday Decorations", a
 * child of the Lighting term, sits under Services). "Lighting" (services term
 * 1505) had been left at the top level instead of nested under the Services
 * view link (views_view:views.services.page_1) like its siblings.
 *
 * This sets that link's parent to the Services link. It's a runtime menu
 * override (persists through menu rebuilds), NOT config — so it's run per
 * environment, not deployed via cim. Idempotent.
 *
 *   drush php:script web/scripts/fix_lighting_menu_parent.php
 */

$mgr = \Drupal::service('plugin.manager.menu.link');
$light = 'taxonomy_menu.menu_link:taxonomy_menu.menu_link.services.1505';
$services = 'views_view:views.services.page_1';

if (!$mgr->hasDefinition($light)) {
  print "  Lighting menu link not found ($light) — skipped\n";
  return;
}
$def = $mgr->getDefinition($light);
if (($def['parent'] ?? '') === $services) {
  print "  Lighting already under Services — no change\n";
  return;
}
$mgr->updateDefinition($light, ['parent' => $services, 'menu_name' => 'main']);
\Drupal::cache('menu')->invalidateAll();
printf("  Lighting moved under Services (parent '%s' -> '%s')\n", $def['parent'] ?? '', $services);
