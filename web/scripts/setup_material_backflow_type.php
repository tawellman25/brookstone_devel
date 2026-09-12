<?php

/**
 * Add field_backflow_type (entity_reference -> taxonomy_term, restricted to the
 * backflow_device_types vocab, single) to the material/backflow bundle, and put
 * it on the form display so the office can set/curate a product's backflow type.
 *
 * IDEMPOTENT; run per env. Entity-API only (no cim — field-instance configs
 * silently skip on cim; this is the BOS idiom, per setup_hardscape_fields.php).
 *
 *   ddev drush php:script web/scripts/setup_material_backflow_type.php   (dev)
 *   drush php:script web/scripts/setup_material_backflow_type.php        (live)
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$BUNDLE = 'backflow';

if (!FieldStorageConfig::loadByName('material', 'field_backflow_type')) {
  FieldStorageConfig::create([
    'field_name' => 'field_backflow_type',
    'entity_type' => 'material',
    'type' => 'entity_reference',
    'settings' => ['target_type' => 'taxonomy_term'],
    'cardinality' => 1,
  ])->save();
  print "storage created: field_backflow_type\n";
}
else {
  print "storage exists: field_backflow_type\n";
}

if (!FieldConfig::loadByName('material', $BUNDLE, 'field_backflow_type')) {
  FieldConfig::create([
    'field_name' => 'field_backflow_type',
    'entity_type' => 'material',
    'bundle' => $BUNDLE,
    'label' => 'Backflow Type',
    'description' => 'The backflow assembly type this product is. Set on device products (RP/PVB/DCVA/SVB); leave blank on parts and repair kits. Drives the canonical type hub link on the product page.',
    'required' => FALSE,
    'settings' => [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => ['backflow_device_types' => 'backflow_device_types'],
        'sort' => ['field' => 'name', 'direction' => 'asc'],
        'auto_create' => FALSE,
      ],
    ],
  ])->save();
  print "instance created: material.$BUNDLE.field_backflow_type\n";
}
else {
  print "instance exists: material.$BUNDLE.field_backflow_type\n";
}

// Form display: add the widget just under Product Name if not already placed.
$repo = \Drupal::service('entity_display.repository');
$form = $repo->getFormDisplay('material', $BUNDLE);
if (!$form->getComponent('field_backflow_type')) {
  $name_weight = ($form->getComponent('field_name')['weight'] ?? 0);
  $form->setComponent('field_backflow_type', [
    'type' => 'options_select',
    'weight' => $name_weight + 1,
    'region' => 'content',
  ])->save();
  print "form display: field_backflow_type placed (weight " . ($name_weight + 1) . ")\n";
}
else {
  print "form display: field_backflow_type already placed\n";
}

print "done.\n";
