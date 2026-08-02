<?php

/**
 * Phase 1 foundation for the property photo gallery.
 *
 * Creates the `property_photo` media type (archive/historical photos matched to
 * a property) and adds a direct `field_property` reference to it AND to the
 * existing `wo_images` / `wo_videos` media types — so one gallery View
 * ("media where field_property = this property") can union WO photos and the
 * imported archive photos with no duplication.
 *
 * REUSES the existing shared `field_media_image_1` image field (has alt text)
 * as the property_photo source, mirroring wo_images. New net fields are created
 * on the `media` entity and instanced only where needed.
 *
 * Idempotent — run once per environment. Standard config (not ECK), but done via
 * entity API for consistency with BOS's other setup scripts and no-cim deploys.
 *
 * Run: drush php:script web/scripts/setup_property_photo_media.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$etm = \Drupal::entityTypeManager();
$repo = \Drupal::service('entity_display.repository');
$MEDIA = 'media';
$TYPE = 'property_photo';

// ── 1. property_photo media type (source = reused field_media_image_1) ────
$mtStorage = $etm->getStorage('media_type');
if (!$mtStorage->load($TYPE)) {
  $mtStorage->create([
    'id' => $TYPE,
    'label' => 'Property Photo',
    'description' => 'Archive/historical photos associated to a property (from the old customer folders, matched by GPS/customer). Powers the public property-page gallery.',
    'source' => 'image',
    'source_configuration' => ['source_field' => 'field_media_image_1'],
    'field_map' => [],
    'new_revision' => FALSE,
    'queue_thumbnail_downloads' => FALSE,
  ])->save();
  print "created media type $TYPE\n";
}
else {
  print "media type $TYPE already exists\n";
}

// ── 2. Net-new fields on the media entity ────────────────────────────────
// [name => [type, label, storage_settings]]
$NEW = [
  'field_property'         => ['entity_reference', 'Property', ['target_type' => 'properties']],
  'field_source_customer'  => ['string',           'Source Customer (folder)', []],
  'field_date_taken'       => ['datetime',          'Date Taken', ['datetime_type' => 'date']],
  'field_match_confidence' => ['string',            'Match Confidence', []],
  'field_match_method'     => ['string',            'Match Method', []],
  'field_original_path'    => ['string',            'Original File Path', ['max_length' => 512]],
];
foreach ($NEW as $name => [$type, $label, $settings]) {
  if (!FieldStorageConfig::loadByName($MEDIA, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $MEDIA,
      'type' => $type,
      'cardinality' => 1,
      'settings' => $settings,
    ])->save();
    print "  created storage media.$name ($type)\n";
  }
}

// ── 3. Instances on property_photo ───────────────────────────────────────
// Source image field first (reused shared storage), with alt text on.
if (!FieldConfig::loadByName($MEDIA, $TYPE, 'field_media_image_1')) {
  FieldConfig::create([
    'field_name' => 'field_media_image_1',
    'entity_type' => $MEDIA,
    'bundle' => $TYPE,
    'label' => 'Photo',
    'required' => TRUE,
    'settings' => [
      'alt_field' => TRUE,
      'alt_field_required' => FALSE,
      'title_field' => FALSE,
      'file_directory' => 'property-photos',
      'file_extensions' => 'png jpg jpeg gif webp',
      'max_filesize' => '',
      'max_resolution' => '',
      'min_resolution' => '',
    ],
  ])->save();
  print "  instanced field_media_image_1 (source) on $TYPE\n";
}

$typeInstances = [
  'field_property'         => ['Property', ['handler' => 'default:properties', 'handler_settings' => ['target_bundles' => ['property' => 'property']]]],
  'field_source_customer'  => ['Source Customer (folder)', NULL],
  'field_date_taken'       => ['Date Taken', NULL],
  'field_match_confidence' => ['Match Confidence', NULL],
  'field_match_method'     => ['Match Method', NULL],
  'field_original_path'    => ['Original File Path', NULL],
];
foreach ($typeInstances as $name => [$label, $settings]) {
  if (!FieldConfig::loadByName($MEDIA, $TYPE, $name)) {
    $vals = ['field_name' => $name, 'entity_type' => $MEDIA, 'bundle' => $TYPE, 'label' => $label];
    if ($settings) {
      $vals['settings'] = $settings;
    }
    FieldConfig::create($vals)->save();
    print "  instanced $name on $TYPE\n";
  }
}

// ── 4. field_property on the existing WO photo media types ────────────────
foreach (['wo_images', 'wo_videos'] as $woType) {
  if (!$mtStorage->load($woType)) {
    print "  WARN: media type $woType missing — skipped\n";
    continue;
  }
  if (!FieldConfig::loadByName($MEDIA, $woType, 'field_property')) {
    FieldConfig::create([
      'field_name' => 'field_property',
      'entity_type' => $MEDIA,
      'bundle' => $woType,
      'label' => 'Property',
      'settings' => ['handler' => 'default:properties', 'handler_settings' => ['target_bundles' => ['property' => 'property']]],
    ])->save();
    print "  instanced field_property on $woType (for the unified gallery + backfill)\n";
  }
}

// ── 4b. Parallel property_video media type (source = reused video file) ──
$VTYPE = 'property_video';
if (!$mtStorage->load($VTYPE)) {
  $mtStorage->create([
    'id' => $VTYPE,
    'label' => 'Property Video',
    'description' => 'Archive/historical videos associated to a property (from the old customer folders). Sibling of property_photo for the property gallery.',
    'source' => 'video_file',
    'source_configuration' => ['source_field' => 'field_media_video_file_1'],
    'field_map' => [],
    'new_revision' => FALSE,
    'queue_thumbnail_downloads' => FALSE,
  ])->save();
  print "created media type $VTYPE\n";
}
// Source video field (reuse the wo_videos storage) + provenance/property fields.
if (!FieldConfig::loadByName($MEDIA, $VTYPE, 'field_media_video_file_1')) {
  FieldConfig::create([
    'field_name' => 'field_media_video_file_1',
    'entity_type' => $MEDIA,
    'bundle' => $VTYPE,
    'label' => 'Video',
    'required' => TRUE,
    'settings' => [
      'file_directory' => 'property-videos',
      'file_extensions' => 'mp4 mov m4v webm ogg',
      'max_filesize' => '',
      'description_field' => FALSE,
    ],
  ])->save();
  print "  instanced field_media_video_file_1 (source) on $VTYPE\n";
}
foreach ($typeInstances as $name => [$label, $settings]) {
  if (!FieldConfig::loadByName($MEDIA, $VTYPE, $name)) {
    $vals = ['field_name' => $name, 'entity_type' => $MEDIA, 'bundle' => $VTYPE, 'label' => $label];
    if ($settings) {
      $vals['settings'] = $settings;
    }
    FieldConfig::create($vals)->save();
    print "  instanced $name on $VTYPE\n";
  }
}
// Form + view displays for property_video.
$vform = $repo->getFormDisplay($MEDIA, $VTYPE);
$vview = $repo->getViewDisplay($MEDIA, $VTYPE);
$vorder = ['field_media_video_file_1', 'field_property', 'field_date_taken', 'field_source_customer', 'field_match_confidence', 'field_match_method', 'field_original_path'];
$vw = 0;
foreach ($vorder as $name) {
  $t = FieldStorageConfig::loadByName($MEDIA, $name)?->getType();
  if (!$t) {
    continue;
  }
  $widget = match ($t) {
    'file' => 'file_generic',
    'entity_reference' => 'entity_reference_autocomplete',
    'datetime' => 'datetime_default',
    default => 'string_textfield',
  };
  $fmt = match ($t) {
    'file' => 'file_video',
    'entity_reference' => 'entity_reference_label',
    'datetime' => 'datetime_default',
    default => 'string',
  };
  $vform->setComponent($name, ['type' => $widget, 'weight' => $vw]);
  $vview->setComponent($name, ['type' => $fmt, 'weight' => $vw, 'label' => 'inline']);
  $vw += 2;
}
$vform->save();
$vview->save();
print "configured form + view displays for $VTYPE\n";

// ── 4c. "Show in public gallery" opt-in flag on ALL photo media types ────
// Uniform public-gallery gate: archive photos get it auto-set by the importer
// (on for confident matches); WO photos default OFF and are opted in per photo.
if (!FieldStorageConfig::loadByName($MEDIA, 'field_public_ok')) {
  FieldStorageConfig::create([
    'field_name' => 'field_public_ok',
    'entity_type' => $MEDIA,
    'type' => 'boolean',
    'cardinality' => 1,
  ])->save();
  print "  created storage media.field_public_ok (boolean)\n";
}
foreach (['property_photo', 'property_video', 'wo_images', 'wo_videos'] as $b) {
  if (!$mtStorage->load($b)) {
    continue;
  }
  if (!FieldConfig::loadByName($MEDIA, $b, 'field_public_ok')) {
    FieldConfig::create([
      'field_name' => 'field_public_ok',
      'entity_type' => $MEDIA,
      'bundle' => $b,
      'label' => 'Show in public gallery',
      'description' => 'When on, this photo appears in the PUBLIC (search-indexed) property gallery. WO photos default OFF.',
      'default_value' => [['value' => 0]],
    ])->save();
    // Make it editable on the form.
    \Drupal::service('entity_display.repository')->getFormDisplay($MEDIA, $b)
      ->setComponent('field_public_ok', ['type' => 'boolean_checkbox', 'weight' => 90])->save();
    print "  instanced field_public_ok on $b\n";
  }
}

// ── 5. Form + view displays for property_photo ───────────────────────────
$form = $repo->getFormDisplay($MEDIA, $TYPE);
$view = $repo->getViewDisplay($MEDIA, $TYPE);
$order = ['field_media_image_1', 'field_property', 'field_date_taken', 'field_source_customer', 'field_match_confidence', 'field_match_method', 'field_original_path'];
$w = 0;
foreach ($order as $name) {
  $t = FieldStorageConfig::loadByName($MEDIA, $name)?->getType();
  if (!$t) {
    continue;
  }
  $widget = match ($t) {
    'image' => 'image_image',
    'entity_reference' => 'entity_reference_autocomplete',
    'datetime' => 'datetime_default',
    default => 'string_textfield',
  };
  $fmt = match ($t) {
    'image' => 'image',
    'entity_reference' => 'entity_reference_label',
    'datetime' => 'datetime_default',
    default => 'string',
  };
  $form->setComponent($name, ['type' => $widget, 'weight' => $w]);
  $view->setComponent($name, ['type' => $fmt, 'weight' => $w, 'label' => 'inline']);
  $w += 2;
}
$form->save();
$view->save();
print "configured form + view displays for $TYPE\n";

// ── 6. "gallery" media view mode + per-bundle gallery display ────────────
// One unified grid: images render as Colorbox thumbnails (lightbox + prev/next,
// alt as caption for SEO); videos render as an inline HTML5 player.
$vmStorage = $etm->getStorage('entity_view_mode');
if (!$vmStorage->load('media.gallery')) {
  $vmStorage->create(['id' => 'media.gallery', 'targetEntityType' => 'media', 'label' => 'Gallery'])->save();
  print "  created media view mode: gallery\n";
}
$imgFormatter = [
  'type' => 'colorbox',
  'label' => 'hidden',
  'settings' => [
    'colorbox_node_style' => 'media_library',
    'colorbox_node_style_first' => '',
    'colorbox_image_style' => 'max_1300x1300',
    'colorbox_gallery' => 'post',
    'colorbox_gallery_custom' => '',
    'colorbox_caption' => 'alt',
    'colorbox_caption_custom' => '',
  ],
];
$vidFormatter = [
  'type' => 'file_video',
  'label' => 'hidden',
  'settings' => ['controls' => TRUE, 'autoplay' => FALSE, 'loop' => FALSE, 'muted' => FALSE, 'width' => NULL, 'height' => NULL],
];
$galleryDisplays = [
  'property_photo' => ['field_media_image_1', $imgFormatter],
  'wo_images'     => ['field_media_image_1', $imgFormatter],
  'property_video' => ['field_media_video_file_1', $vidFormatter],
  'wo_videos'     => ['field_media_video_file_1', $vidFormatter],
];
foreach ($galleryDisplays as $bundle => [$srcField, $formatter]) {
  if (!$mtStorage->load($bundle) || !FieldConfig::loadByName($MEDIA, $bundle, $srcField)) {
    continue;
  }
  $gd = $repo->getViewDisplay($MEDIA, $bundle, 'gallery');
  // Hide everything except the source field.
  foreach (array_keys($gd->getComponents()) as $c) {
    $gd->removeComponent($c);
  }
  $gd->setComponent($srcField, ['weight' => 0] + $formatter);
  $gd->save();
  print "  configured gallery view display for $bundle\n";
}

print "DONE — property_photo media type ready; field_property added to wo_images/wo_videos.\n";
