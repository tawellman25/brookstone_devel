<?php

/**
 * Move the service_request_admin queue to /admin/office/service-requests and
 * nest its menu link under the "Estimates" admin item (estimate_board.board).
 * Idempotent, in-place (no full rebuild). Run per env.
 *
 *   drush php:script web/scripts/move_service_request_view_path.php
 */

use Drupal\views\Entity\View;

$v = View::load('service_request_admin');
if (!$v) {
  print "view service_request_admin NOT FOUND\n";
  return;
}
$display = $v->get('display');
$do = &$display['page_1']['display_options'];
$do['path'] = 'admin/office/service-requests';
$do['menu'] = [
  'type' => 'normal',
  'title' => 'Service Requests',
  'description' => 'Public service-request intake queue.',
  'weight' => 0,
  'menu_name' => 'admin',
  'parent' => 'estimate_board.board',
  'context' => '',
  'expanded' => FALSE,
];
unset($do);
$v->set('display', $display);
$v->save();
print "service_request_admin → /admin/office/service-requests, menu parent estimate_board.board\n";
