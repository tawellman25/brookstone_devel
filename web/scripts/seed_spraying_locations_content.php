<?php

declare(strict_types=1);

/**
 * Seed the 18 Spraying Locations children — verbatim copy from
 * "Spraying Locations - the 18 remaining children.md".
 *
 * Per term: field_short_description (public one-line lead), field_public_description
 * (public body), field_teammate_description (crew, teammate_view only). The legacy
 * core `description` is cleared on these 18 so it does not double-render under the
 * new public body (the public display keeps `description` only so Arena/Driveway —
 * NOT in this file, NOT touched — keep rendering their own good copy).
 *
 * Terms matched by URL-alias slug, so environment-independent. Idempotent. Arena
 * and Driveway are deliberately absent → never modified. A backup of every
 * overwritten field is written first.
 *
 *   drush php:script web/scripts/seed_spraying_locations_content.php
 */

use Drupal\Core\Cache\Cache;

$etm = \Drupal::entityTypeManager();
$vid = 'spraying_locations';

$DATA = [];

$DATA['lawn'] = [
  'short' => 'Broadcast weed control across turf. The product has to kill what is growing in the grass without killing the grass, which is a narrower job than it sounds.',
  'public' => <<<'HTML'
<p>Treating a lawn means using a selective herbicide — one that kills broadleaf weeds and leaves the grass standing. It works because grasses and broadleaf plants grow differently enough that a product can target one and not the other.</p>

<p>That is also its limit. A selective product will not touch grassy weeds, because to the chemistry they are grass. Crabgrass, foxtail and quackgrass need a different approach and often a different season.</p>

<h3>Timing matters more than product</h3>

<p>Weeds absorb best when they are actively growing, which means a treatment lands better in spring and fall than in the heat of a dry July. And a lawn sprayed the day before it is mowed loses much of the application to the clippings — we would rather come a day or two after the mow.</p>

<h3>What to expect afterward</h3>

<p>Broadleaf weeds curl and twist before they brown, which takes several days to a couple of weeks depending on the weed and the weather. Nothing disappears overnight, and a lawn that looks unchanged after two days is not a failed application.</p>

<p>We give you a re-entry interval before we leave — the time before children and pets should be back on the grass. It is on the label and it is not a suggestion.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Check when it was last mowed and when it will be next.</strong> Spraying the day before a mow wastes the application. Note it on the work order if you had to go anyway.</li>
  <li><strong>Selective only.</strong> Never a non-selective product on turf, and watch what is in the tank from the last job.</li>
  <li><strong>Growth stage drives the result.</strong> Dormant or drought-stressed turf and weeds are not taking anything up. If the lawn is off-colour and crunchy, flag it rather than spraying it.</li>
  <li><strong>Give the customer the re-entry interval in words before you leave</strong>, not just on the paperwork. Kids and dogs.</li>
</ul>
HTML,
];

$DATA['spot-spray-lawn'] = [
  'short' => 'Treating the weeds rather than the lawn. Less product on the property, and the right call when the weed pressure is scattered rather than general.',
  'public' => <<<'HTML'
<p>A spot treatment targets individual weeds and clumps instead of covering the whole lawn. On a property where the pressure is light or patchy, it puts a fraction of the product down for the same result.</p>

<p>It costs labour rather than chemical — a tech walking the lawn with a wand takes longer than a broadcast pass. On a small lawn or a lightly infested one that trade is worth making. On a lawn that is more weed than grass it is not, and we will tell you so.</p>

<h3>Why we would often rather do this</h3>

<p>Less herbicide on your property is better for the lawn, better for what borders it, and better for the people and animals using it. Where a spot treatment will do the job, it is the honest recommendation even though the invoice looks similar.</p>

<p>The usual pattern on a maintained property is a broadcast application early in the season and spot treatments after, which keeps the total product down over a year.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Judge the ratio before you start.</strong> Past roughly a third coverage, spot spraying takes longer than broadcast and uses comparable product. Call the office rather than spending two hours on it.</li>
  <li><strong>Mark what you treated</strong> if the customer is likely to walk it — untreated weeds nearby look like a miss rather than a choice.</li>
  <li>Same re-entry interval applies even though less went down. Treated area is treated area.</li>
</ul>
HTML,
];

