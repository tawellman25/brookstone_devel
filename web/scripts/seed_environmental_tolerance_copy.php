<?php

declare(strict_types=1);

/**
 * Environmental Tolerance - the 9 plant_characteristics terms (Batch 1,
 * written 2026-09-29 rev 2, supplied 2026-10-03).
 *
 * Same shape and the same guards as seed_plant_characteristic_copy.php, which
 * loaded Batches 2-8. Together they complete all 41 terms.
 *
 * Replaces the generic copy migrated out of core description on 2026-10-03 -
 * the copy that says "shrubs" on a plant characteristic and addresses a
 * contractor rather than a customer.
 *
 * field_list_order is NOT written here. The instance does not exist on this
 * vocabulary and the eight category views sort by name, so honouring
 * marketing's order is a separate change to 8 live views - see
 * setup_plant_characteristic_list_order.php.
 *
 *   drush php:script web/scripts/seed_environmental_tolerance_copy.php
 *   BOS_ET_APPLY=1 drush php:script web/scripts/seed_environmental_tolerance_copy.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_ET_APPLY') === '1';
$VID = 'plant_characteristics';
$TERMS = [];

$TERMS['Alkaline-Tolerant'] = [
  'short' => 'Handles soil above pH 7.5 without going chlorotic. On the Western Slope this is less a feature than a requirement, and it is the single most useful filter on a plant list.',
  'public' => <<<'HTML'
<p>Alkaline-tolerant means a plant can still pull iron and manganese out of the ground when the soil pH is high enough to lock most of it away. Across Delta and Montrose counties that is ordinary ground — commonly 7.5 to 8.2 — so this is not a bonus trait here. It is the baseline.</p>

<p>The failure it prevents is iron chlorosis, and once you can see it you will see it everywhere in this valley: leaves gone pale yellow or almost white while the veins stay green, worst on the newest growth at the tips of branches. The plant is not short of iron. The iron is in the soil and chemically unavailable, and the plant is starving next to it.</p>

<p>Chlorosis is not fatal quickly. It is worse than that — the plant declines for years, looking progressively thinner and sicker, while somebody feeds it and waters it and wonders what is wrong. Silver and red maple, pin oak, river birch and quaking aspen are the ones we see it on most, all of them excellent trees somewhere with different dirt.</p>

<p>Treatment exists and it is a treadmill. Chelated iron applied to the soil or the leaves greens a plant up for a season, sometimes two. It does not change the pH, so it has to be repeated for the life of the plant. A soil acidifier moves the number temporarily and then the native ground and the irrigation water pull it back.</p>

<p>Which is why the plant list is the answer and the amendment is not. Choose something that is adapted to alkaline ground and the problem never begins.</p>

<p>The other end of the same problem is worth understanding before anybody falls in love with a plant that cannot have it. Azalea, rhododendron and blueberry are <a href="/material/plants/characteristics/environmental-tolerance/acid-loving">acid-loving</a> — they need the pH below 6.5, and on this ground that is a container with its own soil and its own water, not a bed.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>This is the first filter on every plant list, before anything else.</strong> A plant that cannot handle our pH is off the plan regardless of how well it suits the design.</p>

<ul>
<li><strong>Diagnose chlorosis by the veins.</strong> Yellow leaf, green veins, worst on new growth at the tips — that is pH, not fertilizer. Uniform yellowing including the veins is something else, usually nitrogen or water.</li>
<li><strong>Read the neighborhood.</strong> Yellow maples on the block tell you what the soil is doing before anyone runs a test. Use it.</li>
<li><strong>A soil test is cheap on a large install</strong> and it settles arguments. Recommend one on anything over a few thousand dollars in plant material.</li>
<li><strong>If a customer insists on a chlorosis-prone tree,</strong> put the condition and the ongoing treatment in the estimate. Not as a warning — as a line item, so the cost is visible and the decision is theirs on the record.</li>
</ul>

<p><strong>What to tell a customer:</strong> we cannot change the pH of a yard permanently. We can improve a bed for a few years. The plant list is where this gets solved, and there is a good version of almost any planting using material that suits the ground.</p>

<p><strong>What not to sell:</strong> an iron treatment as a fix. It is a management program. If we are putting a susceptible plant in, the treatment goes on the maintenance schedule from day one, priced and scheduled, not quoted as a one-off rescue when the tree turns yellow in year three.</p>
HTML,
  'title' => 'Alkaline-Tolerant Plants | Brookstone Outdoors',
  'desc' => 'Soil here runs pH 7.5 to 8.2, which locks up iron and yellows leaves between green veins. Why alkaline tolerance is the first filter on a plant list.',
];

$TERMS['Acid-Loving'] = [
  'short' => 'Needs soil below about pH 6.5 — which this valley does not have. Read this as a warning label rather than a feature, and plan on a container or a dedicated bed if you want one anyway.',
  'public' => <<<'HTML'
<p>Acid-loving plants want ground somewhere below pH 6.5, and often well below it. Soil across most of this valley sits <a href="/material/plants/characteristics/environmental-tolerance/alkaline-tolerant">between 7.5 and 8.2</a>. That is not a small gap — pH is a logarithmic scale, so soil at 8.0 is around thirty times less acidic than soil at 6.5.</p>

<p>Azalea, rhododendron, blueberry, pieris and most hollies belong to this group, and they are among the most frequently regretted purchases in this region. They come home from a garden center looking healthy, hold on for a season or two on whatever was in the nursery pot, and then go slowly chlorotic and thin and die over three or four years.</p>

<p><strong>The part almost nobody accounts for is the water.</strong> Irrigation water here is hard and alkaline. So even if you build a perfect acidic bed with imported media and sulfur, every watering pushes the pH back up, and you are working against the hose for the life of the plant. That is the reason a well-built acid bed still fails, and why it has very little to do with how carefully it was prepared.</p>

<p>There is an honest version of this. A container with a proper ericaceous mix, an acidifying fertilizer, and rainwater or collected water where you can manage it — that works, and people do it successfully. A raised bed with imported media and an annual sulfur program works too, with attention. Both are a hobby you are taking on rather than a planting you are installing.</p>

<p>What does not work is putting an acid-loving plant in native ground and hoping. We will tell you that before we sell you one.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Treat this tag as a stop sign, not a preference.</strong> If a plant is acid-loving, it does not go in native ground on any job we put our name on.</p>

<ul>
<li><strong>If a customer asks for one, explain the water before you explain the soil.</strong> The hose is the part that surprises people and it is the part that actually decides it.</li>
<li><strong>Offer the container version.</strong> Proper ericaceous mix, acidifying feed, and honesty about the watering. Some customers want the plant badly enough and that is fine — sold as what it is.</li>
<li><strong>A raised bed with imported media is possible</strong> on a job with the budget for it. It needs an annual sulfur program on the maintenance schedule, priced, from the first year.</li>
<li><strong>Never install one in native soil because a customer insisted</strong> without the conversation documented in the estimate. That plant will fail and the failure will be attributed to us.</li>
</ul>

<p><strong>What to tell a customer:</strong> it is not that these plants are difficult. It is that they are wrong for this ground, and no amount of work permanently changes ground. The good news is there is usually something that gets close to the look they are after and actually wants to be here.</p>

<p><strong>Blueberries come up every year.</strong> The answer is a large container or a dedicated raised bed, and a real conversation about the ongoing work. Not a hole in the yard.</p>
HTML,
  'title' => 'Acid-Loving Plants in Alkaline Soil | Brookstone',
  'desc' => 'Azaleas and blueberries want pH under 6.5. This valley runs 7.5 to 8.2 and the irrigation water is alkaline too. What it actually takes to grow them.',
];

$TERMS['Well-Drained Soil'] = [
  'short' => 'Roots need air as well as water, and will rot without it. The most common line on a plant tag, the most often ignored, and in heavy ground here it kills more plants than drought does.',
  'public' => <<<'HTML'
<p>"Prefers well-drained soil" is on more plant tags than any other requirement, which is probably why it gets read as a suggestion. It is not. Roots need oxygen as much as they need water, and in saturated ground they suffocate and rot — and a rotted root system kills a plant that is being watered faithfully, which is why the diagnosis so often goes the wrong way.</p>

<p>Ground here works against you in two ways. Heavy clay holds water and gives it up slowly. And in places there is caliche — a hardpan layer of cemented calcium carbonate, sometimes a few inches down, sometimes a couple of feet — that water simply will not pass through.</p>

<p><strong>Which produces the mistake we see most often: the bathtub.</strong> Somebody digs a hole in heavy ground, backfills with good compost-rich soil, and plants into it. Now there is a pocket of loose, absorbent material sitting inside a bowl of material that does not drain. Water runs in, collects, and has nowhere to go. The plant is standing in a container with no hole in the bottom, and the better the backfill, the worse it is.</p>

<p>Test it before you plant anything. Dig a hole about a foot deep, fill it with water, let it drain, fill it again. If the second filling has not drained within four hours you have a drainage problem, and no plant choice will fix it.</p>

<p>The fix is to plant up rather than down — a raised bed or a graded berm, so the root zone sits above the ground that does not drain. That is a real solution. Amending a hole is not, however good the amendment.</p>

<p>One thing worth knowing before choosing a plant list for a dry site. Most of what is sold as <a href="/material/plants/characteristics/environmental-tolerance/drought-tolerant">drought-tolerant</a> also insists on sharp drainage, because the plants evolved on gravel and decomposed rock rather than on heavy valley clay. Put one in slow ground on a lawn irrigation schedule and it rots — which is a xeric planting failing from too much water, the opposite of what anybody expects.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Perc test before planting on any site with heavy ground.</strong> Foot-deep hole, fill, drain, fill again. Not drained in four hours means drainage work, and it means saying so in the estimate before the plants are ordered.</p>

<ul>
<li><strong>Never dig a hole in clay and backfill with rich mix.</strong> That is the bathtub, and the better the backfill the worse the drowning. Backfill with native soil lightly amended, or build up instead.</li>
<li><strong>Plant high in heavy ground.</strong> Root flare at or slightly above grade. Buried root flares kill trees slowly and it is the most common install error in the trade.</li>
<li><strong>Watch for caliche when you dig.</strong> A hard white layer that the shovel stops on. It has to be broken through or planted above — plan on it before the crew is standing there with a tree.</li>
<li><strong>Berms and raised beds are the answer on wet sites,</strong> not French drains and not amendment. Price them at design, not as a change order.</li>
<li><strong>A wilting plant in heavy ground may be drowning, not thirsty.</strong> Check soil moisture at root depth before telling anyone to water more. Adding water to a drowning plant finishes it.</li>
</ul>

<p><strong>What to tell a customer:</strong> in this valley more plants die from wet feet than from drought, which sounds wrong in a high desert and is true anyway. If their ground holds water, that gets solved by grading and raising, and it gets solved before anything is planted.</p>
HTML,
  'title' => 'Well-Drained Soil & Plant Roots | Brookstone Outdoors',
  'desc' => 'Roots need air, and clay and caliche hold water. Why backfilling a hole with good soil makes a bathtub, and why planting high is the real fix.',
];

$TERMS['Drought-Tolerant'] = [
  'short' => 'Gets by on little water once established — and establishment takes two full seasons of regular watering first. Drought tolerance is earned, not delivered with the plant.',
  'public' => <<<'HTML'
<p>A drought-tolerant plant survives on limited water because its roots are deep enough to find what is down there, or because it is built to lose less. Neither of those is true on the day it comes out of the pot. A nursery plant has a shallow root ball that has been watered daily its whole life, and it has no more drought tolerance at that moment than a lettuce.</p>

<p><strong>It takes about two full growing seasons of regular watering for a plant to earn the label on its own tag.</strong> That is the single most misunderstood thing about xeric planting, and it is why so many water-wise landscapes fail in their first summer. Somebody plants a bed of drought-tolerant material in June, waters it like the label says, and loses most of it by August — not because the plants were wrong, but because they were asked to do something their roots could not do yet.</p>

<p>The other half is less obvious: <strong>established drought-tolerant plants are frequently killed by too much water rather than too little.</strong> A plant adapted to dry ground and put on a lawn irrigation schedule in <a href="/material/plants/characteristics/environmental-tolerance/well-drained-soil">heavy soil</a> sits wet, rots, and dies looking overwatered — yellowing, soft, collapsing. In a mixed planting this is common, because the xeric shrubs end up on the same zone as everything else.</p>

<p>It is also worth knowing that some drought-tolerant plants go summer-dormant. They stop, look tired or half-dead in the worst heat, and come back when it breaks. That is the strategy working, not the plant failing, and it is worth knowing which of yours do it before you pull one out in August.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Two seasons of establishment watering, every time, and it goes on the care sheet in writing.</strong> This is the number one reason xeric plantings fail here and it is entirely preventable with one conversation.</p>

<ul>
<li><strong>Put xeric material on its own irrigation zone.</strong> A drought-tolerant shrub on a turf schedule dies of too much water. If the system cannot be zoned that way, say so at design and adjust the plant list instead.</li>
<li><strong>Deep and infrequent, not light and often.</strong> Light daily water keeps roots shallow and permanently dependent. The watering schedule is what builds the drought tolerance, so it has to be the right schedule.</li>
<li><strong>Taper deliberately.</strong> Full schedule year one, reduced year two, minimal from year three. Tell the customer the plan and put the dates on the care sheet — otherwise nothing ever changes and the plants stay dependent.</li>
<li><strong>Spring and fall installs establish far better than June and July.</strong> Push a xeric install to the shoulder season where the schedule allows it.</li>
<li><strong>Know which ones go summer-dormant</strong> so nobody replaces a plant that is doing exactly what it should.</li>
</ul>

<p><strong>What to tell a customer:</strong> the water savings start in year three. Years one and two, this bed gets watered on a schedule like anything else. Said before the sale that is a reasonable trade. Discovered in August of year one it is a complaint.</p>

<p><strong>If a xeric plant is yellowing and soft:</strong> check moisture before recommending water. It is usually the opposite problem.</p>
HTML,
  'title' => 'Drought-Tolerant Plants | Brookstone Outdoors',
  'desc' => 'Drought tolerance takes two seasons of regular water to earn. Why xeric beds fail their first summer, and why established ones die of too much water.',
];

$TERMS['Sun-Loving'] = [
  'short' => 'Wants six or more hours of direct sun. Worth knowing that sun at 5,000 to 6,500 feet in dry, clear air is considerably stronger than the same hours at low elevation.',
  'public' => <<<'HTML'
<p>A sun-loving plant needs roughly six hours of direct sun a day to grow properly and flower. Below that it does not die — it does something slower and more frustrating. It stretches toward the light, goes leggy and thin, flowers sparsely or not at all, and generally looks like it needs feeding. People fertilize it, and the extra nitrogen makes it stretch further.</p>

<p>The local wrinkle is that <strong>our hours are not the same as somebody else's hours.</strong> At 5,000 to 6,500 feet, in dry air with few cloudy days, sunlight arrives with noticeably more intensity and ultraviolet than it does at low elevation. A plant grown and rated in a humid climate can be genuinely sun-loving there and still scorch on a west-facing wall here.</p>

<p>Which is why exposure matters more than a count of hours. Morning sun is gentler — the air is cooler, the plant is turgid from overnight, and the light climbs gradually. Afternoon west sun is the harshest exposure in any yard here, and it is worse again against a light-colored wall or over rock mulch, both of which reflect heat and light back up into the plant.</p>

<p>Six hours of morning and midday sun and six hours of afternoon west sun are two different assignments, and a fair number of plants will take the first and not the second.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Count the hours and note the exposure. Both.</strong> "Full sun" on a tag is not a placement instruction here.</p>

<ul>
<li><strong>West exposure is the hard one.</strong> Afternoon sun, heat off the wall, and usually wind. When a plant is marginal, put it east and not west.</li>
<li><strong>Rock mulch multiplies it.</strong> Light-colored rock reflects heat and light up into the plant all afternoon. A plant that would be fine in a bark bed can cook in a rock bed on the same wall.</li>
<li><strong>Walk the site at more than one time of day</strong> on a job where light is tight, or ask the customer where the shade is at three in the afternoon. They know.</li>
<li><strong>Account for the trees getting bigger.</strong> A full-sun bed under a young tree is a part-shade bed in eight years. Say it at design so nobody is surprised later.</li>
<li><strong>Leggy, stretched, not flowering is a light problem.</strong> Do not feed it — feeding makes it worse. Move it or change it.</li>
</ul>

<p><strong>What to tell a customer:</strong> the sun is stronger here than most places they have gardened before. A plant that was reliable somewhere else may want an east wall here rather than a west one, and that is placement rather than a different plant.</p>
HTML,
  'title' => 'Full Sun Plants at Altitude | Brookstone Outdoors',
  'desc' => 'Six hours of sun at 6,000 feet is not six hours at sea level. Why west exposure is the hard one, and what leggy growth is telling you about light.',
];

$TERMS['Shade-Tolerant'] = [
  'short' => 'Grows in less than about four hours of direct sun. Worth separating from dry shade under mature trees, which is a harder site than shade alone and the hardest spot in most yards.',
  'public' => <<<'HTML'
<p>Shade-tolerant means a plant does not need much direct sun — usually under four hours, sometimes almost none. What it does not mean is that the plant needs nothing. Shade plants still want water, and in a dry climate they frequently want more of it than they would elsewhere, because the air is pulling moisture out of them whether the sun is on them or not.</p>

<p>Not all shade is the same, and the distinction matters more than the label does.</p>

<p><strong>Open or high shade</strong> — under a tall canopy, or on the north side of a building with sky above — is bright, and plenty of plants do well in it. Shade in clear, dry air at this elevation is noticeably brighter than shade at sea level, and a plant rated for part shade in a humid climate often handles more here than the tag suggests.</p>

<p><strong>Dry shade under a mature tree is a different proposition entirely</strong>, and it is the hardest planting site in a typical yard. A plant there is short of light, and it is also competing with an established root system that got to the water first and takes most of it. Most failures blamed on shade are really that competition. The answer is tough material, generous planting holes worked between the surface roots, and its own water — not a shade plant dropped into the root mat and left to fight for it.</p>

<p><strong>North-side shade against a building</strong> is the third kind: shaded, and also cold and late to warm in spring. Plants there break dormancy later, which is often a genuine advantage with anything that flowers early and would otherwise get frosted.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Identify which kind of shade before you pick anything.</strong> Open shade, dry shade under a tree, and cold north-side shade are three different sites and they take three different lists.</p>

<ul>
<li><strong>Dry shade under a mature tree needs its own water.</strong> Drip, on its own zone, run longer than you would think. Without it the tree wins and the planting fails, and it will look like the wrong plant choice.</li>
<li><strong>Do not trench through a mature tree's root zone</strong> to get irrigation in. Work between roots, hand-dig, and route around. We are not solving a bed by damaging the tree that defines it.</li>
<li><strong>Use the north side for early bloomers.</strong> Late to warm means late to break dormancy means it misses the frost. This is a genuine placement tool, not a compromise.</li>
<li><strong>Shade here is brighter than the tag assumes.</strong> Part-shade plants often take more direct sun at this elevation than their rating suggests — though not west afternoon sun.</li>
<li><strong>Shade plants still need water.</strong> A dry-air climate pulls moisture out regardless of sun. Do not let shade beds fall off the watering schedule.</li>
</ul>

<p><strong>What to tell a customer:</strong> if they have bare ground under a big tree and want it planted, be straight about it. That is the hardest spot in the yard, it needs its own irrigation, and the plant list is short. It is doable and it is not cheap, and knowing that up front is better than finding out in year two.</p>
HTML,
  'title' => 'Shade-Tolerant Plants & Dry Shade | Brookstone',
  'desc' => 'Open shade, dry shade under a tree and cold north-side shade are three sites. Why root competition, not light, kills most plantings under trees.',
];

$TERMS['Cold-Hardy'] = [
  'short' => 'Survives the winter lows here — most of this area is USDA zone 5 to 6, varying with elevation. The zone number is an average, and it says nothing about the late frost that does most of the damage.',
  'public' => <<<'HTML'
<p>A hardiness zone is the average annual minimum winter temperature for an area. Most of Delta and Montrose counties falls in zone 5 or 6, shifting colder as you go up — a yard at Cedaredge or Crawford is not doing the same winter as a yard on the valley floor at Delta, whatever the map shows at that resolution.</p>

<p>Two things the zone number does not tell you, and both matter more than it does.</p>

<p><strong>It is an average, not a floor.</strong> A plant rated exactly to your zone will come through most winters and then meet the one that runs colder than average. Marginal plants do not fail gradually — they succeed for six years and die in the seventh, which is long enough for everyone to have concluded they were a good choice.</p>

<p><strong>And a hard late frost is not a hardiness question at all.</strong> A May freeze does not kill a zone-5 plant by being cold. It kills the new growth the plant has already spent its energy producing, after a warm spell convinced it that winter was over. A perfectly hardy plant can be badly set back by a frost twenty degrees warmer than its rated minimum.</p>

<p>The injury we see most here is not straightforward cold at all. On a clear February day, sun on the south or southwest side of a young trunk warms the bark and starts the tissue under it moving. Overnight it drops back below freezing and that tissue ruptures, leaving a long vertical split or sunken dead strip down the sunny side of the trunk. It is called sunscald or southwest injury, and it is a product of intense winter sun and hard overnight swings — which is to say, our climate specifically. Thin-barked young trees are the casualties, and wrapping the trunk through the first few winters prevents it.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Choose at least one zone hardier than the site.</strong> Rated exactly to zone means it dies in the bad winter, and the bad winter is a matter of when rather than whether.</p>

<ul>
<li><strong>Get the actual elevation, not the county.</strong> Cedaredge and Crawford are colder than Delta and Olathe. The zone map is too coarse for a yard.</li>
<li><strong>Wrap thin-barked young trees for the first three winters.</strong> Light-colored wrap, on in late fall, off in spring — and it must come off, or it holds moisture and girdles. Maple, linden, honeylocust, fruit trees, anything smooth and young.</li>
<li><strong>Containers need two zones hardier than the site</strong> if they overwinter outside. A pot freezes from every side; the ground does not.</li>
<li><strong>Late frost is placement, not hardiness.</strong> Early bloomers go north and east, away from warm south and west walls that push growth early.</li>
<li><strong>Do not write off a frosted plant in May.</strong> Give it until mid-June before calling it. Most push new growth and recover fully.</li>
</ul>

<p><strong>What to tell a customer:</strong> hardiness ratings are averages, so we build in a margin. If they want something marginal, it can go in — with the understanding that it is a good-most-years plant and there will eventually be a winter it does not survive. Some people take that deal happily once it is stated.</p>

<p><strong>On sunscald:</strong> the wrap is not optional and it is not us upselling. Explain what it prevents. A split trunk on a four-year-old tree is not repairable.</p>
HTML,
  'title' => 'Cold-Hardy Plants & Zones | Brookstone Outdoors',
  'desc' => 'Zone 5 to 6 by elevation, and the number is an average rather than a floor. Why late frost and winter sunscald do more damage here than cold does.',
];

$TERMS['Heat-Tolerant'] = [
  'short' => 'Holds up through hot, dry summer afternoons. Here that means dry heat plus high-altitude sun plus wind, which is a harder combination than the temperature alone suggests.',
  'public' => <<<'HTML'
<p>Heat tolerance is usually rated somewhere humid, and dry heat is a different test. In humid air a plant can transpire to cool itself and still hold its water. In dry air at altitude, with wind, the air pulls moisture out faster than the roots can replace it — and the plant wilts in the afternoon while the soil around it is still damp.</p>

<p>That afternoon wilt with wet soil is worth recognizing, because it is routinely mistaken for a watering problem. The plant is not short of water in the ground. It is losing it out of the leaves faster than it can move it up, and it recovers on its own overnight. Adding more water does nothing except waterlog the roots.</p>

<p>The real heat load in a yard is rarely the air temperature. It is what surrounds the plant. A south or west wall re-radiates heat into the evening. Concrete and asphalt do the same. And <strong>light-colored rock mulch is the one that catches people</strong> — it reflects sunlight back up into the underside of the foliage and holds heat well past sundown, so a bed in rock can run substantially hotter than the same bed in bark a few feet away.</p>

<p>Wind compounds all of it. A hot afternoon with wind moves far more water out of a plant than the same temperature in still air, and this is not a still place.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Assess the heat load, not the forecast.</strong> Wall exposure, paving, and mulch type decide what a plant actually experiences.</p>

<ul>
<li><strong>South and west walls, and anything against concrete or asphalt,</strong> need genuinely heat-tough material. This is where marginal plants die first.</li>
<li><strong>Rock mulch raises the heat load significantly.</strong> If the customer wants rock, the plant list has to match it. Do not put a bark-bed plant list in a rock bed and expect the same result.</li>
<li><strong>Afternoon wilt with damp soil is not a watering problem.</strong> Check the soil before telling anyone to add water. If it recovers overnight, the plant is coping.</li>
<li><strong>Wind exposure multiplies heat stress.</strong> An open west face with no windbreak is the hardest spot on most properties.</li>
<li><strong>New plantings need shade or extra water through their first hot spell</strong> regardless of how heat-tolerant the material is. Roots are not down yet.</li>
</ul>

<p><strong>What to tell a customer:</strong> if they are choosing rock mulch for the look or the maintenance, that is fine — but it changes what will grow in the bed, and that is a decision to make at design rather than discover in July.</p>
HTML,
  'title' => 'Heat-Tolerant Plants | Brookstone Outdoors',
  'desc' => 'Dry heat, altitude sun and wind is a harder test than temperature. Why light rock mulch raises the heat load, and why afternoon wilt is not thirst.',
];

$TERMS['Salt-Tolerant'] = [
  'short' => 'Handles salt at the roots or on the foliage. Two sources here: de-icing salt along drives and roads, and salts that accumulate in the soil because there is not enough rain to flush them out.',
  'public' => <<<'HTML'
<p>Two different salt problems turn up in this valley, and the second one gets almost no attention.</p>

<p><strong>De-icing salt</strong> is the obvious one. It reaches plants two ways — dissolved in meltwater running off a drive, walk or road into the soil beside it, and as spray thrown up by traffic onto foliage and stems. Anything within about fifteen feet of a plowed and salted surface is exposed, and the strip along a county road or a highway frontage is the worst of it.</p>

<p><strong>Accumulated soil salts</strong> are the quieter problem and they are a feature of dry climates generally. In a place with substantial rainfall, salts get flushed down through the soil and away. Here there is not enough precipitation to do that, so salts from the soil's own mineral content and from irrigation water stay in the root zone and concentrate over years. It is worse in ground that drains poorly, worse in low spots where runoff collects, and worse in beds that get light frequent watering rather than deep soaking.</p>

<p>The symptom for both is the same: <strong>leaf edges browning and dying inward from the margin, worst on older leaves,</strong> while the center of the leaf stays green. It is easy to read as drought stress or wind burn and it is worth distinguishing, because the treatment is the opposite of what you would do for drought.</p>

<p>Salt-tolerant plants either exclude salt at the root or tolerate it in their tissue. They are what goes along a salted drive, in the hell strip by the road, and at the bottom of a slope where the runoff ends up.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Ask where the snow gets pushed.</strong> That pile is where the salt concentrates when it melts, and it is a planting decision most people never connect.</p>

<ul>
<li><strong>Salt-tolerant material within roughly fifteen feet of any salted drive, walk or road.</strong> Frontage on a plowed county road is the worst exposure on most properties.</li>
<li><strong>Distinguish salt burn from drought burn.</strong> Salt browns the leaf margin and works inward, worst on older leaves. Check the location before the irrigation — if it is beside a drive or in a low spot, suspect salt.</li>
<li><strong>Deep infrequent watering leaches salts down; light frequent watering concentrates them at the surface.</strong> This is a second reason to water deep, and it is a real fix on a mildly affected bed.</li>
<li><strong>Low spots and the ends of slopes collect runoff and everything in it.</strong> Treat those as high-salt sites even away from a road.</li>
<li><strong>If we handle the snow contract on a property, the plant list and the de-icing plan should know about each other.</strong> Worth a look where both are ours.</li>
</ul>

<p><strong>What to tell a customer:</strong> if a bed along the drive keeps failing and nothing else does, salt is the likely answer rather than anything they did. It is fixable with the plant list, a change in where the snow goes, or both.</p>
HTML,
  'title' => 'Salt-Tolerant Plants | Brookstone Outdoors',
  'desc' => 'De-icing salt off the drive, and soil salts that never flush because it does not rain enough. How to tell salt burn from drought burn, and where to plant.',
];

/* ================================================================ LOAD */

