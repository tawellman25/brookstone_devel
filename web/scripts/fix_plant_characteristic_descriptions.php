<?php

declare(strict_types=1);

/**
 * Twelve plant-characteristic descriptions that say "shrubs" on pages about
 * plants.
 *
 * Growth Habit, Origin and Seasonal Interest apply to trees, perennials,
 * grasses and groundcovers as much as to shrubs, but their descriptions were
 * written as though the vocabulary were shrubs-only ("Evergreen shrubs keep
 * their leaves year-round"). Vining was also written in botanical register
 * — "indeterminate stems that lack sufficient structural rigidity" — beside
 * five terms written for a homeowner.
 *
 * Copy is verbatim from Website Copy/Plant Characteristics - Landing Page
 * Copy.md §5. Nothing here is written or edited by Code.
 *
 * FIELD: core `description`. The vocabulary has exactly one configured field
 * (field_characteristic_category, a list_integer) — no field_short_description
 * or field_public_description exist on it, and all 41 terms carry their text in
 * core description. That was verified, not assumed; the copy doc explicitly
 * asked for it to be confirmed before anything was pasted.
 *
 * Idempotent, matched on term name within the vocabulary. Dry run unless
 * BOS_PC_APPLY=1. Writes a backup JSON before changing anything.
 *
 *   drush php:script web/scripts/fix_plant_characteristic_descriptions.php
 */

$apply = getenv('BOS_PC_APPLY') === '1';

$copy = [
  'Clumping' => 'Grows in tight, dense clusters that hold their shape instead of running. Clumping plants stay where they are put, which makes them predictable in a bed and slow to need dividing.',
  'Deciduous' => 'Drops its leaves in fall and regrows them in spring. Deciduous plants give you seasonal change and winter light, and their bare structure is part of what a planting looks like for five months of the year here.',
  'Evergreen' => 'Holds its foliage year-round. Evergreens carry the structure of a planting through winter, which matters more at this elevation than it does where snow cover is brief and the view is green again in March.',
  'Groundcover' => 'Grows low and spreads wide, covering soil rather than standing above it. Used to suppress weeds, hold soil on a slope, and fill the places where turf is impractical to mow or water.',
  'Spreading' => 'Widens outward over time, often well past its original footprint. Spreading plants fill large areas economically, and they have to be spaced for what they become rather than what comes off the truck.',
  'Vining' => 'Climbs or trails rather than standing on its own. Vines need something to hold, and how they attach decides what that something has to be — twiners wrap around a support, tendril climbers grab a wire or lattice, clingers attach directly to a wall, and scramblers simply lean and need tying in.',
  'Native' => 'Occurs naturally in this region, adapted to its soil, water and temperature swings. Natives are generally lower-input once established — though native to Colorado and native to a 5,500-foot valley with alkaline soil are not the same claim, and the distinction matters when a plant list is written.',
  'Hybrid' => 'Bred from two parent plants for a specific trait — disease resistance, bloom size, hardiness, or a more compact habit. Hybrids are often the more reliable choice where a straight species struggles here, and most do not come true from seed.',
  'Exotic' => 'Introduced from outside the region. Plenty of them perform well here. Anything introduced gets checked against the Colorado noxious weed list before it goes on a plan, because a plant that thrives in this climate and spreads on its own is not a feature.',
  'Spring-Blooming' => 'Flowers early, often before much else has leafed out. Worth knowing that an early bloomer at this elevation can open ahead of the last frost, so exposure and placement matter as much as the plant does.',
  'Fall Color' => 'Turns color before leaf drop. Fall color here depends heavily on the year — a warm, dry September pushes it late and dulls it — so a planting is better off not resting entirely on it.',
  'Winter Interest' => 'Holds something worth looking at after everything else has gone: berries, bark, seed heads, or evergreen form. Winter is the longest season on this calendar and the one most plantings ignore.',
];

print $apply ? "APPLYING\n\n" : "DRY RUN (set BOS_PC_APPLY=1 to apply)\n\n";

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$byName = [];
foreach ($storage->loadByProperties(['vid' => 'plant_characteristics']) as $t) {
  $byName[$t->label()] = $t;
}

$backup = [];
$changed = 0;
foreach ($copy as $name => $text) {
  $term = $byName[$name] ?? NULL;
  if (!$term) {
    printf("  MISS   %-18s no term of that name in the vocabulary\n", $name);
    continue;
  }
  $old = (string) $term->getDescription();
  if (trim(strip_tags($old)) === trim($text)) {
    printf("  ok     %-18s (already correct)\n", $name);
    continue;
  }
  printf("  change %-18s\n", $name);
  printf("           was: %s\n", mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($old))), 0, 96));
  printf("           now: %s\n", mb_substr($text, 0, 96));
  $backup[] = ['tid' => $term->id(), 'name' => $name, 'old' => $old];
  $changed++;
  if ($apply) {
    // Keep the format the rest of the vocabulary uses rather than imposing one.
    $format = $term->get('description')->format ?: 'basic_html';
    $term->set('description', ['value' => '<p>' . $text . '</p>', 'format' => $format]);
    $term->save();
  }
}

if ($apply && $backup) {
  $path = 'temporary://plant-char-backup-' . date('Ymd-His') . '.json';
  file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  print "\nbackup: " . \Drupal::service('file_system')->realpath($path) . "\n";
}
printf("\n%d description(s) %s.\n", $changed, $apply ? 'updated' : 'pending');
