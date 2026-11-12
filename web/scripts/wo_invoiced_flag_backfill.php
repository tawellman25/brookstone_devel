<?php

/**
 * @file
 * Backfill the Invoiced flag ("No" = 0) on work orders that have no stored
 * value at all — no row in work_order__field_invoiced.
 *
 * Why: the entity form writes 0 for an unticked checkbox, but programmatic
 * creators (bos_wo_intake — My 2nd Brain / service requests, the backflow
 * test controller, legacy migrations) skipped the flag. A Views boolean
 * filter is `field_invoiced_value = 0`; a missing row never satisfies it,
 * so those WOs appeared in the billing views only under "Any". From
 * 2026-11-12 wo_shared_work_order_presave() stores the 0 on every save;
 * this script closes the gap for the WOs created before that.
 *
 * Writes the field table directly (no entity save), so no wo_* presave
 * recalculation runs and nothing else on the WO changes. Entity + render
 * caches for the touched WOs and the work_order list tag are invalidated,
 * and the ids are logged to watchdog (channel wo_shared).
 *
 * Usage (dev):
 *   ddev drush php:script web/scripts/wo_invoiced_flag_backfill.php -- --dry-run
 *   ddev drush php:script web/scripts/wo_invoiced_flag_backfill.php
 * Live: drush php:script ~/tmp/wo_invoiced_flag_backfill.php [-- --dry-run]
 *
 *   --printed   target field_printed instead of field_invoiced.
 *
 * Idempotent: a second run finds nothing to do.
 */

use Drupal\Core\Cache\Cache;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$field = in_array('--printed', $extra ?? [], TRUE) ? 'field_printed' : 'field_invoiced';
$table = 'work_order__' . $field;
$column = $field . '_value';

$db = \Drupal::database();
if (!$db->schema()->tableExists($table)) {
  echo "Table $table does not exist — nothing to do.\n";
  return;
}

$rows = $db->query(
  "SELECT w.id, w.type, w.langcode FROM {work_order} w LEFT JOIN {" . $table . "} f ON f.entity_id = w.id WHERE f.entity_id IS NULL ORDER BY w.id"
)->fetchAll();

$total = (int) $db->query('SELECT COUNT(*) FROM {work_order}')->fetchField();
echo ($dry ? '[DRY RUN] ' : '') . "$field: " . count($rows) . " of $total work orders have no stored value.\n";
if (!$rows) {
  return;
}

$by_bundle = [];
foreach ($rows as $r) {
  $by_bundle[$r->type] = ($by_bundle[$r->type] ?? 0) + 1;
}
ksort($by_bundle);
foreach ($by_bundle as $bundle => $n) {
  echo sprintf("  %-28s %d\n", $bundle, $n);
}
$ids = array_map(fn($r) => (int) $r->id, $rows);
echo '  ids: ' . implode(', ', $ids) . "\n";

if ($dry) {
  echo "[DRY RUN] No changes made.\n";
  return;
}

$insert = $db->insert($table)->fields(['bundle', 'deleted', 'entity_id', 'revision_id', 'langcode', 'delta', $column]);
foreach ($rows as $r) {
  $insert->values([
    'bundle' => $r->type,
    'deleted' => 0,
    'entity_id' => (int) $r->id,
    'revision_id' => (int) $r->id,
    'langcode' => $r->langcode ?: 'en',
    'delta' => 0,
    $column => 0,
  ]);
}
$insert->execute();

$storage = \Drupal::entityTypeManager()->getStorage('work_order');
$storage->resetCache($ids);
Cache::invalidateTags(array_merge(['work_order_list'], array_map(fn($id) => 'work_order:' . $id, $ids)));

\Drupal::logger('wo_shared')->notice('@field backfill: stored "No" (0) on @n work orders that had no value: @ids', [
  '@field' => $field,
  '@n' => count($ids),
  '@ids' => implode(', ', $ids),
]);

$remaining = (int) $db->query(
  "SELECT COUNT(*) FROM {work_order} w LEFT JOIN {" . $table . "} f ON f.entity_id = w.id WHERE f.entity_id IS NULL"
)->fetchField();
$check = $storage->load($ids[0]);
echo 'Done: ' . count($ids) . " rows written; $remaining still missing; WO {$ids[0]} now reads $field = "
  . var_export($check ? $check->get($field)->value : NULL, TRUE) . "\n";
