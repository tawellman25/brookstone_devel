<?php

/**
 * Add the channel source values to service_request.field_source allowed_values
 * so CampaignSource::forCode() outputs can be stored. Merges with existing
 * values (phone/office/email kept). Idempotent; ECK/field config skips cim, so
 * this is the deploy path. MUST run before deploying the form changes that emit
 * the new values.
 *   ddev drush php:script web/scripts/setup_campaign_source_values.php
 */

use Drupal\bos_service_request\CampaignSource;
use Drupal\field\Entity\FieldStorageConfig;

$storage = FieldStorageConfig::loadByName('service_request', 'field_source');
if (!$storage) {
  print "field_source not found\n";
  return;
}
$settings = $storage->getSettings();
$existing = $settings['allowed_values'] ?? [];
$merged = $existing + CampaignSource::allValues();

if ($merged === $existing) {
  print "no change — all values already present\n";
}
else {
  $settings['allowed_values'] = $merged;
  $storage->setSettings($settings);
  $storage->save();
  print "updated field_source allowed_values\n";
}
foreach (array_keys($merged) as $k) {
  print "  $k\n";
}
print "DONE\n";
