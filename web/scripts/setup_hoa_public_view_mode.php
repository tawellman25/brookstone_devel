<?php

/**
 * @file
 * Create the `public` view mode for properties + the properties.hoa.public
 * display (SHOWCASE — safe fields only). The canonical HOA page renders this to
 * anon/clients (role switch in bos_hoa_entity_view_mode_alter); internal roles
 * get the full page. Only fields added here are ever shown publicly, so the
 * bundle's inherited internal fields (contacts, gate code, WO notes, …) cannot
 * leak. Idempotent.
 *   drush php:script web/scripts/setup_hoa_public_view_mode.php
 */

use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$out = [];

// 1. The `public` view mode for properties.
if (!EntityViewMode::load('properties.public')) {
  EntityViewMode::create([
    'id' => 'properties.public',
    'targetEntityType' => 'properties',
    'label' => 'Public',
  ])->save();
  $out[] = 'created view mode properties.public';
}

// 2. The hoa public display — ONLY safe showcase fields.
$vd = EntityViewDisplay::load('properties.hoa.public');
if (!$vd) {
  $vd = EntityViewDisplay::create([
    'targetEntityType' => 'properties',
    'bundle' => 'hoa',
    'mode' => 'public',
    'status' => TRUE,
  ]);
}
// Safe fields only.
$safe = [
  'field_neighborhood' => ['type' => 'string', 'label' => 'inline', 'weight' => 0],
  'field_service_start' => ['type' => 'datetime_default', 'label' => 'inline', 'weight' => 1, 'settings' => ['format_type' => 'html_date']],
  'field_service_end' => ['type' => 'datetime_default', 'label' => 'inline', 'weight' => 2, 'settings' => ['format_type' => 'html_date']],
  'field_hoa_public_desc' => ['type' => 'text_default', 'label' => 'hidden', 'weight' => 3],
];
foreach ($safe as $name => $cfg) {
  $vd->setComponent($name, $cfg + ['region' => 'content', 'third_party_settings' => []]);
}
// Explicitly HIDE every other field so nothing internal can leak.
$efm = \Drupal::service('entity_field.manager');
foreach ($efm->getFieldDefinitions('properties', 'hoa') as $fname => $def) {
  if (strpos($fname, 'field_') !== 0) { continue; }
  if (!isset($safe[$fname])) { $vd->removeComponent($fname); }
}
$vd->save();
$out[] = 'configured properties.hoa.public display (safe fields only)';

print implode("\n", $out) . "\nDONE.\n";
