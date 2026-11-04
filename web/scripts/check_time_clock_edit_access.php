<?php

/**
 * @file
 * Who can actually edit a time clock entry?
 *
 * Written because I twice asserted permissions from invented names instead of
 * listing what exists, and told Todd teammates could not edit their own time.
 * They have been doing it for years. This resolves it by ASKING THE ACCESS
 * HANDLER as a real crew account, not by reading permission rows.
 *
 * The question that matters for the foreman-crew-clock design is not only
 * "can they edit their own" but "can they edit SOMEBODY ELSE'S" — the
 * permission set includes `edit any wo_time_clock entities of bundle entry`
 * for the teammates role, which is broader than self-service.
 *
 * READ-ONLY.
 *
 * Run: drush php:script web/scripts/check_time_clock_edit_access.php
 */

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();

// A crew-only account: teammates, and none of the office roles.
$crew = NULL;
$ids = \Drupal::entityQuery('user')->accessCheck(FALSE)
  ->condition('status', 1)->condition('roles', 'teammates')->condition('uid', 1, '<>')->execute();
foreach ($etm->getStorage('user')->loadMultiple($ids) as $u) {
  if (!array_intersect(['administrator', 'site_admin', 'site_assistant', 'administration', 'supervisor'], $u->getRoles())) {
    $crew = $u;
    break;
  }
}
if (!$crew) {
  print "ABORT: no crew-only account found.\n";
  return;
}
printf("crew account: %s (uid %s)\n  roles: %s\n\n", $crew->getDisplayName(), $crew->id(), implode(', ', $crew->getRoles()));

$own = $db->query('SELECT entity_id FROM {wo_time_clock__field_teammate}
  WHERE deleted = 0 AND field_teammate_target_id = :u ORDER BY entity_id DESC LIMIT 1',
  [':u' => $crew->id()])->fetchField();
$other = $db->query('SELECT entity_id FROM {wo_time_clock__field_teammate}
  WHERE deleted = 0 AND field_teammate_target_id <> :u ORDER BY entity_id DESC LIMIT 1',
  [':u' => $crew->id()])->fetchField();

$ach = $etm->getAccessControlHandler('wo_time_clock');
printf("%-28s %-9s %-10s %-10s %s\n", 'ENTRY', 'ID', 'VIEW', 'UPDATE', 'DELETE');
printf("%s\n", str_repeat('-', 68));
foreach ([[$own, 'their OWN entry'], [$other, "another teammate's entry"]] as [$id, $label]) {
  if (!$id) {
    printf("%-28s %s\n", $label, '(none found)');
    continue;
  }
  $e = $etm->getStorage('wo_time_clock')->load($id);
  // Reset between checks: entity access is statically cached per
  // (entity, operation, account) and a stale result reads as a real answer.
  $ach->resetCache();
  $view = $e->access('view', $crew);
  $ach->resetCache();
  $update = $e->access('update', $crew);
  $ach->resetCache();
  $delete = $e->access('delete', $crew);
  printf("%-28s %-9s %-10s %-10s %s\n", $label, $id,
    $view ? 'yes' : 'no', $update ? 'ALLOWED' : 'denied', $delete ? 'yes' : 'no');
}

printf("\nhours-cap bypass (administer eck entities): %s\n",
  $crew->hasPermission('administer eck entities') ? 'yes' : 'no');
print "  (the cap still applies to them, but the \"This long entry is correct\"\n"
  . "   checkbox is on the entry form, so they can clear it themselves.)\n";

// How often is self-editing actually used?
$manual = (int) $db->query("SELECT COUNT(*) FROM {wo_time_clock__field_source}
  WHERE deleted = 0 AND field_source_value = 'manual'")->fetchField();
printf("\nentries tagged source=manual: %d\n", $manual);
