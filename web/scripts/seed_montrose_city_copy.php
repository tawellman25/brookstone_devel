<?php

declare(strict_types=1);

/**
 * City of Montrose — marketing's 2026-10-04 rewrite.
 *
 * Replaces the body in full, adds the card summary written for the county
 * page's "Towns We Serve" card, and sets the title tag and meta description.
 *
 * Two live defects this corrects, both confirmed present before the run:
 *
 *  1. The body published the Montrose ZIP codes as "81401, 81402 and 84103".
 *     84103 IS SALT LAKE CITY. The correct third is 81403. The rewrite drops
 *     the ZIP list entirely rather than fixing the digit, because it did no
 *     commercial work on the page.
 *  2. The body opened "Nestled in the heart of the Western Slope" — a banned
 *     word in the house voice, and the whole passage goes.
 *
 * The meta description was EMPTY, so it auto-generated from the first 255
 * characters of the body — which is how the Salt Lake City ZIP reached the
 * search result. It is now written explicitly. Four other city pages already
 * carry field_meta_tags in this exact {title, description} shape.
 *
 * The city is resolved BY URL ALIAS, never by name: the office owns the name
 * (it is "City of Montrose", not "Montrose"), and matching on one is the
 * fragility that has bitten twice — the credential seeder and the fruit
 * category, both renamed under a script that matched on the name.
 *
 * Confirmed by Todd 2026-10-04 before loading: elevation 5,807 ft (it joins
 * Delta County's published 4,961 and Cedaredge's 6,231), the Mancos shale soil
 * description, and that both municipal water and ditch shares exist inside the
 * city limits. Marketing's subtitle was dropped — the entity has no subtitle
 * field and no other city page carries one.
 *
 * Previous values of all three fields are written to a backup JSON first.
 *
 *   drush php:script web/scripts/seed_montrose_city_copy.php
 *   BOS_MONTROSE_APPLY=1 drush php:script web/scripts/seed_montrose_city_copy.php
 */

$apply = getenv('BOS_MONTROSE_APPLY') === '1';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_MONTROSE_APPLY=1 to write)\n\n";

$ALIAS = '/colorado/montrose-county/montrose';
$EXPECT_NAME = 'City of Montrose';

$TITLE_TAG = 'Montrose CO Landscaping & Irrigation | Brookstone Outdoors';
$META_DESC = 'Landscaping and irrigation in Montrose, Colorado. City water or ditch shares, Uncompahgre valley clay, and commercial and HOA grounds work on the valley floor.';
$CARD = 'The largest town in the two counties we work, and the one where water, ground, and property size all behave differently than they do up the valley.';

$BODY = <<<'HTML'
<p>Montrose sits at 5,807 feet on the floor of the Uncompahgre valley. It is the largest town in the area Brookstone Outdoors works, and it is also the part of our service area where a landscape is least likely to behave the way one does up in Delta County.</p>

<h2>Two water sources, inside one city</h2>

<p>A property in Montrose runs on one of two water supplies, and which one it is determines most of what an irrigation system has to be.</p>

<p>Municipal water is treated, pressurized, and available on whatever schedule the owner sets. A system on city water is designed around sprinkler performance and little else.</p>

<p>A property on ditch shares runs on untreated water delivered by the <a href="https://uvwua.com/">Uncompahgre Valley Water Users Association</a>. That requires a pump and filtration, because the water carries sediment that will close a drip emitter and wear a nozzle. It also means the season is set by the association rather than by the weather. Water arrives when the ditch is turned in and stops when it is shut off, and a late shut-off followed by an early freeze is the combination that splits pipe.</p>

<p>Both arrangements exist inside the city limits, some properties on the city's treated supply and others on association shares. The first question on any irrigation visit here is which one the property is on, because the answer changes the startup, the maintenance, and the date the system has to be blown out.</p>

<h2>The ground</h2>

<p>The Uncompahgre valley sits on Mancos shale, and the soil that weathers out of it is heavy clay, strongly alkaline, and slow to drain. In places it carries enough salt to limit what will grow in it at all.</p>

<p>The practical consequence is that drainage and soil amendment are part of an installation here, not an upgrade to one. A bed dug into unamended valley clay holds water against the roots after every cycle, and a plant that would be fine in lighter ground declines over two or three seasons for reasons nothing on the surface explains. We would rather spend the money on the ground first and plant into something that drains.</p>

<p>It also shortens the list of what is worth planting. Species that want acidic or fast-draining soil can be kept alive here with enough intervention, but they do not establish, and we will say so before they go in the ground rather than after.</p>

<h2>Commercial and HOA properties</h2>

<p>Montrose holds the larger properties in our service area: parking lots, common areas, HOA grounds, and commercial entrances that have to be clear and safe before the first person arrives in the morning.</p>

<p>Brookstone Outdoors maintains several properties owned by the City of Montrose. That kind of work is different from residential work in what it demands outside the actual labor. It requires crews and equipment sized to the property, snow and ice response measured against an opening time, irrigation on systems well past the size of a residential lot, and a record of what was done and when.</p>

