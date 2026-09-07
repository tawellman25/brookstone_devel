<?php

/**
 * @file
 * Phase 1 — HOA / Common Area as a new bundle on the `properties` entity.
 *
 * Reuses the property page, map, and photo-gallery plumbing. Fields:
 *   reused:  field_nickname (HOA name), field_geofield (center point),
 *            field_hoa_contracted (is this HOA discounted?), field_neighborhood
 *   new:     field_boundary (geofield — GPS polygon for auto-detect),
 *            field_hoa_public_desc (public description),
 *            field_service_start / field_service_end (common-area timeframe)
 *
 * ECK/field configs silent-skip on cim → this script IS the deploy path.
 * Idempotent. Run: drush php:script web/scripts/setup_hoa_property_bundle.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$etm = \Drupal::entityTypeManager();
$ENTITY = 'properties';
$BUNDLE = 'hoa';
$out = [];

// 1. Bundle on properties (properties_type config entity).
$bundleStorage = $etm->getStorage('properties_type');
if (!$bundleStorage->load($BUNDLE)) {
  $bundleStorage->create([
    'type' => $BUNDLE,
    'name' => 'HOA / Common Area',
    'description' => 'A homeowners-association common area we maintain. Carries a GPS boundary (for auto-flagging member homes for the HOA discount) and a public showcase page (description, photos, service timeframe).',
  ])->save();
  $out[] = "created bundle properties.$BUNDLE";
}
else { $out[] = "bundle properties.$BUNDLE exists"; }

// 2. New field storages (reused ones already exist on `properties`).
$ensureStorage = function (string $name, string $type, array $settings) use (&$out, $ENTITY) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => $ENTITY, 'type' => $type,
      'cardinality' => 1, 'settings' => $settings,
    ])->save();
    $out[] = "created storage $name";
  }
};
$ensureStorage('field_boundary', 'geofield', ['backend' => 'geofield_backend_default']);
$ensureStorage('field_hoa_public_desc', 'text_long', []);
$ensureStorage('field_service_start', 'datetime', ['datetime_type' => 'date']);
$ensureStorage('field_service_end', 'datetime', ['datetime_type' => 'date']);

// 3. Field instances on the hoa bundle.
$ensureField = function (string $name, string $label, string $desc, bool $req = FALSE) use (&$out, $ENTITY, $BUNDLE) {
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    FieldConfig::create([
      'field_storage' => FieldStorageConfig::loadByName($ENTITY, $name),
      'bundle' => $BUNDLE, 'label' => $label, 'description' => $desc, 'required' => $req,
    ])->save();
    $out[] = "instanced $name";
  }
};
$ensureField('field_nickname', 'HOA Name', 'The HOA / common-area name (e.g. "Bear Creek").', TRUE);
$ensureField('field_neighborhood', 'Neighborhood', 'Neighborhood / subdivision label.');
$ensureField('field_geofield', 'Center Point', 'A representative map marker for the common area.');
$ensureField('field_boundary', 'Boundary (GPS)', 'The GPS boundary polygon. Member homes whose location falls inside a discounted HOA boundary are auto-flagged for the HOA discount.');
$ensureField('field_hoa_contracted', 'Discounted / Contracted HOA', 'When checked, homes inside this HOA get the winterizing HOA discount.');
$ensureField('field_hoa_public_desc', 'Public Description', 'Public-facing description of what we do for this common area (shown on the public HOA page).');
$ensureField('field_service_start', 'Service Started', 'When we began maintaining the common areas.');
$ensureField('field_service_end', 'Service Ended', 'When we stopped (leave empty while active).');

// 4. Form display.
$fd = EntityFormDisplay::load("$ENTITY.$BUNDLE.default");
if (!$fd) {
  $fd = EntityFormDisplay::create(['targetEntityType' => $ENTITY, 'bundle' => $BUNDLE, 'mode' => 'default', 'status' => TRUE]);
}
$w = 0;
$fd->setComponent('field_nickname', ['type' => 'string_textfield', 'weight' => $w++, 'region' => 'content', 'settings' => ['size' => 60], 'third_party_settings' => []]);
$fd->setComponent('field_neighborhood', ['type' => 'string_textfield', 'weight' => $w++, 'region' => 'content', 'settings' => ['size' => 40], 'third_party_settings' => []]);
$fd->setComponent('field_hoa_contracted', ['type' => 'boolean_checkbox', 'weight' => $w++, 'region' => 'content', 'settings' => ['display_label' => TRUE], 'third_party_settings' => []]);
$fd->setComponent('field_geofield', ['type' => 'geofield_default', 'weight' => $w++, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
$fd->setComponent('field_boundary', ['type' => 'geofield_default', 'weight' => $w++, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
$fd->setComponent('field_hoa_public_desc', ['type' => 'text_textarea', 'weight' => $w++, 'region' => 'content', 'settings' => ['rows' => 6], 'third_party_settings' => []]);
$fd->setComponent('field_service_start', ['type' => 'datetime_default', 'weight' => $w++, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
$fd->setComponent('field_service_end', ['type' => 'datetime_default', 'weight' => $w++, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
$fd->save();
$out[] = 'form display configured';

// 5. View display.
$vd = EntityViewDisplay::load("$ENTITY.$BUNDLE.default");
if (!$vd) {
  $vd = EntityViewDisplay::create(['targetEntityType' => $ENTITY, 'bundle' => $BUNDLE, 'mode' => 'default', 'status' => TRUE]);
}
$w = 0;
$vd->setComponent('field_neighborhood', ['type' => 'string', 'label' => 'inline', 'weight' => $w++, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
$vd->setComponent('field_hoa_contracted', ['type' => 'boolean', 'label' => 'inline', 'weight' => $w++, 'region' => 'content', 'settings' => ['format' => 'yes-no'], 'third_party_settings' => []]);
$vd->setComponent('field_hoa_public_desc', ['type' => 'text_default', 'label' => 'hidden', 'weight' => $w++, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
$vd->setComponent('field_service_start', ['type' => 'datetime_default', 'label' => 'inline', 'weight' => $w++, 'region' => 'content', 'settings' => ['format_type' => 'html_date'], 'third_party_settings' => []]);
$vd->setComponent('field_service_end', ['type' => 'datetime_default', 'label' => 'inline', 'weight' => $w++, 'region' => 'content', 'settings' => ['format_type' => 'html_date'], 'third_party_settings' => []]);
$vd->save();
$out[] = 'view display configured';

// 6. Auto-label HOA entities from the HOA Name (field_nickname).
$alName = 'auto_entitylabel.settings.properties.hoa';
$al = \Drupal::configFactory()->getEditable($alName);
if ($al->isNew() || $al->get('pattern') !== '[properties:field_nickname]') {
  $al->setData([
    'status' => 1,
    'pattern' => '[properties:field_nickname]',
    'escape' => FALSE,
    'preserve_titles' => FALSE,
    'save' => TRUE,
    'chunk' => 50,
    'dependencies' => ['config' => ['eck.eck_type.properties.hoa']],
  ])->save();
  $out[] = 'auto-label configured (HOA Name)';
}

print implode("\n", $out) . "\nDONE.\n";
