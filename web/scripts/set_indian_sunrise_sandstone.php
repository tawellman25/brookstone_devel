<?php

/**
 * Set the "Bulk Indian Sunrise 3/4 in." rock material to the Sandstone type
 * (a 3/4" decorative chip — sandstone geology, not a flagstone slab), then
 * rebuild its URL alias (/material/rock/sandstone/...).
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/set_indian_sunrise_sandstone.php
 */

$out = [];
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

$terms = $termStorage->loadByProperties(['vid' => 'rock_types', 'name' => 'Sandstone']);
if (!$terms) {
  print "ERROR: no Sandstone term in rock_types\n";
  return;
}
$term = reset($terms);
$out[] = 'Sandstone term tid ' . $term->id();

$mStorage = \Drupal::entityTypeManager()->getStorage('material');
$ids = \Drupal::entityQuery('material')->accessCheck(FALSE)
  ->condition('type', 'decorative_rock')
  ->condition('title', 'Bulk Indian Sunrise%', 'LIKE')
  ->execute();
if (!$ids) {
  $out[] = 'WARN: no "Bulk Indian Sunrise%" material found';
}
foreach ($mStorage->loadMultiple($ids) as $m) {
  $m->set('field_rock_type', $term->id());
  $m->save();
  $aliases = \Drupal::entityTypeManager()->getStorage('path_alias')
    ->loadByProperties(['path' => '/material/' . $m->id()]);
  if ($aliases) {
    \Drupal::entityTypeManager()->getStorage('path_alias')->delete($aliases);
  }
  \Drupal::service('pathauto.generator')->updateEntityAlias($m, 'bulk', ['force' => TRUE]);
  $alias = \Drupal::service('path_alias.manager')->getAliasByPath('/material/' . $m->id());
  $out[] = "set {$m->id()} '{$m->label()}' -> Sandstone; alias: $alias";
}

print implode("\n", $out) . "\nDONE.\n";
