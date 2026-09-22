<?php

/**
 * Image style for the service-card iconic-image strip: a narrow portrait crop
 * (128x256, 2x of the ~64px display strip) so the card only downloads a small
 * cropped derivative instead of the full "large" image. Displayed at 64px wide
 * x full card height via object-fit:cover.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/setup_service_card_image_style.php
 */

use Drupal\image\Entity\ImageStyle;

// Tall portrait crop so a full-card-height left strip stays crisp even on long
// cards. Width is generous for retina; the card CSS displays it ~104px wide.
$style = ImageStyle::load('service_card_strip');
$new = FALSE;
if (!$style) {
  $style = ImageStyle::create(['name' => 'service_card_strip', 'label' => 'Service card strip']);
  $new = TRUE;
}
else {
  // Clear existing effects so re-running updates the dimensions.
  foreach ($style->getEffects() as $effect) {
    $style->deleteImageEffect($effect);
  }
}
$style->set('label', 'Service card strip');
$style->addImageEffect([
  'id' => 'image_scale_and_crop',
  'weight' => 1,
  'data' => ['width' => 240, 'height' => 640, 'anchor' => 'center-center'],
]);
$style->save();
$style->flush();
print ($new ? 'created' : 'updated') . " image style service_card_strip (240x640) + flushed derivatives\n";
print "DONE.\n";
