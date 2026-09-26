<?php

declare(strict_types=1);

/**
 * Add content fields to the backflow_device_types vocabulary, mirroring the
 * services vocab's content tier: banner images, meta-tag SEO override, a short
 * description (teaser), and a teammate (crew-facing) description.
 *
 * All four field STORAGES already exist on taxonomy_term (reused, not created):
 *   field_banner_images        image, multi   (also on equipment_types)
 *   field_meta_tags            metatag        (also on services)
 *   field_short_description     text_long      (also on brookstone_tags/growth_zone)
 *   field_teammate_description  text_long      (also on backflow_uses + many)
 *
 * This adds the INSTANCES on backflow_device_types + their default form widgets.
 * (metatag-type fields feed the entity's meta tags automatically — no hook.)
 * Idempotent; run per env. Fields configs can silently skip on cim, so the
 * entity-API create is the reliable mechanism; sync YAMLs committed for record.
 *
 *   drush php:script web/scripts/setup_backflow_device_type_content_fields.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'taxonomy_term';
$BUNDLE = 'backflow_device_types';

// [field, label, required, instance settings, form widget type, widget settings, weight]
$FIELDS = [
  [
    'field_short_description', 'Short Description', FALSE, [],
    'text_textarea', ['rows' => 5, 'placeholder' => ''], 3,
  ],
  [
    'field_banner_images', 'Banner Images', FALSE,
    [
      'file_directory' => 'backflow/type/banner_images',
      'file_extensions' => 'png gif jpg jpeg webp',
      'alt_field' => TRUE,
      'alt_field_required' => TRUE,
      'title_field' => FALSE,
      'max_resolution' => '',
      'min_resolution' => '',
    ],
    'image_image', ['preview_image_style' => 'thumbnail', 'progress_indicator' => 'throbber'], 6,
  ],
  [
    'field_teammate_description', 'Teammate Description', FALSE, [],
    'text_textarea', ['rows' => 5, 'placeholder' => ''], 7,
  ],
  [
    'field_meta_tags', 'Meta tags', FALSE, [],
    'metatag_firehose', ['sidebar' => TRUE, 'use_details' => TRUE], 50,
  ],
];

$formDisplay = \Drupal::service('entity_display.repository')->getFormDisplay($ENTITY, $BUNDLE, 'default');

foreach ($FIELDS as [$field, $label, $required, $settings, $widget, $widgetSettings, $weight]) {
  if (!FieldStorageConfig::loadByName($ENTITY, $field)) {
    print "!! storage $ENTITY.$field missing — expected to exist; skipping\n";
    continue;
  }
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $field)) {
    FieldConfig::create([
      'field_name' => $field,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => $label,
      'required' => $required,
      'settings' => $settings,
    ])->save();
    print "+ instance $field ($label)\n";
  }
  else {
    print "= instance $field already exists\n";
  }
  if (!$formDisplay->getComponent($field)) {
    $formDisplay->setComponent($field, [
      'type' => $widget,
      'weight' => $weight,
      'settings' => $widgetSettings,
    ]);
    print "  + form widget $field ($widget)\n";
  }
  else {
    print "  = form widget $field already present\n";
  }
}
$formDisplay->save();
print "DONE.\n";
