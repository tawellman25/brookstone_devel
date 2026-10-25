<?php

/**
 * @file
 * Create the `og_image` image style — 1200x630, the Facebook link-card shape.
 *
 * WHY A DEDICATED STYLE. og:image currently points at `max_1300x1300`, which is
 * a SCALE effect: it preserves aspect ratio, so a portrait source stays
 * portrait. Facebook's link card is 1.91:1 and crops whatever it is given to
 * that band, unpredictably. 1200x630 is the shape Facebook actually wants, so
 * the crop happens here — once, deliberately — instead of on their side.
 *
 * ⚠ max_1300x1300 was NOT a careless choice and this does not "fix a mistake":
 * `bos_services.module` explains it was picked because a phone-shot slide can be
 * 9+ MB, over Facebook's 8 MB og:image limit, which makes FB refuse the image
 * outright. That constraint still applies and 1200x630 satisfies it far more
 * comfortably (tens of KB), but anyone changing this style later must keep the
 * size ceiling in mind or they will reintroduce the original bug.
 *
 * Centre anchor, deliberately: Focal Point is not installed on BOS, and the
 * standing instruction is not to add it as part of this work. A centre crop is
 * the accepted trade-off; steering it is the follow-up.
 *
 * Idempotent — leaves an existing style alone rather than overwriting someone's
 * edits. DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/setup_og_image_style.php
 */

use Drupal\image\Entity\ImageStyle;

const OG_STYLE = 'og_image';
const OG_W = 1200;
const OG_H = 630;

$apply = getenv('BOS_APPLY') === '1';
$storage = \Drupal::entityTypeManager()->getStorage('image_style');

$existing = $storage->load(OG_STYLE);
if ($existing) {
  printf("Style '%s' already exists (%s). Effects:\n", OG_STYLE, $existing->label());
  foreach ($existing->getEffects() as $e) {
    printf("  %s %s\n", $e->getPluginId(), json_encode($e->getConfiguration()['data'] ?? []));
  }
  print "Left untouched — this script never overwrites an existing style.\n";
  return;
}

if (!$apply) {
  printf("DRY RUN — would create image style '%s' (Open Graph %dx%d), scale and crop, centre anchor.\n",
    OG_STYLE, OG_W, OG_H);
  print "Re-run with BOS_APPLY=1 to write.\n";
  return;
}

$style = ImageStyle::create([
  'name' => OG_STYLE,
  'label' => sprintf('Open Graph (%dx%d)', OG_W, OG_H),
]);
$style->addImageEffect([
  'id' => 'image_scale_and_crop',
  'weight' => 1,
  'data' => [
    'width' => OG_W,
    'height' => OG_H,
    // Matches the only other scale-and-crop styles on the site; Focal Point
    // would replace this, and is not installed.
    'anchor' => 'center-center',
  ],
]);
$style->save();

$fresh = $storage->load(OG_STYLE);
printf("Created '%s' (%s)\n", OG_STYLE, $fresh->label());
foreach ($fresh->getEffects() as $e) {
  printf("  %s %s\n", $e->getPluginId(), json_encode($e->getConfiguration()['data'] ?? []));
}
