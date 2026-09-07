<?php

/**
 * @file
 * Backfill: flag existing Bear Creek properties as HOA-contracted so their
 * winterizing WOs get the HOA discount (net $60). Targets properties that have
 * a service_request with field_campaign starting 'bearcreek'. Sets
 * field_hoa_contracted=TRUE + field_neighborhood='Bear Creek' (never un-sets).
 *
 * Dry-run by default. BOS_BACKFILL_APPLY=1 to write.
 * Run: drush php:script web/scripts/backfill_bearcreek_hoa.php
 */

use Drupal\Core\Database\Database;

$APPLY = getenv('BOS_BACKFILL_APPLY') === '1';
$db = Database::getConnection();
$etm = \Drupal::entityTypeManager();
print ($APPLY ? "*** APPLY ***\n" : "--- DRY RUN (BOS_BACKFILL_APPLY=1 to write) ---\n");

// Properties referenced by any bearcreek* service request.
$pids = $db->query("SELECT DISTINCT p.field_property_target_id
  FROM {service_request__field_campaign} c
  JOIN {service_request__field_property} p ON p.entity_id = c.entity_id
  WHERE LOWER(c.field_campaign_value) LIKE 'bearcreek%'
    AND p.field_property_target_id IS NOT NULL")->fetchCol();

print 'Bear Creek properties found: ' . count($pids) . "\n";
$set = 0; $already = 0;
foreach ($pids as $pid) {
  $prop = $etm->getStorage('properties')->load((int) $pid);
  if (!$prop) { continue; }
  $isHoa = $prop->hasField('field_hoa_contracted') && (bool) $prop->get('field_hoa_contracted')->value;
  $nick = $prop->get('field_nickname')->value ?: ('property ' . $pid);
  if ($isHoa) { $already++; print "  [already HOA] $pid — $nick\n"; continue; }
  print "  [set HOA] $pid — $nick\n";
  if ($APPLY) {
    $prop->set('field_hoa_contracted', TRUE);
    if ($prop->hasField('field_neighborhood') && $prop->get('field_neighborhood')->isEmpty()) {
      $prop->set('field_neighborhood', 'Bear Creek');
    }
    $prop->save();
  }
  $set++;
}
print "\nto set: $set | already HOA: $already\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN — set BOS_BACKFILL_APPLY=1 to write.\n";
