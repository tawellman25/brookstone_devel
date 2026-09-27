<?php

declare(strict_types=1);

/**
 * Data-safe backfill + Title regeneration for materials, under the
 * auto_entitylabel patterns set by setup_material_title_labels.php (run that
 * first). For each record, BEFORE the title recomposes, make sure the pattern's
 * name field carries what the current title held — so nothing that lived only in
 * a hand-typed title is lost:
 *
 *   - sod   : if field_sod_variety is empty, set it = current title.
 *   - pumps : set field_name = current title (the HP prefix lives only in the
 *             title; field_pump_size is a coded list a token can't render).
 *   - other : if field_name is empty, set it = current title.
 *
 * Then save (auto_entitylabel + preserve_titles=false rewrites the title from the
 * pattern). Because the name field now holds the old title's content, a record
 * with an empty size composes back to its original title — no empty titles, no
 * lost names. Records that already had name+size compose to the intended
 * "Size Name" title.
 *
 * A plain material save fires NO material-module side effects and does NOT run
 * entity validation, so this is safe to bulk-run. Chunked, with progress + a
 * guard that refuses to save a record whose title would come out empty.
 *
 *   drush php:script web/scripts/regenerate_material_titles.php
 */

$st = \Drupal::entityTypeManager()->getStorage('material');
$ids = $st->getQuery()->accessCheck(FALSE)->sort('id')->execute();
$total = count($ids);
printf("Backfilling + re-saving %d material records…\n", $total);

$done = 0;
$changed = 0;
$backfilled = 0;
$skippedEmpty = 0;
$errors = 0;

foreach (array_chunk($ids, 50) as $chunk) {
  foreach ($st->loadMultiple($chunk) as $m) {
    $current = trim((string) $m->label());
    $bundle = $m->bundle();

    // Data-safe backfill of the pattern's name field.
    if ($bundle === 'pumps') {
      if ($current !== '' && $m->get('field_name')->value !== $current) {
        $m->set('field_name', $current);
        $backfilled++;
      }
    }
    elseif ($bundle === 'sod') {
      if ($m->hasField('field_sod_variety') && $m->get('field_sod_variety')->isEmpty() && $current !== '') {
        $m->set('field_sod_variety', $current);
        $backfilled++;
      }
    }
    elseif ($m->hasField('field_name') && $m->get('field_name')->isEmpty() && $current !== '') {
      $m->set('field_name', $current);
      $backfilled++;
    }

    // Guard: never overwrite a good title with an empty one.
    $preview = trim((string) \Drupal::service('token')->replace(
      \Drupal::config("auto_entitylabel.settings.material.$bundle")->get('pattern') ?? '',
      ['material' => $m],
      ['clear' => TRUE]
    ));
    if ($preview === '' && $current !== '') {
      $skippedEmpty++;
      \Drupal::logger('material')->warning('Title regen skipped for material @id (@b): pattern would empty a non-empty title "@t"', ['@id' => $m->id(), '@b' => $bundle, '@t' => $current]);
      $done++;
      continue;
    }

    try {
      $m->save();
      if (trim((string) $m->label()) !== $current) {
        $changed++;
      }
    }
    catch (\Throwable $e) {
      $errors++;
      \Drupal::logger('material')->warning('Title regen failed for material @id: @m', ['@id' => $m->id(), '@m' => $e->getMessage()]);
    }
    $done++;
  }
  \Drupal::service('entity.memory_cache')->deleteAll();
  printf("  %d/%d (backfilled: %d, changed: %d, skipped-empty: %d, errors: %d)\n", $done, $total, $backfilled, $changed, $skippedEmpty, $errors);
}
printf("DONE. %d records, backfilled %d, titles changed %d, skipped-empty %d, errors %d.\n", $done, $backfilled, $changed, $skippedEmpty, $errors);
