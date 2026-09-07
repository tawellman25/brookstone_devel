<?php

/**
 * @file
 * Backfill field_system_type onto existing sprinkler_winterizing +
 * sprinkler_start_up work orders from the property's primary sprinkler system.
 *
 * DIRECT DB insert (no entity save) — so no presave fires and NO billing is
 * recomputed. Completed WOs keep their frozen totals; this only makes System
 * Type visible + queryable on history. Idempotent: skips any WO that already
 * has a field_system_type row. Single-source: uses the property's first
 * (delta 0) sprinkler system, matching the module's pre-fill logic.
 *
 * Dry-run by default. BOS_BACKFILL_APPLY=1 to write.
 * Run: ddev drush php:script web/scripts/backfill_wo_system_type.php
 */

use Drupal\Core\Database\Database;

$APPLY = getenv('BOS_BACKFILL_APPLY') === '1';
$db = Database::getConnection();
print ($APPLY ? "*** APPLY ***\n" : "--- DRY RUN (set BOS_BACKFILL_APPLY=1 to write) ---\n");

$bundles = ['sprinkler_winterizing', 'sprinkler_start_up'];
$grand = ['filled' => 0, 'skip_has' => 0, 'skip_noprop' => 0, 'skip_notype' => 0];
$byType = [];

foreach ($bundles as $bundle) {
  $ids = $db->query('SELECT id FROM {work_order_field_data} WHERE type = :t', [':t' => $bundle])->fetchCol();
  $filled = 0; $has = 0; $noprop = 0; $notype = 0;
  foreach ($ids as $wid) {
    $wid = (int) $wid;
    // Already has the field? skip (idempotent).
    $exists = $db->query('SELECT 1 FROM {work_order__field_system_type} WHERE entity_id = :e LIMIT 1', [':e' => $wid])->fetchField();
    if ($exists) { $has++; continue; }
    // Resolve property -> primary (delta 0) sprinkler system -> system type.
    $tid = $db->query(
      'SELECT st.field_system_type_target_id
         FROM {work_order__field_property} wop
         JOIN {property_sprinkler_info__field_property} pip ON pip.field_property_target_id = wop.field_property_target_id
         JOIN {property_sprinkler_info__field_systems} pis ON pis.entity_id = pip.entity_id AND pis.delta = 0
         JOIN {property_sprinkler_system__field_system_type} st ON st.entity_id = pis.field_systems_target_id
        WHERE wop.entity_id = :e
        LIMIT 1',
      [':e' => $wid]
    )->fetchField();

    $propExists = $db->query('SELECT 1 FROM {work_order__field_property} WHERE entity_id = :e LIMIT 1', [':e' => $wid])->fetchField();
    if (!$propExists) { $noprop++; continue; }
    if (!$tid) { $notype++; continue; }
    $tid = (int) $tid;

    if ($APPLY) {
      $db->insert('work_order__field_system_type')
        ->fields([
          'bundle' => $bundle,
          'deleted' => 0,
          'entity_id' => $wid,
          'revision_id' => $wid,
          'langcode' => 'en',
          'delta' => 0,
          'field_system_type_target_id' => $tid,
        ])
        ->execute();
    }
    $filled++;
    $byType[$tid] = ($byType[$tid] ?? 0) + 1;
  }
  printf("%-22s filled %5d | already had %5d | no property %4d | no system type %5d\n",
    $bundle, $filled, $has, $noprop, $notype);
  $grand['filled'] += $filled; $grand['skip_has'] += $has;
  $grand['skip_noprop'] += $noprop; $grand['skip_notype'] += $notype;
}

print "\n=== totals ===\n";
printf("filled: %d | already had: %d | no property: %d | no system type on file: %d\n",
  $grand['filled'], $grand['skip_has'], $grand['skip_noprop'], $grand['skip_notype']);
print "--- by system type (filled) ---\n";
$names = [13 => 'Domestic', 15 => 'Dirty', 16 => 'Duel', 30742 => 'Well'];
foreach ($byType as $t => $c) {
  print "  " . ($names[$t] ?? "tid $t") . " ($t): $c\n";
}
print $APPLY ? "\nAPPLIED. Run `drush cr` to refresh entity caches.\n" : "\nDRY RUN complete.\n";
