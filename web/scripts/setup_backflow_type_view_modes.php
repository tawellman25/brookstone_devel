<?php

declare(strict_types=1);

/**
 * Build the audience view-mode displays for backflow_device_types:
 *   - full         (public)  -> field_public_description (banner shows as hero)
 *   - teammate_view (crew)   -> field_teammate_description
 *   - admin_view  (office)   -> everything visible
 *
 * The role → view-mode routing + the rotating hero live in bos_backflow_types.
 * field_meta_tags stays hidden on every display (SEO override edited on the
 * form; the metatag module feeds it automatically). field_banner_images is left
 * OFF the public/teammate body (the hero renders it) and shown small on admin.
 *
 * Idempotent; edits active config via the entity API (so view-mode displays are
 * registered). Run per env.
 *
 *   drush php:script web/scripts/setup_backflow_type_view_modes.php
 */

$ENTITY = 'taxonomy_term';
$BUNDLE = 'backflow_device_types';

$ALL = [
  'field_short_description',
  'field_public_description',
  'field_teammate_description',
  'field_type_code',
  'field_is_testable',
  'field_banner_images',
  'field_meta_tags',
];

// mode => [field => component]. Fields not listed are hidden on that display.
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
    'field_type_code' => ['type' => 'list_default', 'label' => 'inline', 'weight' => 3],
    'field_is_testable' => ['type' => 'boolean', 'label' => 'inline', 'weight' => 4, 'settings' => ['format' => 'default', 'format_custom_false' => '', 'format_custom_true' => '']],
    'field_banner_images' => ['type' => 'image', 'label' => 'above', 'weight' => 5, 'settings' => ['image_style' => 'thumbnail', 'image_link' => '']],
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