$DATA['landscape-beds'] = [
  'short' => 'The hardest place to spray well. There is no selective product that separates a weed from an ornamental, so it comes down to placement and to stopping weeds before they emerge.',
  'public' => <<<'HTML'
<p>In a lawn the chemistry does the discriminating. In a planted bed nothing does — a product that kills a weed will kill a perennial just as efficiently, because both are broadleaf plants.</p>

<p>So bed work is about placement. A directed wand, held low, moving deliberately, treating the weed and not what is next to it. It is slow, and the slowness is the whole point.</p>

<h3>The real tool is the pre-emergent</h3>

<p>The most effective thing we do in beds happens before anything is visible. A pre-emergent forms a barrier in the top layer of soil that stops weed seed from establishing — it does nothing to what is already growing, which is why timing it matters so much and why it is applied early.</p>

<p>A bed on a pre-emergent program needs far less spraying through the season, which means far less product going down next to plants you paid for.</p>

<h3>What we will not do</h3>

<p>Blanket-spray a bed. If a bed is overwhelmed to the point where directed treatment is impractical, the honest answer is hand weeding, renovation, or a conversation about what is planted there — not a product decision.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Directed wand, low pressure, coarse droplets.</strong> Pressure is what turns a bed application into a drift event twelve inches from a plant.</li>
  <li><strong>Identify before you spray.</strong> Volunteer perennials and self-seeded ornamentals are not weeds, and the customer knows which is which even when we do not. If in doubt, leave it and ask.</li>
  <li><strong>Mulch depth tells you whether a pre-emergent is worth it.</strong> Note thin or bare beds for the office — that is a mulch conversation and a cheaper long-term answer than repeat spraying.</li>
  <li><strong>Never blanket a bed.</strong> If it needs that, it needs hand work. Say so.</li>
</ul>
HTML,
];

$DATA['spot-spray-beds'] = [
  'short' => 'Targeted treatment of individual weeds inside a planted bed, with a directed wand and nothing hitting the plants around them.',
  'public' => <<<'HTML'
<p>Effectively the only way to treat an established bed safely. Each weed is treated individually with a directed wand rather than anything being covered.</p>

<p>Where a bed is dense — mature perennials, groundcover, anything with foliage touching — even a wand becomes risky, and hand pulling is the better answer. A crew that pulls a dozen weeds in a tight bed rather than spraying them is making the right call, not avoiding work.</p>

<p>Beds on a pre-emergent program need this much less often. That is the argument for the program.</p>
HTML,
  'teammate' => <<<'HTML'
<p>See the Landscape Beds entry — same rules, tighter. Directed wand, low pressure, identify before you spray.</p>
<ul>
  <li><strong>In a dense bed, pull rather than spray.</strong> Faster than explaining a dead hosta, and the customer notices the difference in your favour.</li>
  <li>Watch the wind even in a bed — twelve inches is enough distance for a fine droplet to reach the wrong plant.</li>
</ul>
HTML,
];

$DATA['shrubs'] = [
  'short' => 'Weed control in and around shrub plantings, where the thing you are protecting is close enough to the thing you are treating that wind and droplet size decide the outcome.',
  'public' => <<<'HTML'
<p>Shrub beds sit between lawn and landscape bed in difficulty. There is more room to work than in a tight perennial planting, and the plants are large enough that damage shows for years rather than a season.</p>

<p>A shrub that takes drift does not die immediately. It puts out distorted growth the following spring, and by the time anybody connects that to a spray application months earlier it has usually been blamed on something else. Which is why we are careful here in ways that are not visible on the day.</p>

<h3>What that means on the ground</h3>

<p>Directed application low to the soil, coarse droplets, and a hard stop on wind. Where shrubs are close-planted or the wind is wrong, the work moves to another part of the property and comes back.</p>

<p>Mulch and a pre-emergent do more for a shrub bed than repeat spraying does, and they carry none of this risk.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Damage here is delayed and expensive.</strong> Distorted growth shows up next spring, not this week, and a mature shrub is a real replacement cost.</li>
  <li><strong>Spray low and coarse.</strong> Keep the wand below the foliage line.</li>
  <li><strong>Wind is a hard stop in shrub beds</strong>, tighter than on open turf. If you would not do it next to a vegetable garden, do not do it here.</li>
  <li>Green bark on young shrubs takes up product the same way a leaf does. Watch the base.</li>
</ul>
HTML,
];

