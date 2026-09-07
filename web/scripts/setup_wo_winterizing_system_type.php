<?php

/**
 * @file
 * Add field_system_type to work_order.sprinkler_winterizing.
 *
 * Entity-ref to sprinkler_system_types (radios), so the winterizing WO records
 * the System Type (Domestic / Dirty / Duel / Well) that drives billing — a
 * per-job snapshot the crew can see and correct on site, frozen at completion.
 *
 * ECK/field configs silent-skip on cim, so this script IS the deploy path.
 * Idempotent — safe to re-run on dev + live.
 *
 * Run: ddev drush php:script web/scripts/setup_wo_winterizing_system_type.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$etm = \Drupal::entityTypeManager();
$ENTITY = 'work_order';
$BUNDLE = 'sprinkler_winterizing';
$FIELD = 'field_system_type';

// 1. Field storage (work_order.field_system_type → sprinkler_system_types).
$storage = FieldStorageConfig::loadByName($ENTITY, $FIELD);
if (!$storage) {
  FieldStorageConfig::create([
    'field_name' => $FIELD,
    'entity_type' => $ENTITY,
    'type' => 'entity_reference',
    'settings' => ['target_type' => 'sprinkler_system_types'],
    'cardinality' => 1,
    'translatable' => TRUE,
  ])->save();
  print "created field storage $ENTITY.$FIELD\n";
}
else {
  print "field storage $ENTITY.$FIELD already exists\n";
}

// 2. Field instance on the winterizing bundle.
$field = FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD);
if (!$field) {
  FieldConfig::create([
    'field_name' => $FIELD,
    'entity_type' => $ENTITY,
    'bundle' => $BUNDLE,
    'label' => 'System Type',
    'description' => 'Dirty/Duel/Well require a pump ($25 winterizing pump fee). Pre-filled from the property; correct it here on site — it drives billing and is frozen at completion.',
    'required' => FALSE,
    'translatable' => FALSE,
    'settings' => [
      'handler' => 'default:sprinkler_system_types',
      'handler_settings' => [
        'target_bundles' => ['types' => 'types'],
        'sort' => ['field' => 'title', 'direction' => 'ASC'],
        'auto_create' => FALSE,
        'auto_create_bundle' => '',
      ],
    ],
  ])->save();
  print "created field instance $ENTITY.$BUNDLE.$FIELD\n";
}
else {
  print "field instance $ENTITY.$BUNDLE.$FIELD already exists\n";
}

// 3. Form display — radios, near the top (weight below status/property).
$form = $etm->getStorage('entity_form_display')->load("$ENTITY.$BUNDLE.default");
if ($form && !$form->getComponent($FIELD)) {
  $form->setComponent($FIELD, [
    'type' => 'options_buttons',
    'weight' => -5,
    'region' => 'content',
    'settings' => [],
    'third_party_settings' => [],
  ])->save();
  print "added $FIELD to form display\n";
}
else {
  print "form display already has $FIELD (or no form display)\n";
}

// 4. View display — label above, near the top.
$view = $etm->getStorage('entity_view_display')->load("$ENTITY.$BUNDLE.default");
if ($view && !$view->getComponent($FIELD)) {
  $view->setComponent($FIELD, [
    'type' => 'entity_reference_label',
    'label' => 'above',
    'weight' => -5,
    'region' => 'content',
    'settings' => ['link' => FALSE],
    'third_party_settings' => [],
  ])->save();
  print "added $FIELD to view display\n";
}
else {
  print "view display already has $FIELD (or no view display)\n";
}

print "DONE.\n";
