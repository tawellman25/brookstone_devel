<?php

declare(strict_types=1);

/**
 * Idempotent seeding of backflow_device_types taxonomy terms.
 *
 * Taxonomy terms are CONTENT, not config, so they do NOT ride along on
 * drush cim. After the backflow device system config lands on a target
 * environment (local or live), run this once to seed the 6 device-type
 * terms and set their field_is_testable flag. Safe to re-run — matches on
 * field_type_code (NOT name), so a re-run never duplicates even if a term's
 * display name is later edited, and it corrects the testable flag in place.
 * MUST be run on live post-deploy (field_is_testable is content, not config).
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

// All SIX device types are the mechanism of record here — AVB and Dual Check
// were added to production outside this script after the 2026-06-20 seed; folding
// them in makes the seed authoritative again. 'testable' drives field_is_testable:
// PVB/RP/DCVA/SVB are testable assemblies; AVB and dual check are NOT (no test
// cocks) and must never get a next-due date or a compliance reminder.
$SEEDS = [
  ['name' => 'Double Check Valve Assembly (DCVA)',   'code' => 'DCVA', 'testable' => TRUE],
  ['name' => 'Pressure Vacuum Breaker (PVB)',        'code' => 'PVB',  'testable' => TRUE],
  ['name' => 'Reduced Pressure (RP)',                'code' => 'RP',   'testable' => TRUE],
  ['name' => 'Spill-Resistant Vacuum Breaker (SVB)', 'code' => 'SVB',  'testable' => TRUE],
  ['name' => 'Atmospheric Vacuum Breaker (AVB)',     'code' => 'AVB',  'testable' => FALSE],
  ['name' => 'Dual Check Valve (DuC)',               'code' => 'DuC',  'testable' => FALSE],
];

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$created = 0;
$updated = 0;
$skipped = 0;
foreach ($SEEDS as $s) {
  $want = $s['testable'] ? 1 : 0;
  // Idempotency key is the type code, not the name.
  $existing = $storage->loadByProperties([
    'vid' => $VOCAB_ID,
    'field_type_code' => $s['code'],
  ]);
  if ($existing) {
    $term = reset($existing);
    // Set/correct the testable flag on the existing term (idempotent).
    $cur = ($term->hasField('field_is_testable') && !$term->get('field_is_testable')->isEmpty())
      ? (int) $term->get('field_is_testable')->value : NULL;
    if ($cur !== $want) {
      $term->set('field_is_testable', $want);
      $term->save();
      $updated++;
      echo "  ~ $s[code] testable => " . ($want ? 'TRUE' : 'FALSE') . " (tid " . $term->id() . ")\n";
    }
    else {
      $skipped++;
      echo "  = $s[code] already present + correct (tid " . $term->id() . ")\n";
    }
    continue;
  }
  Term::create([
    'vid' => $VOCAB_ID,
    'name' => $s['name'],
    'field_type_code' => $s['code'],
    'field_is_testable' => $want,
    'weight' => 0,
  ])->save();
  $created++;
  echo "  + $s[name] [$s[code]] testable=" . ($want ? 'TRUE' : 'FALSE') . "\n";
}
echo "\nSeed complete: $created created, $updated updated, $skipped unchanged (of " . count($SEEDS) . " total).\n";