$DATA['tree-rings'] = [
  'short' => 'The mulched circle at the base of a tree. It exists to keep mowers and string trimmers away from the trunk, and keeping it clear without damaging the tree takes some care.',
  'public' => <<<'HTML'
<p>A tree ring is not decoration. It is a buffer that keeps equipment away from the trunk, and the damage it prevents is the reason it is worth maintaining.</p>

<p>String trimmer injury at the base of a tree is one of the most common and least recognised causes of decline we see. Repeated nicking of the bark girdles the tree slowly, and a tree that dies from it usually dies four or five years after the damage was done.</p>

<h3>Why the trunk still needs care during a treatment</h3>

<p>Young trees and many ornamentals have thin green bark, and green bark absorbs herbicide much the way a leaf does. A product sprayed at the base of a young tree can move into it even though nothing touched a leaf.</p>

<p>So treatments in a ring stay low, directed, and off the trunk. On young trees we often stay further back and pull what is close in.</p>

<h3>The better answer</h3>

<p>Mulch, three to four inches deep, pulled back from the trunk rather than piled against it. A properly mulched ring suppresses most of what would otherwise need spraying, holds moisture through a dry July, and keeps the trimmer away.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Green bark absorbs.</strong> Young and thin-barked trees take up product through the trunk. Stay off the bark, and on anything young, pull rather than spray within a hand's width of the trunk.</li>
  <li><strong>Note trimmer damage when you see it</strong> and tell the office. That is a real finding and a conversation worth having with the customer — it is usually their mower crew or their own trimmer.</li>
  <li><strong>Mulch volcanoes get reported too.</strong> Mulch piled against a trunk rots bark and invites the same slow decline.</li>
</ul>
HTML,
];

$DATA['gravel'] = [
  'short' => 'Gravel drives, parking areas and yards. Nothing there is worth keeping, so the product can be stronger and longer-lasting — which makes the edges the part that needs the care.',
  'public' => <<<'HTML'
<p>Gravel is one of the few places where nothing growing is wanted, which means a non-selective product with real residual can be used. One treatment can hold a gravel area for much of a season.</p>

<p>That strength is exactly why the boundary matters. A residual product does not stop at the edge of the gravel if water carries it, and the place it shows up is the lawn or the bed it drains toward.</p>

<h3>What we look at before choosing</h3>

<p>What borders each side, and which way the ground falls. An isolated gravel yard with nothing downhill can take a long-residual treatment and be done for the season. A gravel strip beside a lawn on a slope cannot, and gets a shorter-residual product and more visits instead.</p>

<p>Tree roots run under gravel more often than people expect, and a residual product over the root zone of something you want to keep is a slow problem. That gets checked too.</p>

<h3>Why gravel grows weeds at all</h3>

<p>Dust and organic matter settle into the voids over years until there is effectively soil in there. That is also why a gravel area that has not been refreshed in a decade is harder to keep clean than one that has.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Look at what is downhill before you pick a product.</strong> Residual plus slope plus the customer's lawn is how we buy a reseeding job.</li>
  <li><strong>Check for tree roots under the gravel.</strong> Long residual over the root zone of a mature tree is a problem that shows up two seasons later.</li>
  <li><strong>Edges get a directed wand</strong>, not the boom. The brown stripe along a lawn edge is the single most visible mistake in this trade.</li>
  <li>Note gravel that has silted up badly — it will keep coming back and a refresh is the actual fix.</li>
</ul>
HTML,
];

