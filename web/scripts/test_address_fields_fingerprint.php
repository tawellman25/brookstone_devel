<?php

/**
 * @file
 * Fingerprint every `address` field table — before/after harness for the
 * drupal/address 1.12 -> 2.x upgrade (Drupal 11 project, step 5).
 *
 * Why: address 2.x runs `address_update_9201`, which ALTERS every address
 * field table to add an `address_line3` column. That is a schema change on
 * real data (profile mailing addresses, manufacturer addresses, dump-site
 * locations). This proves the migration touched the schema and NOTHING else:
 * row count and an md5 over every stored value, per table, must be identical
 * before and after; the column list is printed so the one expected addition
 * is visible as the only difference.
 *
 *   ddev drush scr web/scripts/test_address_fields_fingerprint.php
 *
 * Read-only. Safe anywhere, including live (one SELECT per address table).
 */

$db = \Drupal::database();
$etm = \Drupal::entityTypeManager();

foreach ($etm->getStorage('field_storage_config')->loadMultiple() as $storage) {
  if ($storage->getType() !== 'address') {
    continue;
  }
  $table = $etm->getStorage($storage->getTargetEntityTypeId())->getTableMapping()->getDedicatedDataTableName($storage);
  $prefix = $storage->getName() . '_';

  $cols = $db->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t ORDER BY ORDINAL_POSITION",
    [':t' => $db->getPrefix() . $table]
  )->fetchCol();
  $addr_cols = array_values(array_filter($cols, fn($c) => str_starts_with($c, $prefix)));

  // Fingerprint only the columns that exist in BOTH versions, so a new empty
  // column (address_line3) does not change the hash by itself. Any column that
  // appears after the upgrade is listed, and that is the expected difference.
  $stable = array_values(array_filter($addr_cols, fn($c) => $c !== $prefix . 'address_line3'));
  $select = implode(', ', array_map(fn($c) => "COALESCE($c, '~')", $stable));
  $rows = $db->query("SELECT entity_id, delta, $select FROM {{$table}} ORDER BY entity_id, delta")->fetchAll(\PDO::FETCH_NUM);

  printf("  %-44s rows=%-5d md5=%s\n", $table, count($rows), substr(md5(json_encode($rows)), 0, 16));
  printf("     %d column(s): %s\n", count($addr_cols), implode(', ', array_map(fn($c) => substr($c, strlen($prefix)), $addr_cols)));
  if (in_array($prefix . 'address_line3', $addr_cols, TRUE)) {
    $nonempty = $db->query("SELECT COUNT(*) FROM {{$table}} WHERE {$prefix}address_line3 IS NOT NULL AND {$prefix}address_line3 <> ''")->fetchField();
    printf("     address_line3 present — non-empty values: %d (expected 0 right after the upgrade)\n", $nonempty);
  }
}
