<?php

declare(strict_types=1);

/**
 * Configure the audience view-mode displays for the material_types taxonomy
 * vocabulary (the category pages: Plants, Trees, Brass, …), matching the BOS
 * audience pattern used by services/backflow. Role routing lives in the
 * material module (office -> admin_view, crew -> teammate_view, else full).
 *
 *   full          (Public) -> field_public_description ONLY
 *   teammate_view (crew)   -> field_teammate_description
 *   admin_view    (office) -> everything (public + teammate + wording document)
 *
 * The `full` (public) display previously also showed field_teammate_description
 * and the internal wording document — this fixes that so public visitors see
 * only the public copy. Idempotent; entity-API. Run per env.
 *
 *   drush php:script web/scripts/setup_material_types_view_modes.php
 */

$ENTITY = 'taxonomy_term';
$BUNDLE = 'material_types';

$ALL = ['field_public_description', 'field_teammate_description', 'field_wording_document'];

// mode => [field => component]. Fields not listed are hidden on that display.
$PLAN = [
  'full' => [
    'field_public_description' => ['type' => 'text_default', 'label' => 'hidden', 'weight' => 0],
  ],
  'teammate_view' => [
    'field_teammate_description' => ['type' => 'text_default', 'label' => 'hidden', 'weight' => 0],
  ],
  'admin_view' => [
    'field_public_description' => ['type' => 'text_default', 'label' => 'above', 'weight' => 0],
    'field_teammate_description' => ['type' => 'text_default', 'label' => 'above', 'weight' => 1],
    'field_wording_document' => ['type' => 'file_default', 'label' => 'above', 'weight' => 2],
  ],
];

$storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
foreach ($PLAN as $mode => $components) {
  $id = "$ENTITY.$BUNDLE.$mode";
  $display = $storage->load($id);
  if (!$display) {
    $display = $storage->create([
      'targetEntityType' => $ENTITY,
      'bundle' => $BUNDLE,
      'mode' => $mode,
      'status' => TRUE,
    ]);
  }
  foreach ($ALL as $field) {
    if (isset($components[$field])) {
      $display->setComponent($field, $components[$field]);
    }
    else {
      $display->removeComponent($field);
    }
  }
  $display->save();
  print "set $mode: " . implode(', ', array_keys($components)) . "\n";
}
print "DONE.\n";
