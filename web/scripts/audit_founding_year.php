<?php

declare(strict_types=1);

/**
 * Read-only: find every "1995" in content, config, and derived claims.
 *
 * Changing a founding year is not a find-and-replace. The same digits carry
 * different claims — when the PREDECESSOR company started, when WE started,
 * how many years of service we advertise — and some of those are arithmetic
 * off the year rather than the year itself. This lists them so a human can say
 * which is which.
 *
 *   drush php:script web/scripts/audit_founding_year.php
 */

$db = \Drupal::database();
$schema = $db->query('SELECT DATABASE()')->fetchField();

$cols = $db->query("
  SELECT TABLE_NAME, COLUMN_NAME
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = :s
    AND DATA_TYPE IN ('varchar','text','mediumtext','longtext','tinytext','char')
", [':s' => $schema])->fetchAll();

printf("scanning %d text columns for 1995…\n\n", count($cols));

$hits = 0;
foreach ($cols as $c) {
  $table = $c->TABLE_NAME;
  $col = $c->COLUMN_NAME;
  // config blobs are serialised elsewhere; skip cache/session noise.
  if (preg_match('/^(cache|sessions|watchdog|queue|key_value_expire|search_|history|flood|batch|semaphore)/', $table)) {
    continue;
  }
  try {
    $rows = $db->query(
      "SELECT * FROM {$table} WHERE `{$col}` LIKE :v LIMIT 20",
      [':v' => '%1995%']
    )->fetchAll();
  }
  catch (\Throwable $e) {
    continue;
  }
  foreach ($rows as $r) {
    $val = (string) $r->{$col};
    // Skip UUIDs and other incidental digit runs.
    if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}/i', $val) && !preg_match('/\b1995\b/', $val)) {
      continue;
    }
    if (!preg_match('/\b1995\b/', $val)) {
      continue;
    }
    $hits++;
    $id = '';
    foreach (['entity_id', 'id', 'nid', 'tid', 'uid', 'revision_id', 'name', 'collection'] as $k) {
      if (isset($r->{$k})) { $id .= "$k={$r->{$k}} "; }
    }
    // Show the sentence around it, not the whole body.
    $plain = trim(preg_replace('/\s+/', ' ', strip_tags($val)));
    if (preg_match('/(.{0,90}\b1995\b.{0,90})/', $plain, $m)) {
      $plain = '…' . trim($m[1]) . '…';
    }
    printf("%-46s %s\n    %s\n\n", "$table.$col", trim($id), $plain);
  }
}

print "--- derived claims that depend on the year ---\n";
foreach ($cols as $c) {
  $table = $c->TABLE_NAME;
  $col = $c->COLUMN_NAME;
  if (preg_match('/^(cache|sessions|watchdog|queue|key_value|search_|history|flood|batch|semaphore)/', $table)) {
    continue;
  }
  try {
    $rows = $db->query(
      "SELECT * FROM {$table} WHERE `{$col}` REGEXP :v LIMIT 10",
      [':v' => '(3[0-9]\\+? *(years|yrs)|over 3[0-9] years|since 19)']
    )->fetchAll();
  }
  catch (\Throwable $e) {
    continue;
  }
  foreach ($rows as $r) {
    $val = trim(preg_replace('/\s+/', ' ', strip_tags((string) $r->{$col})));
    if (!preg_match('/(.{0,70}(3[0-9]\+? *(years|yrs)|since 19\d\d).{0,70})/i', $val, $m)) {
      continue;
    }
    $id = '';
    foreach (['entity_id', 'id', 'nid', 'tid'] as $k) {
      if (isset($r->{$k})) { $id .= "$k={$r->{$k}} "; }
    }
    printf("%-46s %s\n    …%s…\n\n", "$table.$col", trim($id), trim($m[1]));
  }
}

printf("\n%d content hits on 1995.\n", $hits);
