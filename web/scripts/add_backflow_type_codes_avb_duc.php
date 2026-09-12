<?php

/**
 * Add AVB + DuC to taxonomy_term.field_type_code allowed values, and stamp the
 * two new backflow_device_types terms: AVB on 1949, DuC on 1950. Both are
 * non-testable device classes (no gauge-test procedure like RP/PVB).
 *
 * Idempotent; run per env (allowed_values is storage-level; the term ids are
 * content). Entity-API only (no cim).
 *
 *   ddev drush php:script web/scripts/add_backflow_type_codes_avb_duc.php   (dev)
 *   drush php:script web/scripts/add_backflow_type_codes_avb_duc.php        (live)
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;

$storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_type_code');
if (!$storage) {
  print "field_type_code storage not found\n";
  return;
}
$settings = $storage->getSettings();
$existing = $settings['allowed_values'] ?? [];
$add = ['AVB' => 'AVB', 'DuC' => 'DuC'];
$merged = $existing + $add;
if ($merged !== $existing) {
  $settings['allowed_values'] = $merged;
  $storage->setSettings($settings);
  $storage->save();
  print "allowed_values += " . implode(', ', array_keys(array_diff_key($add, $existing))) . "\n";
}
else {
  print "allowed_values already include AVB + DuC\n";
}

foreach ([1949 => 'AVB', 1950 => 'DuC'] as $tid => $code) {
  $term = Term::load($tid);
  if (!$term) {
    print "term $tid MISSING — skipped (run on the env where it exists)\n";
    continue;
  }
  if ($term->bundle() !== 'backflow_device_types') {
    print "term $tid is not backflow_device_types ({$term->bundle()}) — skipped\n";
    continue;
  }
  if (($term->get('field_type_code')->value ?? '') === $code) {
    print "term $tid ({$term->label()}) already $code\n";
    continue;
  }
  $term->set('field_type_code', $code)->save();
  print "term $tid ({$term->label()}) field_type_code -> $code\n";
}

print "done.\n";
