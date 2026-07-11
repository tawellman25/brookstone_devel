<?php

/**
 * Move three orphaned (ungrouped, bottom-of-page) business_setting fields up
 * into the "Labor Rates" field group (group_labor_rates) where the other crew
 * rates / minimums live:
 *   - field_lighting_technician_rate ("Lighting Technician")   — weights 47/48
 *   - field_lighting_tech_minimum   ("Lighting Technician Minimum")  already
 *     slot them right after Sprinkler Technician (46) / before the lighting
 *     cost (49); they were just never added to the group's children list.
 *   - field_other_minimum_time ("Other Minimum Time") — used by wo_lawn_mowing
 *     (min billable time for "other" mow tasks). Re-weighted to sit with the
 *     other minimum-time fields.
 *
 * Idempotent. Run: drush php:script web/scripts/move_labor_fields_to_group.php
 */

$fd = \Drupal::service('entity_display.repository')->getFormDisplay('config_pages', 'business_setting');

$group = $fd->getThirdPartySetting('field_group', 'group_labor_rates');
if (!$group) {
  print "group_labor_rates not found — aborting\n";
  return;
}

$add = ['field_lighting_technician_rate', 'field_lighting_tech_minimum', 'field_other_minimum_time'];
foreach ($add as $f) {
  if (!in_array($f, $group['children'], TRUE)) {
    $group['children'][] = $f;
  }
}
$fd->setThirdPartySetting('field_group', 'group_labor_rates', $group);

// Position "Other Minimum Time" with the other minimums (general=35, cleanup=40).
if (($c = $fd->getComponent('field_other_minimum_time'))) {
  $c['weight'] = 36;
  $fd->setComponent('field_other_minimum_time', $c);
}

$fd->save();

print "moved lighting rate/min + Other Minimum Time into group_labor_rates\n";
