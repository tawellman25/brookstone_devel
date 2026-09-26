<?php

/**
 * Regenerate the URL alias for every property_backflow_device so the new
 * pathauto pattern (/{property}/backflow/bf-000027) takes effect. With
 * pathauto update_action=2 (delete old + create redirect) and the redirect
 * module on, each device's OLD alias gets a 301 → its canonical automatically.
 *
 * Idempotent: re-running only rewrites aliases that changed. Run per env AFTER
 * a DB backup on live.
 *
 *   drush php:script web/scripts/regenerate_backflow_aliases.php
 */

$storage = \Drupal::entityTypeManager()->getStorage('property_backflow_device');
$generator = \Drupal::service('pathauto.generator');
$aliasManager = \Drupal::service('path_alias.manager');
$ids = $storage->getQuery()->accessCheck(FALSE)->execute();
print 'Devices to process: ' . count($ids) . "\n";
$done = 0;
foreach (array_chunk($ids, 100) as $chunk) {
  foreach ($storage->loadMultiple($chunk) as $device) {
    $generator->updateEntityAlias($device, 'update');
    $done++;
    if ($done <= 5 || $done === count($ids)) {
      $aliasManager->cacheClear();
      print '  ' . $device->label() . ' -> ' . $aliasManager->getAliasByPath('/property_backflow_device/' . $device->id()) . "\n";
    }
  }
}
print "Regenerated {$done} device aliases.\nDONE.\n";
