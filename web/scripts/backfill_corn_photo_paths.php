<?php

/**
 * Re-file the 5 already-broken Corn Commercial wo_images (WO 53699) from the
 * over-255 long path to the new short filefield_paths scheme
 * ({street}/wo{id}/photos/{fid}.{ext}) and regenerate derivatives, so their
 * thumbnails build in the public gallery.
 *
 *   drush php:script backfill_corn_photo_paths.php
 */

use Drupal\Core\File\FileSystemInterface;

$MIDS = [9138, 9139, 9140, 9141, 9142];
$cleaner = \Drupal::service('pathauto.alias_cleaner');
$fs = \Drupal::service('file_system');
$out = [];

foreach ($MIDS as $mid) {
  $m = \Drupal::entityTypeManager()->getStorage('media')->load($mid);
  if (!$m) { $out[] = "$mid: missing"; continue; }
  $file = $m->get('field_media_image_1')->entity;
  if (!$file) { $out[] = "$mid: no file"; continue; }

  $old = $file->getFileUri();
  $woId = (int) $m->get('field_work_order')->target_id;
  $wo = \Drupal::entityTypeManager()->getStorage('work_order')->load($woId);
  $prop = $wo->get('field_property')->entity;
  $street = $prop->hasField('field_street_address') ? (string) $prop->get('field_street_address')->value : '';
  $slug = $cleaner->cleanString($street) ?: ('prop' . $prop->id());
  $ext = pathinfo($old, PATHINFO_EXTENSION) ?: 'jpg';
  $dir = "public://$slug/wo$woId/photos";
  $new = "$dir/{$file->id()}.$ext";

  if ($old === $new) { $out[] = "$mid: already short ($new)"; }
  else {
    $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
    // Flush any (broken) derivatives keyed on the old uri first.
    image_path_flush($old);
    $moved = $fs->move($old, $new, FileSystemInterface::EXISTS_RENAME);
    $file->setFileUri($moved);
    $file->save();
    $m->save();
    $out[] = "$mid: -> $moved (" . strlen($moved) . " chars)";
  }

  // Warm the worst-case derivative to prove URI length + generation.
  $uri = $file->getFileUri();
  $style = \Drupal\image\Entity\ImageStyle::load('max_2600x2600');
  $deriv = $style->buildUri($uri);
  $ok = file_exists($deriv) ? TRUE : $style->createDerivative($uri, $deriv);
  $out[] = "    max_2600x2600 deriv len=" . strlen($deriv) . " created=" . ($ok ? 'YES' : 'no') . " under255=" . (strlen($deriv) < 255 ? 'YES' : 'NO');
}

print implode("\n", $out) . "\nDONE.\n";
