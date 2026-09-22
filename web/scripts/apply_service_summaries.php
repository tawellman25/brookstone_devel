<?php

/**
 * Write card summaries onto services taxonomy terms' field_service_public_desc
 * (the Summary), from web/scripts/service_summaries.json (tid => {name, summary}).
 * Preserves each term's existing description body + text format; only sets the
 * summary. Idempotent (re-running just re-sets the same summaries).
 *
 * Run per env:
 *   drush php:script web/scripts/apply_service_summaries.php
 */

$path = __DIR__ . '/service_summaries.json';
$data = json_decode(file_get_contents($path), TRUE);
if (!$data) {
  print "Could not read $path\n";
  return;
}

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$done = 0;
$missing = [];

foreach ($data as $tid => $row) {
  if ($tid === '_note' || empty($row['summary'])) {
    continue;
  }
  $term = $storage->load($tid);
  if (!$term || $term->bundle() !== 'services' || !$term->hasField('field_service_public_desc')) {
    $missing[] = $tid;
    continue;
  }
  $field = $term->get('field_service_public_desc');
  if ($field->isEmpty()) {
    $term->set('field_service_public_desc', [
      'value' => '',
      'summary' => $row['summary'],
      'format' => 'basic_html',
    ]);
  }
  else {
    $value = $field->getValue();
    $value[0]['summary'] = $row['summary'];
    $term->set('field_service_public_desc', $value);
  }
  $term->save();
  $done++;
}

print "Applied $done summaries.\n";
if ($missing) {
  print 'Skipped (not found / wrong bundle): ' . implode(', ', $missing) . "\n";
}
print "DONE.\n";
