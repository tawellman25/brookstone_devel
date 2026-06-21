<?php

declare(strict_types=1);

/**
 * Idempotent seeding of backflow_device_types taxonomy terms.
 *
 * Taxonomy terms are CONTENT, not config, so they do NOT ride along on
 * drush cim. After the backflow device system config lands on a target
 * environment (local or live), run this once to seed the 4 device-type
 * terms. Safe to re-run — matches on field_type_code (NOT name), so a
 * re-run never duplicates even if a term's display name is later edited.
 *
 * field_public_description is intentionally left empty (training content
 * authored separately). Weight 0; Drupal sorts taxonomy terms weight ASC,
 * name ASC.
 *
 * Usage:
 *   ddev drush scr web/scripts/seed_backflow_device_types.php
 *
 *   # On live (after the backflow config is in place):
 *   ssh brookstone "cd /home/brookstoneadmin/brookstone && \
 *     ./vendor/bin/drush scr web/scripts/seed_backflow_device_types.php"
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$VOCAB_ID = 'backflow_device_types';

if (!Vocabulary::load($VOCAB_ID)) {
  echo "ERROR: vocabulary '$VOCAB_ID' does not exist. Run cim (or the backflow setup) first.\n";
  exit(1);
}

$SEEDS = [
  ['name' => 'Double Check Valve Assembly (DCVA)',   'code' => 'DCVA'],
  ['name' => 'Pressure Vacuum Breaker (PVB)',        'code' => 'PVB'],
  ['name' => 'Reduced Pressure (RP)',                'code' => 'RP'],
  ['name' => 'Spill-Resistant Vacuum Breaker (SVB)', 'code' => 'SVB'],
];

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$created = 0;
$skipped = 0;
foreach ($SEEDS as $s) {
  // Idempotency key is the type code, not the name.
  $existing = $storage->loadByProperties([
    'vid' => $VOCAB_ID,
    'field_type_code' => $s['code'],
  ]);
  if ($existing) {
    $skipped++;
    echo "  = $s[code] already present (tid " . reset($existing)->id() . ")\n";
    continue;
  }
  Term::create([
    'vid' => $VOCAB_ID,
    'name' => $s['name'],
    'field_type_code' => $s['code'],
    'weight' => 0,
  ])->save();
  $created++;
  echo "  + $s[name] [$s[code]]\n";
}
echo "\nSeed complete: $created created, $skipped already present (of " . count($SEEDS) . " total).\n";
