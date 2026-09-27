<?php

declare(strict_types=1);

/**
 * READ-ONLY audit: find every stored reference to the former "S&E Ward's
 * Landscape Management" name (and the old dev domain sewardsdevel.com) across
 * every text-bearing DB column, with enough surrounding text to judge tense.
 * Writes the full report to a file and prints a summary. Changes NOTHING.
 *
 *   drush php:script web/scripts/audit_seward_references.php
 */

$NAME_NEEDLES = [
  'S&E Ward', 'S&amp;E Ward', 'S & E Ward', 'S&E Wards', 'SE Ward',
  "Ward's Landscape", 'Ward’s Landscape', 'Ward&#039;s Landscape', 'Ward&rsquo;s Landscape', 'Wards Landscape',
];
$DEV_NEEDLE = 'sewardsdevel';

$db = \Drupal::database();
$schema = $db->getConnectionOptions()['database'];
$types = ['char', 'varchar', 'text', 'mediumtext', 'longtext', 'tinytext'];
$cols = $db->query(
  "SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = :s AND DATA_TYPE IN (:types[])",
  [':s' => $schema, ':types[]' => $types]
)->fetchAll();
// Skip transient/log tables AND migration tables (migrate_message_* etc. are
// D7->D10 migration logs named after the old `sewardsdevel9_sewards` database —
// noise, not production content).
$skip = '/^(cache|sessions|watchdog|queue|semaphore|key_value_expire|flood|batch|history|search_|sql__|migrate_|migration_|migrate$)/';

/** best-effort id column present on the table */
$idCol = function (string $table) use ($db): ?string {
  foreach (['entity_id', 'tid', 'nid', 'id', 'name', 'revision_id'] as $cand) {
    if ($db->schema()->fieldExists($table, $cand)) {
      return $cand;
    }
  }
  return NULL;
};
/** map a field/data table + id to entity type/bundle/url */
$describe = function (string $table, $id) : string {
  try {
    if (str_starts_with($table, 'taxonomy_term')) {
      $t = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($id);
      return $t ? "taxonomy_term/{$t->bundle()} tid={$id} url=" . $t->toUrl()->toString() : "taxonomy_term tid=$id (gone)";
    }
    if (str_starts_with($table, 'node')) {
      $n = \Drupal::entityTypeManager()->getStorage('node')->load($id);
      return $n ? "node/{$n->bundle()} nid={$id} url=" . $n->toUrl()->toString() : "node nid=$id";
    }
    if (str_starts_with($table, 'block_content')) {
      return "block_content id=$id";
    }
    if (str_starts_with($table, 'menu_link_content')) {
      return "menu_link_content id=$id";
    }
  }
  catch (\Throwable $e) {
  }
  return "$table id=$id";
};

$snippet = function (string $val, array $needles): string {
  $val = (string) $val;
  $lc = mb_strtolower($val);
  $pos = PHP_INT_MAX;
  foreach ($needles as $n) {
    $p = mb_strpos($lc, mb_strtolower($n));
    if ($p !== FALSE && $p < $pos) {
      $pos = $p;
    }
  }
  if ($pos === PHP_INT_MAX) {
    return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($val))), 0, 160);
  }
  $start = max(0, $pos - 90);
  $chunk = mb_substr($val, $start, 260);
  return ($start > 0 ? '…' : '') . trim(preg_replace('/\s+/', ' ', strip_tags($chunk))) . '…';
};

$out = [];
$out[] = "=== S&E Ward's / sewardsdevel.com AUDIT — " . date('Y-m-d H:i') . " ===\n";

foreach ([['NAME REFERENCES', $NAME_NEEDLES], ['sewardsdevel.com (REPORT ONLY)', [$DEV_NEEDLE]]] as [$label, $needles]) {
  $out[] = "\n########## $label ##########";
  $found = 0;
  foreach ($cols as $col) {
    if (preg_match($skip, $col->t)) {
      continue;
    }
    // config table: search the serialized data blob.
    $where = [];
    $args = [];
    foreach ($needles as $i => $n) {
      $where[] = "`{$col->c}` LIKE :q$i";
      $args[":q$i"] = "%$n%";
    }
    try {
      $id = $idCol($col->t);
      $select = $id ? "`$id` AS _id, `{$col->c}` AS _v" : "`{$col->c}` AS _v";
      $rows = $db->query("SELECT $select FROM `{$col->t}` WHERE " . implode(' OR ', $where), $args)->fetchAll();
    }
    catch (\Throwable $e) {
      continue;
    }
    foreach ($rows as $r) {
      $found++;
      $rid = $r->_id ?? '(n/a)';
      $desc = ($col->t === 'config') ? "config: $rid" : $describe($col->t, $rid);
      $out[] = sprintf("\n• %s\n  col: %s.%s\n  text: %s", $desc, $col->t, $col->c, $snippet($r->_v, $needles));
    }
  }
  $out[] = "\n-- $label total rows: $found --";
}

$report = implode("\n", $out) . "\n";
$file = '/tmp/seward_audit.txt';
file_put_contents($file, $report);
print $report;
print "\n(Full report also written to $file)\n";
