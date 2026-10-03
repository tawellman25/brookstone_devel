<?php

/**
 * Clear the one stray core `description` left on material_types.
 *
 * "Blocks and Pavers" still held a legacy one-liner ("These are the Blocks and
 * Pavers that are used in the landscaping.") while its real copy sits in
 * field_public_description. Every other material_types term has already moved,
 * so this is the last of it and the vocabulary is then clean.
 *
 * Guarded: refuses unless field_public_description is populated, so the copy is
 * never the only record of itself. Records what it clears.
 *
 * Usage:
 *   drush php:script web/scripts/clear_material_types_stray_description.php
 *   BOS_STRAY_APPLY=1 drush php:script ...
 */

$apply = getenv('BOS_STRAY_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_STRAY_APPLY=1 to write)');

$cleared = $refused = 0;
$backup = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'material_types']) as $t) {
  $core = $t->get('description');
  $val = $core->isEmpty() ? '' : trim((string) ($core->first()->getValue()['value'] ?? ''));
  if (strip_tags($val) === '') {
    continue;
  }
  $pub = $t->get('field_public_description');
  $hasPub = !$pub->isEmpty() && trim(strip_tags((string) $pub->first()->getValue()['value'])) !== '';
  if (!$hasPub) {
    printf("  REFUSED %-26s core description is the only copy this term has\n", (string) $t->label());
    $refused++;
    continue;
  }
  printf("  clear   %-26s %s\n", (string) $t->label(), mb_substr(preg_replace('/\s+/', ' ', strip_tags($val)), 0, 70));
  $cleared++;
  $backup[] = ['tid' => $t->id(), 'name' => (string) $t->label(), 'core_description' => $val];
  if ($apply) {
    $t->set('description', ['value' => '', 'format' => NULL])->save();
  }
}
if ($backup) {
  $f = sys_get_temp_dir() . '/material_types_stray_desc_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  printf("\nrecorded -> %s\n", $f);
}
printf("\n%d cleared, %d refused\n", $cleared, $refused);
if (!$apply) { print "Nothing written. Re-run with BOS_STRAY_APPLY=1 to apply.\n"; }
