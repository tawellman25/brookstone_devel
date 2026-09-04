<?php

/**
 * Fall Cleanup landing page — config + editable body copy. Idempotent; run per
 * env. (1) Adds the goog26-fall campaign code + the fall_cleanup bundle settings
 * to bos_service_request.settings (editable config, NOT cim). (2) Loads the
 * approved "How we do it" body into the Fall Cleanup services term's
 * field_service_public_desc (editable in the admin UI — no deploy to fix a typo).
 *
 *   ddev drush php:script web/scripts/setup_fall_cleanup_page.php
 */

// ── Fall Cleanup services term ───────────────────────────────────────────────
$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$found = $ts->loadByProperties(['vid' => 'services', 'name' => 'Fall Cleanup']);
$term = $found ? reset($found) : NULL;
if (!$term) {
  print "!! Fall Cleanup services term not found — aborting\n";
  return;
}
$termId = (int) $term->id();
print "Fall Cleanup services term: {$termId}\n";

// ── 1. Config: campaign allowlist + bundle settings ──────────────────────────
$config = \Drupal::configFactory()->getEditable('bos_service_request.settings');
$campaigns = $config->get('campaigns') ?? [];
if (!in_array('goog26-fall', $campaigns, TRUE)) {
  $campaigns[] = 'goog26-fall';
  $config->set('campaigns', $campaigns);
  print "added campaign: goog26-fall\n";
}
$bundles = $config->get('bundles') ?? [];
if (empty($bundles['fall_cleanup'])) {
  $bundles['fall_cleanup'] = [
    'service_term_id' => $termId,
    'service_year' => 2026,
    'signup_open' => TRUE,
    'open_from' => '2026-09-01',
    'open_until' => '2026-12-01',
    'service_year_start' => '2026-09-01',
    'service_year_end' => '2027-01-31',
    'scheduling_notice' => 'Routes fill from late October through the first snow.',
    'closed_notice' => 'Fall cleanup signup for this season is closed. Call the office at 970-835-9661 and we will tell you what is still possible.',
  ];
  $config->set('bundles', $bundles);
  print "added bundle settings: fall_cleanup (service_term_id={$termId})\n";
}
$config->save();

// ── 2. Editable body copy → field_service_public_desc ────────────────────────
// The "How we do it" body (copy SECTION 4). Rendered as accordions by
// _bos_service_request_accordions() (splits on <h3>). Intro paragraph first,
// then one <h3> subsection per accordion. Internal links use the real paths.
$body = <<<'HTML'
<p>A fall cleanup is not a tidiness service. What you do to a yard in October and November decides what it looks like in April. Turf that goes into winter buried under wet leaves does not come out of winter. Beds that go in full of weeds come out with ten times as many. The work is cheap now and expensive later, which is the whole argument for doing it.</p>

<h3>What leaves actually do to a lawn</h3>
<p>A layer of leaves on turf does not decompose over a Western Slope winter. It gets rained on, snowed on, and pressed flat, and it turns into a wet mat that seals the grass underneath away from air and light.</p>
<p>Two things happen under that mat. The grass suffocates, and snow mold sets up — a gray or pink fungal growth that lives in exactly those conditions, matted debris and long grass under prolonged snow cover. You will not see it in December. You will see it in the first week of April, as flat straw-colored patches that stay dead while the rest of the lawn greens up around them.</p>
<p>Reseeding those patches in the spring costs more than the cleanup did.</p>
<p>The nuance worth knowing: leaves are a problem <strong>on turf</strong>. Leaves left in a planting bed are not a problem at all — a light layer of leaf litter insulates perennial crowns and gives overwintering pollinators somewhere to be. We clear the lawn thoroughly and we do not strip your beds down to bare dirt unless you want that. Tell us which you prefer.</p>

<h3>Fallen fruit is a different problem</h3>
<p>This is orchard country, and a lot of the properties we work on have apple, pear, plum, cherry or peach trees that are older than the house.</p>
<p>Fruit that drops and stays on the ground draws bears, deer and raccoons, and it draws them to a specific address — yours. Colorado Parks and Wildlife has said the same thing for years: pick up the drop. On the Surface Creek side especially, a yard full of windfall apples in October is an invitation, and the bear does not leave after the fruit is gone.</p>
<p>Fallen fruit also ferments, kills the turf underneath it, and carries next season's disease and insect problems into the ground under the tree. We haul it off with the rest of the debris.</p>

<h3>What we cut back, and what we leave standing</h3>
<p>Not everything should be cut down in the fall, and a crew that cuts everything to the ground is not doing you a favor.</p>
<p><strong>We cut back:</strong> peonies, hostas, daylilies, iris, and anything else that turns to mush and holds disease over the winter. Iris in particular — cutting the foliage back removes where iris borer overwinters. Anything with visible powdery mildew or leaf spot comes out and goes on the truck, not in your compost.</p>
<p><strong>We leave standing:</strong> ornamental grasses, coneflower, black-eyed Susan, sedum, and most seed heads. Grass crowns come through winter better with the top growth left on to protect them, and they look like something in January when nothing else does. The seed heads feed birds. We cut those back in early spring instead, and we would rather do that than leave you with a flat brown rectangle for five months.</p>
<p>If you want it all cut down, we will cut it all down. But we will tell you what we would do first.</p>

