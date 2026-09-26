<?php

declare(strict_types=1);

/**
 * Idempotent seeding of backflow_device_types taxonomy terms.
 *
 * Taxonomy terms are CONTENT, not config, so they do NOT ride along on
 * drush cim. After the backflow device system config lands on a target
 * environment (local or live), run this once to seed the 7 device-type
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

// Verbatim marketing copy for the HBVB term (full_html). The CTA is a plain link
// (no button class) to match the sibling device terms, which use no CTA class.
$HBVB_DESC = <<<'HTML'
<p>This is the smallest and most common backflow device on any property, and most people have one without knowing what it is — the short brass fitting threaded onto an outdoor faucet, between the tap and the hose.</p>

<p>It works on air. While water is flowing, the pressure holds an internal seal shut. When the pressure drops and the flow reverses, the seal opens and lets air into the line, which breaks the suction before anything in the hose can be pulled backward. A hose lying in a flower bed, a garden sprayer with herbicide in it, or an end sitting in a stock tank all stop being a path into the house supply.</p>

<h3>What it does not do</h3>

<p>It protects against suction only. Anything on the other end that can push water back on its own — a pump, most obviously — defeats it entirely.</p>

<p>It is also not a testable assembly. There are no test cocks, so there is no way to verify one in place and no annual certification to file on it. Where a provider requires certified testing on a connection, a hose bibb breaker does not satisfy that, and the connection steps up to a <a href="/services/backflow-prevention/pressure-vacuum-breaker-pvb">pressure vacuum breaker</a> or, where the hazard is high or backpressure is possible, a <a href="/services/backflow-prevention/reduced-pressure-rp">reduced pressure assembly</a>.</p>

<p>Most are not rated for continuous pressure either. A hose left charged against a closed nozzle wears the seal out, which is why the common failure is a breaker that drips from the vent under pressure. That drip is the device telling you it is finished.</p>

<h3>Where it belongs</h3>

<p>Any outdoor tap, any wall hydrant, any utility sink with a threaded spout, any hose connection in a mechanical room. Plumbing codes require them on hose connections in new construction, and on a great many Western Slope properties built before that, they are simply not there.</p>

<p>On an exterior tap we install them, replace failed ones, and will tell you when the connection has outgrown one. Inside the building we test and repair but do not replace — <a href="/services/backflow-prevention">that line is explained here</a>.</p>

<p>More on the connection itself: <a href="/services/backflow-prevention/uses/hose-bibb-wall-hydrant">hose bibbs and wall hydrants</a>.</p>

<p><a href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

// All SEVEN device types are the mechanism of record here — AVB and Dual Check
// were added to production outside this script after the 2026-06-20 seed, and
// HBVB (2026-09-26) is added here from the start. 'testable' drives
// field_is_testable: PVB/RP/DCVA/SVB are testable assemblies; AVB, dual check
// and HBVB are NOT (no test cocks) and must never get a next-due date or a
// compliance reminder. 'description' (optional) sets field_public_description on
// CREATE / when empty — it never clobbers an existing (marketing-edited) body.
$SEEDS = [
  ['name' => 'Double Check Valve Assembly (DCVA)',   'code' => 'DCVA', 'testable' => TRUE],
  ['name' => 'Pressure Vacuum Breaker (PVB)',        'code' => 'PVB',  'testable' => TRUE],
  ['name' => 'Reduced Pressure (RP)',                'code' => 'RP',   'testable' => TRUE],
  ['name' => 'Spill-Resistant Vacuum Breaker (SVB)', 'code' => 'SVB',  'testable' => TRUE],
  ['name' => 'Atmospheric Vacuum Breaker (AVB)',     'code' => 'AVB',  'testable' => FALSE],
  ['name' => 'Dual Check Valve (DuC)',               'code' => 'DuC',  'testable' => FALSE],
  ['name' => 'Hose Bibb Vacuum Breaker (HBVB)',      'code' => 'HBVB', 'testable' => FALSE, 'description' => $HBVB_DESC],
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
    $changed = FALSE;
    // Set/correct the testable flag on the existing term (idempotent).
    $cur = ($term->hasField('field_is_testable') && !$term->get('field_is_testable')->isEmpty())
      ? (int) $term->get('field_is_testable')->value : NULL;
    if ($cur !== $want) {
      $term->set('field_is_testable', $want);
      $changed = TRUE;
    }
    // Set the description only when one is provided AND the term has none yet —
    // never clobber a marketing-edited body.
    if (!empty($s['description']) && $term->get('field_public_description')->isEmpty()) {
      $term->set('field_public_description', ['value' => $s['description'], 'format' => 'full_html']);
      $changed = TRUE;
    }
    if ($changed) {
      $term->save();
      $updated++;
      echo "  ~ $s[code] updated (tid " . $term->id() . ")\n";
    }
    else {
      $skipped++;
      echo "  = $s[code] already present + correct (tid " . $term->id() . ")\n";
    }
    continue;
  }
  $values = [
    'vid' => $VOCAB_ID,
    'name' => $s['name'],
    'field_type_code' => $s['code'],
    'field_is_testable' => $want,
    'weight' => 0,
  ];
  if (!empty($s['description'])) {
    $values['field_public_description'] = ['value' => $s['description'], 'format' => 'full_html'];
  }
  Term::create($values)->save();
  $created++;
  echo "  + $s[name] [$s[code]] testable=" . ($want ? 'TRUE' : 'FALSE') . (!empty($s['description']) ? ' +desc' : '') . "\n";
}
echo "\nSeed complete: $created created, $updated updated, $skipped unchanged (of " . count($SEEDS) . " total).\n";