$DATA['parking-lot'] = [
  'short' => 'Commercial paved areas. Straightforward chemistry, with the complications being storm drains, access, and working around vehicles and people.',
  'public' => <<<'HTML'
<p>Weeds in a parking lot grow in cracks, along curbs, in islands and at the edges — anywhere debris has collected enough to hold a seed. A non-selective treatment handles it, and the product choice is rarely the hard part.</p>

<h3>The hard part is the storm drain</h3>

<p>A paved lot is a collection surface. Everything applied to it stays on the surface until it rains, and then it goes wherever the lot drains — which on most commercial sites is a storm inlet, and from there into a ditch or a creek without treatment.</p>

<p>That drives the timing. We do not treat a lot with rain in the forecast, we keep product out of the inlets and their immediate surrounds, and on lots that drain to open water the product choice changes.</p>

<h3>Scheduling around the business</h3>

<p>A lot full of cars cannot be treated properly, and a lot that needs a re-entry interval cannot be full of customers an hour later. Commercial work here usually happens early morning or on a closed day, arranged in advance rather than dropped on a property manager.</p>

<p>Every application is documented — product, area, conditions, timing — which is what a property manager needs for their own records.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Find the drains first.</strong> Walk the lot and locate every inlet before you start. Nothing goes in or immediately around them.</li>
  <li><strong>Check the forecast.</strong> Rain within the label's rainfast window on an impervious surface means the application ends up in the storm system. Reschedule.</li>
  <li><strong>Confirm access and timing with the property manager</strong>, not with whoever is at the front desk. Re-entry intervals on a commercial lot are a liability question.</li>
  <li>Islands and landscape strips inside a lot are beds. Different product, different rules — do not carry the lot product into them.</li>
</ul>
HTML,
];

$DATA['sidewalk-cracks'] = [
  'short' => 'Weeds growing in expansion joints and cracks. A small job with an outsized runoff consideration, because a crack in a hard surface is a drainage path.',
  'public' => <<<'HTML'
<p>Cracks and joints collect exactly what a weed needs — grit, organic matter and water that lingers after the surface has dried. That is why the same few feet of sidewalk grows weeds every year while the slab beside it never does.</p>

<h3>Why a small job gets real attention</h3>

<p>A sidewalk is impervious, so anything applied to it does not soak in. It sits there until water moves it, and then it travels along the surface to whatever the surface drains into — a lawn edge, a gutter, a storm inlet.</p>

<p>So these are treated with a directed wand, in the crack rather than across the slab, with nothing broadcast. Precision here is a runoff decision, not a tidiness one.</p>

<h3>The permanent fix is not chemical</h3>

<p>A joint that grows weeds every season is a joint that needs sealing. Spraying it is managing a symptom, and if that is what is happening on your property we would rather tell you than keep coming back.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>In the crack, not across the slab.</strong> Directed wand, low pressure, minimum volume.</li>
  <li><strong>Impervious surface means no absorption</strong> — everything you put down is available to move the next time it rains. Check the forecast and check where the surface drains.</li>
  <li><strong>Pedestrian traffic.</strong> Sidewalks are walked on by people who did not hire us. Mind the re-entry interval and the timing of day.</li>
  <li>Recurring crack weeds get reported as a sealing recommendation. We are not paid to spray the same joint for five years.</li>
</ul>
HTML,
];

$DATA['parking-lot-cracks'] = [
  'short' => 'Weeds in the joints and cracks of a paved lot. Same job as sidewalk cracks, usually more of it, and with vehicle traffic and storm drains in the picture.',
  'public' => <<<'HTML'
<p>Cracks are where a parking lot starts to fail, and weeds accelerate it. Roots widen a crack, water gets deeper into it, and the freeze-thaw cycle this ground delivers every winter does the rest.</p>

<p>Treating them is directed and precise — in the joint, not over the asphalt — both because that is where the weeds are and because anything on the surface of a paved lot will move to the nearest storm inlet the next time it rains.</p>

<h3>Worth knowing about the lot itself</h3>

<p>A crack that supports plant growth is a crack holding water and soil, which means it is already past the point where sealing would have been cheap. Persistent weed growth across a lot is usually a sign the surface is due for attention, and we will say so rather than quietly spraying it every season.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li>Same rules as sidewalk cracks: directed, in the joint, minimum volume, storm inlets mapped first.</li>
  <li><strong>Vehicle traffic and re-entry.</strong> Coordinate with the property manager rather than working around moving cars.</li>
  <li><strong>Report lot condition.</strong> Widespread crack growth is a surface problem, and telling the property manager is worth more than the spray was.</li>
</ul>
HTML,
];

