<?php

/**
 * Rock material (material/decorative_rock) URL aliases:
 *   pattern  -> material/rock/{rock_type}/{title}
 *   generate -> (re)build aliases for every existing Rock material.
 *
 * The material_rock_path pattern existed but was material/rock/[material:title]
 * (no rock-type segment) and existing Rock materials never got aliases (they
 * predate the pattern), so they were still on canonical /material/{id}.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/fix_rock_material_pathauto.php
 */

$NEW_PATTERN = 'material/rock/[material:field_rock_type:entity:name]/[material:title]';
$out = [];

// 1) Update the pattern.
$pattern = \Drupal::entityTypeManager()->getStorage('pathauto_pattern')->load('material_rock_path');
if (!$pattern) {
  print "ERROR: pathauto pattern material_rock_path not found\n";
  return;
}
if ($pattern->getPattern() !== $NEW_PATTERN) {
  $pattern->setPattern($NEW_PATTERN);
  $pattern->save();
  $out[] = "pattern -> $NEW_PATTERN";
}
else {
  $out[] = 'pattern already correct';
}

// 2) (Re)generate aliases for every Rock material.
$generator = \Drupal::service('pathauto.generator');
$storage = \Drupal::entityTypeManager()->getStorage('material');
$ids = \Drupal::entityQuery('material')->accessCheck(FALSE)
  ->condition('type', 'decorative_rock')->execute();

$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$done = 0;
$no_rock_type = [];
$samples = [];
foreach ($storage->loadMultiple($ids) as $m) {
  // Remove ALL existing aliases for this material first, so a re-run can never
  // leave a stale old-pattern alias behind (pathauto's update setting on this
  // site keeps the old one rather than replacing it → duplicates).
  $existing = $aliasStorage->loadByProperties(['path' => '/material/' . $m->id()]);
  if ($existing) {
    $aliasStorage->delete($existing);
  }
  $generator->updateEntityAlias($m, 'bulk', ['force' => TRUE]);
  $done++;
  if ($m->get('field_rock_type')->isEmpty()) {
    $no_rock_type[] = $m->id() . ' (' . $m->label() . ')';
  }
  if (count($samples) < 5) {
    $samples[] = \Drupal::service('path_alias.manager')->getAliasByPath('/material/' . $m->id());
  }
}

$out[] = "aliases generated: $done / " . count($ids);
$out[] = 'sample aliases:';
foreach ($samples as $s) {
  $out[] = "  $s";
}
if ($no_rock_type) {
  $out[] = 'WARNING: ' . count($no_rock_type) . ' Rock material(s) have NO rock type (their alias omits that segment):';
  foreach ($no_rock_type as $n) {
    $out[] = "  - $n";
  }
}

print implode("\n", $out) . "\nDONE.\n";
