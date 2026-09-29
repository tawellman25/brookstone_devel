<?php

declare(strict_types=1);

/**
 * Stage 3 — seed the category copy.
 *
 * TWO DIFFERENT PROVENANCES, and the difference matters:
 *
 *  - field_short_description is VERBATIM from
 *    "Plant Characteristics - Landing Page Copy.md" §2. Not edited here.
 *
 *  - field_public_description is a DRAFT written by Code, because no copy
 *    exists for it — the copy file lists the eight category bodies as "the next
 *    batch after this one". They are here so the pages are not blank on launch,
 *    and are meant to be replaced. Each is written to be distinct, because
 *    eight near-identical openings is the exact SEO problem the copy file
 *    flags about the current auto-generated line.
 *
 * Writes only where the field is EMPTY, so a later pass — or anything the
 * office edits by hand — is never clobbered by a re-run.
 *
 * Idempotent. Dry run unless BOS_PC_APPLY=1.
 */

const CAT_VID = 'plant_character_categories';

$apply = getenv('BOS_PC_APPLY') === '1';
print $apply ? "APPLYING\n\n" : "DRY RUN\n\n";

// §2, verbatim.
$short = [
  'Aesthetic Features' => 'Flowers, foliage, fragrance and fruit. What a plant contributes to look at, and the traits most people start from when they picture a finished planting.',
  'Environmental Tolerance' => 'Soil, water, sun, cold and salt. On the Western Slope this is the category that decides whether a plant lives, and alkaline tolerance is the one most often missed.',
  'Growth Habit' => 'Form, and how a plant occupies space as it matures — upright, spreading, clumping, climbing or flat to the ground. Habit at maturity sets the spacing, not the size of the pot it arrives in.',
  'Maintenance & Behavior' => 'Growth rate, longevity, disease and pest resistance, and how much pruning a plant will ask for. The difference between a planting that looks better in year five and one that becomes a standing chore.',
  'Origin' => 'Native, hybrid or introduced. Origin is useful shorthand for how a plant is likely to handle local conditions, though it is not a guarantee in either direction.',
  'Seasonal Interest' => 'When a plant earns its place — spring bloom, fall color, or structure and berries through winter. A planting built entirely around May looks empty for the other eleven months.',
  'Special Uses' => 'Plants chosen for a job rather than a look: holding a slope, screening a view, filling a container, or feeding pollinators.',
  'Wildlife Interaction' => 'What a plant attracts and what leaves it alone. Deer and rabbit pressure is real through most of this valley, and browse is the fastest way to lose a new planting in its first winter.',
];

// DRAFTS — Code-written, to be replaced. Deliberately distinct from one another.
$body = [
  'Environmental Tolerance' => '<p>This is the category that decides whether a plant is still alive in three years, and on the Western Slope it is not close. Soil across most of Delta and Montrose counties runs alkaline, which quietly rules out a long list of plants sold as dependable everywhere else. Add a growing season that can frost in late May, irrigation water that varies in quality from one ditch to the next, and a thousand feet of elevation between one job and the next, and tolerance stops being a nice-to-have.</p><p>Alkalinity is the one most often missed. A plant that wants acid soil will not simply grow more slowly here — it will yellow, stall, and die over two or three seasons while everything around it does fine.</p>',
  'Growth Habit' => '<p>Habit is what a plant does with space as it matures: stands upright, spreads sideways, stays in a clump, climbs, or runs flat along the ground. It is the trait most often judged from the pot, which is why plantings get crowded — a one-gallon shrub and a mature one occupy very different amounts of room.</p><p>Spacing comes from habit at maturity, not from what fits on the truck. Getting it right is the difference between a bed that fills in and a bed that has to be thinned in year four.</p>',
  'Seasonal Interest' => '<p>Most plantings are designed for May. May is the easiest month to make look good here and the least useful one to design around, because the other eleven are what people actually live with.</p><p>Spring bloom, summer foliage, fall color and winter structure are four separate jobs, and a planting that does all four was planned that way. Winter is the longest season on this calendar — bark, berries, seed heads and evergreen form are doing the work from November to April.</p>',
  'Wildlife Interaction' => '<p>Deer and rabbit pressure is real through most of this valley, and it is the fastest way to lose a new planting. A young shrub that gets browsed to the stems in its first winter rarely recovers into the plant it was supposed to be.</p><p>Resistance is a spectrum, not a guarantee — a hungry enough deer in a hard enough February will eat things every list says it will not. What these traits buy you is odds, and on an exposed site they are worth spending money on.</p>',
  'Special Uses' => '<p>Sometimes a plant is chosen for a job rather than a look: holding a cut slope together, screening a neighbour\'s shop building, surviving in a container through a Delta summer, or feeding pollinators from spring through frost.</p><p>These are the traits people reach for when they can describe the problem but not the plant — which is usually how a planting starts.</p>',
  'Maintenance & Behavior' => '<p>Every planting carries a maintenance cost. The question is whether that cost was chosen deliberately or inherited by accident.</p><p>Growth rate, longevity, disease and pest resistance and pruning demand decide whether a bed looks better in year five or becomes a standing chore. We maintain a good share of what we install, which keeps us honest about this category — a plant that needs babysitting becomes our problem too.</p>',
  'Aesthetic Features' => '<p>Flowers, foliage, fragrance and fruit — the traits most people start from when they picture a finished planting, and the ones that sell a plant in a nursery in April.</p><p>They matter. They are also the easiest to satisfy: for nearly any look there is more than one plant that achieves it, which means aesthetics is usually the last filter to apply rather than the first. Decide what will survive the site, then choose among what is left on how it looks.</p>',
  'Origin' => '<p>Native, hybrid or introduced. Origin is useful shorthand for how a plant is likely to handle local conditions, and it is routinely over-read in both directions.</p><p>Native to Colorado and native to a 5,500-foot valley with alkaline soil are not the same claim. Plenty of introduced plants perform well here, and anything introduced gets checked against the Colorado noxious weed list before it goes on a plan — a plant that thrives in this climate and spreads on its own is not a feature.</p>',
];

$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => CAT_VID]);
$byName = [];
foreach ($terms as $t) {
  $byName[$t->label()] = $t;
}

$set = 0; $skipped = 0;
foreach ($short as $name => $text) {
  $term = $byName[$name] ?? NULL;
  if (!$term) {
    printf("  MISS  %s\n", $name);
    continue;
  }
  $dirty = FALSE;
  foreach ([['field_short_description', $text, 'short (verbatim)'],
            ['field_public_description', $body[$name] ?? '', 'body  (DRAFT)']] as [$f, $val, $what]) {
    if ($val === '') {
      continue;
    }
    if (trim((string) $term->get($f)->value) !== '') {
      printf("  keep  %-24s %s — already written, left alone\n", $name, $what);
      $skipped++;
      continue;
    }
    $term->set($f, [
      'value' => str_starts_with($val, '<') ? $val : '<p>' . $val . '</p>',
      'format' => 'full_html',
    ]);
    printf("  set   %-24s %s\n", $name, $what);
    $set++;
    $dirty = TRUE;
  }
  if ($dirty && $apply) {
    $term->save();
  }
}
printf("\n  %d field(s) %s, %d left alone.\n", $set, $apply ? 'written' : 'pending', $skipped);
