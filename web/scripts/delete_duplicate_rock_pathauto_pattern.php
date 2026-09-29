<?php

declare(strict_types=1);

/**
 * Remove the duplicate decorative_rock pathauto pattern.
 *
 * Two patterns governed material:decorative_rock with the SAME pattern string
 * and the SAME bundle condition:
 *
 *   material_rock_path        weight -2   in config/sync, maintained by
 *                                         fix_rock_material_pathauto.php,
 *                                         documented in pathauto_patterns.md
 *   materials_rock_aliases    weight -5   undocumented, live-only drift
 *
 * Pathauto returns the lowest-weight matching pattern, so the undocumented one
 * was the one actually generating rock URLs while the canonical one sat unused.
 * Deleting it hands generation to material_rock_path, which produces a
 * byte-identical alias — verified before and after on each environment.
 *
 * Deleting a pattern does NOT touch existing aliases; it only governs future
 * generation. Nothing in the URL of any rock material changes.
 *
 * REFUSES to delete unless the surviving pattern exists, is enabled, and matches
 * the doomed one on type, pattern string and bundle conditions — so this can
 * never silently drop the only pattern for a bundle.
 *
 * Idempotent (a second run reports nothing to do). Run per env:
 *   drush php:script web/scripts/delete_duplicate_rock_pathauto_pattern.php
 */

const BOS_ROCK_KEEP = 'material_rock_path';
const BOS_ROCK_DROP = 'materials_rock_aliases';

$storage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
$keep = $storage->load(BOS_ROCK_KEEP);
$drop = $storage->load(BOS_ROCK_DROP);

if (!$drop) {
  print 'nothing to do — ' . BOS_ROCK_DROP . " is not present.\n";
  return;
}

if (!$keep) {
  print 'REFUSING: ' . BOS_ROCK_KEEP . ' is missing, so ' . BOS_ROCK_DROP
    . " is the only pattern for this bundle. Nothing deleted.\n";
  return;
}

if (!$keep->status()) {
  print 'REFUSING: ' . BOS_ROCK_KEEP . " exists but is disabled. Nothing deleted.\n";
  return;
}

/**
 * Compare the two on everything that decides what URL comes out: the entity
 * type they apply to, the pattern string, and the bundles they select.
 */
$bundles = static function ($pattern): array {
  $out = [];
  foreach ($pattern->getSelectionConditions() as $condition) {
    $config = $condition->getConfiguration();
    foreach (array_keys($config['bundles'] ?? []) as $bundle) {
      $out[] = $bundle;
    }
  }
  sort($out);
  return $out;
};

$mismatch = [];
if ($keep->getType() !== $drop->getType()) {
  $mismatch[] = 'type (' . $keep->getType() . ' vs ' . $drop->getType() . ')';
}
if ($keep->getPattern() !== $drop->getPattern()) {
  $mismatch[] = 'pattern (' . $keep->getPattern() . ' vs ' . $drop->getPattern() . ')';
}
if ($bundles($keep) !== $bundles($drop)) {
  $mismatch[] = 'bundles (' . implode('+', $bundles($keep)) . ' vs ' . implode('+', $bundles($drop)) . ')';
}

if ($mismatch) {
  print "REFUSING: the two patterns are not equivalent — they differ on:\n";
  foreach ($mismatch as $m) {
    print '  - ' . $m . "\n";
  }
  print "Nothing deleted. Reconcile them by hand first.\n";
  return;
}

print 'deleting ' . BOS_ROCK_DROP . ' (weight ' . $drop->getWeight() . ")\n";
print '  keeping ' . BOS_ROCK_KEEP . ' (weight ' . $keep->getWeight() . '): ' . $keep->getPattern() . "\n";
print '  bundles: ' . implode(', ', $bundles($keep)) . "\n";
$drop->delete();
print "done — existing aliases untouched.\n";
