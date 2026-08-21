<?php

/**
 * Order the Office admin-menu children as: Calendar, Daily Recap, Work Load,
 * Work Orders, Service Requests, Estimates, Contracts.
 *
 * Existing weights already give Calendar / Daily Recap(-10) / Work Load(-9) /
 * Work Orders(-6) / Contracts(-3); only Service Requests and Estimates need to
 * move between Work Orders and Contracts. Idempotent; run per env.
 *
 *   drush php:script web/scripts/reorder_office_menu.php
 */

use Drupal\views\Entity\View;

// Service Requests → -5 (via the view's page menu weight — Views-derived link).
$v = View::load('service_request_admin');
if ($v) {
  $display = $v->get('display');
  if (isset($display['page_1']['display_options']['menu'])) {
    $display['page_1']['display_options']['menu']['weight'] = -5;
    $v->set('display', $display);
    $v->save();
    print "Service Requests menu weight → -5\n";
  }
}

// Estimates (estimate_board.board, module-defined static link) → -4, persisted
// as a static_menu_link_override via the menu link manager.
\Drupal::service('plugin.manager.menu.link')->updateDefinition('estimate_board.board', ['weight' => -4], TRUE);
print "Estimates menu weight → -4\n";

// Report the resulting order.
$mlm = \Drupal::service('plugin.manager.menu.link');
$office = 'menu_link_content:4b4baafc-6631-4b64-9157-b570acf68c2c';
$kids = [];
foreach ($mlm->getDefinitions() as $pid => $def) {
  if (($def['parent'] ?? '') === $office) {
    $kids[] = ['w' => $def['weight'] ?? 0, 't' => $def['title'] ?? '', 'id' => $pid];
  }
}
usort($kids, fn($a, $b) => ($a['w'] <=> $b['w']) ?: strcmp($a['t'], $b['t']));
print "resulting Office order:\n";
foreach ($kids as $k) {
  printf("  w=%-3s %s\n", $k['w'], $k['t']);
}
