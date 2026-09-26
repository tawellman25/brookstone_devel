<?php

declare(strict_types=1);

/**
 * Add the `backflow-types-landing` css_class to the backflow_device_types_landing
 * view so the landing CSS (bos_backflow_types/landing) can scope to it. The view
 * wrapper otherwise has no stable id class to target. Idempotent; run per env.
 *
 *   drush php:script web/scripts/set_backflow_landing_css_class.php
 */

$cfg = \Drupal::configFactory()->getEditable('views.view.backflow_device_types_landing');
if ($cfg->isNew()) {
  print "view backflow_device_types_landing not found.\n";
  return;
}
$class = 'backflow-types-landing';
$existing = (string) $cfg->get('display.default.display_options.css_class');
if (strpos($existing, $class) !== FALSE) {
  print "css_class already set.\n";
  return;
}
$new = trim($existing === '' ? $class : $existing . ' ' . $class);
$cfg->set('display.default.display_options.css_class', $new)->save();
print "Set css_class on backflow_device_types_landing: '$new'\n";
