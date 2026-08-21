<?php

/**
 * Move the service_request_admin queue to /admin/office/service-requests and
 * nest its menu link under the "Office" admin item. Idempotent, in-place.
 * Run per env — the Office link is a menu_link_content whose UUID differs
 * between environments, so it is resolved dynamically (by title, then by the
 * stable page_manager office route), never hardcoded.
 *
 *   drush php:script web/scripts/move_service_request_view_path.php
 */

use Drupal\views\Entity\View;

/** Resolve the "Office" admin-menu link plugin id for THIS environment. */
function _bos_sr_office_parent(): string {
  $mlm = \Drupal::service('plugin.manager.menu.link');
  $officeRoute = 'page_manager.page_view_office_administation_office_administation-layout_builder-0';
  foreach ($mlm->getDefinitions() as $pid => $def) {
    if (($def['menu_name'] ?? '') === 'admin' && strcasecmp((string) ($def['title'] ?? ''), 'office') === 0) {
      return $pid;
    }
  }
  foreach ($mlm->getDefinitions() as $pid => $def) {
    if (($def['menu_name'] ?? '') === 'admin' && ($def['route_name'] ?? '') === $officeRoute) {
      return $pid;
    }
  }
  return '';
}

$v = View::load('service_request_admin');
if (!$v) {
  print "view service_request_admin NOT FOUND\n";
  return;
}
$parent = _bos_sr_office_parent();
if ($parent === '') {
  print "WARNING: 'Office' admin menu link not found — placing at top level.\n";
}

$display = $v->get('display');
$do = &$display['page_1']['display_options'];
$do['path'] = 'admin/office/service-requests';
$do['menu'] = [
  'type' => 'normal',
  'title' => 'Service Requests',
  'description' => 'Public service-request intake queue.',
  'weight' => -5,
  'menu_name' => 'admin',
  'parent' => $parent,
  'context' => '',
  'expanded' => FALSE,
];
unset($do);
$v->set('display', $display);
$v->save();
printf("service_request_admin → /admin/office/service-requests, menu parent '%s' (Office)\n", $parent);
