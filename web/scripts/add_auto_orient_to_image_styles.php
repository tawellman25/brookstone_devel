<?php

/**
 * Add the Auto Rotate Lite effect (rotate per EXIF orientation) as the FIRST
 * effect on every image style, so styled derivatives of portrait phone photos
 * render upright instead of sideways. Then flush derivatives so existing images
 * regenerate corrected. Non-EXIF images are unaffected. Idempotent, no cim.
 *
 * Run per env:
 *   drush php:script web/scripts/add_auto_orient_to_image_styles.php
 */

$styles = \Drupal\image\Entity\ImageStyle::loadMultiple();
$added = [];
$skipped = [];

foreach ($styles as $style) {
  $has = FALSE;
  foreach ($style->getEffects() as $effect) {
    if ($effect->getPluginId() === 'auto_rotate_lite') {
      $has = TRUE;
      break;
    }
  }
  if ($has) {
    $skipped[] = $style->id();
    continue;
  }
  $style->addImageEffect([
    'id' => 'auto_rotate_lite',
    'weight' => -50,
    'data' => [],
  ]);
  $style->save();
  $style->flush();
  $added[] = $style->id();
}

print 'Added auto-orient to: ' . (implode(', ', $added) ?: '(none)') . "\n";
if ($skipped) {
  print 'Already had it: ' . implode(', ', $skipped) . "\n";
}
print "DONE.\n";
