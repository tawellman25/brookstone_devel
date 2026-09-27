<?php

declare(strict_types=1);

/**
 * Authored metatags for all 20 Spraying Locations children — verbatim from
 * "Spraying Locations — metatags for all 20 children" (2026-09-27).
 *
 * Sets field_meta_tags title / description / og_description per term. These are
 * authored, measured values (every title <= 60 ch, every description 135-158, all
 * 20 descriptions unique) and they REPLACE the interim descriptions that were
 * derived from field_short_description earlier the same day.
 *
 * Any other tag already stored on a term is preserved (the three keys are merged
 * in, not written over the whole value). Terms matched by URL-alias slug, so
 * environment-independent. Idempotent. Dry-run by default; prints measured
 * lengths so the counts in the source doc can be confirmed.
 *
 *   drush php:script web/scripts/seed_spraying_locations_metatags.php
 *   BOS_META_APPLY=1 drush php:script web/scripts/seed_spraying_locations_metatags.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_META_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$vid = 'spraying_locations';

// slug => [title, description, og_description]
$DATA = [
  'lawn' => [
    "Lawn Weed Control | Delta & Montrose County, CO",
    "Selective weed control for turf — what it kills, what it will not touch, and why timing around mowing matters. Delta and Montrose counties, Colorado.",
    "The product has to kill what is growing in the grass without killing the grass.",
  ],
  'spot-spray-lawn' => [
    "Spot Spray Lawn Weed Treatment | Western Colorado",
    "Treating the weeds instead of the whole lawn — when a spot treatment is the right call, and why less herbicide on your property is usually the better answer.",
    "Less product on the property, and often the honest recommendation.",
  ],
  'landscape-beds' => [
    "Weed Control in Landscape Beds | Delta & Montrose CO",
    "No product separates a weed from an ornamental, so bed work comes down to placement and pre-emergent timing. How we treat beds without killing plants.",
    "Nothing in a bed does the discriminating for you. Placement is the whole job.",
  ],
  'spot-spray-beds' => [
    "Spot Spraying Landscape Beds | Western Colorado",
    "Targeted weed treatment inside a planted bed, with a directed wand. When spraying is right, and when hand pulling is the better answer in a dense planting.",
    "Each weed treated individually, and in a tight bed, pulled instead.",
  ],
  'shrubs' => [
    "Weed Control Around Shrubs | Delta & Montrose County, CO",
    "Drift damage to a shrub shows up as distorted growth the following spring. How we treat around shrub plantings, and the wind standard that applies there.",
    "Damage here is delayed a season and a mature shrub is a real replacement cost.",
  ],
  'tree-rings' => [
    "Tree Ring Weed Control | Delta & Montrose County, CO",
    "Why tree rings exist, how string trimmer damage kills a tree years later, and why green bark takes up herbicide the same way a leaf does.",
    "The ring keeps equipment off the trunk. That is the damage it prevents.",
  ],
  'gravel' => [
    "Gravel Driveway & Yard Weed Control | Western Colorado",
    "Nothing in gravel is worth keeping, so the product can be stronger and last longer — which makes the edges and what drains downhill the part that needs care.",
    "Longer residual is the point, and the reason the boundary matters.",
  ],
  'driveway' => [
    "Driveway Weed Control | Delta & Montrose County, CO",
    "Weed control matched to your driveway surface — concrete, asphalt or gravel — with directed application at the edges to protect adjacent lawn and beds.",
    "The edge where a drive meets lawn is where the care goes.",
  ],
  'parking-lot' => [
    "Commercial Parking Lot Weed Control | Western Colorado",
    "Weed control for paved commercial lots, scheduled around your business, with storm drains mapped first and every application documented for your records.",
    "The chemistry is simple. The storm drain is the part that needs planning.",
  ],
  'sidewalk-cracks' => [
    "Sidewalk Crack Weed Control | Delta & Montrose CO",
    "Weeds in expansion joints and sidewalk cracks — treated in the crack rather than across the slab, because a hard surface is a drainage path, not a sponge.",
    "A crack in an impervious surface is a runoff path. Precision is the point.",
  ],
  'parking-lot-cracks' => [
    "Parking Lot Crack Weed Treatment | Western Colorado",
    "Weeds in the joints of a paved lot widen cracks and accelerate surface failure. Directed treatment, storm drains protected, and honest advice on the surface.",
    "Roots widen a crack, and a Colorado winter finishes the job.",
  ],
  'pathway' => [
    "Pathway & Walkway Weed Control | Delta & Montrose CO",
    "Concrete, pavers, flagstone or gravel — the surface decides the treatment and the planted edges decide how careful it has to be. Weed control for paths.",
    "A path is mostly edge, which is what makes it careful work.",
  ],
  'pasture' => [
    "Pasture Weed Spraying | Delta & Montrose County, Colorado",
    "Pasture weed control with grazing and hay restrictions handled properly — what grazes there changes the product, and clover is broadleaf. Western Slope.",
    "The grazing restriction is the job, not a footnote on the label.",
  ],
  'arena' => [
    "Arena & Livestock Area Weed Control | Western Colorado",
    "Weed control for horse and livestock arenas, using products with livestock-safe designations and re-entry intervals communicated before we leave the job.",
    "The animals using the space decide the product and the re-entry interval.",
  ],
  'vacant-lot' => [
    "Vacant Lot Weed Control | Delta & Montrose County, CO",
    "An untended lot seeds every property around it — and Colorado noxious weed law applies to unused ground, including List A mandatory eradication. What to know.",
    "The obligation applies to land nobody is using, and the county enforces it.",
  ],
  'roadside' => [
    "Roadside & Ditch Bank Weed Control | Western Colorado",
    "Frontage, ditch banks and right-of-way — where noxious weeds travel, why irrigation ditches change the product, and how we confirm whose ground it is.",
    "Traffic on one side, somebody else's property on the other.",
  ],
  'retention-pond' => [
    "Retention Pond Weed Control | Delta & Montrose County, CO",
    "The most label-restricted site we treat. A product used near water must be labeled for aquatic use, and the slopes of a basin count as the water body.",
    "Aquatic label or no application. The basin is designed to hold water.",
  ],
  'fence-line' => [
    "Fence Line Weed Control | Delta & Montrose County, CO",
    "The strip a mower cannot reach, with somebody else's property inches away. Directed treatment, the tightest wind standard we use, and why it matters.",
    "A fence line left alone is a seed bank pointed at your lawn.",
  ],
  'entire-area' => [
    "Whole Property Weed Control | Delta & Montrose County, CO",
    "Treating an entire property does not mean one product everywhere — turf, beds, hard surfaces and the transitions between them each get what belongs on them.",
    "A scope note, not a product decision.",
  ],
  'other-see-description' => [
    "Weed Control for Unusual Sites | Western Colorado",
    "Some sites do not fit a standard category. How we document an unusual application so the record and your notice describe what actually happened.",
    "Real properties are not standard. The description carries the detail.",
  ],
];

$am = \Drupal::service('path_alias.manager');
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vid)->execute();
$bySlug = [];
foreach ($etm->getStorage('taxonomy_term')->loadMultiple($tids) as $t) {
  $alias = $am->getAliasByPath('/taxonomy/term/' . $t->id());
  $bySlug[substr(strrchr($alias, '/'), 1)] = $t;
}

printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_META_APPLY=1 to write)');
printf("%-23s %5s %5s %5s  %s\n", 'SLUG', 'title', 'desc', 'og', 'FLAGS');
print str_repeat('-', 72) . "\n";

$done = 0;
$missing = [];
$flagged = [];
$descs = [];
foreach ($DATA as $slug => [$title, $desc, $og]) {
  if (!isset($bySlug[$slug])) {
    $missing[] = $slug;
    continue;
  }
  $t = $bySlug[$slug];
  if (!$t->hasField('field_meta_tags')) {
    print "  ERROR field_meta_tags missing on $slug — run setup_vocab_meta_tags.php first\n";
    return;
  }

  $lt = mb_strlen($title);
  $ld = mb_strlen($desc);
  $lo = mb_strlen($og);
  $flags = [];
  if ($lt > 60) { $flags[] = 'TITLE>60'; }
  if ($ld < 135 || $ld > 158) { $flags[] = 'DESC OUTSIDE 135-158'; }
  if (isset($descs[$desc])) { $flags[] = 'DUPLICATE DESC of ' . $descs[$desc]; }
  $descs[$desc] = $slug;
  if ($flags) { $flagged[] = "$slug: " . implode(', ', $flags); }

  printf("%-23s %5d %5d %5d  %s\n", $slug, $lt, $ld, $lo, $flags ? '⚠ ' . implode(', ', $flags) : 'ok');

  if ($apply) {
    $raw = (string) ($t->get('field_meta_tags')->value ?? '');
    $tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
    $tags['title'] = $title;
    $tags['description'] = $desc;
    $tags['og_description'] = $og;
    $t->set('field_meta_tags', json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $t->save();
    Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
  }
  $done++;
}

printf("\n%d of %d terms%s\n", $done, count($DATA), $apply ? ' written.' : ' (dry-run — nothing written).');
printf("unique descriptions: %d / %d\n", count($descs), $done);
if ($missing) { print "!! no term for slug: " . implode(', ', $missing) . "\n"; }
if ($flagged) { print "\nFLAGGED:\n  - " . implode("\n  - ", $flagged) . "\n"; }
else { print "all titles <=60, all descriptions 135-158, no duplicates.\n"; }
