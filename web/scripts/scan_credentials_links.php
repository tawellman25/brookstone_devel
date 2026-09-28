<?php

declare(strict_types=1);

/**
 * READ-ONLY, targeted scan for links to /credentials that are not already
 * /about-us/credentials. Checks the stores where BOS public copy actually lives:
 * config (Views areas + blocks), node bodies, taxonomy term text fields, block
 * content, and menu links.
 *
 *   drush php:script web/scripts/scan_credentials_links.php
 */

$db = \Drupal::database();
$bare = '#(?<!about-us)/credentials#';
$total = 0;

$report = function (string $where, string $detail, int $n) use (&$total) {
  printf("  %-46s %-34s %d\n", $where, $detail, $n);
  $total += $n;
};

print "=== config (views areas, blocks, settings) ===\n";
foreach ($db->query("SELECT name, data FROM {config} WHERE CAST(data AS CHAR) LIKE '%/credentials%'")->fetchAll() as $row) {
  $n = preg_match_all($bare, (string) $row->data);
  if ($n) { $report($row->name, 'config object', $n); }
}

print "=== node bodies (current + revisions) ===\n";
foreach (['node__body' => 'entity_id', 'node_revision__body' => 'entity_id'] as $t => $k) {
  if (!$db->schema()->tableExists($t)) { continue; }
  foreach ($db->query("SELECT $k AS id, body_value AS v FROM {" . $t . "} WHERE body_value LIKE '%/credentials%'")->fetchAll() as $r) {
    $n = preg_match_all($bare, (string) $r->v);
    if ($n) { $report($t, "node $r->id", $n); }
  }
}

print "=== taxonomy term text fields ===\n";
foreach (['taxonomy_term__description' => 'description_value',
          'taxonomy_term__field_public_description' => 'field_public_description_value',
          'taxonomy_term__field_teammate_description' => 'field_teammate_description_value',
          'taxonomy_term__field_short_description' => 'field_short_description_value'] as $t => $col) {
  if (!$db->schema()->tableExists($t)) { continue; }
  foreach ($db->query("SELECT entity_id, $col AS v FROM {" . $t . "} WHERE $col LIKE '%/credentials%'")->fetchAll() as $r) {
    $n = preg_match_all($bare, (string) $r->v);
    if ($n) { $report($t, "term $r->entity_id", $n); }
  }
}

print "=== block content ===\n";
foreach (['block_content__body' => 'body_value'] as $t => $col) {
  if (!$db->schema()->tableExists($t)) { continue; }
  foreach ($db->query("SELECT entity_id, $col AS v FROM {" . $t . "} WHERE $col LIKE '%/credentials%'")->fetchAll() as $r) {
    $n = preg_match_all($bare, (string) $r->v);
    if ($n) { $report($t, "block $r->entity_id", $n); }
  }
}

print "=== menu links ===\n";
foreach ($db->query("SELECT id, link__uri FROM {menu_link_content_data} WHERE link__uri LIKE '%credentials%'")->fetchAll() as $r) {
  $n = preg_match_all($bare, (string) $r->link__uri);
  if ($n) { $report('menu_link_content_data', "link $r->id: $r->link__uri", $n); }
}

printf("\nTOTAL bare /credentials occurrences: %d\n", $total);
