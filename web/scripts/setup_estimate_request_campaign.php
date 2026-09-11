<?php

/**
 * @file
 * Add field_campaign to estimate_request so /request-estimate leads carry their
 * ?c= attribution on the BOS record (matching service_request on /winterize and
 * /contact). Idempotent; run per env.
 *
 *   ddev drush php:script web/scripts/setup_estimate_request_campaign.php   (dev)
 *   drush php:script web/scripts/setup_estimate_request_campaign.php        (live)
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

if (!FieldStorageConfig::loadByName('estimate_request', 'field_campaign')) {
  FieldStorageConfig::create([
    'field_name' => 'field_campaign',
    'entity_type' => 'estimate_request',
    'type' => 'string',
    'cardinality' => 1,
  ])->save();
  echo "• created storage estimate_request.field_campaign\n";
}
if (!FieldConfig::loadByName('estimate_request', 'standard', 'field_campaign')) {
  FieldConfig::create([
    'field_name' => 'field_campaign',
    'entity_type' => 'estimate_request',
    'bundle' => 'standard',
    'label' => 'Campaign',
  ])->save();
  echo "• added estimate_request.standard.field_campaign\n";
}
else {
  echo "• field_campaign already on estimate_request.standard\n";
}
echo "Done.\n";