$DATA['pathway'] = [
  'short' => 'Walking paths through a property — concrete, pavers, flagstone, gravel or bare ground. The surface decides the treatment, and the edges decide how careful it has to be.',
  'public' => <<<'HTML'
<p>Paths are narrow, which means almost all of a path is edge, and edges are where a non-selective product causes damage that shows.</p>

<p>A concrete or paver path through a lawn is a directed application into the joints, nothing more. A gravel path with nothing planted along it can take a broader treatment. Flagstone with plantings between the stones is closer to bed work than to hard surface work, and is often better hand-weeded.</p>

<h3>What we check first</h3>

<p>What the path is made of, what is planted along it, whether it is used barefoot — a path to a pool or a patio changes the re-entry conversation — and whether it drains toward anything that matters.</p>

<p>Where a path is heavily jointed and weeds come back every year, polymeric sand or joint sealing solves it permanently and we would rather point you at that.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Identify the surface and what borders it before choosing anything.</strong> A path is mostly edge.</li>
  <li><strong>Plantings between stones mean bed rules</strong>, or hand weeding. Not a non-selective pass.</li>
  <li><strong>Ask about barefoot use.</strong> Pool surrounds and patio paths change the re-entry conversation, and the customer will not think to mention it.</li>
</ul>
HTML,
];

$DATA['pasture'] = [
  'short' => 'Grazing ground, where weed control is about forage quality and animal safety as much as appearance — and where re-entry and grazing restrictions are the part that matters most.',
  'public' => <<<'HTML'
<p>Weeds in a pasture are not an appearance problem. They take water, light and ground from the forage you are trying to grow, and some of them are dangerous to the animals grazing there.</p>

<h3>The restrictions are the job</h3>

<p>Pasture products carry grazing restrictions, and they are specific: how long before livestock can return, whether the interval differs for lactating animals, and whether hay cut from treated ground can be fed or sold. Those intervals are on the label and they are enforceable.</p>

<p>We ask what is grazing, when, and whether the ground gets cut for hay, before anything is selected — because the answers change the product. We give you the intervals in writing and we record what grazed there on the application.</p>

<h3>Selective, usually</h3>

<p>The goal is normally to remove broadleaf weeds and leave the grass, which means a selective product. Worth knowing: clover and alfalfa are broadleaf. A product that cleans up the weeds will take the legumes with it, and on a pasture where the clover is doing real work that is a trade to make deliberately rather than discover afterward.</p>

<h3>And the ones you are required to control</h3>

<p>Colorado's noxious weed list is state law. List A species carry mandatory eradication from all lands in the state, and List B species must be managed under a plan set with local government. Pasture ground is where these turn up most, and the obligation sits with the landowner whether or not anybody has mentioned it.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Ask before you mix: what grazes here, when do they come back, is it cut for hay?</strong> Grazing restriction and hay restriction are different numbers and both are on the label.</li>
  <li><strong>Lactating animals often carry a separate, longer interval.</strong> Do not assume one number covers the herd.</li>
  <li><strong>Warn about clover and alfalfa</strong> before spraying, not after. A selective broadleaf product removes them, and on some pastures that is the customer's feed value.</li>
  <li><strong>Record livestock presence on the application.</strong> Not optional on this ground.</li>
  <li><strong>Flag suspected noxious species to the office</strong> with a photo. List A is a legal obligation for the landowner and it is a real conversation, not an upsell.</li>
</ul>
HTML,
];

