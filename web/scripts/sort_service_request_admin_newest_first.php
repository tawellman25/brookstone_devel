<?php

/**
 * @file
 * Sort the office service-request queue (service_request_admin) newest-first so
 * new submissions appear at the top instead of the bottom. Flips the `created`
 * sort from ASC to DESC on the default display. Idempotent; run per env.
 *
 *   ddev drush php:script web/scripts/sort_service_request_admin_newest_first.php   (dev)
 *   drush php:script web/scripts/sort_service_request_admin_newest_first.php        (live)
 */

$view = \Drupal\views\Entity\View::load('service_request_admin');
if (!$view) {
  echo "! view service_request_admin not found\n";
  return;
}
$display = $view->get('display');
$sorts = &$display['default']['display_options']['sorts'];
if (empty($sorts['created'])) {
  echo "! no `created` sort on the default display — nothing changed\n";
  return;
}
if (($sorts['created']['order'] ?? '') === 'DESC') {
  echo "• already newest-first (created DESC)\n";
  return;
}
$sorts['created']['order'] = 'DESC';
$view->set('display', $display);
$view->save();
echo "• service_request_admin now sorts created DESC (newest first)\n";
