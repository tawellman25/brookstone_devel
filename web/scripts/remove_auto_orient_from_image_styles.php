<?php

/**
 * Remove the `auto_rotate_lite` image effect from every image style.
 *
 * WHY: auto_rotate_lite's transformDimensions()/getImageOrientation() reads each
 * image's EXIF from the *source file* on EVERY render (to decide whether to swap
 * width/height). On S3 (s3fs) that is a remote file read per image, per page
 * load. On a property page with many service photos this stacked up to ~33s and
 * cold-cache 500s (see the 2026-09-26 perf investigation). It slowed every page
 * that renders images.
 *
 * The 2026-09-22 EXIF fix also installed `exif_orientation`, which rotates the
 * SOURCE at upload — so new photos are correct without any derivative-time
 * effect. Removing auto_rotate_lite restores fast renders. We do NOT flush
 * derivatives: already-generated (rotated) derivatives are kept, so legacy
 * portrait photos stay correct where a derivative already exists.
 *
 * Edits ACTIVE config via configFactory (NOT the ImageStyle entity API) so it
 * does not trigger ImageStyle::postSave()'s full derivative flush.
 *
 * Idempotent. Run:  drush php:script web/scripts/remove_auto_orient_from_image_styles.php
 */

$cf = \Drupal::configFactory();
$removed = 0;
$touched = [];
foreach ($cf->listAll('image.style.') as $name) {
  $config = $cf->getEditable($name);
  $effects = $config->get('effects') ?? [];
  $changed = FALSE;
  foreach ($effects as $uuid => $effect) {
    if (isset($effect['id']) && strpos($effect['id'], 'auto_rotate') !== FALSE) {
      unset($effects[$uuid]);
      $changed = TRUE;
      $removed++;
    }
  }
  if ($changed) {
    $config->set('effects', $effects)->save();
    $touched[] = str_replace('image.style.', '', $name);
  }
}
print "Removed auto_rotate effect from " . count($touched) . " image style(s) ({$removed} effect rows):\n";
foreach ($touched as $t) {
  print "  - {$t}\n";
}
print $touched ? "DONE (derivatives intentionally NOT flushed).\n" : "Nothing to remove (already clean).\n";
