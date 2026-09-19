<?php

/**
 * Add a per-term Meta tags override field (field_meta_tags, type metatag) to the
 * services taxonomy so office can set a custom SEO title/description per service
 * in the term edit form. Overrides the global taxonomy_term metatag defaults.
 *
 * Also seeds Sprinkler Systems as the first example.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/setup_services_metatag_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$out = [];

if (!FieldStorageConfig::loadByName('taxonomy_term', 'field_meta_tags')) {
  FieldStorageConfig::create([
    'field_name' => 'field_meta_tags',
    'entity_type' => 'taxonomy_term',
    'type' => 'metatag',
    'cardinality' => 1,
  ])->save();
  $out[] = 'storage taxonomy_term.field_meta_tags created';
}
if (!FieldConfig::loadByName('taxonomy_term', 'services', 'field_meta_tags')) {
  FieldConfig::create([
    'field_name' => 'field_meta_tags',
    'entity_type' => 'taxonomy_term',
    'bundle' => 'services',
    'label' => 'Meta tags',
    'description' => 'Optional SEO overrides for this service page. Leave blank to use the site default (name + brand + geo).',
  ])->save();
  $out[] = 'field_meta_tags added to services';
}

// Place the widget on the services term form.
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('taxonomy_term', 'services', 'default');
if (!$fd->getComponent('field_meta_tags')) {
  $fd->setComponent('field_meta_tags', ['type' => 'metatag_firehose', 'weight' => 50, 'region' => 'content'])->save();
  $out[] = 'field_meta_tags added to the services term form';
}

// Seed Sprinkler Systems as the first example.
// NOTE: Metatag 2.x stores field_meta_tags as JSON — read/write with json,
// and MERGE so we never clobber an existing og_image / other tags.
$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$hits = $ts->loadByProperties(['vid' => 'services', 'name' => 'Sprinkler Systems']);
if ($hits) {
  $term = reset($hits);
  $raw = $term->get('field_meta_tags')->value;
  $tags = $raw ? (json_decode($raw, TRUE) ?: []) : [];
  $want = [
    'title' => 'Sprinkler Systems | Brookstone Outdoors | Delta & Montrose CO',
    'description' => 'Sprinkler system design, installation, repair, start-up, check-ups and winterizing across Delta and Montrose counties, Colorado.',
  ];
  $merged = $tags + $want;
  // Only fill missing title/description; never overwrite office edits.
  foreach ($want as $k => $v) {
    if (empty($tags[$k])) {
      $merged[$k] = $v;
    }
  }
  if ($merged !== $tags) {
    $term->set('field_meta_tags', ['value' => json_encode($merged)])->save();
    $out[] = 'Sprinkler Systems (' . $term->id() . ') title/description restored (JSON merge)';
  }
  else {
    $out[] = 'Sprinkler Systems already complete — left as-is';
  }
}
else {
  $out[] = 'Sprinkler Systems term not found';
}

print implode("\n", $out) . "\nDONE.\n";
