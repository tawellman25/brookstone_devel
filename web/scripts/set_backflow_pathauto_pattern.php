<?php

/**
 * Set the backflow device pathauto pattern to use the clean BF-number segment.
 *
 * From: [property_backflow_device:field_property:entity:url:path]/backflow/[property_backflow_device:title]
 * To:   [property_backflow_device:field_property:entity:url:path]/backflow/[property_backflow_device:bf_number]
 *
 * Keeps the /backflow/ segment; drops the device description from the last
 * segment, leaving just bf-000027. Requires the [property_backflow_device:bf_number]
 * token (backflow_device.module). Idempotent; run per env (active config edit,
 * no cim — the pattern config is drifted like the rest).
 *
 *   drush php:script web/scripts/set_backflow_pathauto_pattern.php
 */

$want = '[property_backflow_device:field_property:entity:url:path]/backflow/[property_backflow_device:bf_number]';
$cfg = \Drupal::configFactory()->getEditable('pathauto.pattern.backflow_device_paths');
if ($cfg->isNew()) {
  print "pattern backflow_device_paths not found.\n";
  return;
}
$have = $cfg->get('pattern');
if ($have === $want) {
  print "Pattern already set — nothing to do.\n";
  return;
}
$cfg->set('pattern', $want)->save();
print "Pattern updated:\n  from: {$have}\n  to:   {$want}\n";