$DATA['vacant-lot'] = [
  'short' => 'Undeveloped or unoccupied ground. Usually a weed-seed source for everything around it, and sometimes a legal obligation the owner does not know they have.',
  'public' => <<<'HTML'
<p>An untended lot is a nursery. Weeds there set seed and every neighbouring property downwind inherits it, which is why a well-kept yard next to an empty lot never quite stays clean.</p>

<h3>The part most owners do not know</h3>

<p>Colorado's noxious weed list is state law and it applies to all land, including ground nobody is using. <strong>List A species carry mandatory eradication statewide.</strong> List B species must be managed under a plan set by the state in consultation with local government. Delta and Montrose counties both run weed programs that enforce this.</p>

<p>An owner who has never visited the lot still holds the obligation, and the first they usually hear about it is a letter from the county.</p>

<h3>What treating one actually involves</h3>

<p>Vacant ground is rougher work than a maintained property — bigger plants, uneven footing, no irrigation, and frequently a mix of species rather than one problem. It often takes more than one visit in a season, and on a badly established lot mowing before spraying is the cheaper first step.</p>

<p>If you are holding land in either county and have never had it looked at, that is worth doing before somebody else raises it.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Photograph and report anything you suspect is List A or B.</strong> That is a compliance conversation for the owner and the county may already be involved.</li>
  <li><strong>Walk it before quoting.</strong> Vacant ground hides holes, debris, old fencing and wells. Footing and hazards first.</li>
  <li><strong>Check what borders it on every side</strong> — vacant lots are usually surrounded by ground somebody cares about.</li>
  <li>Heavy established growth is a mow-then-spray job. Spraying four-foot weeds wastes product.</li>
</ul>
HTML,
];

$DATA['roadside'] = [
  'short' => 'Frontage, ditch banks and right-of-way. Open ground with traffic on one side and somebody else\'s property on the other, which narrows the conditions we will work in.',
  'public' => <<<'HTML'
<p>Roadside strips are where noxious weeds travel. Vehicles, road maintenance equipment and moving air carry seed along a corridor, which is why the worst weed pressure on many rural properties is along the road rather than in the middle.</p>

<h3>Why it is treated carefully</h3>

<p>A roadside has traffic on one side and property on the other, and often a ditch running through it. All three constrain the work.</p>

<p>Ditches carry irrigation water to somebody downstream, so products near them have to be chosen for that. Neighbouring property means drift matters more here than almost anywhere. And moving vehicles mean the crew is visible, slow, and needs to be seen — which shapes when we do it as much as how.</p>

<h3>Whose ground is it</h3>

<p>Right-of-way boundaries are rarely where people assume, and the county or the state may control a strip the owner believes is theirs. On county and state right-of-way there may already be a management program running. We check before treating rather than after.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Confirm the right-of-way boundary before you spray.</strong> Do not treat county or state ground on a residential work order.</li>
  <li><strong>Irrigation ditches.</strong> Water in that ditch belongs to somebody downstream. Product selection near it is a label question and it is not flexible.</li>
  <li><strong>Drift discipline is tighter here.</strong> Neighbouring property on one side, public road on the other.</li>
  <li><strong>Visibility and safety.</strong> Vest on, vehicle positioned to protect the crew, and not at dusk.</li>
  <li>Roadsides are the best early-warning line for noxious species moving into an area. Report what you see.</li>
</ul>
HTML,
];

