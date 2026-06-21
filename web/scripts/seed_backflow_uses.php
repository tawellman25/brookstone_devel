<?php

declare(strict_types=1);

/**
 * Idempotent seeding of backflow_uses taxonomy terms.
 *
 * Taxonomy terms are CONTENT, not config, so they do NOT ride along on
 * drush cim. After the backflow_uses vocab + fields land on a target
 * environment, run this once to seed the 14 use/application terms. Safe to
 * re-run — matches on field_use_code (NOT name), so a re-run never
 * duplicates even if a term's display name is later edited.
 *
 * Each term carries:
 *   - field_use_code            stable machine code (key logic off this)
 *   - field_public_description  customer / water-district facing
 *   - field_teammate_description training / tech facing
 *
 * Usage:
 *   ddev drush scr web/scripts/seed_backflow_uses.php
 *
 *   # On live (after the backflow_uses config is in place):
 *   ssh brookstone "cd /home/brookstoneadmin/brookstone && \
 *     ./vendor/bin/drush scr web/scripts/seed_backflow_uses.php"
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$VOCAB_ID = 'backflow_uses';

if (!Vocabulary::load($VOCAB_ID)) {
  echo "ERROR: vocabulary '$VOCAB_ID' does not exist. Run cim (or the backflow_uses setup) first.\n";
  exit(1);
}

$SEEDS = [
  [
    'code' => 'IRRIGATION',
    'name' => 'Irrigation / Lawn Sprinkler',
    'public' => "Protects your home or building's drinking water from contamination by the irrigation system. Sprinkler lines sit in contact with soil, fertilizer, and standing water; this assembly stops that water from being drawn back into the potable supply.",
    'training' => "Most common application. Back-siphonage is the primary risk on a basic lawn system; PVB or DCVA are typical. Downstream chemical injection or any backpressure raises the requirement toward RP. Confirm there is no fertigation before assuming low hazard. Local cross-connection authority governs the required assembly.",
  ],
  [
    'code' => 'DOMESTIC',
    'name' => 'Domestic Water / Building Supply',
    'public' => "Protects the public water main from anything inside the building's plumbing. Installed on the main supply line so contaminants from within the property cannot flow back into the community's water.",
    'training' => "Whole-building containment at or near the meter. Required assembly depends on the highest hazard inside the building — DCVA for low-hazard occupancies, RP where high-hazard uses exist. Sizing is often large; verify line size and clearance before scheduling. Local authority governs.",
  ],
  [
    'code' => 'FIRE',
    'name' => 'Fire Suppression / Fire Sprinkler',
    'public' => "Keeps water that sits in the building's fire-sprinkler piping from flowing back into the drinking-water supply. Fire lines hold standing water year-round, which this assembly isolates from the potable system.",
    'training' => "Detector assemblies (DCDA / RPDA) are standard so low-flow leakage is metered. No additives and no antifreeze typically means a low-hazard double-check detector; antifreeze or chemical additives push it to a reduced-pressure detector. Often large diameter — confirm size and shutoff coordination with the fire system before testing. Local fire and water authorities govern.",
  ],
  [
    'code' => 'BOILER',
    'name' => 'Boiler / Heating System',
    'public' => "Protects drinking water from the chemically treated, heated water inside a boiler or hydronic heating loop. Treated heating water must never re-enter the potable supply.",
    'training' => "High hazard. Heating loops are commonly chemically treated and run under backpressure, so RP is the typical requirement. Treat as high hazard unless proven otherwise. Verify the makeup-water connection point. Local authority governs.",
  ],
  [
    'code' => 'FERTIGATION',
    'name' => 'Irrigation with Chemical Injection / Fertigation',
    'public' => "Protects drinking water on irrigation systems that inject fertilizer or other chemicals. Because chemicals are added to the water, a higher level of protection is required than on a plain sprinkler system.",
    'training' => "High hazard by definition — chemical injection means RP (or an approved air gap) is required. Never classify a fertigation system as low hazard. Confirm injection equipment is present and note it on the device record. Local authority governs.",
  ],
  [
    'code' => 'KITCHEN',
    'name' => 'Commercial Kitchen / Food Service',
    'public' => "Protects drinking water from equipment in commercial kitchens such as dishwashers, combination ovens, and carbonated-beverage systems. Some of this equipment can push contaminated water back toward the supply.",
    'training' => "High hazard. Carbonators are a classic poisoning hazard (CO2 + copper tubing produces toxic copper) and require a dedicated RP or approved device. Dishwashers and combi-ovens add chemical and thermal hazards. RP is typical. Local health and water authorities govern.",
  ],
  [
    'code' => 'COOLING_TOWER',
    'name' => 'Cooling Tower / HVAC Makeup',
    'public' => "Protects drinking water from the chemically treated, recirculating water used in cooling towers and large HVAC systems.",
    'training' => "High hazard. Recirculating tower water is chemically treated (biocides, scale inhibitors) — RP is the standard requirement. Treat as high hazard. Verify the makeup line. Local authority governs.",
  ],
  [
    'code' => 'INDUSTRIAL',
    'name' => 'Process / Industrial Water',
    'public' => "Protects drinking water from industrial or manufacturing processes that use, treat, or contaminate water.",
    'training' => "Hazard varies widely but trends high — process water can contain chemicals, metals, or biological agents. RP is the safe default; confirm the specific process before classifying. Local authority governs the required assembly.",
  ],
  [
    'code' => 'MEDICAL',
    'name' => 'Medical / Dental / Lab',
    'public' => "Protects drinking water in medical, dental, and laboratory settings, where equipment and procedures can introduce serious contaminants.",
    'training' => "High hazard. Aspirators, amalgam, chemicals, and lab equipment make these health hazards requiring RP or air gap. Always treat as high hazard. Local authority governs.",
  ],
  [
    'code' => 'POOL',
    'name' => 'Pool / Spa Fill',
    'public' => "Protects drinking water from the chemically treated water in pools and spas via the automatic-fill line.",
    'training' => "Hazard depends on setup. Residential auto-fill is often protected by a PVB/AVB on the fill line; commercial pools and any chemical feed trend to RP. Confirm whether the fill line has backpressure or a chemical feed. Local authority governs.",
  ],
  [
    'code' => 'POND',
    'name' => 'Pond / Water Feature',
    'public' => "Protects drinking water from decorative ponds and water features that use an automatic-fill connection to the supply.",
    'training' => "Low-to-moderate hazard depending on treatment. Untreated decorative features are often back-siphonage only (PVB/DCVA); treated or fish-stocked features raise the hazard. Confirm any chemical treatment. Local authority governs.",
  ],
  [
    'code' => 'AGRICULTURAL',
    'name' => 'Livestock / Agricultural Water',
    'public' => "Protects drinking water from agricultural and livestock water systems, including troughs and irrigation for crops and animals.",
    'training' => "Relevant on the Western Slope. Animal-contact troughs are a back-siphonage health hazard; ag chemical use raises it further. Assembly ranges PVB to RP by use. Confirm chemical and trough connections. Local authority governs.",
  ],
  [
    'code' => 'HOSE_BIBB',
    'name' => 'Hose Bibb / Wall Hydrant',
    'public' => "Protects drinking water at outdoor spigots and wall hydrants, where a hose left in a bucket, pool, or chemical sprayer could draw contaminants back into the supply.",
    'training' => "Back-siphonage protection at the spigot. Small atmospheric or spill-resistant vacuum breakers (AVB / SVB); many hose-bibb vacuum breakers are non-testable, but testable SVB units exist and require annual testing. Confirm whether the device is a testable type before scheduling. Local authority governs.",
  ],
  [
    'code' => 'MOBILE',
    'name' => 'Mobile / Temporary',
    'public' => "Protects drinking water at temporary or mobile connections, such as construction sites, hydrant meters, and tanker-fill stations.",
    'training' => "Temporary high-hazard connections (hydrant meters, tanker fill, construction water) typically require an air gap or RP. Treat as high hazard given unknown downstream use. Often tracked per-connection rather than per-fixed-location. Local authority governs.",
  ],
];

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$created = 0;
$skipped = 0;
foreach ($SEEDS as $s) {
  // Idempotency key is the use code, not the name.
  $existing = $storage->loadByProperties([
    'vid' => $VOCAB_ID,
    'field_use_code' => $s['code'],
  ]);
  if ($existing) {
    $skipped++;
    echo "  = $s[code] already present (tid " . reset($existing)->id() . ")\n";
    continue;
  }
  Term::create([
    'vid' => $VOCAB_ID,
    'name' => $s['name'],
    'field_use_code' => $s['code'],
    'field_public_description' => ['value' => $s['public'], 'format' => 'basic_html'],
    'field_teammate_description' => ['value' => $s['training'], 'format' => 'basic_html'],
    'weight' => 0,
  ])->save();
  $created++;
  echo "  + $s[name] [$s[code]]\n";
}
echo "\nSeed complete: $created created, $skipped already present (of " . count($SEEDS) . " total).\n";
