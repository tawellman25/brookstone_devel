<?php

/**
 * @file
 * Give equipment_maintenance_event.standard and equipment_defect.standard a
 * saved form + view display (idempotent: skips a display that already exists).
 *
 * Surfaced 2026-11-13 while adding Parts Used / Parts Needed: neither entity
 * had EVER had a display config (dev and live alike), and without one Drupal
 * 11 renders the add/edit form with NO field widgets at all — title, author
 * and date only — so the new reference fields had nowhere to appear and,
 * for that matter, neither did any other field. The displays are built from
 * each field's default widget / formatter in a working order, saved, then
 * exported to config/sync (web/scripts/equipment_parts_config_files.txt);
 * equipment_parts_setup.php creates them on an environment that lacks them.
 *
 *   ddev drush php:script web/scripts/equipment_parts_display_bootstrap.php
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$order = [
  'equipment_maintenance_event' => [
    'field_equipment', 'field_event_date', 'field_event_type', 'field_work_performed', 'field_parts_used',
    'field_cost_parts', 'field_cost_labor', 'field_cost_total', 'field_downtime_days', 'field_service_reading',
    'field_vendor_or_mechanic', 'field_related_defect', 'field_next_service_due', 'field_next_service_due_date',
    'field_verified_complete', 'field_verified_by',
  ],
  'equipment_defect' => [
    'field_equipment', 'field_source_inspection', 'field_defect_date_reported', 'field_defect_category',
    'field_defect_severity', 'field_defect_summary', 'field_defect_status', 'field_repeat_issue',
    'field_out_of_service_required', 'field_parts_needed', 'field_estimated_repair_cost', 'field_actual_repair_cost',
    'field_assigned_to', 'field_resolution_notes', 'field_resolved_on',
  ],
];
$efm = \Drupal::service('entity_field.manager');
foreach ($order as $et => $fields) {
  $defs = $efm->getFieldDefinitions($et, 'standard');
  $missing = array_diff(array_keys(array_filter($defs, fn($d) => str_starts_with($d->getName(), 'field_'))), $fields);
  if ($missing) {
    echo "  !! $et: fields not in the order list (appended): " . implode(', ', $missing) . "\n";
    $fields = array_merge($fields, array_values($missing));
  }
  foreach (['form' => EntityFormDisplay::class, 'view' => EntityViewDisplay::class] as $ctx => $class) {
    $id = "$et.standard.default";
    if ($class::load($id)) {
      echo "  $ctx display $id exists\n";
      continue;
    }
    /** @var \Drupal\Core\Entity\Display\EntityDisplayInterface $display */
    $display = $class::create(['targetEntityType' => $et, 'bundle' => 'standard', 'mode' => 'default', 'status' => TRUE]);
    $w = 0;
    foreach ($fields as $f) {
      if (!isset($defs[$f])) {
        continue;
      }
      // Default widget / formatter for the field type, labels inline on view.
      $display->setComponent($f, $ctx === 'view' ? ['label' => 'inline', 'weight' => $w++] : ['weight' => $w++]);
    }
    // Titles are auto-labelled (auto_entitylabel) — not hand-edited.
    foreach (['title', 'uid', 'created', 'path'] as $base) {
      $display->removeComponent($base);
    }
    $display->save();
    echo "  $ctx display $id CREATED with " . count($display->getComponents()) . " components\n";
  }
}
