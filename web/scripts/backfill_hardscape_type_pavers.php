<?php

/**
 * Backfill field_hardscape_type = "Paver" on existing material.pavers rows.
 *
 * SEPARATE from the structural setup on purpose ("surface, don't automate
 * silently"). Run deliberately after the field + terms exist. It PRINTS every
 * row (id, name, size, current type) so a human can eyeball that each is truly
 * a paver, then sets "Paver" ONLY where field_hardscape_type is empty.
 * Idempotent — a second run fills nothing.
 *
 *   drush php:script web/scripts/backfill_hardscape_type_pavers.php
 */

$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => 'hardscape_types', 'name' => 'Paver']);
if (!$term) {
  print "ERROR: 'Paver' term missing — run seed_hardscape_types.php first.\n";
  return;
}
$paverTid = (int) reset($term)->id();

$store = \Drupal::entityTypeManager()->getStorage('material');
$ids = \Drupal::entityQuery('material')->accessCheck(FALSE)->condition('type', 'pavers')->execute();

$filled = 0;
$already = 0;
print "material.pavers rows (review before/after):\n";
foreach ($store->loadMultiple($ids) as $m) {
  $size = ($m->hasField('field_size') && !$m->get('field_size')->isEmpty()) ? $m->get('field_size')->value : '-';
  $cur = ($m->hasField('field_hardscape_type') && !$m->get('field_hardscape_type')->isEmpty() && $m->get('field_hardscape_type')->entity)
    ? $m->get('field_hardscape_type')->entity->label() : '(empty)';
  printf("  #%-6s size=%-16s type=%-10s  %s\n", $m->id(), $size, $cur, $m->label());
  if ($m->hasField('field_hardscape_type') && $m->get('field_hardscape_type')->isEmpty()) {
    $m->set('field_hardscape_type', $paverTid)->save();
    $filled++;
  }
  else {
    $already++;
  }
}
printf("DONE — %d rows: %d set to Paver, %d already set.\n", count($ids), $filled, $already);
