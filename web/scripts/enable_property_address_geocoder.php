<?php

/**
 * Turn on the address-search / geocode control on the property map widget, so the
 * office can type an address, look it up on Google, and confirm the pin on the
 * properties add/edit form. Idempotent; edits the (drifted) form-display active
 * config — run per env, not cim.
 *
 *   drush php:script web/scripts/enable_property_address_geocoder.php
 */

$fd = \Drupal::service('entity_display.repository')->getFormDisplay('properties', 'property');
$c = $fd->getComponent('field_geofield');
if (!$c) {
  print "field_geofield not on the property form — nothing changed.\n";
  return;
}
$c['settings']['map_geocoder']['control'] = 1;
$c['settings']['map_geocoder']['settings']['providers'] = ['googlemaps'];
if (($c['settings']['map_geocoder']['settings']['min_terms'] ?? 0) < 3) {
  $c['settings']['map_geocoder']['settings']['min_terms'] = 4;
}
$fd->setComponent('field_geofield', $c)->save();

$check = \Drupal::service('entity_display.repository')->getFormDisplay('properties', 'property')->getComponent('field_geofield');
print "geocoder control = " . ($check['settings']['map_geocoder']['control'] ?? '?')
  . " providers = " . implode(',', $check['settings']['map_geocoder']['settings']['providers'] ?? []) . "\n";
print "Done.\n";
