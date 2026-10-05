<?php

declare(strict_types=1);

/**
 * Crew and office copy for Holiday Decorations, from the Rev 2 field sheet.
 *
 *   field_service_crew_desc    -> teammate_view (crews)
 *   field_service_office_desc  -> admin_view only (office)
 *
 * WHY THE CREW COPY CARRIES ALMOST NO PRICES: crews have the estimator at
 * /teammates/holiday-estimate, which reads every rate live from Business
 * Settings. Reprinting a rate card in prose would create a second place to
 * maintain it and a second place for it to go stale — the exact problem the
 * price book was built to end. So this carries the JUDGEMENT the estimator
 * cannot: how to tell a medium trunk from a large one, what to measure, what to
 * say when somebody pushes back. Where a headline rate does appear it is a
 * TOKEN, substituted at render by bos_services from the price book.
 *
 * ⚠ The office margins are presented WITH the sheet's own section 12 caveat.
 * Every one of them assumes a $30/man-hour burdened labour rate and material
 * costs backed out of retail, none of which is verified. Publishing them into
 * BOS without that warning would turn an estimate into an apparent fact on an
 * office screen.
 *
 * Todd's correction, 2026-10-04: the two-year term holds its rate FLAT for both
 * years. The 3% escalator starts in year three, not year two.
 *
 * Idempotent, backs both previous values up first.
 *
 *   drush php:script web/scripts/seed_holiday_crew_office_copy.php
 *   BOS_HOLCOPY_APPLY=1 drush php:script web/scripts/seed_holiday_crew_office_copy.php
 */

$apply = getenv('BOS_HOLCOPY_APPLY') === '1';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_HOLCOPY_APPLY=1 to write)\n\n";

// Resolved by ALIAS: the office owns term names and this one has been both
// "Christmas Decorations" and "Holiday Decorations".
$path = \Drupal::service('path_alias.manager')->getPathByAlias('/services/christmas-decorations');
if (!preg_match('#^/taxonomy/term/(\d+)$#', $path, $m)) {
  print "ERROR: alias does not resolve to a term (got $path). Aborting.\n";
  return;
}
$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load((int) $m[1]);
foreach (['field_service_crew_desc', 'field_service_office_desc'] as $f) {
  if (!$term || !$term->hasField($f)) {
    print "ERROR: $f missing — run setup_service_office_desc_field.php first. Aborting.\n";
    return;
  }
}
printf("  term %d \"%s\"\n", $term->id(), $term->label());

$CREW = <<<'HTML'
<h3>Quote the two-year first, every time</h3>
<p>The two-year rate is [holiday-price] and the one-year is meaningfully higher. That spread is the whole sales argument: the customer pays less and we get the light purchase spread across two seasons instead of one. Lead with it. The one-year exists for somebody who will not commit, not as an equal option.</p>
<p><strong>The two-year price holds flat for both years.</strong> Renewal after that is at the same rate with a 3% annual escalator, and that escalator starts in <strong>year three</strong> — never year two. Do not describe year two as a price increase, because it is not one.</p>

<h3>Measuring</h3>
<ul>
  <li>Laser distance measure. Walk the roofline and eaves, measure each run <strong>separately</strong>, and write the segments on the quote — if they add scope later you re-price off a record instead of an argument.</li>
  <li><strong>Add 15%</strong> to the roofline total for corners, valleys and angles. They eat strand without throwing light.</li>
  <li>Everything else is <strong>counted, not measured</strong>. Trees, wreaths, columns, windows, border sections.</li>
  <li>Build the number in the <a href="/teammates/holiday-estimate"><strong>estimator</strong></a> while you are standing there. It carries the current rates, so you are never quoting from memory.</li>
</ul>

<h3>Sizing a tree without a tape</h3>
<p>Deciduous, by the trunk, wrapped to the first major branches:</p>
<ul>
  <li><strong>Small</strong> — trunk thinner than a basketball; you can get both hands around it.</li>
  <li><strong>Medium</strong> — about one arm's reach around, roughly 10–16 inches.</li>
  <li><strong>Large</strong> — you can just barely reach around it, 16–24 inches.</li>
  <li><strong>Extra large</strong> — takes two people to reach around. Quote it on site, not off the bracket.</li>
</ul>
<p>Evergreen, by height, spiral wrapped to about three-quarter coverage. A single-storey roofline is roughly 10 ft if you need something to compare against. Over 12 ft needs a lift and is quoted on site.</p>
<p><strong>Width drives cost as much as height does.</strong> An unusually wide or dense spruce is a site quote even if its height lands in a bracket. When a tree sits near the line between two brackets, size up — the thin end of a bracket is where the margin goes.</p>

