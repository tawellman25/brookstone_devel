<?php

/**
 * @file
 * Add field_snow_signed_pdf (file, PDF) to contracts.snow_removal — the scanned
 * executed agreement. Idempotent. Files use public:// (s3fs on live).
 * Run: ddev drush php:script web/scripts/setup_snow_signed_pdf_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

if (!FieldStorageConfig::loadByName('contracts', 'field_snow_signed_pdf')) {
  FieldStorageConfig::create([
    'field_name' => 'field_snow_signed_pdf',
    'entity_type' => 'contracts',
    'type' => 'file',
    'settings' => ['uri_scheme' => 'public', 'target_type' => 'file', 'display_field' => FALSE, 'display_default' => FALSE],
    'cardinality' => 1,
  ])->save();
  print "storage created\n";
}
if (!FieldConfig::loadByName('contracts', 'snow_removal', 'field_snow_signed_pdf')) {
  FieldConfig::create([
    'field_name' => 'field_snow_signed_pdf',
    'entity_type' => 'contracts',
    'bundle' => 'snow_removal',
    'label' => 'Signed Contract (PDF)',
    'description' => 'The scanned, signed/executed Snow Removal Service Agreement.',
    'settings' => [
      'file_directory' => 'snow-signed-contracts/[date:custom:Y]',
      'file_extensions' => 'pdf',
      'max_filesize' => '',
      'description_field' => FALSE,
    ],
  ])->save();
  print "instance created\n";
}
foreach (['default', 'admin'] as $fd_id) {
  $fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("contracts.snow_removal.$fd_id");
  if ($fd && !$fd->getComponent('field_snow_signed_pdf')) {
    $fd->setComponent('field_snow_signed_pdf', ['type' => 'file_generic', 'weight' => 40])->save();
  }
}
$vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('contracts.snow_removal.default');
if ($vd && !$vd->getComponent('field_snow_signed_pdf')) {
  $vd->setComponent('field_snow_signed_pdf', ['type' => 'file_default', 'weight' => 40, 'label' => 'above'])->save();
}
print "DONE\n";
