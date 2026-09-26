<?php

/**
 * Add a public "Backflow Devices" list PAGE display to the existing
 * backflow_property_devices_eva view, at path properties/%/backflow.
 *
 * The property-path pretty URL (/{property-alias}/backflow) is routed to this
 * system path by the BackflowListPathProcessor (bos-only inbound processor), so
 * stripping the BF number off a device tag lands on the property's device list.
 *
 * Gated on 'view any property_backflow_device entities' — the same permission
 * that already makes individual device pages public — so a field user can browse
 * a property's devices. The EVA display (on the staff property page) is untouched.
 *
 * Idempotent. Run per env: drush php:script web/scripts/build_backflow_list_page.php
 */

// Load the View as an ENTITY (not raw config) so that saving it fires the
// Views route rebuild — a raw configFactory save leaves the page route
// unregistered (the display exists in config but the route 404s).
$view = \Drupal::entityTypeManager()->getStorage('view')->load('backflow_property_devices_eva');
if (!$view) {
  print "View backflow_property_devices_eva not found.\n";
  return;
}
$display = $view->get('display');
if (isset($display['page_1'])) {
  print "page_1 display already present — nothing to do.\n";
  return;
}

$display['page_1'] = [
  'id' => 'page_1',
  'display_title' => 'Backflow list page',
  'display_plugin' => 'page',
  'position' => 2,
  'display_options' => [
    'display_extenders' => [],
    'path' => 'properties/%/backflow',
    // Only override access, title and menu; inherit fields, the
    // field_property_target_id contextual filter, style, row and pager from
    // the default display (a child display honours an override only when its
    // matching defaults[...] flag is FALSE).
    'defaults' => [
      'access' => FALSE,
      'title' => FALSE,
      'menu' => FALSE,
    ],
    'access' => [
      'type' => 'perm',
      'options' => ['perm' => 'view any property_backflow_device entities'],
    ],
    'title' => 'Backflow Devices',
    'menu' => ['type' => 'none'],
  ],
];
$view->set('display', $display);
$view->save();
// Belt-and-suspenders: make sure the new page route is registered now.
\Drupal::service('router.builder')->rebuild();
print "Added page_1 (properties/%/backflow) to backflow_property_devices_eva + rebuilt routes.\n";
print "DONE.\n";
