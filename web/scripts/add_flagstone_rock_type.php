<?php

/**
 * Add a "Flagstone" rock type and assign it to the Lyons Red flagstone material,
 * then rebuild that material's URL alias (/material/rock/flagstone/...).
 *
 * Flagstone is a product/form category (like the existing "River Rock" term) —
 * how the yard sells it — rather than a strict geologic type. Lyons Red is a
 * flagstone slab product; the "Bulk Indian Sunrise 3/4 in." chip is left for a
 * separate decision (Sandstone).
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/add_flagstone_rock_type.php
 */

$out = [];
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

// 1) Find-or-create the Flagstone term in rock_types.
$existing = $termStorage->loadByProperties(['vid' => 'rock_types', 'name' => 'Flagstone']);
if ($existing) {
  $term = reset($existing);
  $out[] = 'Flagstone term exists (tid ' . $term->id() . ')';
}
else {
  $term = $termStorage->create(['vid' => 'rock_types', 'name' => 'Flagstone']);
  $term->save();
  $out[] = 'Flagstone term created (tid ' . $term->id() . ')';
}

// 2) Assign it to the Lyons Red flagstone material (matched by title, env-safe).
$mStorage = \Drupal::entityTypeManager()->getStorage('material');
$ids = \Drupal::entityQuery('material')->accessCheck(FALSE)
  ->condition('type', 'decorative_rock')
  ->condition('title', 'Flagstone Lyons Red%', 'LIKE')
  ->execute();
if (!$ids) {
  $out[] = 'WARN: no "Flagstone Lyons Red%" material found';
}
foreach ($mStorage->loadMultiple($ids) as $m) {
  $m->set('field_rock_type', $term->id());
  $m->save();
  // Clean + regenerate the alias so no stale one is left behind.
  $aliases = \Drupal::entityTypeManager()->getStorage('path_alias')
    ->loadByProperties(['path' => '/material/' . $m->id()]);
  if ($aliases) {
    \Drupal::entityTypeManager()->getStorage('path_alias')->delete($aliases);
  }
  \Drupal::service('pathauto.generator')->updateEntityAlias($m, 'bulk', ['force' => TRUE]);
  $alias = \Drupal::service('path_alias.manager')->getAliasByPath('/material/' . $m->id());
  $out[] = "set {$m->id()} '{$m->label()}' -> Flagstone; alias: $alias";
}

print implode("\n", $out) . "\nDONE.\n";
