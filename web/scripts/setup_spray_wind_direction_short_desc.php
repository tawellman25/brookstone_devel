<?php

declare(strict_types=1);

/**
 * Add the field_short_description INSTANCE to the wind_direction vocabulary
 * (the shared taxonomy_term storage already exists — used by backflow_uses,
 * brookstone_tags, growth_zone). Same pattern as the backflow_uses work.
 *
 * Step 2 of the spray parent-views task puts a public one-line description on
 * each wind_direction leaf term in this field (the crew instruction goes in the
 * separate, teammate-gated field_teammate_description). No new storage; instance
 * + form widget only. Idempotent. Field configs silently skip on cim, so this
 * script is the deploy path (run per env).
 *
 *   drush php:script web/scripts/setup_spray_wind_direction_short_desc.php
 */

use Drupal\field\Entity\FieldConfig;

$etm = \Drupal::entityTypeManager();
$vid = 'wind_direction';
$field = 'field_short_description';

$storage = $etm->getStorage('field_storage_config')->load("taxonomy_term.$field");
if (!$storage) {
  print "ERROR: storage taxonomy_term.$field missing — aborting.\n";
  return;
}

// 1. Field instance.
$fc = $etm->getStorage('field_config')->load("taxonomy_term.$vid.$field");
if (!$fc) {
  FieldConfig::create([
    'field_storage' => $storage,
    'bundle' => $vid,
    'label' => 'Short Description',
    'required' => FALSE,
    'description' => 'One public sentence shown on the leaf term page and usable as a landing teaser.',
  ])->save();
  print "created instance taxonomy_term.$vid.$field\n";
}
else {
  print "instance taxonomy_term.$vid.$field already exists\n";
}

// 2. Add to the default form display so the office can edit it.
$fd = $etm->getStorage('entity_form_display')->load("taxonomy_term.$vid.default");
if ($fd) {
  if (!$fd->getComponent($field)) {
    $fd->setComponent($field, [
      'type' => 'text_textarea',
      'weight' => 2,
      'settings' => ['rows' => 3],
    ])->save();
    print "added $field to wind_direction edit form\n";
  }
  else {
    print "$field already on wind_direction edit form\n";
  }
}

print "DONE.\n";
