<?php

/**
 * @file
 * Add field_hoa (entity_reference → properties/hoa) to the residential
 * `property` bundle. Records which HOA a home belongs to — set automatically by
 * the GPS point-in-polygon scan (bos_hoa). Its presence marks a home as
 * AUTO-managed, so the scan can safely add/remove the HOA discount without ever
 * touching a manually-flagged home.
 *
 * ECK/field configs silent-skip on cim → this script IS the deploy path.
 * Idempotent. Run: drush php:script web/scripts/setup_property_field_hoa.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$ENTITY = 'properties';
$BUNDLE = 'property';
$FIELD = 'field_hoa';
$out = [];

if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  FieldStorageConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'type' => 'entity_reference',
    'cardinality' => 1, 'settings' => ['target_type' => 'properties'],
  ])->save();
  $out[] = "created storage $FIELD";
}
if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_storage' => FieldStorageConfig::loadByName($ENTITY, $FIELD),
    'bundle' => $BUNDLE, 'label' => 'HOA', 'required' => FALSE,
    'description' => 'The HOA this home falls within (set automatically by the GPS boundary scan). Auto-managed: while set, the HOA discount is applied/removed by the scan.',
    'settings' => [
      'handler' => 'default:properties',
      'handler_settings' => [
        'target_bundles' => ['hoa' => 'hoa'],
        'sort' => ['field' => '_none'], 'auto_create' => FALSE,
      ],
    ],
  ])->save();
  $out[] = "instanced $FIELD on $BUNDLE";
}

$fd = EntityFormDisplay::load("$ENTITY.$BUNDLE.default");
if ($fd && !$fd->getComponent($FIELD)) {
  $fd->setComponent($FIELD, ['type' => 'entity_reference_autocomplete', 'weight' => 22, 'region' => 'content', 'settings' => ['match_operator' => 'CONTAINS', 'size' => 60, 'placeholder' => ''], 'third_party_settings' => []])->save();
  $out[] = 'form: added field_hoa';
}
$vd = EntityViewDisplay::load("$ENTITY.$BUNDLE.default");
if ($vd && !$vd->getComponent($FIELD)) {
  $vd->setComponent($FIELD, ['type' => 'entity_reference_label', 'label' => 'inline', 'weight' => 22, 'region' => 'content', 'settings' => ['link' => TRUE], 'third_party_settings' => []])->save();
  $out[] = 'view: added field_hoa';
}

print implode("\n", $out) . "\nDONE.\n";
