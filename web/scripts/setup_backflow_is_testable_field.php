<?php

declare(strict_types=1);

/**
 * Add field_is_testable (boolean) to the backflow_device_types vocabulary.
 *
 * A testable assembly (PVB/RP/DCVA/SVB) has test cocks + shutoffs and needs the
 * annual Colorado certification; a non-testable device (AVB, dual check) cannot
 * be gauge-tested and must NOT receive a next-due date or appear in compliance
 * reminders. This flag is the data-model guard for that distinction.
 *
 * Default TRUE (fails safe: a future term is presumed testable unless flagged).
 *
 * Idempotent; run per env (field configs can silently skip on cim, so the
 * entity-API create is the reliable mechanism — sync YAMLs are committed for
 * git record). Setting the per-term VALUES is content — see
 * seed_backflow_device_types.php.
 *
 *   drush php:script web/scripts/setup_backflow_is_testable_field.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'taxonomy_term';
$BUNDLE = 'backflow_device_types';
$FIELD = 'field_is_testable';
$HELP = "A testable assembly has test cocks and shutoff valves and is subject to Colorado's annual certification requirement (Reg 11.39 §11.39). A non-testable device — AVB, dual check, hose bibb breaker — cannot be gauge-tested in place and is inspected and replaced on condition. Devices of a non-testable type must not receive a next-due date or appear in compliance reminders.";

// 1. Field storage.
if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  FieldStorageConfig::create([
    'field_name' => $FIELD,
    'entity_type' => $ENTITY,
    'type' => 'boolean',
    'cardinality' => 1,
  ])->save();
  print "+ created field storage $ENTITY.$FIELD\n";
}
else {
  print "= field storage $ENTITY.$FIELD already exists\n";
}

// 2. Field instance on the vocabulary.
if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_name' => $FIELD,
    'entity_type' => $ENTITY,
    'bundle' => $BUNDLE,
    'label' => 'Testable assembly',
    'required' => FALSE,
    'description' => $HELP,
    'default_value' => [['value' => 1]],
    'settings' => ['on_label' => 'Testable', 'off_label' => 'Non-testable'],
  ])->save();
  print "+ created field instance $ENTITY.$BUNDLE.$FIELD\n";
}
else {
  print "= field instance already exists\n";
}

// 3. Form display: single on/off checkbox.
$formDisplay = \Drupal::service('entity_display.repository')
  ->getFormDisplay($ENTITY, $BUNDLE, 'default');
if (!$formDisplay->getComponent($FIELD)) {
  $formDisplay->setComponent($FIELD, [
    'type' => 'boolean_checkbox',
    'weight' => 5,
    'settings' => ['display_label' => TRUE],
  ])->save();
  print "+ added $FIELD to the default form display (boolean_checkbox)\n";
}
else {
  print "= form display component already present\n";
}

print "DONE.\n";