<p>Brookstone Outdoors runs its own business-management platform, which holds property information, work orders, service history, and pesticide application records. For a property manager or an HOA board, that means the documentation already exists in a form that can be put in front of a board or an insurer without being assembled first.</p>

<h2>Scheduling here</h2>

<p>Montrose work is scheduled in grouped routes rather than one property at a time. A crew already working a street can take the property next door for less than a crew sent out for a single stop, and the schedule is built with that in mind.</p>

<p>If several properties on the same block want the same service, it is worth saying so when you call. It is usually the difference between being fit into an existing route and waiting for one.</p>

<h2>The year</h2>

<p>Sprinkler startup in spring, mowing through the summer, cleanups and blowouts in the fall. The valley floor here runs a slightly longer season than the high ground in Delta County, which moves the dates without changing the order. On a property watering off ditch shares, the ditch schedule moves them again.</p>

<p>To discuss work on a property in Montrose, <a href="/request-estimate">request an estimate</a> or call the office.</p>
HTML;

// ----------------------------------------------------------------- resolve
$path = \Drupal::service('path_alias.manager')->getPathByAlias($ALIAS);
if (!preg_match('#^/city/(\d+)$#', $path, $m)) {
  print "ERROR: $ALIAS does not resolve to a city entity (got $path). Aborting.\n";
  return;
}
$city = \Drupal::entityTypeManager()->getStorage('city')->load($m[1]);
if (!$city) {
  print "ERROR: city {$m[1]} not loadable. Aborting.\n";
  return;
}
printf("  resolved %s -> city/%s \"%s\"\n", $ALIAS, $city->id(), $city->label());
if ($city->label() !== $EXPECT_NAME) {
  printf("  NOTE: name is \"%s\", expected \"%s\" — the office owns the name, proceeding on the alias.\n", $city->label(), $EXPECT_NAME);
}

foreach (['field_city_description', 'field_short_description', 'field_meta_tags'] as $f) {
  if (!$city->hasField($f)) {
    print "ERROR: $f missing — run setup_city_short_description.php first. Aborting.\n";
    return;
  }
}

// Refuse to publish a body that still carries either defect.
// Pairs, not a keyed map: PHP coerces the numeric string key '84103' to the
// INTEGER 84103, and strpos() then rejects it as a needle.
foreach ([['84103', 'the Salt Lake City ZIP'], ['estled in the heart', 'the banned "nestled" opening']] as [$needle, $what]) {
  if (strpos($BODY, $needle) !== FALSE) {
    print "ERROR: the replacement body still contains $what. Aborting.\n";
    return;
  }
}

$oldBody = (string) $city->get('field_city_description')->value;
$oldFormat = $city->get('field_city_description')->format ?: 'full_html';
$oldCard = (string) $city->get('field_short_description')->value;
$oldMeta = (string) $city->get('field_meta_tags')->value;

printf("\n  body     %d -> %d chars (format %s, preserved)\n", strlen($oldBody), strlen($BODY), $oldFormat);
printf("    was carrying 84103: %s · \"Nestled\": %s\n",
  strpos($oldBody, '84103') !== FALSE ? 'YES' : 'no',
  stripos($oldBody, 'nestled') !== FALSE ? 'YES' : 'no');
printf("  card     %s -> %d chars\n", $oldCard === '' ? '(empty)' : strlen($oldCard) . ' chars', strlen($CARD));
printf("  meta     %s -> title %d ch, description %d ch\n", $oldMeta === '' ? '(EMPTY — auto-generated from the body)' : 'set', strlen($TITLE_TAG), strlen($META_DESC));

if (strlen($META_DESC) > 160) {
  printf("    NOTE: description is %d chars; Google renders ~155-160.\n", strlen($META_DESC));
}

if (!$apply) {
  print "\n(dry-run — nothing written)\n";
  return;
}

// ------------------------------------------------------------------ backup
$backup = sprintf('%s/docs/backups/montrose-city-copy-backup-%s.json', DRUPAL_ROOT . '/..', date('Ymd-His'));
@mkdir(dirname($backup), 0775, TRUE);
file_put_contents($backup, json_encode([
  'city_id' => $city->id(),
  'name' => $city->label(),
  'alias' => $ALIAS,
  'field_city_description' => ['value' => $oldBody, 'format' => $oldFormat],
  'field_short_description' => $oldCard,
  'field_meta_tags' => $oldMeta,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
print "\n  backup written: $backup\n";

// ------------------------------------------------------------------- write
// Merge metatags so any other stored tag survives.
$tags = $oldMeta !== '' ? (json_decode($oldMeta, TRUE) ?: []) : [];
$tags['title'] = $TITLE_TAG;
$tags['description'] = $META_DESC;

$city->set('field_city_description', ['value' => $BODY, 'format' => $oldFormat]);
$city->set('field_short_description', ['value' => $CARD, 'format' => 'full_html']);
$city->set('field_meta_tags', json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$city->save();

\Drupal\Core\Cache\Cache::invalidateTags(['city:' . $city->id()]);
print "  saved.\n";
