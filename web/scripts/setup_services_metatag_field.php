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
$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$hits = $ts->loadByProperties(['vid' => 'services', 'name' => 'Sprinkler Systems']);
if ($hits) {
  $term = reset($hits);
  if ($term->get('field_meta_tags')->isEmpty()) {
    $term->set('field_meta_tags', ['value' => serialize([
      'title' => 'Sprinkler Systems | Brookstone Outdoors | Delta & Montrose CO',
      'description' => 'Sprinkler system design, installation, repair, start-up, check-ups and winterizing across Delta and Montrose counties, Colorado.',
    ])])->save();
    $out[] = 'Sprinkler Systems (' . $term->id() . ') meta tags seeded';
  }
  else {
    $out[] = 'Sprinkler Systems already has meta tags — left as-is';
  }
}
else {
  $out[] = 'Sprinkler Systems term not found';
}

print implode("\n", $out) . "\nDONE.\n";
