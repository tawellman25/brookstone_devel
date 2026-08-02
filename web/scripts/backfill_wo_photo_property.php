<?php

/**
 * Backfill field_property on existing wo_images / wo_videos media from their
 * work order (media.field_work_order -> work_order.field_property), so WO photos
 * become directly discoverable by property and join the unified gallery.
 *
 * Idempotent — only sets field_property when empty; never overwrites. Safe to
 * re-run. Read + targeted write of the property ref only.
 *
 * Run: drush php:script web/scripts/backfill_wo_photo_property.php
 */

$etm = \Drupal::entityTypeManager();
$mediaStorage = $etm->getStorage('media');
$woStorage = $etm->getStorage('work_order');

$set = 0;
$already = 0;
$noWo = 0;
$noProp = 0;
$total = 0;

// Cache WO -> property id to avoid reloading the same WO repeatedly.
$woPropCache = [];

foreach (['wo_images', 'wo_videos'] as $bundle) {
  $ids = \Drupal::entityQuery('media')->accessCheck(FALSE)->condition('bundle', $bundle)->execute();
  foreach (array_chunk($ids, 200) as $chunk) {
    foreach ($mediaStorage->loadMultiple($chunk) as $m) {
      $total++;
      if (!$m->hasField('field_property')) {
        continue;
      }
      if (!$m->get('field_property')->isEmpty()) {
        $already++;
        continue;
      }
      if (!$m->hasField('field_work_order') || $m->get('field_work_order')->isEmpty()) {
        $noWo++;
        continue;
      }
      $woId = (int) $m->get('field_work_order')->target_id;
      if (!array_key_exists($woId, $woPropCache)) {
        $wo = $woStorage->load($woId);
        $woPropCache[$woId] = ($wo && $wo->hasField('field_property') && !$wo->get('field_property')->isEmpty())
          ? (int) $wo->get('field_property')->target_id
          : 0;
      }
      $propId = $woPropCache[$woId];
      if ($propId <= 0) {
        $noProp++;
        continue;
      }
      $m->set('field_property', ['target_id' => $propId]);
      $m->save();
      $set++;
    }
  }
}

printf("WO photo media scanned: %d\n", $total);
printf("  field_property SET from WO: %d\n", $set);
printf("  already had a property:     %d\n", $already);
printf("  no work order on media:     %d\n", $noWo);
printf("  WO had no property:         %d\n", $noProp);
