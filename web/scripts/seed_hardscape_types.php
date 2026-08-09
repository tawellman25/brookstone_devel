<?php

/**
 * Idempotent seeding of hardscape_types taxonomy terms (CONTENT — not config,
 * so they don't ride drush cim). Run after setup_hardscape_fields.php creates
 * the vocabulary. Safe to re-run — skips any term already present (by exact
 * name within the vocabulary). Drupal sorts terms by weight ASC, name ASC.
 *
 *   drush php:script web/scripts/seed_hardscape_types.php
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$VOCAB = 'hardscape_types';
if (!Vocabulary::load($VOCAB)) {
  print "ERROR: vocabulary '$VOCAB' missing — run setup_hardscape_fields.php first.\n";
  return;
}

$SEEDS = [
  ['Paver', 0],
  ['Wall Block', 1],
  ['Cap', 2],
  ['Coping', 3],
  ['Edger', 4],
  ['Step', 5],
  ['Slab', 6],
  ['Other', 100],
];

$store = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$created = 0;
foreach ($SEEDS as [$name, $weight]) {
  if ($store->loadByProperties(['vid' => $VOCAB, 'name' => $name])) {
    print "  exists: $name\n";
    continue;
  }
  Term::create(['vid' => $VOCAB, 'name' => $name, 'weight' => $weight])->save();
  print "  created: $name (weight $weight)\n";
  $created++;
}
print "DONE — hardscape_types seeded ($created new).\n";