<h3>The last mow of the year</h3>
<p>The final cut of the season comes down slightly — around two to two and a half inches for the bluegrass and fescue lawns we mostly see here.</p>
<p>The reason is snow mold again. Grass left tall going into winter lies over, mats down under snow, and gives the fungus exactly the conditions it wants. Grass cut too short is worse — a scalped lawn goes into winter with no reserves and comes out thin. There is a correct height, it is not dramatic, and it matters.</p>
<p>We time the last mow to actual growth, not to the calendar.</p>

<h3>Aeration and the last feeding</h3>
<p>If we are out in <strong>September or early October</strong>, core aeration is the single best thing you can do for a cool-season lawn, and fall is the right season for it. Pulling plugs relieves compaction and opens the soil right when the grass is putting its energy into roots instead of blades. Paired with a late-season fertilizer application, it is most of the reason some lawns on this valley floor look better every year while their neighbors look worse.</p>
<p>If we are out in <strong>November</strong>, we will tell you to wait. Aerating into cold ground on dormant turf accomplishes very little, and we would rather book you for spring than take your money for a job that will not do anything. That is a real answer, not a sales position.</p>

<h3>What we do not do in the fall</h3>
<p>We do not do heavy pruning in September or October, and you should be careful about anyone who offers to.</p>
<p>Cutting a tree or shrub hard in early fall tells it to push new growth, and that new growth does not have time to harden off before the first hard freeze. You lose the growth and you can lose more than the growth. The right window for structural pruning on most deciduous trees and shrubs here is <strong>late winter — February into March</strong> — when the plant is fully dormant, the structure is visible with the leaves off, and the cuts have the whole spring to close.</p>
<p>The exceptions are dead, damaged, diseased, or hazardous limbs. Those come off whenever we find them, including during a fall cleanup, and we will point them out while we are there.</p>
<p>If you want pruning done, we will put you on the late-winter list.</p>

<h3>When to schedule it</h3>
<p>The window is narrower than people expect. You want the cleanup after leaf drop is mostly finished but before the ground freezes and the snow arrives — on this side of the valley that is generally <strong>late October into mid-November</strong>, moving a week or two either direction depending on the year and your elevation.</p>
<p>Book early anyway. The properties that get done in the good weather are the ones that were on the list in September. The ones that call after the first storm get cleaned up in the cold, in the mud, or in the spring.</p>

<h3>Where the debris goes</h3>
<p>On the truck and off your property. Leaves, cuttings, weeds, fallen fruit, and whatever else came out of the beds — hauled off and disposed of, included in the price we quoted you.</p>
<p>We do not leave bagged piles at the curb for you to deal with, and we do not build a pile behind the shed that you find in July. If you want the leaves kept for your own compost, say so and we will stage them where you want them.</p>

<h3>Do you need to be home?</h3>
<p>No. If we can get to the yard, we can do the work. Most cleanups happen with nobody home.</p>
<p>Tell us about gates, dogs, sprinkler heads in odd places, anything locked, and anything in the yard you do not want moved. That is what the access notes field on the form is for, and filling it in properly is the difference between a clean visit and a phone call.</p>

<h3>How we price it</h3>
<p>Fall cleanup is quoted per property. There is no honest flat rate for it, because the difference between a small lot with one maple and an acre under mature cottonwoods is not a small difference — it is the difference between an hour and most of a day, and one trailer load versus four.</p>
<p>What we price on: the size of the area being cleared, how many mature trees are dropping on it, how much bed work there is, and how much volume we are hauling away.</p>
<p>We will look at it and give you a firm number before we start. If you are already on one of our maintenance routes, we can usually quote it without a visit.</p>

<h3>Do it the same week we winterize your sprinklers</h3>
<p>This is the one thing on this page that will save you money without you doing anything.</p>
<p>We are already going to be at your property in October to blow out your sprinklers. If the cleanup is booked for the same week, it is one truck, one trip, and one setup instead of two — and we would rather do it that way than drive out to your address twice.</p>
<p>There is a checkbox for it on the form above. If your sprinklers are already scheduled, just tell us and we will line the two up.</p>
<p><a href="/winterize">Sprinkler winterization →</a></p>

<h3>While we are there</h3>
<p>Two other things worth thinking about in October, both of which are easier before the ground freezes:</p>
<p><strong><a href="/services/snow-removal">Snow removal.</a></strong> Contracts written in October get scheduled first when the first storm lands. Contracts written in December get what is left. Residential drives, commercial lots, HOA common areas — seasonal or per-push.</p>
<p><strong><a href="/lighting/landscape-lighting">Landscape lighting.</a></strong> Low-voltage lighting is the improvement you use most during the months you are outside least, and running wire is a great deal easier before the ground is frozen.</p>
HTML;

$term->set('field_service_public_desc', ['value' => $body, 'format' => 'full_html']);
$term->save();
print "loaded body copy into term {$termId} field_service_public_desc (" . strlen($body) . " bytes)\n";
print "DONE\n";
