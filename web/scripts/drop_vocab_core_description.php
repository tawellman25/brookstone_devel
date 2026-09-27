<?php

declare(strict_types=1);

/**
 * Drop core `description` from every view display of a vocabulary (BOS_VID).
 *
 * Surgical counterpart to setup_spraying_locations_fields.php: it removes ONLY
 * the `description` component and leaves every other component — and every
 * enabled/disabled choice made in Manage Display — exactly as it is. Use this on
 * an environment whose displays have been curated through the UI and must not be
 * rebuilt wholesale.
 *
 * Run it once the vocabulary's public copy lives in its dedicated
 * fields (field_short_description / field_public_description) so `description` is
 * inert — retire_vocab_core_description.php clears the stored values.
 * Idempotent.
 *
 *   BOS_VID=spraying_locations drush php:script web/scripts/drop_vocab_core_description.php
 */

$etm = \Drupal::entityTypeManager();
$vid = getenv('BOS_VID') ?: '';
$field = 'description';

if ($vid === '') {
  print "ERROR: set BOS_VID to the vocabulary machine name. Aborting.\n";
  return;
}
print "VOCAB: $vid\n";

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
