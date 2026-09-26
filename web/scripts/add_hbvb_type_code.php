<?php

declare(strict_types=1);

/**
 * Add the HBVB code to the field_type_code storage allowed_values so the new
 * Hose Bibb Vacuum Breaker term can populate its required code field.
 *
 * field_type_code is a list_string whose allowed_values are a STORAGE-level
 * property (used only by the backflow_device_types vocabulary). Adding is safe;
 * we never remove an in-use value. Idempotent; run per env before seeding.
 *
 *   drush php:script web/scripts/add_hbvb_type_code.php
 */

$cfg = \Drupal::configFactory()->getEditable('field.storage.taxonomy_term.field_type_code');
if ($cfg->isNew()) {
  print "field.storage.taxonomy_term.field_type_code not found.\n";
  return;
}
$allowed = $cfg->get('settings.allowed_values') ?? [];
foreach ($allowed as $row) {
  if (($row['value'] ?? NULL) === 'HBVB') {
    print "HBVB already in allowed_values — nothing to do.\n";
    return;
  }
}
$allowed[] = ['value' => 'HBVB', 'label' => 'HBVB'];
$cfg->set('settings.allowed_values', $allowed)->save();
// Refresh field settings cache so the new value is available immediately.
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
print "Added HBVB to field_type_code allowed_values (now " . count($allowed) . " values).\n";
