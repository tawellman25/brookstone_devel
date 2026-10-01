<?php

/**
 * @file
 * Stop requiring alt text on sprinkler photos.
 *
 * Crews enter these in a yard on a phone. Alt text is still STORED and still
 * editable — properties_entity_presave() generates it as
 * "{property} - {what it is} {NN}" when it is left empty — so nothing is lost for
 * screen readers or the office. It is simply no longer a field a tech has to fill
 * before the form will save.
 *
 * Idempotent. Per environment: field configs silently skip cim.
 */

use Drupal\field\Entity\FieldConfig;

$targets = [
  'property_sprinkler_system.system' => ['field_zone_map'],
  'property_ss_sources.dirty_water_source' => ['field_ss_shut_off_location_pic'],
  'property_ss_sources.domestic_source' => ['field_ss_backflow_photos', 'field_ss_shut_off_location_pic'],
  'property_ss_sources.well_water_source' => ['field_ss_shut_off_location_pic'],
  'property_ss_zones.zone' => ['field_ss_zone_photos'],
  'property_system_controller.controller' => ['field_controller_photos'],
  'property_sprinkler_pumps.pump' => ['field_pump_configuration_photos', 'field_pump_serial_number_pic'],
  'property_sprinkler_design.design' => ['field_sprinkler_design'],
];

$changed = 0;
foreach ($targets as $key => $fields) {
  [$entity_type, $bundle] = explode('.', $key);
  foreach ($fields as $field) {
    $config = FieldConfig::loadByName($entity_type, $bundle, $field);
    if (!$config) {
      printf("MISSING  %s.%s\n", $key, $field);
      continue;
    }
    $settings = $config->getSettings();
    if (empty($settings['alt_field_required'])) {
      printf("already ok  %s.%s\n", $key, $field);
      continue;
    }
    // Keep alt_field TRUE: the box stays, so anyone can still type one, and the
    // presave hook fills it when they do not.
    $config->setSetting('alt_field_required', FALSE);
    $config->setSetting('alt_field', TRUE);
    $config->save();
    printf("relaxed     %s.%s\n", $key, $field);
    $changed++;
  }
}
printf("\n%d field(s) changed.\n", $changed);
