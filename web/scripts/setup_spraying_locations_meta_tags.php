<?php

declare(strict_types=1);

/**
 * Add the field_meta_tags SEO-override instance to the spraying_locations vocab.
 *
 * Why: `metatag.metatag_defaults.taxonomy_term` sets
 * `description: '[term:description]'` and `metatag.metatag_defaults.global`
 * defines NO `description` tag at all — so once core `description` was retired on
 * this vocabulary (2026-09-27) every location page shipped with no meta
 * description and no fallback. A per-term override field is the scoped fix;
 * repointing the sitewide taxonomy default would touch every vocabulary.
 *
 * The `taxonomy_term.field_meta_tags` storage already exists (services,
 * backflow_uses, geo) — this adds the INSTANCE + the metatag_firehose form
 * widget, mirroring setup_backflow_uses_content_fields.php. The field is
 * deliberately NOT added to any view display: metatag consumes it directly, and
 * office edits it on the term form.
 *
 * Idempotent; field configs can silently skip on cim, so the entity-API create is
 * the reliable mechanism. Run per env.
 *
 *   drush php:script web/scripts/setup_spraying_locations_meta_tags.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'taxonomy_term';
$BUNDLE = 'spraying_locations';
$FIELD = 'field_meta_tags';

if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  print "ERROR: storage $ENTITY.$FIELD missing — expected to exist. Aborting.\n";
  return;
}

if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_name' => $FIELD,
    'entity_type' => $ENTITY,
    'bundle' => $BUNDLE,
    'label' => 'Meta tags',
    'required' => FALSE,
    'settings' => [],
  ])->save();
  print "created instance $ENTITY.$BUNDLE.$FIELD\n";
}
else {
  print "instance $ENTITY.$BUNDLE.$FIELD exists\n";
}

$formDisplay = \Drupal::service('entity_display.repository')->getFormDisplay($ENTITY, $BUNDLE, 'default');
if (!$formDisplay->getComponent($FIELD)) {
  $formDisplay->setComponent($FIELD, [
    'type' => 'metatag_firehose',
    'weight' => 50,
    'settings' => ['sidebar' => TRUE, 'use_details' => TRUE],
  ])->save();
  print "added metatag_firehose widget to the term form (sidebar)\n";
}
else {
  print "form widget already present\n";
}

print "DONE.\n";