$DATA['retention-pond'] = [
  'short' => 'Detention and retention basins, and the ground around them. The most label-restricted site we treat, because almost nothing ordinary is permitted near open water.',
  'public' => <<<'HTML'
<p>Retention and detention basins exist to hold water, which makes them the most restricted ground we work on.</p>

<h3>Most products are prohibited here</h3>

<p>A product used in or near water has to be specifically labeled for aquatic use. The great majority are not, and applying one that is not — even to dry ground that will hold water later — is a violation of federal law, because using a pesticide inconsistently with its labeling is an offence regardless of intent.</p>

<p>That rule does not stop at the waterline. A basin is designed to collect runoff from everything around it, so the ground on the slopes is a delivery path into the water whether or not it is wet on the day.</p>

<h3>What that means in practice</h3>

<p>Aquatic-labeled products where they are needed, buffer distances observed, and on many basins the honest answer being mechanical control rather than chemical. A basin kept clear by mowing is a basin nobody has to worry about.</p>

<p>Basins on commercial and HOA property frequently sit under a stormwater permit with its own conditions. We ask before treating, and we document every application — which is what a property manager needs when somebody asks.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Aquatic label or you do not spray it.</strong> No exceptions, no judgment calls, no "it is dry today." A basin is designed to hold water.</li>
  <li><strong>The slopes count.</strong> Everything around a basin drains into it. Treat the whole bowl as the water body.</li>
  <li><strong>Ask whether the site is under a stormwater permit.</strong> Commercial and HOA basins often are, and the permit may carry its own conditions.</li>
  <li><strong>When in doubt, recommend mowing.</strong> Mechanical control near water is always defensible and often cheaper.</li>
  <li>Photograph and document everything on these. This is the site type where a question is most likely to arrive months later.</li>
</ul>
HTML,
];

$DATA['entire-area'] = [
  'short' => 'The whole property treated rather than selected zones. What that means in practice depends entirely on what the property is made of.',
  'public' => <<<'HTML'
<p>Entire Area on a work order means the whole property was covered rather than particular zones — but it does not mean one product went everywhere. A property with lawn, beds, a gravel drive and a fence line needs four approaches, and all four happened.</p>

<h3>What it does not mean</h3>

<p>It is not a blanket non-selective application. The turf gets a selective product, the beds get directed work, the hard surfaces get something stronger, and the transitions between them get the slowest attention of all.</p>

<h3>When it is the right call</h3>

<p>On a property with general weed pressure across most of it, whole-property treatment is more efficient than treating zones and coming back. On a property where the problem is confined — a fence line, a drive — treating everything is spending money and putting product where neither is needed.</p>

<p>We will tell you which of those your property is.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Entire Area is a scope note, not a product decision.</strong> Each surface still gets what belongs on it.</li>
  <li><strong>Log the actual products and areas</strong> — "entire area" alone is not a record, and the notice needs the detail.</li>
  <li>If the pressure was really only in one zone, say so on the work order. Billing a whole-property pass on a property that needed a fence line is how accounts get cancelled.</li>
</ul>
HTML,
];

$DATA['other-see-description'] = [
  'short' => 'A site that does not fit the standard categories. The work order description carries the detail — this is used deliberately rather than as a shortcut.',
  'public' => <<<'HTML'
<p>Properties are not standard. A greenhouse floor, a storage yard, the ground under a deck, a riverbank, a cemetery plot, the base of a grain bin — real sites that no fixed list anticipates.</p>

<p>When a job does not fit a category, the crew selects Other and writes what the site actually was. That description carries onto the record and onto your notice, so the application is documented accurately rather than filed under something approximate.</p>

<p>If a site type keeps coming up, it stops being Other and becomes its own category with its own handling. That is how most of the list above got there.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Other requires a description. Always.</strong> An application logged as Other with an empty description is an application we cannot account for later.</li>
  <li><strong>Describe the site, not the task.</strong> "Gravel yard behind shop, drains to ditch" is a record. "Sprayed weeds" is not.</li>
  <li><strong>If you use Other more than once for the same kind of site, tell the office.</strong> It probably needs to be a category.</li>
  <li>Unusual sites are where unusual label restrictions live. Near water, near food crops, inside a structure — check before you mix.</li>
</ul>
HTML,
];

