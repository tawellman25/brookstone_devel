<?php

/**
 * Add a per-entity Meta tags override field (field_meta_tags, type metatag) to
 * the state / county / city ECK entities so office can set a custom SEO
 * title / description / Open Graph per geo page in the entity edit form.
 * Overrides the site defaults + the auto-derived description in
 * bos_state_metatags_alter().
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/setup_geo_metatag_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

// state/county/city ECK entities each have bundle == entity-type name.
$types = ['state', 'county', 'city'];
$out = [];

foreach ($types as $type) {
  if (!FieldStorageConfig::loadByName($type, 'field_meta_tags')) {
    FieldStorageConfig::create([
      'field_name' => 'field_meta_tags',
      'entity_type' => $type,
      'type' => 'metatag',
      'cardinality' => 1,
    ])->save();
    $out[] = "storage $type.field_meta_tags created";
  }

  if (!FieldConfig::loadByName($type, $type, 'field_meta_tags')) {
    FieldConfig::create([
      'field_name' => 'field_meta_tags',
      'entity_type' => $type,
      'bundle' => $type,
      'label' => 'Meta tags',
      'description' => 'Optional SEO overrides for this page (title, meta description, Open Graph). Leave blank to use the automatic description + banner image.',
    ])->save();
    $out[] = "field_meta_tags added to $type";
  }

  // Place the widget on the entity form.
  $fd = \Drupal::service('entity_display.repository')->getFormDisplay($type, $type, 'default');
  if (!$fd->getComponent('field_meta_tags')) {
    $fd->setComponent('field_meta_tags', [
      'type' => 'metatag_firehose',
      'weight' => 50,
      'region' => 'content',
    ])->save();
    $out[] = "field_meta_tags widget added to the $type form";
  }
}

print implode("\n", $out) . "\nDONE.\n";
