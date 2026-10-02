<?php

/**
 * Heal stored auto_entitylabel placeholders ('%AutoEntityLabel: <uuid>%').
 *
 * WHY NOT JUST RE-SAVE THE ENTITY
 * -------------------------------
 * A re-save would also heal the label, but it fires every presave/update hook
 * on the way through, and for the two affected types in BOS that is the wrong
 * trade:
 *
 *  - media:wo_images — an enabled pathauto pattern (work_order_media_groups)
 *    keys on [media:name], so 3,436 saves would mint 3,436 URL aliases that
 *    none of these records have today and nobody asked for, plus a thumbnail
 *    read per record against S3 (the 2026-08-03 split measured ~2s each).
 *  - wo_status_updates — a save propagates status onto the parent work order.
 *    That is the 2026-09-29 incident path. It must not be walked for a
 *    cosmetic title repair.
 *
 * So this computes the label with auto_entitylabel's OWN decorator — the same
 * code a presave would run, so the text is byte-identical to what a save would
 * write — and then writes only the label column, invalidating the entity's
 * cache tag. Same approach as the 2026-09-07 work-order system-type backfill.
 *
 * The placeholder is stored, not displayed-only: contrib repairs the label on
 * the in-memory entity during hook_entity_insert while leaving the row carrying
 * the placeholder until a shutdown re-save that, for these records, never ran
 * (the 2026-08-03 wo_photo_split batch was killed after "completing all work" —
 * that hang WAS its 3,436 queued shutdown re-saves).
 *
 * Usage (dry-run by default):
 *   BOS_HEAL_TYPES=media drush php:script web/scripts/heal_media_label_placeholders.php
 *   BOS_HEAL_TYPES=media BOS_HEAL_APPLY=1 drush php:script ...
 *   BOS_HEAL_TYPES=media BOS_HEAL_LIMIT=5 BOS_HEAL_APPLY=1 drush php:script ...
 *
 * Entity types must be named explicitly so nothing is ever healed by accident.
 */

$types = array_filter(array_map('trim', explode(',', (string) getenv('BOS_HEAL_TYPES'))));
if (!$types) {
  print "Refusing to run: set BOS_HEAL_TYPES (comma separated), e.g. BOS_HEAL_TYPES=media\n";
  return;
}
$apply = getenv('BOS_HEAL_APPLY') === '1';
$limit = (int) getenv('BOS_HEAL_LIMIT') ?: 0;
$batch = 250;

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$dec = \Drupal::service('auto_entitylabel.entity_decorator');
$invalidator = \Drupal::service('cache_tags.invalidator');

printf("mode: %s%s\n\n", $apply ? 'APPLY' : 'DRY RUN', $limit ? " (limit {$limit})" : '');

$grand = ['found' => 0, 'healed' => 0, 'skipped' => 0, 'errors' => 0];

foreach ($types as $type) {
  if (!$etm->hasDefinition($type)) {
    printf("%s: no such entity type\n", $type);
    continue;
  }
  $def = $etm->getDefinition($type);
  $labelKey = $def->getKey('label');
  if (!$labelKey) {
    printf("%s: no label key\n", $type);
    continue;
  }
  $storage = $etm->getStorage($type);
  $mapping = $storage->getTableMapping();
  // Resolve the real tables rather than assuming names.
  $dataTable = $mapping->getFieldTableName($labelKey);
  $idKey = $def->getKey('id');
  $revKey = $def->isRevisionable() ? $def->getKey('revision') : NULL;
  $revTable = NULL;
  if ($revKey && method_exists($def, 'getRevisionDataTable')) {
    $candidate = $def->getRevisionDataTable() ?: $def->getRevisionTable();
    if ($candidate && $db->schema()->fieldExists($candidate, $labelKey)) {
      $revTable = $candidate;
    }
  }
  printf("=== %s ===\n  label column %s in %s%s\n", $type, $labelKey, $dataTable,
    $revTable ? " (+ revision table {$revTable})" : '');

  $ids = $db->select($dataTable, 't')
    ->fields('t', [$idKey])
    ->condition($labelKey, '%AutoEntityLabel%', 'LIKE')
    ->orderBy($idKey)
    ->execute()
    ->fetchCol();
  $ids = array_values(array_unique($ids));
  if ($limit) {
    $ids = array_slice($ids, 0, $limit);
  }
  printf("  carrying a stored placeholder: %d\n", count($ids));
  $grand['found'] += count($ids);
  if (!$ids) {
    print "\n";
    continue;
  }

  $healed = $skipped = $errors = 0;
  $tags = [];
  $shown = 0;
  foreach (array_chunk($ids, $batch) as $chunk) {
    $storage->resetCache($chunk);
    foreach ($storage->loadMultiple($chunk) as $entity) {
      $id = $entity->id();
      $before = (string) $entity->get($labelKey)->value;
      try {
        // auto_entitylabel's own renderer: in-memory only, nothing is saved.
        $computed = (string) $dec->decorate($entity)->setLabel();
      }
      catch (\Throwable $e) {
        $errors++;
        printf("  ERROR %-8s %s\n", $id, $e->getMessage());
        continue;
      }
      $computed = trim($computed);
      // Never replace one unusable title with another.
      if ($computed === '' || str_contains($computed, '%AutoEntityLabel')) {
        $skipped++;
        printf("  SKIP  %-8s computed %s\n", $id, var_export($computed, TRUE));
        continue;
      }
      if (mb_strlen($computed) > 255) {
        $computed = mb_substr($computed, 0, 255);
      }
      if ($shown < 5) {
        printf("  %-8s -> %s\n", $id, $computed);
        $shown++;
      }
      if (!$apply) {
        $healed++;
        continue;
      }
      try {
        $db->update($dataTable)
          ->fields([$labelKey => $computed])
          ->condition($idKey, $id)
          ->execute();
        // Keep the current revision row in step; older revisions keep their
        // own history.
        if ($revTable && $revKey) {
          $db->update($revTable)
            ->fields([$labelKey => $computed])
            ->condition($revKey, $entity->getRevisionId())
            ->execute();
        }
        $tags[] = $type . ':' . $id;
        $healed++;
      }
      catch (\Throwable $e) {
        $errors++;
        printf("  ERROR %-8s write failed: %s\n", $id, $e->getMessage());
      }
    }
    if ($apply && $tags) {
      $invalidator->invalidateTags($tags);
      $tags = [];
    }
    $storage->resetCache($chunk);
  }

  printf("  %s: %d, skipped: %d, errors: %d\n\n", $apply ? 'healed' : 'would heal', $healed, $skipped, $errors);
  $grand['healed'] += $healed;
  $grand['skipped'] += $skipped;
  $grand['errors'] += $errors;
}

printf("TOTAL  found %d | %s %d | skipped %d | errors %d\n",
  $grand['found'], $apply ? 'healed' : 'would heal', $grand['healed'], $grand['skipped'], $grand['errors']);
if (!$apply) {
  print "\nNothing was written. Re-run with BOS_HEAL_APPLY=1 to apply.\n";
}
