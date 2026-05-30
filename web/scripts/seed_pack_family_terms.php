<?php

declare(strict_types=1);

/**
 * Phase 3.11 — seed pack_family taxonomy with canonical pack rules
 * derived from the SiteOne scrape sweeps (2026-05-26 to 2026-05-30).
 *
 * Each family term carries the Mid label / Mid qty / Case qty rule
 * that all member SKUs in that family share. The parser auto-creates
 * any family it sees in a scrape that isn't seeded here; this script
 * front-loads the well-evidenced families so the office has rules in
 * place for the most common SKU patterns from day one.
 *
 * The family name strings here MUST match the values the scrape
 * writes into the CSV's pack_family column verbatim — otherwise the
 * parser's lookup creates a duplicate term.
 *
 * Idempotent: existing terms with matching name are updated only if
 * the rule has changed; new terms are created with the rule.
 *
 * Usage:
 *   ddev drush scr web/scripts/seed_pack_family_terms.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\taxonomy\Entity\Term;

// Each entry: family name (must match scrape exactly), Mid label
// (NULL if no Mid tier — i.e., only Each + Case), Mid qty, Case qty,
// confidence summary for the term description.
$FAMILIES = [
  // Rain Bird — irrigation / drip ────────────────────────────────────
  ['Rain Bird Spiral Barb Fitting', 'Bag',     50,  250, 'confirmed (5 PDPs: SBE050, SBE075, SBCPLG, SBTEE, SWGF050)'],
  ['Rain Bird VAN',                 'Bag',     25,  250, 'confirmed (12VAN); siblings 4VAN..18VAN consistent'],
  ['Rain Bird R-Series',            'Bag',     25,  500, 'confirmed (R12H); siblings R5H..R15H consistent'],
  ['Rain Bird R-VAN',               'Package', 10,  50,  'confirmed (R-VAN18); R-VAN14/R-VAN24 consistent'],
  ['Rain Bird HE-VAN',              'Package', 25,  250, 'confirmed (HEVAN15); HEVAN8/10/12 consistent'],
  ['Rain Bird U-Series',            NULL,      NULL, 100, 'confirmed (RU15H); no Mid tier in scrape'],
  ['Rain Bird SA Swing Joint',      'Bag',     50,  250, 'inferred_low_confidence (no PDP confirmation; flag for office review)'],
  // Hunter — irrigation ─────────────────────────────────────────────
  ['Hunter MP Rotator',             'Package', 10,  200, 'confirmed (3 PDPs incl MP200090, MP800SR90, MP2000HT90)'],
  ['Hunter PRO Adjustable Arc',     'Package', 25,  250, 'confirmed (10A-NLA); 4A..17AHE siblings consistent'],
  ['Hunter PRO Fixed Nozzle',       'Package', 25,  250, 'inferred (H10H, H10F, H12H, H12Q, etc. all consistent)'],
  ['Hunter Bubbler',                'Package', 25,  250, 'inferred (PCN20, PCB20, MSBN20F all consistent)'],
  ['Hunter Stream Spray',           'Package', 25,  250, 'inferred_low_confidence (S8A-NLA, S16A-NLA)'],
  ['Hunter I-40',                   NULL,      NULL, 12,  'confirmed (I4006SS); siblings I4004SS, I4006SSHS consistent'],
  ['Hunter Golf/Commercial Rotor',  NULL,      NULL, 4,   'confirmed (G880E48P8S); golf rotor case-of-4 pattern'],
  ['Hunter TTS-800',                NULL,      NULL, 4,   'confirmed (GT800EP8)'],
  ['Hunter MPR Nozzle',             'Bag',     25,  250, 'inferred_low_confidence (MPR-25, MPR-30, MPR-35)'],
  ['Hunter ST Commercial',          NULL,      NULL, 1,   'listing_only on ST-1200BR, ST-1600-HS-B; case=1 (single units)'],
  // Toro — irrigation ───────────────────────────────────────────────
  ['Toro 570 MPR Plus',             'Package', 25,  1000, 'confirmed (T15QPC); MPR Plus nozzle pattern'],
  ['Toro 570Z',                     NULL,      NULL, 50,  'confirmed (570Z-4LP-PR); spray body case-of-50'],
  ['Toro Nozzle',                   'Package', 25,  1000, 'inferred_low_confidence (O-10-H, T15HPC family of Toro nozzles)'],
  // K-Rain ───────────────────────────────────────────────────────────
  ['K-Rain Super Pro',              NULL,      NULL, 12,  'confirmed (10003-HP-CV); K-Rain rotor pattern'],
  ['K-Rain',                        NULL,      NULL, 12,  'inferred_low_confidence (RN200-ADJ, RPS75, etc.)'],
  // Others ───────────────────────────────────────────────────────────
  ['Underhill Impact',              NULL,      NULL, 20,  'confirmed (SI100P)'],
  ['I-Pro',                         NULL,      NULL, 25,  'confirmed (I-PRO1200-SI-PR-CV); Irritrol I-Pro series'],
  // Cross-brand generic patterns (the scrape uses these as fallback
  // family names for items without a more specific family) ─────────
  ['Rotor-Case-20',                 NULL,      NULL, 20,  'cross-brand industry-standard rotor case-of-20 (PGP, I-20, 5004, T5, etc.)'],
  ['Spray-Body-Case-20',            NULL,      NULL, 20,  'cross-brand spray-body case-of-20'],
];

$created = 0;
$updated = 0;
$alreadySet = 0;
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');

foreach ($FAMILIES as [$name, $midLabel, $midQty, $caseQty, $descCore]) {
  $existing = $termStorage->loadByProperties(['vid' => 'pack_family', 'name' => $name]);
  if ($existing) {
    $term = reset($existing);
    $needsSave = FALSE;

    $currentLabel = $term->get('field_pack_qty_mid_label')->value;
    $currentMid   = $term->get('field_pack_qty_mid')->value;
    $currentCase  = $term->get('field_pack_qty_case')->value;

    if (($currentLabel ?? NULL) !== $midLabel) {
      $term->set('field_pack_qty_mid_label', $midLabel);
      $needsSave = TRUE;
    }
    if (((int) ($currentMid ?? 0)) !== ($midQty ?? 0)) {
      $term->set('field_pack_qty_mid', $midQty);
      $needsSave = TRUE;
    }
    if (((int) ($currentCase ?? 0)) !== $caseQty) {
      $term->set('field_pack_qty_case', $caseQty);
      $needsSave = TRUE;
    }
    if ($needsSave) {
      $term->set('description', "Pack rule: {$descCore}. Each / " . ($midLabel ? "{$midLabel}({$midQty})" : 'no Mid') . " / Case({$caseQty}).");
      $term->save();
      $updated++;
      echo "UPDATED $name\n";
    } else {
      $alreadySet++;
    }
  } else {
    $term = $termStorage->create([
      'vid' => 'pack_family',
      'name' => $name,
      'description' => "Pack rule: {$descCore}. Each / " . ($midLabel ? "{$midLabel}({$midQty})" : 'no Mid') . " / Case({$caseQty}).",
      'field_pack_qty_mid_label' => $midLabel,
      'field_pack_qty_mid' => $midQty,
      'field_pack_qty_case' => $caseQty,
    ]);
    $term->save();
    $created++;
    echo "CREATED $name (tid={$term->id()})\n";
  }
}

echo "\nResult: created=$created, updated=$updated, already_set=$alreadySet\n";
echo "Total families seeded: " . count($FAMILIES) . "\n";
