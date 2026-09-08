<?php

/**
 * @file
 * Add field_zone_count (list_string) to service_request.sprinkler_winterizing,
 * mirroring field_water_supply. Captures a rough zone count from the /winterize
 * form so the office can quote before the crew rolls, route the job's real
 * length, and get zone data for the 2027 pricing decision.
 *
 * ECK/field configs silent-skip on cim → this script IS the deploy path.
 * Idempotent. Run: drush php:script web/scripts/setup_service_request_zone_count.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'service_request';
$BUNDLE = 'sprinkler_winterizing';
$FIELD = 'field_zone_count';
$out = [];

$allowed = [
  'unsure' => 'Not sure',
  '1_5' => '1–5 zones',
  '6_10' => '6–10 zones',
  '11_plus' => '11 or more',
];

if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  FieldStorageConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'type' => 'list_string',
    'cardinality' => 1, 'settings' => ['allowed_values' => $allowed],
  ])->save();
  $out[] = "created storage $FIELD (list_string)";
}
if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_storage' => FieldStorageConfig::loadByName($ENTITY, $FIELD),
    'bundle' => $BUNDLE, 'label' => 'How many zones?', 'required' => FALSE,
    'description' => "A rough count is fine — we'll confirm on site.",
  ])->save();
  $out[] = "instanced $FIELD on $BUNDLE";
}

print implode("\n", $out ?: ['already present']) . "\nDONE.\n";
