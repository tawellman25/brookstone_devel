<?php

/**
 * Clean up the backflow test form (wo_tasks_list:backflow_testing default form
 * display):
 *   - Hide the Title field (auto-generated in code — see
 *     _wo_backflow_testing_generate_title()).
 *   - New collapsed "Office Admin" group: uid (Authored by), created (Authored
 *     on), path (URL alias), field_report_image (Report Image / legacy scan).
 *   - New "Test Readings" group holding the six reading fields (were loose and
 *     interspersed with the meta fields).
 *   - field_report_image moves out of "Repairs & Report" into "Office Admin".
 *
 * Idempotent; entity-API (form displays are config but this avoids a full cim).
 * Run per env. drush php:script web/scripts/setup_backflow_test_form.php
 */

$fd = \Drupal::service('entity_display.repository')->getFormDisplay('wo_tasks_list', 'backflow_testing');
if ($fd->isNew()) {
  print "form display not found\n";
  return;
}

// Hide Title (auto-generated).
if ($fd->getComponent('title')) {
  $fd->removeComponent('title');
  print "hid title\n";
}

// Ensure the group members are visible components in the content region with
// sensible per-field weights (order within each group).
$weights = [
  'uid' => 50, 'created' => 51, 'path' => 52, 'field_report_image' => 53,
  'field_line_pressure_psi' => 20, 'field_check_valve_1_psid' => 21,
  'field_check_valve_2_psid' => 22, 'field_relief_valve_psid' => 23,
  'field_air_inlet_psid' => 24, 'field_check_valve_psid' => 25,
];
foreach ($weights as $f => $w) {
  if ($c = $fd->getComponent($f)) {
    $c['weight'] = $w;
    $c['region'] = 'content';
    $fd->setComponent($f, $c);
  }
}

$groups = $fd->getThirdPartySettings('field_group');

// Drop report image from Repairs & Report (moves to Office Admin).
if (!empty($groups['group_repairs_report']['children'])) {
  $groups['group_repairs_report']['children'] = array_values(array_diff(
    $groups['group_repairs_report']['children'], ['field_report_image']));
  $groups['group_repairs_report']['weight'] = 30;
}
if (!empty($groups['group_test_details'])) {
  $groups['group_test_details']['weight'] = 10;
}

// Test Readings group (after Test Details).
$groups['group_test_readings'] = [
  'children' => [
    'field_line_pressure_psi', 'field_check_valve_1_psid', 'field_check_valve_2_psid',
    'field_relief_valve_psid', 'field_air_inlet_psid', 'field_check_valve_psid',
  ],
  'label' => 'Test Readings',
  'region' => 'content',
  'parent_name' => '',
  'weight' => 20,
  'format_type' => 'details',
  'format_settings' => [
    'classes' => '', 'show_empty_fields' => FALSE, 'id' => '', 'open' => TRUE,
    'description' => 'Gauge readings — only the fields for this device type are shown.',
    'required_fields' => TRUE,
  ],
];

// Office Admin group (collapsed, last).
$groups['group_office_admin'] = [
  'children' => ['uid', 'created', 'path', 'field_report_image'],
  'label' => 'Office Admin',
  'region' => 'content',
  'parent_name' => '',
  'weight' => 40,
  'format_type' => 'details',
  'format_settings' => [
    'classes' => '', 'show_empty_fields' => FALSE, 'id' => '', 'open' => FALSE,
    'description' => 'Author, created date, URL alias, and legacy report scan — office use.',
    'required_fields' => TRUE,
  ],
];

foreach ($groups as $gid => $def) {
  $fd->setThirdPartySetting('field_group', $gid, $def);
}
$fd->save();

print "groups: " . implode(', ', array_keys($groups)) . "\n";
print "office admin: [" . implode(', ', $groups['group_office_admin']['children']) . "]\n";
print "test readings: [" . implode(', ', $groups['group_test_readings']['children']) . "]\n";
print "DONE.\n";
