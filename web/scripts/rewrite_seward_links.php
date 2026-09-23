<?php

/**
 * Relativize links/images pointing at the un-owned old domain sewardslandscape.com
 * in CONTENT fields (not email/login fields). Turns
 *   https://sewardslandscape.com/colorado/x  ->  /colorado/x
 *   http://sewardslandscape.com/sites/y      ->  /sites/y
 * so they resolve on the current site and never point at the third-party domain.
 *
 * Only touches occurrences with a scheme or protocol-relative "//" prefix, so
 * bare "name@sewardslandscape.com" emails inside text are left alone.
 * Pure SQL text replace — no entity saves (no WO recalc / status side effects).
 * Backs up affected rows to a JSON file first.
 *
 * Dry-run by default; set BOS_APPLY=1 to write:
 *   drush php:script web/scripts/rewrite_seward_links.php
 *   BOS_APPLY=1 drush php:script web/scripts/rewrite_seward_links.php
 */

$apply = getenv('BOS_APPLY') === '1';
$db = \Drupal::database();

// [table, value column]. Content fields only — NO email/login columns.
$targets = [
  ['taxonomy_term__field_service_public_desc', 'field_service_public_desc_value'],
  ['taxonomy_term__field_service_crew_desc', 'field_service_crew_desc_value'],
  ['taxonomy_term_revision__field_service_crew_desc', 'field_service_crew_desc_value'],
  ['taxonomy_term_r__8b1ddaae7b', 'field_service_public_desc_value'],
  ['node__body', 'body_value'],
  ['node_revision__body', 'body_value'],
  ['manufacturer__field_description', 'field_description_value'],
  ['properties__field_property_description', 'field_property_description_value'],
  ['work_order__field_work_todo_description', 'field_work_todo_description_value'],
  ['wo_status_updates__field_status_change_note', 'field_status_change_note_value'],
  ['property_snow_removal_info__a200001995', 'field_snow_removal_instructions_value'],
  ['address__field_address_to', 'field_address_to_value'],
];

$like = '%//sewardslandscape.com%';
$backup = [];
$report = [];

foreach ($targets as [$t, $c]) {
  try {
    $rows = $db->query("SELECT * FROM `$t` WHERE `$c` LIKE :q", [':q' => $like])->fetchAll(\PDO::FETCH_ASSOC);
  }
  catch (\Exception $e) {
    $report[] = "$t.$c — SKIPPED (" . $e->getMessage() . ')';
    continue;
  }
  if (!$rows) {
    continue;
  }
  $backup[$t] = $rows;

  if ($apply) {
    // Full-scheme forms first (inner), protocol-relative last (outer).
    $sql = "UPDATE `$t` SET `$c` = "
      . "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`$c`,"
      . "'https://www.sewardslandscape.com',''),"
      . "'http://www.sewardslandscape.com',''),"
      . "'https://sewardslandscape.com',''),"
      . "'http://sewardslandscape.com',''),"
      . "'//sewardslandscape.com','') "
      . "WHERE `$c` LIKE :q";
    $affected = $db->query($sql, [':q' => $like]);
    $report[] = "$t.$c — rewrote " . count($rows) . ' row(s)';
  }
  else {
    $report[] = "$t.$c — WOULD rewrite " . count($rows) . ' row(s)';
  }
}

if ($backup) {
  $file = 'sewardslandscape-links-backup-' . date('Ymd-His') . '.json';
  file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  print ($apply ? 'Backed up' : 'Would back up') . " affected rows to $file (" . array_sum(array_map('count', $backup)) . " rows)\n";
}

print "\n" . ($apply ? 'APPLIED:' : 'DRY RUN (set BOS_APPLY=1 to write):') . "\n  " . implode("\n  ", $report) . "\n";
print "DONE.\n";
