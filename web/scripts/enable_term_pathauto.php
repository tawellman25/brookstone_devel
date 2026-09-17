<?php

/**
 * Bulk-enable "Generate automatic URL alias" for every term in a vocabulary and
 * generate the aliases — the thing the Pathauto Bulk-Generate UI can't do when
 * the per-term checkbox is unchecked (state = SKIP), without editing each term.
 *
 * Sets each term's path.pathauto state to CREATE (checks the box, persisted) and
 * regenerates its alias from the vocabulary's pathauto pattern. Existing term
 * aliases are cleared first so no stale duplicate is left behind.
 *
 * Idempotent; entity-API (no cim). Vocabulary via env (default plant_characteristics):
 *   VOCAB=plant_characteristics drush php:script web/scripts/enable_term_pathauto.php
 */

use Drupal\pathauto\PathautoState;

$vid = getenv('VOCAB') ?: 'plant_characteristics';
$out = [];

$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$aliasManager = \Drupal::service('path_alias.manager');

$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vid)->execute();
if (!$tids) {
  print "No terms in vocabulary '$vid'.\n";
  return;
}

$done = 0;
$samples = [];
foreach ($termStorage->loadMultiple($tids) as $term) {
  // Clear any existing alias so we don't leave a stale duplicate.
  $existing = $aliasStorage->loadByProperties(['path' => '/taxonomy/term/' . $term->id()]);
  if ($existing) {
    $aliasStorage->delete($existing);
  }
  // Check the box (persisted) + save -> pathauto generates the alias.
  if ($term->hasField('path')) {
    $term->get('path')->pathauto = PathautoState::CREATE;
  }
  $term->save();
  $done++;
  if (count($samples) < 6) {
    $samples[] = $aliasManager->getAliasByPath('/taxonomy/term/' . $term->id());
  }
}

$out[] = "vocabulary: $vid";
$out[] = "terms enabled + aliased: $done / " . count($tids);
$out[] = 'sample aliases:';
foreach ($samples as $s) {
  $out[] = "  $s";
}
// Report any that still lack an alias (e.g. a token that resolved empty).
$missing = 0;
foreach ($tids as $tid) {
  if ($aliasManager->getAliasByPath('/taxonomy/term/' . $tid) === '/taxonomy/term/' . $tid) {
    $missing++;
  }
}
$out[] = "still without an alias: $missing";

print implode("\n", $out) . "\nDONE.\n";
