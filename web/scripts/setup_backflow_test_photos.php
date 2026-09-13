<?php

/**
 * Add gauge/tag photo capture to the backflow test (wo_tasks_list:backflow_testing):
 *   - One image field PER READING (shown beside its reading; only the applicable
 *     ones render per device type, mirroring the reading visibility):
 *       field_photo_line_pressure, field_photo_cv1, field_photo_cv2,
 *       field_photo_relief, field_photo_air_inlet, field_photo_cv
 *   - field_tag_photos (multi-value): the tag close-up + the "hooked to the
 *     backflow" shot.
 *   - Image style 'backflow_report_photo' (scale-width 720) used to embed
 *     resized derivatives in the PDF so it stays small.
 * Places the per-reading photos interleaved with their readings in the Test
 * Readings group, and tag photos in a new "Tag Photos" group.
 *
 * Idempotent; entity-API (no cim). Run per env.
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\Entity\ImageStyleInterface;

$BUNDLE = 'backflow_testing';
$out = [];

// Reading field => [photo field, label].
$READING_PHOTOS = [
  'field_line_pressure_psi' => ['field_photo_line_pressure', 'Photo — Line Pressure'],
  'field_check_valve_1_psid' => ['field_photo_cv1', 'Photo — Check Valve 1'],
  'field_check_valve_2_psid' => ['field_photo_cv2', 'Photo — Check Valve 2'],
  'field_relief_valve_psid' => ['field_photo_relief', 'Photo — Relief Valve'],
  'field_air_inlet_psid' => ['field_photo_air_inlet', 'Photo — Air Inlet'],
  'field_check_valve_psid' => ['field_photo_cv', 'Photo — Check Valve'],
];

$ensureImageField = function (string $name, string $label, int $card) use ($BUNDLE, &$out) {
  if (!FieldStorageConfig::loadByName('wo_tasks_list', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => 'wo_tasks_list', 'type' => 'image',
      'cardinality' => $card,
    ])->save();
    $out[] = "storage $name";
  }
  if (!FieldConfig::loadByName('wo_tasks_list', $BUNDLE, $name)) {
    FieldConfig::create([
      'field_name' => $name, 'entity_type' => 'wo_tasks_list', 'bundle' => $BUNDLE,
      'label' => $label, 'required' => FALSE,
      'settings' => [
        'file_directory' => 'wo_tasks_list/backflow_testing/photos',
        'alt_field' => TRUE, 'alt_field_required' => FALSE,
        'file_extensions' => 'png gif jpg jpeg webp',
      ],
    ])->save();
    $out[] = "instance $name";
  }
};

foreach ($READING_PHOTOS as $reading => [$pf, $label]) {
  $ensureImageField($pf, $label, 1);
}
$ensureImageField('field_tag_photos', 'Tag Photos', -1);

// Image style for the report (resized so the embedded PDF stays small).
if (!ImageStyle::load('backflow_report_photo')) {
  $style = ImageStyle::create(['name' => 'backflow_report_photo', 'label' => 'Backflow report photo']);
  $style->addImageEffect([
    'id' => 'image_scale',
    'weight' => 1,
    'data' => ['width' => 720, 'height' => NULL, 'upscale' => FALSE],
  ]);
  $style->save();
  $out[] = "image style backflow_report_photo";
}

// Form display: interleave each photo after its reading (Test Readings group),
// tag photos in their own group.
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('wo_tasks_list', $BUNDLE);
$w = 20;
$readingChildren = [];
foreach ($READING_PHOTOS as $reading => [$pf, $label]) {
  if ($fd->getComponent($reading)) {
    $c = $fd->getComponent($reading); $c['weight'] = $w++; $fd->setComponent($reading, $c);
    $readingChildren[] = $reading;
  }
  $fd->setComponent($pf, ['type' => 'image_image', 'weight' => $w++, 'region' => 'content', 'settings' => ['preview_image_style' => 'thumbnail', 'progress_indicator' => 'throbber']]);
  $readingChildren[] = $pf;
}
$fd->setComponent('field_tag_photos', ['type' => 'image_image', 'weight' => 60, 'region' => 'content', 'settings' => ['preview_image_style' => 'thumbnail', 'progress_indicator' => 'throbber']]);

$groups = $fd->getThirdPartySettings('field_group');
$groups['group_test_readings']['children'] = $readingChildren;
$groups['group_tag_photos'] = [
  'children' => ['field_tag_photos'],
  'label' => 'Tag Photos',
  'region' => 'content',
  'parent_name' => '',
  'weight' => 25,
  'format_type' => 'details',
  'format_settings' => [
    'classes' => '', 'show_empty_fields' => TRUE, 'id' => '', 'open' => TRUE,
    'description' => 'Photos of the tag — a close-up and one showing it on the assembly.',
    'required_fields' => TRUE,
  ],
];
foreach ($groups as $gid => $def) { $fd->setThirdPartySetting('field_group', $gid, $def); }
$fd->save();
$out[] = 'form: photos interleaved in Test Readings + Tag Photos group';

print implode("\n", $out) . "\nDONE.\n";
