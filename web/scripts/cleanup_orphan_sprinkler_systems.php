<?php
/**
 * Remove sprinkler system records that are junk, after proving they are junk.
 *
 * Surfaced 2026-10-09 (deferred_work #29): seven property_sprinkler_system
 * records are in no property_sprinkler_info.field_systems list, which is the
 * only path winterizing billing uses to reach a system. They are not one
 * problem, and only these seven were ever in scope:
 *
 *   1168, 1170, 1171 -- created 2025-10-11 and named "Test System",
 *     "4th test System", "test 5 System". Linked to no property at all.
 *   1169 -- created in that SAME batch, to the second, as 1168 and 1171, but
 *     pointed at a real property (300 Stafford Lane) and carrying 40 zones.
 *     Todd confirmed 2026-10-10 the real count is 18, which is what the live
 *     record 752 holds and what WO#52767 correctly billed. So the 40 was a
 *     practice entry, never a measurement.
 *   917, 949, 1117 -- exact duplicates (same name, type and zone count) of
 *     916, 948 and 1118, which are the records billing already uses.
 *
 * ⚠ The duplicates must be DELETED, never "fixed" by linking them to the info
 * record: zone charges SUM across every system billing can see, so linking 917
 * would take 1750 Lynx from 6 zones to 12 and overcharge that customer $30.
 *
 * Refuses to delete anything that is referenced. Dry run by default; set
 * BOS_SPRINKLER_CLEANUP_APPLY=1 to actually remove. Writes a full JSON backup
 * of every record it touches before touching it.
 */

$apply = getenv('BOS_SPRINKLER_CLEANUP_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
$db = \Drupal::database();
$storage = $etm->getStorage('property_sprinkler_system');

$TARGETS = [
  1168 => 'test record, no property',
  1170 => 'test record, no property',
  1171 => 'test record, no property',
  1169 => 'practice record on 300 Stafford Lane (40 zones; real count is 18, held by 752)',
  917  => 'exact duplicate of 916 (1750 Lynx St.)',
  949  => 'exact duplicate of 948 (755 S Grand Mesa Dr.)',
  1117 => 'exact duplicate of 1118 (726 E 5th St.)',
];

printf("%s\n\n", $apply ? '*** APPLYING ***' : 'DRY RUN (set BOS_SPRINKLER_CLEANUP_APPLY=1 to apply)');

// Every entity_reference field anywhere that can point at a sprinkler system --
// discovered, not guessed, so a field added later cannot be missed.
$incoming = [];
foreach ($efm->getFieldMapByFieldType('entity_reference') as $entityType => $fields) {
  foreach ($fields as $fieldName => $info) {
    foreach ($info['bundles'] as $bundle) {
      $defs = $efm->getFieldDefinitions($entityType, $bundle);
      if (!isset($defs[$fieldName])) { continue; }
      if ($defs[$fieldName]->getSetting('target_type') === 'property_sprinkler_system') {
        $incoming[$entityType . '.' . $fieldName] = [$entityType, $fieldName];
      }
    }
  }
}
printf("reference fields that can point at a sprinkler system: %s\n\n",
  $incoming ? implode(', ', array_keys($incoming)) : '(none found)');

$backup = [];
$deletable = [];
foreach ($TARGETS as $id => $why) {
  $sys = $storage->load($id);
  if (!$sys) { printf("  %-5s GONE ALREADY — nothing to do\n", $id); continue; }

  // Full dump, so anything removed can be reconstructed.
  $values = [];
  foreach ($sys->getFields() as $name => $item) {
    if (!$item->isEmpty()) { $values[$name] = $item->getValue(); }
  }
  $backup[$id] = ['label' => $sys->label(), 'reason' => $why, 'values' => $values];

  // Who points at it, right now?
  $refs = [];
  foreach ($incoming as [$etype, $fname]) {
    $found = $etm->getStorage($etype)->getQuery()->accessCheck(FALSE)
      ->condition($fname, $id)->execute();
    foreach ($found as $fid) { $refs[] = "$etype.$fname:$fid"; }
  }
  // And the list billing actually walks.
  $listed = $db->query("SELECT entity_id FROM {property_sprinkler_info__field_systems} WHERE field_systems_target_id = :i", [':i' => $id])->fetchCol();
  foreach ($listed as $iid) { $refs[] = "property_sprinkler_info.field_systems:$iid"; }

  printf("  %-5s %-16s zones=%-5s %s\n         %s\n", $id, $sys->label(),
    $sys->get('field_total_zones')->value ?? '-',
    $refs ? 'REFERENCED — WILL NOT DELETE' : 'unreferenced — safe to delete', $why);
  if ($refs) { printf("         referenced by: %s\n", implode(', ', $refs)); continue; }
  $deletable[] = $id;
}

// Write through the STREAM WRAPPER, never realpath(). On live public:// is S3
// via s3fs and realpath() returns empty, so the original form of this silently
// aimed at "/sprinkler-systems-removed-….json" at the filesystem root, failed
// with permission denied, and still printed "backup written" -- which is how
// the 2026-10-10 run deleted seven records with no backup beside them. The
// nightly dump covered it, but the script must not rely on that.
$dir = 'public://bos-backups';
\Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
$path = $dir . '/sprinkler-systems-removed-' . date('Ymd-His') . '.json';
if ($apply && $backup) {
  $written = @file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT));
  if ($written === FALSE || !file_exists($path)) {
    print "\nABORTING: could not write the backup to $path — nothing deleted.\n";
    return;
  }
  printf("\nbackup written: %s (%d bytes)\n", $path, $written);
}

printf("\n%d record(s) deletable, %d refused\n", count($deletable), count($TARGETS) - count($deletable));
if (!$apply) { print "nothing deleted (dry run)\n"; return; }
foreach ($deletable as $id) {
  $storage->load($id)?->delete();
  printf("  deleted %d\n", $id);
}
print "\n=== after ===\n";
$listedAll = [];
foreach ($db->query("SELECT field_systems_target_id FROM {property_sprinkler_info__field_systems}")->fetchCol() as $s) { $listedAll[(int) $s] = TRUE; }
$all = $db->query("SELECT id FROM {property_sprinkler_system}")->fetchCol();
$orphans = array_values(array_filter($all, fn($i) => !isset($listedAll[(int) $i])));
printf("  systems total: %d\n  still unreachable by billing: %d%s\n", count($all), count($orphans),
  $orphans ? ' -> ' . implode(',', $orphans) : '');