$DATA['fence-line'] = [
  'short' => 'The strip under and along a fence, where a mower cannot reach. Almost always has somebody else\'s property on the far side, which is what makes it more careful work than it looks.',
  'public' => <<<'HTML'
<p>Fence lines grow weeds because nothing can mow them. The strip under a fence is unreachable by equipment, sheltered, and often slightly wetter than the ground either side — ideal conditions, and the reason a fence line is frequently the worst-looking part of an otherwise tidy property.</p>

<h3>The consideration is the other side</h3>

<p>Most fence lines are a boundary. Whatever we apply on your side is inches from ground somebody else owns, and a non-selective product carried a couple of feet by a breeze lands in their lawn, their garden or their beds.</p>

<p>So fence lines are directed work with coarse droplets, close to the ground, and with a wind standard tighter than we use anywhere else on a property. If the wind is wrong for the fence line we do the rest of the property and come back.</p>

<h3>Left alone, it gets worse</h3>

<p>A fence line is a seed bank pointed at your lawn. Weeds there set seed, the seed moves a few feet in either direction, and the lawn work has to fight it every season. Keeping the fence line clean is one of the higher-leverage things on a maintenance program, which is not obvious until it is skipped for a year.</p>

<p>Where a fence line is a persistent problem, a clean edge and mulch or gravel underneath solves it more permanently than repeat spraying.</p>
HTML,
  'teammate' => <<<'HTML'
<ul>
  <li><strong>Look at the far side before you start.</strong> A vegetable garden, ornamental beds, or an organic operation on the other side of that fence changes the job or cancels it.</li>
  <li><strong>Tightest wind standard on the property.</strong> If you would not spray it next to a garden, do not spray it here — the neighbour's garden may be exactly what is there.</li>
  <li><strong>Directed, low, coarse droplets.</strong> No boom on a fence line.</li>
  <li><strong>Know where the property line is.</strong> The fence is not always on it, and treating a neighbour's ground is a complaint we cannot defend.</li>
  <li>Recurring fence line problems get reported as an edging and mulch recommendation.</li>
</ul>
HTML,
];

// --- apply ---
$am = \Drupal::service('path_alias.manager');
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vid)->execute();
$bySlug = [];
foreach ($etm->getStorage('taxonomy_term')->loadMultiple($tids) as $t) {
  $alias = $am->getAliasByPath('/taxonomy/term/' . $t->id());
  $bySlug[substr(strrchr($alias, '/'), 1)] = $t;
}

$backup = [];
$changed = 0;
$missing = [];
foreach ($DATA as $slug => $c) {
  if (!isset($bySlug[$slug])) {
    $missing[] = $slug;
    continue;
  }
  $t = $bySlug[$slug];
  $backup[] = [
    'slug' => $slug, 'tid' => $t->id(), 'name' => $t->label(),
    'old_description' => $t->hasField('description') ? $t->get('description')->value : NULL,
    'old_public' => $t->hasField('field_public_description') ? $t->get('field_public_description')->value : NULL,
    'old_short' => $t->hasField('field_short_description') ? $t->get('field_short_description')->value : NULL,
    'old_teammate' => $t->hasField('field_teammate_description') ? $t->get('field_teammate_description')->value : NULL,
  ];
  $t->set('field_short_description', ['value' => $c['short'], 'format' => 'basic_html']);
  $t->set('field_public_description', ['value' => $c['public'], 'format' => 'full_html']);
  $t->set('field_teammate_description', ['value' => $c['teammate'], 'format' => 'full_html']);
  // Clear the legacy core description so it does not double-render under the new
  // public body (Arena/Driveway keep theirs — they are not in $DATA).
  $t->set('description', ['value' => '', 'format' => 'basic_html']);
  $t->save();
  $changed++;
  printf("  set %-22s (tid %s)\n", $slug, $t->id());
}

if ($backup) {
  $file = 'public://spraying_locations_seed_backup_' . date('Ymd_His') . '.json';
  \Drupal::service('file_system')->saveData(json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), $file, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
  print "  backup: " . \Drupal::service('file_system')->realpath($file) . "\n";
  Cache::invalidateTags(array_map(fn($b) => 'taxonomy_term:' . $b['tid'], $backup));
}
if ($missing) {
  print "  WARNING unmatched slugs: " . implode(', ', $missing) . "\n";
}
printf("DONE. %d terms seeded, %d unmatched.\n", $changed, count($missing));
