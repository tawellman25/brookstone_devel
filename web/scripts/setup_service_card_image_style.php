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

if (!ImageStyle::load('service_card_strip')) {
  $style = ImageStyle::create([
    'name' => 'service_card_strip',
    'label' => 'Service card strip (64px)',
  ]);
  $style->addImageEffect([
    'id' => 'image_scale_and_crop',
    'weight' => 1,
    'data' => ['width' => 128, 'height' => 256, 'anchor' => 'center-center'],
  ]);
  $style->save();
  print "created image style service_card_strip\n";
}
else {
  print "image style service_card_strip already exists\n";
}
print "DONE.\n";
