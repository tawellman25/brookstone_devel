<?php

use Drupal\taxonomy\Entity\Term;

$on_demand_services = [
  377  => 'Weekly Lawn Mowing',
  1277 => 'Weed Control',
  414  => 'Landscape Beds (weed)',
  373  => 'Snow Removal',
  368  => 'Sprinkler Repair',
];

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

$updated = 0;
$already_correct = 0;
$missing = [];
$wrong_vocab = [];
$missing_field = [];

foreach ($on_demand_services as $tid => $expected_label) {
  $term = $term_storage->load($tid);

  if (!$term) {
    $missing[] = sprintf('%d (%s)', $tid, $expected_label);
    continue;
  }

  if ($term->bundle() !== 'services') {
    $wrong_vocab[] = sprintf('%d (%s) is in vocab "%s", expected "services"', $tid, $term->label(), $term->bundle());
    continue;
  }

  if (!$term->hasField('field_on_demand')) {
    $missing_field[] = sprintf('%d (%s) has no field_on_demand', $tid, $term->label());
    continue;
  }

  $current = !$term->get('field_on_demand')->isEmpty()
    ? (int) $term->get('field_on_demand')->value
    : 0;

  if ($current === 1) {
    $already_correct++;
    echo sprintf("  - %d %s — already TRUE, skipped.\n", $tid, $term->label());
    continue;
  }

  $term->set('field_on_demand', 1);
  $term->save();
  $updated++;
  echo sprintf("  + %d %s — set to TRUE.\n", $tid, $term->label());
}

echo "\n----------------------------------------\n";
echo "field_on_demand backfill complete.\n";
echo "----------------------------------------\n";
echo sprintf("Updated:         %d\n", $updated);
echo sprintf("Already correct: %d\n", $already_correct);

if (!empty($missing)) {
  echo "\nWARNING — Term IDs not found:\n";
  foreach ($missing as $entry) { echo "  - $entry\n"; }
}
if (!empty($wrong_vocab)) {
  echo "\nWARNING — Terms in unexpected vocabulary:\n";
  foreach ($wrong_vocab as $entry) { echo "  - $entry\n"; }
}
if (!empty($missing_field)) {
  echo "\nERROR — Terms missing field_on_demand:\n";
  foreach ($missing_field as $entry) { echo "  - $entry\n"; }
}
echo "\n";