$etm = \Drupal::entityTypeManager();
$storage = $etm->getStorage('taxonomy_term');

print $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (BOS_ET_APPLY=1 to write)\n";
printf("Copy supplied for %d terms.\n\n", count($TERMS));

$artifacts = [];
foreach ($TERMS as $name => $d) {
  foreach (['public', 'crew'] as $k) {
    if (strpos($d[$k], '**') !== FALSE) { $artifacts[] = "$name/$k"; }
  }
}
if ($artifacts) { print "ABORT — markdown ** inside HTML:\n  " . implode("\n  ", $artifacts) . "\n"; return; }
print "✓ no markdown artifacts in any HTML value\n";

$links = [];
foreach ($TERMS as $name => $d) {
  if (preg_match_all('~href="(/[^"]+)"~', $d['public'] . $d['crew'], $m)) {
    foreach ($m[1] as $h) { $links[$h][] = $name; }
  }
}
print $links ? '' : "✓ no internal links in this batch (nothing to resolve)\n";
foreach ($links as $href => $where) {
  $alias = \Drupal::service('path_alias.manager')->getPathByAlias($href);
  if ($alias === $href) { print "ABORT — unresolved link $href (in " . implode(', ', $where) . ")\n"; return; }
  printf("✓ internal link %-56s (in %s)\n", $href, implode(', ', $where));
}

