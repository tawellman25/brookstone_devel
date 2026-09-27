<?php

declare(strict_types=1);

/**
 * Add field_public_description + field_short_description INSTANCES to the
 * spraying_locations vocabulary (shared taxonomy_term storage already exists —
 * used by backflow_uses / material_types / wind_direction), and (re)build its
 * three audience view-mode displays so the new public copy renders and the crew
 * field stays teammate-only.
 *
 * Display model (governed by bos_spray_types — office->admin_view, crew->
 * teammate_view, else full, with the user.roles cache context):
 *   - full (public):  name, field_short_description (lead), field_public_description
 *                     (body), description (kept ONLY so Arena/Driveway — whose good
 *                     copy lives in core description and which we do NOT touch —
 *                     keep rendering), field_applicable_services. NO crew field.
 *   - teammate_view:  name, field_teammate_description (crew instruction only).
 *   - admin_view:     everything.
 *
 * Idempotent. Field configs skip on cim, so this script is the deploy path.
 *
 *   drush php:script web/scripts/setup_spraying_locations_fields.php
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;

$etm = \Drupal::entityTypeManager();
$vid = 'spraying_locations';

/* 1. Field instances (+ form widgets). */
$newFields = [
  'field_public_description' => 'Public Description',
  'field_short_description' => 'Short Description',
];
$formDisplay = $etm->getStorage('entity_form_display')->load("taxonomy_term.$vid.default");
$w = 3;
foreach ($newFields as $field => $label) {
  $storage = $etm->getStorage('field_storage_config')->load("taxonomy_term.$field");
  if (!$storage) {
    print "ERROR: storage taxonomy_term.$field missing — aborting.\n";
    return;
  }
  if (!$etm->getStorage('field_config')->load("taxonomy_term.$vid.$field")) {
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $vid,
      'label' => $label,
      'required' => FALSE,
    ])->save();
    print "created instance taxonomy_term.$vid.$field\n";
  }
  else {
    print "instance taxonomy_term.$vid.$field exists\n";
  }
  if ($formDisplay && !$formDisplay->getComponent($field)) {
    $formDisplay->setComponent($field, ['type' => 'text_textarea', 'weight' => $w++, 'settings' => ['rows' => 6]]);
  }
}
if ($formDisplay) {
  $formDisplay->save();
  print "form widgets ensured\n";
}

/* 2. Rebuild the three displays. */
$FULL = ['name', 'field_short_description', 'field_public_description', 'description', 'field_applicable_services'];
$TEAMMATE = ['name', 'field_teammate_description'];
$ADMIN = ['name', 'field_short_description', 'field_public_description', 'description', 'field_teammate_description', 'field_applicable_services'];

$default = $etm->getStorage('entity_view_display')->load("taxonomy_term.$vid.default");
$defaultComponents = $default ? ($default->get('content') ?? []) : [];

$synth = function (string $field) use ($etm, $vid): array {
  if ($field === 'name') {
    return ['type' => 'string', 'label' => 'hidden', 'settings' => ['link_to_entity' => FALSE]];
  }
  $fc = $etm->getStorage('field_config')->load("taxonomy_term.$vid.$field");
  $type = $fc ? $fc->getType() : 'string';
  $fmt = match ($type) {
    'text_long', 'text_with_summary', 'text' => 'text_default',
    'entity_reference' => 'entity_reference_label',
    default => 'string',
  };
  return ['type' => $fmt, 'label' => 'hidden', 'settings' => []];
};

$build = function (string $mode, array $fields) use ($etm, $vid, $defaultComponents, $synth) {
  $id = "taxonomy_term.$vid.$mode";
  $disp = $etm->getStorage('entity_view_display')->load($id)
    ?: EntityViewDisplay::create(['targetEntityType' => 'taxonomy_term', 'bundle' => $vid, 'mode' => $mode, 'status' => TRUE]);
  $disp->setStatus(TRUE);
  foreach (array_keys($disp->get('content') ?? []) as $f) {
    $disp->removeComponent($f);
  }
  $weight = 0;
  foreach ($fields as $field) {
    $comp = $defaultComponents[$field] ?? $synth($field);
    $comp['weight'] = $weight++;
    if (!isset($comp['label'])) {
      $comp['label'] = 'hidden';
    }
    $disp->setComponent($field, $comp);
  }
  $disp->save();
  print "  built $id [" . implode(',', $fields) . "]\n";
};

$build('full', $FULL);
$build('teammate_view', $TEAMMATE);
$build('admin_view', $ADMIN);

print "DONE.\n";
