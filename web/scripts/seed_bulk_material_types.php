<?php

declare(strict_types=1);

/**
 * Idempotent seeding of bulk_material_types taxonomy terms.
 *
 * Taxonomy terms are CONTENT, not config, so they don't ride along on
 * drush cim. After the bulk_material bundle's config lands on a target
 * environment (local or live), run this once to seed the 15 starter
 * terms. Safe to re-run — skips any term already present (matched by
 * exact name within the vocabulary).
 *
 * Order spec: alphabetical at weight 0, with Soil Amendment (Other)
 * and Other pinned to the bottom by higher weights. Drupal sorts
 * taxonomy terms by weight ASC, name ASC.
 *
 * Usage:
 *   ddev drush scr web/scripts/seed_bulk_material_types.php
 *
 *   # On live (after the bulk_material bundle config is in place):
 *   ssh brookstone "cd /home/brookstoneadmin/brookstone && \
 *     ./vendor/bin/drush scr web/scripts/seed_bulk_material_types.php"
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$VOCAB_ID = 'bulk_material_types';

if (!Vocabulary::load($VOCAB_ID)) {
  echo "ERROR: vocabulary '$VOCAB_ID' does not exist. Run cim (or the bundle setup) first.\n";
  exit(1);
}

$SEEDS = [
  ['name' => 'Compost',                'weight' => 0],
  ['name' => 'Decomposed Granite',     'weight' => 0],
  ['name' => 'Fill Dirt',              'weight' => 0],
  ['name' => 'Garden Mix',             'weight' => 0],
  ['name' => 'Gravel',                 'weight' => 0],
  ['name' => 'Gypsum',                 'weight' => 0],
  ['name' => 'Iron Sulfate',           'weight' => 0],
  ['name' => 'Lime',                   'weight' => 0],
  ['name' => 'Manure (Composted)',     'weight' => 0],
  ['name' => 'Sand',                   'weight' => 0],
  ['name' => 'Screened Topsoil',       'weight' => 0],
  ['name' => 'Sulfur',                 'weight' => 0],
  ['name' => 'Topsoil',                'weight' => 0],
  ['name' => 'Soil Amendment (Other)', 'weight' => 100],
  ['name' => 'Other',                  'weight' => 101],
];

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$created = 0;
$skipped = 0;
foreach ($SEEDS as $s) {
  $existing = $storage->loadByProperties(['vid' => $VOCAB_ID, 'name' => $s['name']]);
  if ($existing) {
    $skipped++;
    continue;
  }
  Term::create(['vid' => $VOCAB_ID, 'name' => $s['name'], 'weight' => $s['weight']])->save();
  $created++;
  echo "  + $s[name]\n";
}
echo "\nSeed complete: $created created, $skipped already present (of " . count($SEEDS) . " total).\n";
