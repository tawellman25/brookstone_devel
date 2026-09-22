<?php

/**
 * Write DRAFT full descriptions onto stub/empty services taxonomy terms'
 * field_service_public_desc (the body value), from web/scripts/service_bodies.json
 * (tid => {name, body}). PRESERVES each term's existing Summary; only replaces the
 * description body. Base copy for the office to develop further. Idempotent.
 *
 * Run per env:
 *   drush php:script web/scripts/apply_service_bodies.php
 */

$path = __DIR__ . '/service_bodies.json';
$data = json_decode(file_get_contents($path), TRUE);
if (!$data) {
  print "Could not read $path\n";
  return;
}

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$done = 0;
$missing = [];

foreach ($data as $tid => $row) {
  if ($tid === '_note' || empty($row['body'])) {
    continue;
  }
  $term = $storage->load($tid);
  if (!$term || $term->bundle() !== 'services' || !$term->hasField('field_service_public_desc')) {
    $missing[] = $tid;
    continue;
  }
  $field = $term->get('field_service_public_desc');
  $summary = $field->isEmpty() ? '' : (string) $field->summary;
  $term->set('field_service_public_desc', [
    'value' => $row['body'],
    'summary' => $summary,
    'format' => 'full_html',
  ]);
  $term->save();
  $done++;
}

print "Applied $done draft descriptions.\n";
if ($missing) {
  print 'Skipped (not found / wrong bundle): ' . implode(', ', $missing) . "\n";
}
print "DONE.\n";
