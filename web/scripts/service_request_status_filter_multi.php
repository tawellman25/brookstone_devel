<?php

/**
 * @file
 * Make the office service-request queue Status filter multi-select and default
 * it to New + Needs Review (the actionable statuses), so the office lands on the
 * work that needs attention without hiding the rest — the other statuses are
 * still selectable in the same widget. Idempotent; run per env (status tids are
 * content and differ dev↔live, so they are resolved by name here).
 *
 *   ddev drush php:script web/scripts/service_request_status_filter_multi.php   (dev)
 *   drush php:script web/scripts/service_request_status_filter_multi.php        (live)
 */

$view = \Drupal\views\Entity\View::load('service_request_admin');
if (!$view) {
  echo "! view service_request_admin not found\n";
  return;
}

// Resolve the default statuses by name.
$want = ['New', 'Needs Review'];
$tids = [];
foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => 'service_request_status']) as $t) {
  if (in_array($t->label(), $want, TRUE)) {
    $tids[(int) $t->id()] = (string) $t->id();
  }
}
if (count($tids) !== 2) {
  echo "! could not resolve both New + Needs Review (found: " . implode(',', array_keys($tids)) . ") — aborting\n";
  return;
}

$display = $view->get('display');
$filters = &$display['default']['display_options']['filters'];
$key = 'field_request_status_target_id';
if (empty($filters[$key])) {
  echo "! status filter not found on the default display\n";
  return;
}
$filters[$key]['operator'] = 'or';
$filters[$key]['value'] = [];                     // no config default (exposed)
$filters[$key]['expose']['multiple'] = TRUE;      // render a multi-select
$filters[$key]['expose']['reduce'] = FALSE;       // offer every status

$view->set('display', $display);
$view->save();
// The working default (New + Needs Review on first load) is applied by
// bos_service_request_views_pre_view() — an exposed filter's stored `value` is
// NOT used as the default once it is exposed, so it must be injected as exposed
// input before the query runs.
echo "• status filter is now multi-select (default New + Needs Review applied by views_pre_view)\n";
