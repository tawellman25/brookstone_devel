<?php

/**
 * @file
 * Cleanup for the Contact opt-in model. Retires the legacy marketing-consent
 * fields from the User entity AFTER Stage 2 has moved their values to Contact,
 * leaving Contact as the single home for opt-ins.
 *
 * Removes: field_ok_to_email, field_sms_consent, field_consent_updated (user).
 * KEEPS operational governance flags: field_do_not_schedule, field_credit_hold,
 * field_service_suspension_reason, field_ok_to_email? no. Verified no code
 * references the removed fields.
 *
 * Dry-run by default (BOS_RETIRE_APPLY=1 to delete). Run Stage 2 first.
 *
 * Run (dry):   ddev drush php:script web/scripts/retire_user_consent_fields.php
 * Run (apply): ddev exec 'BOS_RETIRE_APPLY=1 drush php:script web/scripts/retire_user_consent_fields.php'
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$APPLY = getenv('BOS_RETIRE_APPLY') === '1';
$RETIRE = ['field_ok_to_email', 'field_sms_consent', 'field_consent_updated'];
$KEEP = ['field_do_not_schedule', 'field_credit_hold', 'field_service_suspension_reason'];
print ($APPLY ? "*** APPLY (deletes fields) ***\n" : "--- DRY RUN ---\n");
print "keeping operational flags: " . implode(', ', $KEEP) . "\n";

foreach ($RETIRE as $name) {
  $inst = FieldConfig::loadByName('user', 'user', $name);
  $store = FieldStorageConfig::loadByName('user', $name);
  if (!$inst && !$store) { print "  $name: already gone\n"; continue; }
  if ($APPLY) {
    try {
      // Deleting the instance cascades to the storage for a last-bundle field;
      // only delete the storage directly if the instance is already gone.
      if ($inst) { $inst->delete(); print "  $name: DELETED (instance; storage auto-removed)\n"; }
      elseif ($store) { $store->delete(); print "  $name: DELETED (orphan storage)\n"; }
    }
    catch (\Throwable $e) {
      print "  $name: ERROR " . $e->getMessage() . "\n";
    }
  }
  else {
    print "  $name: would delete (instance=" . ($inst ? 'y' : 'n') . " storage=" . ($store ? 'y' : 'n') . ")\n";
  }
}
print $APPLY ? "APPLIED. Run cron to purge field data.\n" : "DRY RUN — set BOS_RETIRE_APPLY=1 to delete.\n";
