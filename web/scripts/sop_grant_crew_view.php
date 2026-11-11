<?php

/**
 * @file
 * Open the crew-facing SOP bundles to the people they are written for (Todd, 2026-11-11).
 *
 *   drush php:script web/scripts/sop_grant_crew_view.php -- --dry-run
 *   drush php:script web/scripts/sop_grant_crew_view.php
 *
 * SOP view access is per bundle ("view any sop entities of bundle X"). Before this,
 * teammates could read only sprinkler_maintenance (+ system_procedures since 10:15 MT
 * today); landscaping, spray, snow, safety, training, maintenance and lighting SOPs
 * were invisible to crews (deferred_work.md #34). Idempotent; never revokes anything;
 * office_administration and sop_governance are deliberately NOT touched.
 */

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';

$bundles = ['landscaping', 'lighting', 'maintenance', 'safety', 'snow_removal', 'spray', 'training', 'sprinkler_maintenance', 'system_procedures'];
$roles = ['teammates', 'supervisor', 'administration'];

$known = \Drupal::service('user.permissions')->getPermissions();
$bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('sop');
$storage = \Drupal::entityTypeManager()->getStorage('user_role');

foreach ($roles as $rid) {
  $role = $storage->load($rid);
  if (!$role) { echo "{$tag}role $rid MISSING\n"; continue; }
  $granted = $had = $skipped = [];
  foreach ($bundles as $b) {
    $perm = "view any sop entities of bundle $b";
    if (!isset($bundle_info[$b]) || !isset($known[$perm])) { $skipped[] = $b; continue; }
    if ($role->hasPermission($perm)) { $had[] = $b; continue; }
    if (!$dry) { $role->grantPermission($perm); }
    $granted[] = $b;
  }
  if ($granted && !$dry) { $role->save(); }
  printf("%s%-15s granted: %s · already had: %s%s\n", $tag, $rid, $granted ? implode(', ', $granted) : '-', $had ? implode(', ', $had) : '-', $skipped ? ' · SKIPPED (no such bundle/permission): ' . implode(', ', $skipped) : '');
}
foreach (['office_administration', 'sop_governance'] as $b) {
  $r = $storage->load('teammates');
  printf("%steammates on %-22s %s (unchanged by design)\n", $tag, $b . ':', $r->hasPermission("view any sop entities of bundle $b") ? 'CAN view' : 'no view');
}
echo "{$tag}done\n";
