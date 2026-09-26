<?php

declare(strict_types=1);

/**
 * Add the remaining content-tier fields to the backflow_uses vocabulary so it
 * matches the "ALL FIELDS" marketing spec:
 *   field_short_description  text_long  (landing-card teaser)
 *   field_list_order         integer    (landing-card order)
 *   field_meta_tags          metatag    (SEO override — feeds meta tags, no hook)
 *
 * field_public_description + field_teammate_description already exist on the
 * bundle. All three storages below already exist on taxonomy_term (reused, not
 * created) — this adds the INSTANCES + their default form widgets. Idempotent;
 * field configs can silently skip on cim, so the entity-API create is the
 * reliable mechanism. Run per env.
 *
 *   drush php:script web/scripts/setup_backflow_uses_content_fields.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'taxonomy_term';
$BUNDLE = 'backflow_uses';

// [field, label, required, instance settings, form widget, widget settings, weight]
$FIELDS = [
  [
    'field_short_description', 'Short Description', FALSE, [],
    'text_textarea', ['rows' => 4, 'placeholder' => ''], 2,
  ],
  [
    'field_list_order', 'List Order', FALSE, [],
    'number', ['placeholder' => ''], 3,
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