<h3>Two things that are pure margin and get forgotten</h3>
<p>Steep pitch and work above 25 feet both carry no material at all. Check for them on <em>every</em> walkthrough. Forgetting them is the most common way a good quote turns mediocre.</p>

<h3>Never quote the roofline alone</h3>
<p>On a typical house the add-ons are around two-thirds of the ticket, on the same truck roll, same drive, same setup. Quote the roofline plus three add-ons and let the customer take one away — that lands better than building up from the roofline and it ends higher.</p>
<p>Shrubs have a three-unit minimum. If they want one, price three and say so.</p>

<h3>Lights they already own</h3>
<p>Hang &amp; Remove is [holiday-hang-rate] per foot, [holiday-hang-min] minimum, install and takedown only — no service guarantee and no storage, because they are not our lights. <strong>Look at the lights before you quote it.</strong> Condition varies enormously, and clips and detangling are chargeable. If they are in poor shape, say so then, not in January.</p>

<h3>Deposit and scheduling</h3>
<p>50% at signing, balance on completion. The deposit is what buys the material, so September and October signings fund the October order. <strong>No deposit, no spot on the install schedule</strong> — that is not a negotiating position, it is how the order gets sized.</p>

<h3>The season</h3>
<ul>
  <li><strong>Now – late October</strong> — selling.</li>
  <li><strong>By about 20 October</strong> — material order placed, sized off signed contracts.</li>
  <li><strong>Late October</strong> — shop pre-build: sets cut and labelled by address so crews arrive with a finished assembly.</li>
  <li><strong>1 November – 15 December</strong> — install window. Very little sells after about 5 December.</li>
  <li><strong>15 December – 1 January</strong> — service calls only.</li>
  <li><strong>January</strong> — takedown, inventory in, sets logged to the property.</li>
</ul>

<h3>When they push back</h3>
<p><strong>"I can do it myself for two hundred bucks."</strong><br>
You can. Most of our customers did for years. It is two Saturdays, a ladder, and about half those strands will not light next year. People usually call us the season they decide they are done doing it.</p>
<p><strong>"Why is the one-year so much more?"</strong><br>
Because we buy the lights. On a two-year the cost spreads across both seasons, so we can charge you less. One season and we carry it all up front.</p>
<p><strong>"What if I move, or I don't want them next year?"</strong><br>
Then we come and get our lights and we are square. We are not going to chase anybody over Christmas lights. Say it plainly — the honest answer closes better than a stiff one.</p>
<p><strong>"That seems like a lot."</strong><br>
It is one price for the whole season — we put them up, we fix anything that goes out, we take them down in January, and we store them. You never touch a ladder and you never see another invoice. Stay on the full scope. Drop scope to meet a number, never service.</p>
<p><strong>"How long will it take?"</strong><br>
The price does not change either way — it is set off the measurement, not the clock.</p>

<h3>Safety overrides throughput</h3>
<p>One serious fall costs more than a season's gross profit in direct comp alone, before three years of experience-modifier damage. Work steady and careful. The margin is set in September, not on a ladder in November.</p>
HTML;

$OFFICE = <<<'HTML'
<h3>⚠ Read this before quoting any figure below</h3>
<p><strong>These margins are not verified.</strong> Every one of them assumes a burdened labour rate of $30 per man-hour and material costs backed out of retail rather than quoted from a supplier. The 175 ft reference roofline is a trade benchmark, not a Brookstone measurement, and the wrap production rates (about 70 ft of string per crew-hour on trunks, 50 on evergreens) have not been timed on our own crews.</p>
<p>Treat them as a planning model, not as fact. Four things would make them real: the true winter burdened rate including comp on ladder work, contractor quotes for mini lights and wreaths, an aerial pass to measure the actual average roofline on existing properties, and timing the first two trees in November.</p>

<h3>Plan margins at the reference roofline</h3>
<p>Year two material assumes a 10% replacement allowance on company-owned sets.</p>
<ul>
  <li><strong>Full Season, two-year</strong> — about 41% in year one, about 71% in year two, blending to roughly <strong>56% across the term</strong>. Year one is thin <em>by design</em>: the lights are paid off over two seasons.</li>
  <li><strong>Full Season, one-year</strong> — about 55%, and no year two.</li>
  <li><strong>Hang &amp; Remove</strong> — about 50%, with no material at risk either year.</li>
