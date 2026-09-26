<?php

declare(strict_types=1);

/**
 * Build the audience view-mode displays for the backflow_uses vocabulary:
 *   - full         (public)  -> field_public_description
 *   - teammate_view (crew)   -> field_teammate_description
 *   - admin_view  (office)   -> everything visible
 *
 * The role -> view-mode routing lives in bos_backflow_types (which governs both
 * backflow_device_types and backflow_uses via BOS_BACKFLOW_TYPES_VIDS).
 * backflow_uses has no banner field, so there is no hero here — this is the
 * plain 3-tier pattern (mirrors bos_services).
 *
 * Idempotent; edits active config via the entity API (so the view-mode displays
 * are registered — a raw configFactory save leaves them unregistered). Run per
 * env.
 *
 *   drush php:script web/scripts/setup_backflow_uses_view_modes.php
 */

$ENTITY = 'taxonomy_term';
$BUNDLE = 'backflow_uses';

$ALL = [
  'field_short_description',
  'field_public_description',
  'field_teammate_description',
  'field_use_code',
  'field_list_order',
  'field_meta_tags',
];

// mode => [field => component]. Fields not listed are hidden on that display.
// field_meta_tags stays hidden on every display (edited on the form; the metatag
// module feeds the page's meta tags automatically). field_short_description /
// field_list_order surface only on the landing view + admin_view.
$PLAN = [
  'full' => [
    'field_public_description' => ['type' => 'text_default', 'label' => 'hidden', 'weight' => 0],
  ],
  'teammate_view' => [
    'field_teammate_description' => ['type' => 'text_default', 'label' => 'hidden', 'weight' => 0],
  ],
  'admin_view' => [
    'field_short_description' => ['type' => 'text_default', 'label' => 'above', 'weight' => 0],
    'field_public_description' => ['type' => 'text_default', 'label' => 'above', 'weight' => 1],
    'field_teammate_description' => ['type' => 'text_default', 'label' => 'above', 'weight' => 2],
    'field_use_code' => ['type' => 'string', 'label' => 'inline', 'weight' => 3],
    'field_list_order' => ['type' => 'number_integer', 'label' => 'inline', 'weight' => 4],
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
  print "set display $mode: " . implode(', ', array_keys($components)) . "\n";
}
print "DONE.\n";