$over = [];
foreach ($TERMS as $name => $d) {
  if (mb_strlen($d['desc']) > 160) { $over[] = $name . ' (' . mb_strlen($d['desc']) . ')'; }
  if (mb_strlen($d['title']) > 60) { $over[] = $name . ' title (' . mb_strlen($d['title']) . ')'; }
}
print $over ? "⚠ over length: " . implode(', ', $over) . "\n" : "✓ all titles ≤60 and descriptions ≤160 chars\n";

$byName = [];
foreach ($storage->loadMultiple(\Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
  ->condition('vid', $VID)->execute()) as $t) { $byName[mb_strtolower(trim($t->label()))] = $t; }

$matched = []; $missing = [];
foreach ($TERMS as $name => $d) {
  $k = mb_strtolower(trim($name));
  isset($byName[$k]) ? $matched[$name] = $byName[$k] : $missing[] = $name;
}
printf("\n%d of %d matched a live term.\n", count($matched), count($TERMS));
if ($missing) {
  print "✗ NOT FOUND (reported, not created):\n  " . implode("\n  ", $missing) . "\n";
  return;
}

$backup = []; $changed = 0;
foreach ($matched as $name => $term) {
  $d = $TERMS[$name];
  $raw = (string) ($term->get('field_meta_tags')->value ?? '');
  $tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
  $backup[$term->id()] = [
    'name' => $name,
    'short' => $term->get('field_short_description')->value,
    'public' => $term->get('field_public_description')->value,
    'crew' => $term->get('field_teammate_description')->value,
    'meta' => $raw,
  ];
  $deltas = [];
  $set = function (string $field, string $value) use ($term, &$deltas) {
    $old = (string) ($term->get($field)->value ?? '');
    if (trim($old) === trim($value)) { return; }
    $term->set($field, ['value' => $value, 'format' => 'full_html']);
    $deltas[] = $field . ' ' . ($old === '' ? '(was empty)' : sprintf('(%d→%d ch)', mb_strlen($old), mb_strlen($value)));
  };
  $set('field_short_description', $d['short']);
  $set('field_public_description', $d['public']);
  $set('field_teammate_description', $d['crew']);
  $want = ['title' => $d['title'], 'description' => $d['desc'], 'og_description' => $d['desc']];
  if (array_intersect_key($tags, $want) != $want) {
    $term->set('field_meta_tags', json_encode(array_merge($tags, $want), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $deltas[] = 'meta';
  }
  if (!$deltas) { printf("  %-22s unchanged\n", $name); continue; }
  printf("  %-22s %s\n", $name, implode(', ', $deltas));
  $changed++;
  if ($apply) { $term->save(); Cache::invalidateTags(['taxonomy_term:' . $term->id()]); }
}

if ($apply) {
  $f = '/tmp/et_copy_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  print "\nPrevious values backed up to $f\n";
}
printf("\n%d terms changed%s.\n", $changed, $apply ? '' : ' (dry-run — nothing written)');