</ul>

<h3>Add-ons</h3>
<p>Most sit between 41% and 55% in year one. Two worth knowing:</p>
<ul>
  <li><strong>Wreaths improve enormously on renewal</strong> — a 48 in wreath is about 38% in year one and roughly 79% after, because the wreath is already bought. A one-and-done wreath customer leaves us at 38% holding a used wreath, which is the weakest outcome in the whole book.</li>
  <li><strong>Steep pitch and the height adder carry no material</strong>, so they are effectively all margin. This is why the crew note tells everybody to check for them on every walkthrough.</li>
</ul>

<h3>Churn does not break the model</h3>
<p>Per 100 two-year customers at the reference size: at full renewal the book blends to about 56%; at a 75% renewal rate, which is the industry norm, about 54%; at 50%, about 51%; and <strong>even if nobody renews at all it still stands at roughly 41%</strong>. The two-year term is not a bet that everybody stays.</p>

<h3>Three conclusions worth acting on</h3>
<ul>
  <li><strong>Small jobs are the thin spot.</strong> At the job minimum, year one runs near 35%. The two-year term is what makes a small house worth doing — never write a small one as a single season at the two-year rate.</li>
  <li><strong>Wreaths need the term more than anything else does.</strong></li>
  <li><strong>The premiums are free money</strong> and the most commonly forgotten line on a walkthrough.</li>
</ul>

<h3>Where the real numbers live</h3>
<p>Every price a crew or a customer sees comes from <strong>Business Settings → Holiday Lighting</strong>, and both estimators read it. Change a rate there and it changes on the public page, the crew estimator and the service page at once. Nothing on this page is used to calculate anything — it is background for pricing decisions.</p>
HTML;

$oldCrew = (string) $term->get('field_service_crew_desc')->value;
$oldOffice = (string) $term->get('field_service_office_desc')->value;
printf("  crew   %d -> %d chars\n", strlen($oldCrew), strlen($CREW));
printf("  office %d -> %d chars\n", strlen($oldOffice), strlen($OFFICE));

// Refuse to publish a markdown artefact into a full_html field (2026-10-03).
foreach (['crew' => $CREW, 'office' => $OFFICE] as $which => $html) {
  if (str_contains($html, '**')) {
    print "ERROR: literal ** in the $which copy — it would render as asterisks. Aborting.\n";
    return;
  }
}
// Every internal link must EXIST. Deliberately not path.validator->isValid():
// that also runs an access check as the CURRENT user, and drush runs as
// anonymous — who is correctly denied this crew-only route, so isValid()
// returns FALSE for a link that is perfectly good. Asking the router whether
// the route exists is the question actually being asked. (Same family as the
// 2026-10-03 credentials guard, where getPathByAlias() was used as a
// reachability check and was not one.)
foreach ([
  '/teammates/holiday-estimate' => 'bos_service_request.holiday_estimate',
] as $link => $route) {
  try {
    \Drupal::service('router.route_provider')->getRouteByName($route);
  }
  catch (\Throwable $e) {
    print "ERROR: route $route behind $link does not exist. Aborting.\n";
    return;
  }
  // And it must NOT be open to the public — this is crew copy pointing at a
  // crew tool, so anonymous access would be the defect, not the link.
  $anon = \Drupal\user\Entity\User::load(0);
  $open = $anon && $anon->hasPermission('use holiday lighting estimator');
  printf("  link ok: %-30s route exists, anonymous access: %s\n", $link, $open ? 'OPEN — unexpected' : 'denied (correct)');
  if ($open) {
    print "ERROR: the crew estimator is anonymously accessible. Aborting.\n";
    return;
  }
}

if (!$apply) {
  print "\n(dry-run — nothing written)\n";
  return;
}

$backup = sprintf('%s/docs/backups/holiday-crew-office-%s.json', DRUPAL_ROOT . '/..', date('Ymd-His'));
@mkdir(dirname($backup), 0775, TRUE);
file_put_contents($backup, json_encode([
  'term' => $term->id(),
  'field_service_crew_desc' => $oldCrew,
  'field_service_office_desc' => $oldOffice,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
print "  backup: $backup\n";

$term->set('field_service_crew_desc', ['value' => $CREW, 'format' => 'full_html']);
$term->set('field_service_office_desc', ['value' => $OFFICE, 'format' => 'full_html']);
$term->save();
\Drupal\Core\Cache\Cache::invalidateTags(['taxonomy_term:' . $term->id()]);
print "  saved.\n";
