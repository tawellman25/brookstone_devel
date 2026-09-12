<?php

/**
 * Re-title property_backflow_device records whose title is the bare device code
 * (BF-NNNNNN, no model/type suffix) when a model or type is now available, so
 * the label reads e.g. "BF-000006 - Pressure Vacuum Breaker (PVB)".
 *
 * The insert-time finalize (backflow_device_entity_insert) now falls back to the
 * device TYPE when no product is linked, but it only runs on insert; this heals
 * devices created before that fallback existed (e.g. BF-000006/000007).
 * Idempotent: only changes a title that is exactly the bare code AND for which a
 * model/type suffix is available. Never disturbs a title that already has one.
 *
 * Dry-run by default; BOS_APPLY=1 to write.
 *
 *   drush php:script web/scripts/retitle_backflow_devices.php               (dry-run)
 *   BOS_APPLY=1 drush php:script web/scripts/retitle_backflow_devices.php   (apply)
 */

use Drupal\Core\Entity\ContentEntityInterface;

$APPLY = getenv('BOS_APPLY') === '1';
print $APPLY ? "=== APPLY ===\n" : "=== DRY RUN (BOS_APPLY=1 to write) ===\n";

$storage = \Drupal::entityTypeManager()->getStorage('property_backflow_device');
$ids = $storage->getQuery()->accessCheck(FALSE)->execute();
$changed = 0;

foreach ($storage->loadMultiple($ids) as $device) {
  /** @var \Drupal\Core\Entity\ContentEntityInterface $device */
  $code = 'BF-' . str_pad((string) $device->id(), 6, '0', STR_PAD_LEFT);
  $current = (string) $device->label();
  // Only heal titles that are exactly the bare code.
  if ($current !== $code) {
    continue;
  }
  // Same suffix logic as _backflow_device_finalize(): model, else type.
  $model = '';
  if ($device->hasField('field_material_backflow') && !$device->get('field_material_backflow')->isEmpty()
      && ($material = $device->get('field_material_backflow')->entity)) {
    $model = $material->hasField('field_name') && !$material->get('field_name')->isEmpty()
      ? trim((string) $material->get('field_name')->value)
      : trim((string) $material->label());
  }
  if ($model === '' && $device->hasField('field_device_type') && !$device->get('field_device_type')->isEmpty()
      && ($type_term = $device->get('field_device_type')->entity)) {
    $model = trim((string) $type_term->label());
  }
  if ($model === '') {
    // No model/type to add — leave the bare code (still a valid label).
    continue;
  }
  $new = $code . ' - ' . $model;
  print "  {$device->id()}: [{$current}] -> [{$new}]\n";
  $changed++;
  if ($APPLY) {
    $device->set('title', $new)->save();
  }
}

print "\n" . ($APPLY ? "re-titled" : "would re-title") . ": $changed\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN.\n";
