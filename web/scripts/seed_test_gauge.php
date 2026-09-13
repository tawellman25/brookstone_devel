<?php

/**
 * Seed the company's backflow test gauge as equipment + its calibration event.
 *   Gauge: Mid-West Instrument model 845, serial 05261902, Active (1301),
 *          assigned to Todd (uid 1), type "Backflow Test Gauge".
 *   Calibration (equipment_maintenance_event): 2026-06-08 -> due 2027-06-08,
 *          vendor "American Backflow Products Company".
 * Idempotent (gauge keyed on serial; calibration keyed on equipment+date).
 *   drush php:script web/scripts/seed_test_gauge.php
 */

use Drupal\taxonomy\Entity\Term;

$etm = \Drupal::entityTypeManager();
$out = [];

// Equipment type term (equipment_labels titles from field_common_name).
$termId = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'equipment_types']) as $tm) {
  if ($tm->hasField('field_common_name') && strcasecmp((string) $tm->get('field_common_name')->value, 'Backflow Test Gauge') === 0) {
    $termId = $tm->id();
  }
}
if (!$termId) {
  $term = Term::create([
    'vid' => 'equipment_types',
    'name' => 'Backflow Test Gauge',
    'field_common_name' => 'Backflow Test Gauge',
  ]);
  if ($term->hasField('field_equipment_bundle')) {
    $term->set('field_equipment_bundle', 'test_gauges');
  }
  $term->save();
  $termId = $term->id();
  $out[] = "created equipment_type term Backflow Test Gauge ($termId)";
}

// The gauge (keyed on serial).
$SERIAL = '05261902';
$existing = $etm->getStorage('equipment')->getQuery()->accessCheck(FALSE)
  ->condition('type', 'test_gauges')->condition('field_serial_code_number', $SERIAL)->execute();
if (!$existing) {
  $gauge = $etm->getStorage('equipment')->create([
    'type' => 'test_gauges',
    'field_equipment_make' => 'Mid-West Instrument',
    'field_model' => '845',
    'field_serial_code_number' => $SERIAL,
    'field_status' => 1301,
    'field_equipment_type' => $termId,
    'field_assigned_to' => 1,
  ]);
  $gauge->save();
  $out[] = "created gauge equipment {$gauge->id()} (\"{$gauge->label()}\")";
}
else {
  $gauge = $etm->getStorage('equipment')->load(reset($existing));
  $out[] = "gauge exists {$gauge->id()} (\"{$gauge->label()}\")";
}

// Calibration event (keyed on equipment + event_date).
$CAL_DATE = '2026-06-08';
$dup = $etm->getStorage('equipment_maintenance_event')->getQuery()->accessCheck(FALSE)
  ->condition('field_equipment', $gauge->id())
  ->condition('field_event_type', 'calibration')
  ->condition('field_event_date', $CAL_DATE)->execute();
if (!$dup) {
  $ev = $etm->getStorage('equipment_maintenance_event')->create([
    'type' => 'standard',
    'field_equipment' => $gauge->id(),
    'field_event_type' => 'calibration',
    'field_event_date' => $CAL_DATE,
    'field_next_service_due_date' => '2027-06-08',
    'field_vendor_or_mechanic' => 'American Backflow Products Company',
    'field_work_performed' => 'Annual calibration of backflow differential test gauge.',
    'field_verified_complete' => TRUE,
  ]);
  $ev->save();
  $out[] = "created calibration event {$ev->id()} (2026-06-08 -> due 2027-06-08)";
}
else {
  $out[] = 'calibration event exists';
}

print implode("\n", $out) . "\nDONE.\n";
