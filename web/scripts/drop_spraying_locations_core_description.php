<?php

declare(strict_types=1);

/**
 * Drop core `description` from every spraying_locations view display.
 *
 * Surgical counterpart to setup_spraying_locations_fields.php: it removes ONLY
 * the `description` component and leaves every other component — and every
 * enabled/disabled choice made in Manage Display — exactly as it is. Use this on
 * an environment whose displays have been curated through the UI and must not be
 * rebuilt wholesale.
 *
 * All 20 spraying_locations terms carry their public copy in
 * field_short_description + field_public_description, so `description` is inert;
 * this makes that explicit. Idempotent.
 *
 *   drush php:script web/scripts/drop_spraying_locations_core_description.php
 */

$etm = \Drupal::entityTypeManager();
$vid = 'spraying_locations';
$field = 'description';

$storage = $etm->getStorage('entity_view_display');
$ids = $storage->getQuery()->accessCheck(FALSE)
  ->condition('targetEntityType', 'taxonomy_term')
  ->condition('bundle', $vid)
  ->execute();

$touched = 0;
foreach ($storage->loadMultiple($ids) as $disp) {
  $mode = $disp->getMode();
  $before = array_keys($disp->get('content') ?? []);
  if (!in_array($field, $before, TRUE)) {
    printf("  %-14s already clear  [%s]\n", $mode, implode(', ', $before));
    continue;
  }
  $disp->removeComponent($field);
  $disp->save();
  $touched++;
  printf("  %-14s dropped '%s'  now [%s]\n", $mode, $field, implode(', ', array_keys($disp->get('content') ?? [])));
}

printf("DONE. %d display(s) changed.\n", $touched);
