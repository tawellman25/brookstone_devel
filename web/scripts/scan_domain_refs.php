<?php

/**
 * Scan every text-bearing column in the database for a needle (default:
 * sewardslandscape) and report table.column = count. Skips transient/log tables.
 * Read-only. Run per env:
 *   drush php:script web/scripts/scan_domain_refs.php
 *   BOS_SCAN_NEEDLE=example.com drush php:script web/scripts/scan_domain_refs.php
 */

$needle = getenv('BOS_SCAN_NEEDLE') ?: 'sewardslandscape';
$db = \Drupal::database();
$schema = $db->getConnectionOptions()['database'];

$types = ['char', 'varchar', 'text', 'mediumtext', 'longtext', 'tinytext'];
$cols = $db->query(
  "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = :s AND DATA_TYPE IN (:types[])",
  [':s' => $schema, ':types[]' => $types]
)->fetchAll();

$skip = '/^(cache|sessions|watchdog|queue|semaphore|key_value_expire|flood|batch|history|search_)/';
$hits = [];
$scanned = 0;

foreach ($cols as $col) {
  if (preg_match($skip, $col->t)) {
    continue;
  }
  $scanned++;
  try {
    $n = $db->query("SELECT COUNT(*) FROM `{$col->t}` WHERE `{$col->c}` LIKE :q", [':q' => "%{$needle}%"])->fetchField();
    if ($n > 0) {
      $hits[] = "{$col->t}.{$col->c} = {$n}";
    }
  }
  catch (\Exception $e) {
    // Skip columns that can't be queried (e.g. blob-ish, permission).
  }
}

print "needle: {$needle}\n";
print "scanned {$scanned} text columns\n";
print $hits
  ? ("MATCHES:\n  " . implode("\n  ", $hits) . "\n")
  : "NO occurrences found in any content/config text column.\n";
print "DONE.\n";
