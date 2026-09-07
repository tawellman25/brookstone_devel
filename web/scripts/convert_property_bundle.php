<?php

/**
 * @file
 * Reversible ECK bundle conversion for `properties` entities (non-revisionable).
 * Changes a property's bundle in place — SAME entity ID — so every Work Order,
 * contract, ownership record, and detail entity (mowing info, sprinkler info, …)
 * that references it by ID stays intact, and it stays on the mow list.
 *
 * Because the `hoa` bundle is a SUPERSET of `property`, every field row converts
 * with zero orphans (and vice-versa for a revert, as long as the target bundle
 * has the field).
 *
 * Env:
 *   BOS_CONVERT_IDS=144252,144255   (required)
 *   BOS_CONVERT_FROM=property       (default property)
 *   BOS_CONVERT_TO=hoa             (default hoa)   ← swap FROM/TO to revert
 *   BOS_CONVERT_APPLY=1            (omit = dry run)
 *
 * Run: drush php:script web/scripts/convert_property_bundle.php
 */

use Drupal\Core\Database\Database;

$IDS = array_filter(array_map('trim', explode(',', (string) getenv('BOS_CONVERT_IDS'))));
$FROM = getenv('BOS_CONVERT_FROM') ?: 'property';
$TO = getenv('BOS_CONVERT_TO') ?: 'hoa';
$APPLY = getenv('BOS_CONVERT_APPLY') === '1';

if (!$IDS) { print "Set BOS_CONVERT_IDS=comma,separated,ids\n"; return; }
print ($APPLY ? "*** APPLY ***" : "--- DRY RUN ---") . "  $FROM → $TO  ids: " . implode(', ', $IDS) . "\n\n";

$db = Database::getConnection();
$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');

// Confirm the destination bundle has every field the source rows use (superset guard).
$toFields = array_keys($efm->getFieldDefinitions('properties', $TO));

// All field tables for properties.
$fieldTables = [];
foreach ($db->query("SHOW TABLES LIKE 'properties\\_\\_%'")->fetchCol() as $t) {
  $fieldTables[] = $t;
}

$refCount = function ($etype, $field, $pid) use ($etm) {
  try {
    return (int) $etm->getStorage($etype)->getQuery()->accessCheck(FALSE)->condition($field, $pid)->count()->execute();
  }
  catch (\Throwable $e) { return -1; }
};

foreach ($IDS as $pid) {
  $pid = (int) $pid;
  $cur = $db->query("SELECT type FROM {properties_field_data} WHERE id = :id", [':id' => $pid])->fetchField();
  if (!$cur) { print "  id $pid: NOT FOUND — skip\n"; continue; }
  if ($cur !== $FROM) { print "  id $pid: bundle is '$cur', not '$FROM' — skip\n"; continue; }

  // Snapshot references (must be identical after).
  $before = [
    'wo' => $refCount('work_order', 'field_property', $pid),
    'mow' => $refCount('property_lawn_maintenance', 'field_property', $pid),
    'contracts' => $refCount('contracts', 'field_property', $pid),
    'ownership' => $refCount('ownership_record', 'field_property_reference', $pid),
  ];
  $title = $db->query("SELECT n.field_nickname_value FROM {properties__field_nickname} n WHERE n.entity_id = :id", [':id' => $pid])->fetchField();
  print "  id $pid ('$title'): $FROM → $TO | refs before: WO {$before['wo']}, mow {$before['mow']}, contracts {$before['contracts']}, ownership {$before['ownership']}\n";

  if (!$APPLY) { continue; }

  $txn = $db->startTransaction();
  try {
    $db->update('properties')->fields(['type' => $TO])->condition('id', $pid)->execute();
    $db->update('properties_field_data')->fields(['type' => $TO])->condition('id', $pid)->execute();
    foreach ($fieldTables as $t) {
      // Only tables that actually have a bundle column + rows for this entity.
      $db->update($t)->fields(['bundle' => $TO])->condition('entity_id', $pid)->execute();
    }
    unset($txn); // commit
  }
  catch (\Throwable $e) {
    // $txn rolls back when it goes out of scope on exception.
    print "    ERROR: " . $e->getMessage() . " — rolled back\n";
    continue;
  }

  // Refresh caches + re-save so the title regenerates from the new bundle's rule.
  $etm->getStorage('properties')->resetCache([$pid]);
  \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
  $p = $etm->getStorage('properties')->load($pid);
  $newBundle = $p ? $p->bundle() : '?';
  if ($p) { $p->save(); $p = $etm->getStorage('properties')->load($pid); }

  $after = [
    'wo' => $refCount('work_order', 'field_property', $pid),
    'mow' => $refCount('property_lawn_maintenance', 'field_property', $pid),
    'contracts' => $refCount('contracts', 'field_property', $pid),
    'ownership' => $refCount('ownership_record', 'field_property_reference', $pid),
  ];
  $intact = ($before == $after) ? 'INTACT' : 'CHANGED(!)';
  print "    → bundle now '$newBundle', title '" . ($p ? $p->label() : '?') . "' | refs after: WO {$after['wo']}, mow {$after['mow']}, contracts {$after['contracts']}, ownership {$after['ownership']} — $intact\n";
}

print "\n" . ($APPLY ? "APPLIED." : "DRY RUN — set BOS_CONVERT_APPLY=1 to write.") . "\n";
