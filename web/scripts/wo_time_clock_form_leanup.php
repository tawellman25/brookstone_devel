<?php

/**
 * Lean up the wo_time_clock:entry edit form (used in the WO-page "Edit Time
 * Entry" modal): keep Teammate, Start Time, End Time (+ the time-limit
 * override) as the primary visible fields — Teammate stays up top because
 * foremen set it during manual entry — and tuck Notes into a COLLAPSED "Notes"
 * field group that expands only when someone wants to add/edit a note. The
 * existing collapsed "Office Administration" group (created / uid / work_order)
 * is left as-is.
 *
 * Idempotent. Run: drush php:script web/scripts/wo_time_clock_form_leanup.php
 */

$fd = \Drupal::service('entity_display.repository')->getFormDisplay('wo_time_clock', 'entry');

// Collapsed Notes group (mirrors group_office_admin's format).
$fd->setThirdPartySetting('field_group', 'group_entry_notes', [
  'children' => ['field_notes'],
  'label' => 'Notes',
  'region' => 'content',
  'parent_name' => '',
  'weight' => 5,
  'format_type' => 'details',
  'format_settings' => [
    'classes' => '',
    'show_empty_fields' => FALSE,
    'id' => '',
    'open' => FALSE,
    'description' => 'Expand to add or edit a note on this time entry.',
    'required_fields' => TRUE,
  ],
]);

// Office Administration group stays created/uid/work_order only — make sure the
// teammate is NOT tucked in here (foremen need it visible for manual entry).
$admin = $fd->getThirdPartySetting('field_group', 'group_office_admin');
if ($admin) {
  $admin['children'] = array_values(array_filter($admin['children'], fn($c) => $c !== 'field_teammate'));
  $admin['weight'] = 6;
  $fd->setThirdPartySetting('field_group', 'group_office_admin', $admin);
}

// Primary visible field order: Teammate, Start, End, override. Notes first
// inside the collapsed notes group.
$weights = [
  'field_teammate' => 1,
  'field_start_time' => 2,
  'field_end_time' => 3,
  'field_time_limit_override' => 4,
  'field_notes' => 0,
];
foreach ($weights as $field => $weight) {
  if (($c = $fd->getComponent($field))) {
    $c['weight'] = $weight;
    $fd->setComponent($field, $c);
  }
}

$fd->save();

print "wo_time_clock entry form leaned up: Start/End visible, Notes + Office Admin collapsed\n";
