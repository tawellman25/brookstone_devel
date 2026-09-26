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

$cfg = \Drupal::configFactory()->getEditable('views.view.backflow_property_devices_eva');
if ($cfg->isNew()) {
  print "View backflow_property_devices_eva not found.\n";
  return;
}
$display = $cfg->get('display');
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
$cfg->set('display', $display)->save();
print "Added page_1 (properties/%/backflow) to backflow_property_devices_eva.\n";
print "DONE.\n";
