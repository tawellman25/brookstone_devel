<?php

/**
 * Shorten filefield_paths patterns on media image/file fields so derivative URIs
 * (public://styles/{style}/public/ + relative path) stay under Drupal's 255-char
 * URI limit on long-address properties.
 *
 * wo_images.field_media_image_1 was the failure: the FILE NAME repeated the whole
 * breadcrumb — [street_address]-[photos_of]-[alt] — on top of a full url:path
 * FILE PATH, so max_2600x2600 / media_library derivatives blew past 255 and no
 * thumbnails generated (Corn Commercial, 681 Industrial Blvd).
 *
 * New scheme keeps the organizing intent but bounded by construction (numeric
 * ids + short street address):
 *   path: {street-address}/wo{wo-id}/photos
 *   name: {file-fid}.{ext}          (short, unique per file — alt still carries SEO)
 * estimate_images uses the same long url:path in its PATH (filename already short)
 * -> shortened to est{estimate-id}/photos.
 *
 * property_photo (no filefield_paths) and wo_videos (short date-bucket path) are
 * not affected and are left unchanged.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/fix_media_filefield_paths_length.php
 */

$changes = [
  'media.wo_images.field_media_image_1' => [
    'file_path' => '[media:field_work_order:entity:field_property:entity:field_street_address]/wo[media:field_work_order:target_id]/photos',
    'file_name' => '[file:fid].[file:ffp-extension-original]',
  ],
  'media.estimate_images.field_media_image_1' => [
    'file_path' => 'est[media:field_estimate:target_id]/photos',
    // filename already short ([file:ffp-name-only-original]) — leave it.
  ],
];

$out = [];
foreach ($changes as $id => $new) {
  $fc = \Drupal\field\Entity\FieldConfig::load($id);
  if (!$fc) {
    $out[] = "SKIP $id — not found";
    continue;
  }
  $ffp = $fc->getThirdPartySettings('filefield_paths');
  if (!$ffp) {
    $out[] = "SKIP $id — no filefield_paths";
    continue;
  }
  foreach (['file_path', 'file_name'] as $k) {
    if (isset($new[$k])) {
      $ffp[$k]['value'] = $new[$k];
    }
  }
  $fc->setThirdPartySetting('filefield_paths', 'file_path', $ffp['file_path']);
  if (isset($new['file_name'])) {
    $fc->setThirdPartySetting('filefield_paths', 'file_name', $ffp['file_name']);
  }
  $fc->save();
  $out[] = "$id: file_path='{$ffp['file_path']['value']}'" . (isset($new['file_name']) ? " file_name='{$ffp['file_name']['value']}'" : '');
}

print implode("\n", $out) . "\nDONE.\n";
