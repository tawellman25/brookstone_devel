<?php

/**
 * Remove the duplicate lighting labor-rate field from business_setting.
 *
 * There were TWO lighting rate inputs on the Business Settings page, both $75:
 *   - field_lighting_technician_rate ("Lighting Technician")  ← USED by the
 *     wo_landscape_lighting / wo_exterior_lighting billing modules (kept).
 *   - field_lighting_crew_labor_rate ("Lighting Crew Labor Rate") ← read by NO
 *     code; the only `*_crew_labor_rate` field in the system (a one-off
 *     duplicate). This deletes it.
 *
 * The per-crew internal-cost field field_labor_cost_lighting_crew
 * ("Lighting Crew Labor Cost") is a DIFFERENT concept (part of the consistent
 * field_labor_cost_* set across all crews) and is left untouched.
 *
 * Deleting the field instance also removes it from the form/view displays.
 * Idempotent. Run: drush php:script web/scripts/remove_duplicate_lighting_rate.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$name = 'field_lighting_crew_labor_rate';

$fc = FieldConfig::loadByName('config_pages', 'business_setting', $name);
if ($fc) {
  $fc->delete();
  print "deleted field instance config_pages.business_setting.$name\n";
}
else {
  print "field instance already absent\n";
}

$fs = FieldStorageConfig::loadByName('config_pages', $name);
if ($fs) {
  $fs->delete();
  print "deleted field storage config_pages.$name\n";
}
else {
  print "field storage already absent\n";
}

// Deleting a field does not clean it out of a field_group's children list —
// strip the dangling reference so the form display is consistent.
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('config_pages', 'business_setting');
$changed = FALSE;
foreach ($fd->getThirdPartySettings('field_group') as $gname => $g) {
  if (!empty($g['children']) && in_array($name, $g['children'], TRUE)) {
    $g['children'] = array_values(array_filter($g['children'], fn($c) => $c !== $name));
    $fd->setThirdPartySetting('field_group', $gname, $g);
    $changed = TRUE;
  }
}
if ($changed) {
  $fd->save();
  print "removed dangling $name reference from field-group children\n";
}

print "done — 'Lighting Technician' (field_lighting_technician_rate) is now the single lighting rate.\n";
