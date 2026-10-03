<?php

declare(strict_types=1);

/**
 * Load marketing's authored copy for the 31 plant_characteristics terms
 * (Batches 2-8, written 2026-10-03).
 *
 * Four fields per term: field_short_description (the CARD line shown on the
 * parent category page), field_public_description (the term page body),
 * field_teammate_description (crew only), field_meta_tags.
 *
 * Deliberately NOT field_call_to_action - per marketing, the leaf pages are
 * reference and the eight category pages carry the ask.
 *
 * Terms are matched by NAME because they already exist and this script only
 * updates copy; a name that does not match is REPORTED and skipped, never
 * created and never guessed at (the credential-seeder lesson).
 *
 * Previous values of every field are written to a backup JSON before anything
 * is changed. field_public_description currently holds the copy migrated out
 * of core description on 2026-10-03, so this load REPLACES it - which is what
 * marketing asked for, and the backup is how it is undone.
 *
 *   drush php:script web/scripts/seed_plant_characteristic_copy.php
 *   BOS_PC_APPLY=1 drush php:script web/scripts/seed_plant_characteristic_copy.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_PC_APPLY') === '1';
$VID = 'plant_characteristics';
$TERMS = [];

/* ---------------------------------------------------------------- BATCH 2 */

$TERMS['Bird-Attracting'] = [
  'short' => 'Provides food, cover or both. Fruit and seed get the attention; dense branching to shelter in is the half that gets left out and the half that decides whether birds stay.',
  'public' => <<<'HTML'
<p>A plant brings birds in by feeding them or by giving them somewhere to sit out a storm, and the good ones do both. Fruit, hips and seed heads are the food. Dense, twiggy branching is the cover, and it is the part most people skip.</p>

<p>Which matters because a single fruiting plant standing on its own in an open yard gets stripped in a week and then ignored. Birds will not spend time in the open where a hawk can see them. The same plant with something thick beside it to retreat into becomes a place they come back to.</p>

<p>The food that counts most here is winter food — fruit that holds on the branch into January rather than dropping in October. That is when the yard has almost nothing else in it, and it is the reason a crabapple or a sumac does more for birds than a summer-fruiting plant does.</p>

<p>One honest note. Birds move seed, and some of what they move does not belong here. Anything introduced and heavily fruiting is worth a look at the <a href="/material/plants/characteristics/origin/exotic">noxious weed list</a> before it goes in near open ground or a ditch.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Sell cover as well as fruit.</strong> A bird planting that is all fruit and no shelter does not work, and the customer notices that the birds are not staying.</p>

<ul>
<li><strong>Pair a fruiting plant with something dense within a few feet.</strong> Conifer, thicket shrub, anything twiggy. That combination is what holds birds.</li>
<li><strong>Prioritize fruit that persists.</strong> Winter food is scarce food. Plants that drop in October feed birds for two weeks and then do nothing.</li>
<li><strong>Keep it off hard surface.</strong> Birds plus fruit over a patio means droppings and stain, not just fruit drop.</li>
<li><strong>Check heavy fruiters against the noxious weed list</strong> near ditches, fence lines and open ground.</li>
<li><strong>Do not sell a bird planting into a property on a heavy spray program</strong> without flagging it. Coordinate or pick a different spot.</li>
</ul>

<p><strong>What to tell a customer:</strong> fruit brings birds past, cover brings them back. If they want birds in the yard rather than through it, the planting needs both.</p>
HTML,
  'title' => 'Bird-Attracting Plants | Brookstone Outdoors',
  'desc' => 'Fruit brings birds past, cover brings them back. Why persistent winter fruit matters most here, and why a lone fruiting plant gets stripped and then ignored.',
];

$TERMS['Deer-Resistant'] = [
  'short' => 'Deer eat it last, not never. A useful ranking that gets shorter in a hard February, and nursery-fresh growth is more palatable than the same plant will be in five years.',
  'public' => <<<'HTML'
<p><strong>Deer-resistant means deer eat it last.</strong> It is a ranking, not a guarantee, and the ranking gets shorter as winter goes on. A deer in February, with the benches grazed down, will eat a long list of things the book says it will not.</p>

<p>Two things make the label less reliable than it looks. New nursery growth is soft and well fed, and it is more palatable than the same plant will be once it has hardened off and spent a few seasons here — so a planting is at its most vulnerable in exactly its first two winters. And pressure varies enormously inside a few miles. A property backing onto open ground, a bench edge or a drainage gets hit in a way a house in the middle of town does not, and the same plant list succeeds on one street and is stripped on the next.</p>

<p>Used properly, the tag is still one of the most valuable filters on a plant list in this valley. It shifts the odds far enough that a planting gets established, and most of it stops being worth a deer's attention once it has.</p>

<p>What it does not replace is protection through those first couple of winters. That is the cheapest insurance on any job where deer are around.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Never say deer-proof.</strong> Not in conversation, not on an estimate, not on a plant list. The February a hungry herd goes through a planting is the conversation that follows.</p>

<ul>
<li><strong>Ask on every design walk: do you see deer, and how often?</strong> The customer's answer beats any regional map.</li>
<li><strong>Note what backs the property.</strong> Open ground, a bench edge or a ditch corridor means high pressure regardless of the neighbors.</li>
<li><strong>Protect for the first two winters even on a resistant list.</strong> Nursery growth is the most palatable it will ever be.</li>
<li><strong>Browsed tops usually recover.</strong> Look before quoting a replacement.</li>
<li><strong>Deer-resistant is not rabbit-resistant.</strong> Different animal, different damage, and the rabbit version kills.</li>
</ul>

<p><strong>What to tell a customer:</strong> deer-resistant means eaten last, and in a hard winter the list gets shorter. Said before the install, browse is weather. Said after, it is our plant selection.</p>
HTML,
  'title' => 'Deer-Resistant Plants | Brookstone Outdoors',
  'desc' => 'Deer-resistant means eaten last, not never, and the ranking gets shorter in February. Why a new planting is most at risk in its first two winters here.',
];

$TERMS['Rabbit-Resistant'] = [
  'short' => 'Less about browsing than about bark. Rabbits girdle young trunks at snow line in winter, the damage is hidden until spring, and a girdled plant does not recover.',
  'public' => <<<'HTML'
<p>Rabbits get far less attention than deer and they do the damage that cannot be undone.</p>

<p>Deer browse the top of a plant and disfigure it. Rabbits work at the base in winter and strip bark, and when they take a complete ring around a trunk the plant is finished. Everything above that ring is cut off from the roots. It will leaf out in spring on stored energy, look perfectly healthy into June, and then die — which is why the cause is so often missed entirely.</p>

<p><strong>The detail that matters is snow line.</strong> Rabbits feed standing on the snow, so the damage happens eighteen inches or two feet up a trunk, under the drifts, where nobody looks until the snow goes. Young trees with smooth, thin bark are the usual casualties, and fruit trees are near the top of the list.</p>

<p>A rabbit-resistant plant is one they take last. On anything young and smooth-barked, the more reliable answer is not a plant choice at all — it is a trunk guard, set high enough to clear the snow, on every winter until the bark roughens.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Trunk guards on every young smooth-barked tree, every winter, set off snow depth rather than ground level.</strong> This is not optional and it is not an upsell — a girdled trunk is not repairable.</p>

<ul>
<li><strong>Guard height is measured from expected snow, not from the ground.</strong> A two-foot guard on a property that gets three feet of snow protects nothing.</li>
<li><strong>Fruit trees, maples, lindens, young ornamentals</strong> — smooth and thin is what they go for.</li>
<li><strong>Guards come off in spring.</strong> Left on, they hold moisture against the bark and cause the problem they prevented.</li>
<li><strong>Check at spring cleanup, at the base, under where the snow was.</strong> This is the one damage type nobody reports because nobody sees it.</li>
<li><strong>Partial ring: the plant may live. Complete ring: it will not.</strong> Know the difference before you quote.</li>
</ul>

<p><strong>What to tell a customer:</strong> deer damage looks bad and usually isn't fatal. Rabbit damage is invisible and often is. The guards are a few dollars a tree and they are the best money on the job.</p>
HTML,
  'title' => 'Rabbit-Resistant Plants | Brookstone Outdoors',
  'desc' => 'Rabbits girdle bark at snow line in winter, out of sight under the drifts. Why the plant looks fine until June, and why trunk guards beat plant choice.',
];

/* ---------------------------------------------------------------- BATCH 3 */

$TERMS['Container-Friendly'] = [
  'short' => 'Tolerates life in a pot, which here means surviving a winter with its roots exposed on every side. Plant two zones hardier than the site or treat it as an annual.',
  'public' => <<<'HTML'
<p>A container plant faces a harder winter than anything in the ground, and the reason is simple geometry. A root ball in the ground is wrapped in soil that never gets close to air temperature. A root ball in a pot is surrounded by air on every side and freezes right through.</p>

<p><strong>The working rule is two zones hardier than the site.</strong> On a zone 5 property, anything staying outside through winter should be rated to zone 3. Plant to the site's zone instead and you have bought an annual without meaning to.</p>

<p>Water is the other half, and it kills more containers here than cold does. A pot dries out in days in July, and it also needs occasional water on warm winter days when the ground has thawed — a plant in a pot cannot send roots anywhere to look for moisture. Dry roots freeze harder than moist ones, so a container going into winter dry is in real trouble.</p>

<p>Pot size helps on both counts. The bigger the volume of soil, the slower it swings in either direction, which is why a large container is a genuinely different proposition from a small one.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Two zones hardier than the site, or sell it as an annual.</strong> There is no third option and the customer should hear which one they are buying.</p>

<ul>
<li><strong>Bigger pot, better odds.</strong> Soil volume buffers both temperature and moisture. Talk customers up a size on anything overwintering.</li>
<li><strong>Containers need water in winter.</strong> Warm day, ground thawed. Put it on the care sheet — this is the single most common container failure here.</li>
<li><strong>Going into winter dry is worse than going in cold.</strong> Water deeply at the last visit of the season.</li>
<li><strong>Glazed or thick-walled pots overwinter better than thin plastic, and unglazed terracotta cracks.</strong> Worth saying before they buy the pot.</li>
<li><strong>Against a wall and out of the wind beats the middle of a patio</strong> for anything marginal.</li>
</ul>

<p><strong>What to tell a customer:</strong> a plant in a pot is living two zones colder than the same plant in the ground. That one fact decides the whole list, and it is why the thing that died was not their fault.</p>
HTML,
  'title' => 'Container Plants in Cold Climates | Brookstone',
  'desc' => 'A root ball in a pot freezes from every side. Why container plants need to be two zones hardier than the site, and why they need water on warm winter days.',
];

$TERMS['Erosion Control'] = [
  'short' => 'Holds soil with roots rather than with weight. The planting is half the answer on a real slope — the other half is what holds the ground while those roots get established.',
  'public' => <<<'HTML'
<p>A plant controls erosion by binding soil with roots and by breaking the force of rain before it hits bare ground. Both take time, and time is the whole problem.</p>

<p><strong>A new planting on a slope does not hold anything.</strong> Root systems that will eventually knit a bank together are, in their first season, a scattering of small plants in loose soil — and the first heavy runoff does not wait for them. That is why a slope planting is two jobs: the plants, and whatever holds the ground until the plants can.</p>

<p>Which one depends on the slope. A gentle grade may need nothing more than mulch and patience. Anything steep wants erosion blanket, matting, or terracing, and on a fall install it wants it before winter rather than in spring.</p>

<p>The plants themselves divide by how they hold. Fibrous, spreading roots knit the surface and stop sheet erosion. Deeper taproots anchor against slumping. A good slope planting usually has some of both, and groundcovers that spread to close the gaps between them.</p>

<p>Honest version: if somebody is told the plants alone will hold a steep bank through a heavy runoff year, they have been told something that is not true.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Price the stabilization with the planting, not as an option.</strong> A slope job that is plants only is a job we will be back on.</p>

<ul>
<li><strong>Anything steep gets blanket, matting or terracing.</strong> Say why — the plants cannot hold it in year one and nobody should pretend otherwise.</li>
<li><strong>Fall installs need the stabilization in before winter</strong>, not scheduled for spring. Runoff does not wait.</li>
<li><strong>Mix root types.</strong> Fibrous spreaders for the surface, something deeper for anchorage, groundcover to close the gaps.</li>
<li><strong>Water is harder on a slope.</strong> It runs off before it soaks in. Drip, low flow, longer runs — and say so, because hand watering a bank does almost nothing.</li>
<li><strong>Look uphill before quoting.</strong> If the water is coming from somewhere, the planting is treating a symptom.</li>
</ul>

<p><strong>What to tell a customer:</strong> the plants are the permanent fix and they need two or three years to become one. What we install alongside them is what gets the slope to that point.</p>
HTML,
  'title' => 'Plants for Erosion Control | Brookstone Outdoors',
  'desc' => 'Roots hold a slope, but not in year one. Why a bank planting is two jobs, which root types do what, and why a fall install needs stabilizing before winter.',
];

$TERMS['Hedge/Screening'] = [
  'short' => 'Grows dense enough to block a view. The question is never mature height — it is density at the height you actually need it, which is where most screens quietly fail.',
  'public' => <<<'HTML'
<p>Everybody asks how tall it gets. The question that decides whether a screen works is how dense it is at the height you need the screening.</p>

<p><strong>A great many plants sold for screening are solid at eye level for six or eight years and then go bare at the bottom as they mature.</strong> The canopy lifts, the lower branches shade out and die back, and what was a wall becomes a row of trunks with a view through it. By then it has had a decade of growth and starting over is a genuinely unpleasant conversation.</p>

<p>So the first thing to establish is what is being screened and from where. A screen that has to block a neighbor's deck from a kitchen window is working at eight or ten feet. A screen blocking headlights from a road is working at three. Those are different plants and the second one is the harder brief, because low density is what most screening plants lose first.</p>

<p>The second thing is time. A screen is years, not seasons, and the number depends on install size and spacing more than on species. Planting closer buys speed and costs you later, when the row crowds itself and starts thinning from the inside.</p>

<p>And the plant has to survive the site. An exposed property line on open ground is the hardest spot on most properties, and it is where screens get asked for.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Stand where the customer stands and look at what they want gone.</strong> Then you know the height, and the height decides the plant.</p>

<ul>
<li><strong>Check mature density at the working height, not mature height.</strong> Bare-at-the-bottom is the failure mode and it shows up in year eight.</li>
<li><strong>Low screening is the hard brief.</strong> Headlights, a road, a neighbor's yard at ground level. Say so and plan for it rather than assuming height covers it.</li>
<li><strong>Give the real timeline.</strong> Somebody expecting privacy next summer from a five-gallon install will be unhappy on schedule.</li>
<li><strong>Tight spacing buys speed and costs thinning later.</strong> If they want it fast, price it honestly including what happens at year ten.</li>
<li><strong>Exposed property lines on open ground:</strong> wind, deer and winter sun all at once. Upright juniper over arborvitae, every time.</li>
</ul>

<p><strong>What to tell a customer:</strong> a screen is a ten-year plant doing a one-year job for the first few years. Choosing for density at the right height is what makes it still work in year fifteen.</p>
HTML,
  'title' => 'Hedge & Screening Plants | Brookstone Outdoors',
  'desc' => 'Mature height is the wrong question. Why screens go bare at the bottom by year eight, why low screening is harder, and what to plant on an exposed line.',
];

$TERMS['Pollinator-Friendly'] = [
  'short' => 'Feeds bees, butterflies and the rest. Succession matters more than quantity — something in flower from the first warm weeks to hard frost beats a mass of one thing in June.',
  'public' => <<<'HTML'
<p>A pollinator planting works on succession, not on volume. Twenty of one plant that flowers for three weeks is a feast followed by nothing, and nothing is the part that matters — pollinators need forage across the whole season, and the gaps are what limit them.</p>

<p>So the useful version is a handful of species with overlapping bloom windows, running from the first warm weeks of spring through to hard frost. <strong>Early and late are the valuable ends.</strong> Midsummer takes care of itself in most yards; it is the plant flowering in April and the one still going in October that do the real work.</p>

<p>Variety of flower shape matters too, more than most people expect. A short-tongued bee and a butterfly cannot feed from the same flower, so a planting that is all one shape feeds one group. Flat clusters, tubes and open daisies between them cover most of what is out there.</p>

<p>Two practical notes. Native plants generally support more local insects than introduced ones, because the relationships are older — this is one of the places where the native question has a real answer. And doubled or heavily bred ornamental flowers frequently have little pollen or nectar left in them, which is why a bed of showy cultivars can be busy with color and empty of bees.</p>

<p><strong>And it has to be coordinated with any spray program on the property.</strong> There is no point planting forage and then treating it.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>⚠ Check the spray schedule before quoting a pollinator planting.</strong> If the property is on a program, the two have to be coordinated or we are working against ourselves and the customer will eventually notice.</p>

<ul>
<li><strong>Spread the bloom window, do not mass one species.</strong> Something in flower from first warmth to hard frost. Early and late are the scarce ends.</li>
<li><strong>Mix flower shapes.</strong> Flat clusters, tubes, open daisies. One shape feeds one group of insects.</li>
<li><strong>Lean native where it fits.</strong> Longer-standing relationships, more insects supported, and it is defensible rather than a slogan.</li>
<li><strong>Avoid heavily doubled cultivars</strong> for pollinator work — bred for petals, often short on pollen and nectar.</li>
<li><strong>Site it away from doors, walks and seating</strong> if the customer is nervous about bees. Far corner, not the patio edge.</li>
</ul>

<p><strong>What to tell a customer:</strong> pollinators need a season of food, not a week of it. Six things flowering in turn beats sixty of one, and it costs the same.</p>
HTML,
  'title' => 'Pollinator-Friendly Plants | Brookstone Outdoors',
  'desc' => 'Succession beats quantity — early and late bloom do the real work. Why flower shape matters, why doubled cultivars often feed nothing, and the spray conflict.',
];

/* ---------------------------------------------------------------- BATCH 4 */

$TERMS['Clumping'] = [
  'short' => 'Grows in tight, dense clusters that hold their shape instead of running. Clumping plants stay where they are put, which makes them predictable in a bed and slow to need dividing.',
  'public' => <<<'HTML'
<p>A clumping plant expands slowly outward from a single crown and stays where it was planted. It is the opposite of a runner, and the difference shows up three or four years in — one of them is still the shape you planted and the other is in the lawn.</p>

<p>That predictability is the whole value. A clumping plant can be spaced to a plan and the plan still holds in year five. It does not need edging to contain it, it does not come up where it was not wanted, and it does not have to be pulled out of its neighbors every spring.</p>

<p>The trade is patience and cost. Clumpers fill a bed slowly, so a planting that will look right in year four looks sparse in year one, and covering the same area takes more plants. On ornamental grasses in particular this is the distinction worth paying for — a clumping grass is an asset and a running grass in the wrong bed is a long-term problem.</p>

<p>Most eventually want dividing, when the center of the clump thins and the growth moves to the edges. On most perennials that is somewhere between year four and year seven, and it is routine rather than a failure.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Clumping is the safe habit in a mixed bed.</strong> When a design has to hold its shape, this is what holds it.</p>

<ul>
<li><strong>Know which grasses clump and which run</strong> before anything goes on a plan. A running grass in a perennial bed is a removal job.</li>
<li><strong>Space to mature clump width.</strong> It will look thin in year one and correct by year three — tell the customer before they ask.</li>
<li><strong>Dead center, growth at the edges, means it wants dividing.</strong> Not a failure. Put it on the maintenance schedule rather than replacing the plant.</li>
<li><strong>Clumpers cost more per square foot covered.</strong> Say why: they stay put, and the cheap alternative is one we come back to pull.</li>
</ul>

<p><strong>What to tell a customer:</strong> this one stays where we put it. That is worth more in a designed bed than faster coverage is, and it is the reason the spacing looks generous now.</p>
HTML,
  'title' => 'Clumping Plants & Grasses | Brookstone Outdoors',
  'desc' => 'Clumping plants stay where they are put, which keeps a planting plan intact in year five. Why it matters most with ornamental grasses, and when to divide.',
];

$TERMS['Deciduous'] = [
  'short' => 'Drops its leaves in fall and regrows them in spring. Deciduous plants give you seasonal change and winter light, and their bare structure is part of what a planting looks like for five months here.',
  'public' => <<<'HTML'
<p>Deciduous plants spend roughly five months of the year here without leaves, and whether that is a cost or a feature depends entirely on whether the planting was designed for it.</p>

<p><strong>It is also why they are the easier half of the catalog on this ground.</strong> A dormant plant loses almost no water. An evergreen beside it is still transpiring through a dry January wind into frozen ground it cannot draw from — which is why winter burn is an evergreen problem and not a deciduous one, and why most of the toughest, lowest-water plants here are deciduous.</p>

<p>Bare is also useful. A deciduous tree on the south or west side of a house shades it in July and lets the sun through in January, which is a thing no evergreen can do. The same applies to a bed under a window.</p>

<p>And bare is not nothing to look at. Red twigs against snow, peeling bark, held fruit, a good silhouette — the structure of a deciduous planting in January is a real part of the design, and the plantings that read as empty in winter are the ones where nobody thought about it.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Lead with deciduous on exposed sites.</strong> Dormant plants do not winter-burn, and that is the honest recommendation on a windy southwest corner.</p>

<ul>
<li><strong>Deciduous on the south and west of a house</strong> gives summer shade and winter sun. Point this out — most people have not thought of it and it is a real benefit.</li>
<li><strong>Raise the January question on any all-deciduous plan.</strong> "What do you see from the kitchen in February?" It usually adds an evergreen or a winter-interest shrub.</li>
<li><strong>Do not strip winter structure at fall cleanup.</strong> Seed heads, red twigs and held fruit are the January view.</li>
<li><strong>Know which way it sets buds before pruning.</strong> Spring bloomers after flowering; summer bloomers in late winter.</li>
</ul>

<p><strong>What to tell a customer:</strong> deciduous is the easier plant here, not the lesser one — wider choice, more flowering, better fall color, and it does not burn out over winter. The trade is five bare months, and a planting built for that still looks like something in January.</p>
HTML,
  'title' => 'Deciduous Plants & Winter Structure | Brookstone',
  'desc' => 'Five bare months, and the easier half of the catalog here — dormant plants do not winter-burn. Why deciduous shade belongs on the south side of a house.',
];

$TERMS['Evergreen'] = [
  'short' => 'Holds its foliage year-round. Evergreens carry the structure of a planting through winter, which matters more at this elevation than where snow cover is brief and March is green again.',
  'public' => <<<'HTML'
<p>An evergreen is doing its job in February, which is the whole case for it. Five months of the year everything else is bare, and whatever holds foliage is carrying the shape of the yard.</p>

<p><strong>February is also when it is under the most stress, and the cause is not cold.</strong> An evergreen keeps its leaves, so it keeps losing water through them all winter — and when the ground is frozen the roots cannot replace what is lost. Add dry wind and the intense winter sun we get at this elevation and the plant dries out from the exposed side inward. Foliage goes brown and brittle. That is winter burn, and it has nothing to do with hardiness ratings.</p>

<p>It shapes which evergreens work here. Needled and scaled plants — junipers, pines, dwarf spruce — have less surface area losing water and a waxy coating holding it in, and most of them come from somewhere just as dry. Broadleaf evergreens have more leaf exposed and show it; boxwood is the one most often asked for and it browns on the south and west sides unless it is sited carefully.</p>

<p>Two things decide the outcome: where it goes, and whether it went into winter watered. North and east exposures out of the prevailing wind, and a deep soak before the ground freezes. That soak is the most useful thing anybody can do for an evergreen here and almost nobody does it.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Siting and the late-fall soak are most of the job.</strong> The right evergreen in the wrong exposure fails visibly, usually on the side facing the street.</p>

<ul>
<li><strong>Broadleaf evergreens go north or east, sheltered.</strong> Never an exposed southwest corner, never over light rock mulch.</li>
<li><strong>Needled and scaled are the safe recommendation</strong> on any exposed site — juniper, mugo, dwarf spruce.</li>
<li><strong>Deep water before ground freeze, every evergreen, every year.</strong> Care sheet and maintenance schedule. It prevents the March phone call.</li>
<li><strong>Winter burn is not dead.</strong> Wait until June before quoting a replacement, and do not cut it back hard in March.</li>
<li><strong>Protect from browse the first two winters.</strong> In February an evergreen is one of very few green things available.</li>
</ul>

<p><strong>What to tell a customer:</strong> evergreens hold the yard together in winter and they work harder for it than anything else out there. Watered going into fall and put in the right spot, they are reliable. On an exposed corner with no fall water, they brown and it looks like our plant selection.</p>
HTML,
  'title' => 'Evergreen Plants & Winter Burn | Brookstone Outdoors',
  'desc' => 'Winter burn, not cold, browns an evergreen here — water lost through foliage the frozen roots cannot replace. Why needled beats broadleaf, and the fall soak.',
];

$TERMS['Groundcover'] = [
  'short' => 'Grows low and spreads wide, covering soil rather than standing above it. Used to suppress weeds, hold soil on a slope, and fill the places where turf is impractical to mow or water.',
  'public' => <<<'HTML'
<p>A groundcover does what turf does without the mowing, the water or the edges — it closes bare soil, keeps weeds from getting a start, and holds ground that would otherwise wash.</p>

<p>Its real value here is the awkward ground. Slopes too steep to mow safely. Dry shade under a mature tree where turf has never worked. Strips between a walk and a drive too narrow to water without soaking the concrete. Those are places where a lawn is a permanent argument and a groundcover is a one-time solve.</p>

<p><strong>It is not a lawn substitute where people walk.</strong> Almost nothing low and spreading takes foot traffic the way turf does, and a groundcover used as a path wears through in one season. If somebody needs to cross it, it needs stepping stones in it from the start.</p>

<p>Two things worth knowing before choosing one. Spreading is the point, so the plant has to be contained where containment matters — against a lawn edge, a bed line, a neighbor. And weed suppression only works once it has closed. In the first two seasons, before the canopy knits, a groundcover bed needs weeding like any other, and the ones that fail are usually the ones abandoned in year one.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Groundcover is the answer to ground that fights turf.</strong> Slopes, dry shade, narrow strips. Lead with the problem, not the plant.</p>

<ul>
<li><strong>Never sell it as walkable.</strong> If there is a route across it, stepping stones go in at install.</li>
<li><strong>Edge it where it meets lawn.</strong> Spreading is the feature and it does not know where to stop.</li>
<li><strong>Years one and two need weeding.</strong> Suppression starts when the canopy closes. Say so, or the customer concludes it does not work.</li>
<li><strong>Dry shade under a mature tree needs its own water</strong> and hand-dug holes between roots. Hardest site in the yard — price it that way.</li>
<li><strong>Check spread rate against the space.</strong> An aggressive cover in a small bed is next year's removal.</li>
</ul>

<p><strong>What to tell a customer:</strong> it takes two seasons to do its job, and after that it is the lowest-maintenance ground in the yard. The weeding in year one is what buys that.</p>
HTML,
  'title' => 'Groundcover Plants | Brookstone Outdoors',
  'desc' => 'For slopes too steep to mow, dry shade under trees and strips too narrow to water. Why groundcover is not walkable, and why years one and two need weeding.',
];

$TERMS['Spreading'] = [
  'short' => 'Widens outward over time, often well past its original footprint. Spreading plants fill large areas economically, and they have to be spaced for what they become rather than what comes off the truck.',
  'public' => <<<'HTML'
<p>A spreading plant keeps widening — by runner, by rooting where branches touch, or just by growing outward year after year. Used deliberately, it is the cheapest way to cover ground, because a few plants eventually do the work of many.</p>

<p><strong>Used carelessly it is the most common planting mistake there is.</strong> A spreader planted at the spacing that looks right on install day is a spreader growing into its neighbors by year four, and the fix is always removal — either of it or of what it crowded.</p>

<p>So the number that matters is mature width, and it has to be believed. Half the mature width from any hard edge, half the combined widths between two plants. A bed spaced that way looks sparse the first year, correct in year three, and like the drawing by year five.</p>

<p>Worth separating two things the same word covers. A plant that spreads by slowly widening its own crown is predictable and stays one plant. A plant that spreads by runner or by self-seeding travels, and traveling is a different commitment — near a lawn, a fence line, a ditch or open ground, that is the question to ask before it goes in, not after.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Space to mature width and hold the line on it.</strong> This is the habit customers push back on hardest and the one where being right matters most.</p>

<ul>
<li><strong>Half the mature width from every hard edge</strong> — walk, drive, foundation, fence.</li>
<li><strong>Establish how it spreads before it goes on the plan.</strong> Widening crown is predictable. Runners and self-seeders travel, and near a ditch or open ground that is a different conversation.</li>
<li><strong>When they say it looks empty, do not add plants.</strong> Describe year three. A bed that looks full on install day is one we thin at their expense.</li>
<li><strong>Aggressive spreaders get checked against the noxious weed list.</strong></li>
</ul>

<p><strong>What to tell a customer:</strong> spreading is how we cover a large area without buying three times the plants. The price of it is spacing that looks generous today, and that is the part worth trusting us on.</p>
HTML,
  'title' => 'Spreading Plants & Spacing | Brookstone Outdoors',
  'desc' => 'Spreading plants cover ground cheaply and must be spaced for what they become. The gap between a widening crown and a runner, and why it decides siting.',
];

$TERMS['Vining'] = [
  'short' => 'Climbs or trails rather than standing on its own. How a vine attaches — twining, tendrils, clinging or scrambling — decides what the support has to be, and that is a decision made before planting.',
  'public' => <<<'HTML'
<p>A vine has no self-supporting structure. Everything about using one comes down to what it holds onto and how, and there are four ways it does that.</p>

<ul>
<li><strong>Twiners</strong> wrap their whole stem around something. They need a post, a wire or a slim upright — they cannot wrap a flat wall or a wide beam.</li>
<li><strong>Tendril climbers</strong> grip with thin coiling shoots and need something slender to catch: wire, netting, thin lattice. A tendril cannot grab a four-by-four.</li>
<li><strong>Clingers</strong> attach directly to a flat surface with aerial roots or adhesive pads. They need no structure at all, which is the appeal and the problem.</li>
<li><strong>Scramblers</strong> do not climb. They lean, and they have to be tied in — climbing roses are the familiar example.</li>
</ul>

<p><strong>Match the vine to the support before planting, not after.</strong> A twiner on a flat wall will never get hold of it, and a tendril climber on a heavy pergola post does the same nothing.</p>

<p>Two cautions. A mature vine is heavy, and wet snow on one makes it far heavier — a trellis screwed to siding with two anchors comes off the wall. And clingers should not go on wood siding, stucco or mortar that would ever need repointing; on sound masonry or a standoff frame they are fine, and anywhere else they are a repair bill.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Establish the support before you quote the vine.</strong> This is the one habit where the wrong pairing means the plant simply never climbs.</p>

<ul>
<li><strong>Twiner → post or wire. Tendril → wire or thin lattice. Clinger → sound masonry only. Scrambler → tying in, forever.</strong></li>
<li><strong>Build the support for a wet snow load.</strong> Lag into framing, not two screws into siding.</li>
<li><strong>No clingers on wood, stucco, or mortar we would have to repoint.</strong> Standoff frame if the customer insists.</li>
<li><strong>Scramblers are a maintenance line.</strong> Somebody ties them in every year — put it on the schedule or do not sell the look.</li>
<li><strong>Check what the vine will reach in ten years</strong> — gutters, roof edge, window trim.</li>
</ul>

<p><strong>What to tell a customer:</strong> the support is part of the plant, not an accessory. Get that right at install and the vine does the rest; get it wrong and it sits there.</p>
HTML,
  'title' => 'Vines & Climbing Plants | Brookstone Outdoors',
  'desc' => 'Twiners, tendril climbers, clingers and scramblers each need a different support. Match it before planting, and keep clingers off siding, stucco and mortar.',
];

/* ---------------------------------------------------------------- BATCH 5 */

$TERMS['Spring-Blooming'] = [
  'short' => 'Flowers early, often before much else has leafed out. At this elevation an early bloomer can open ahead of the last frost, so exposure and placement matter as much as the plant does.',
  'public' => <<<'HTML'
<p>Spring bloom is the most anticipated thing in a yard and the least reliable, and both facts have the same cause: it happens at the edge of winter.</p>

<p><strong>An early flowering plant can open ahead of the last hard freeze and lose the whole year's display overnight.</strong> That is not a defective plant and it is not bad luck — it is placement. A warm week in April convinces the plant that winter is over, the buds open, and the frost that follows takes them.</p>

<p>Which means exposure does more work than species choice. The same variety on a warm south or west wall breaks dormancy early every year and gambles. On a north or east exposure it stays cold longer, opens later, misses most frosts and flowers reliably. Putting early bloomers on the cold side of a property sounds backwards and is the single most useful thing to know about them here.</p>

<p>The other half is pruning, and it costs more flowers in this valley than frost does. <strong>Spring bloomers set their buds the previous summer, on last year's wood.</strong> Prune in late winter or early spring and the flowers come off before they ever open — the plant is perfectly healthy and simply never blooms, which is exactly how it gets diagnosed as a bad plant. They get pruned right after they finish flowering.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Early bloomers go north and east.</strong> It sounds wrong to customers and it is the difference between flowers most years and flowers some years.</p>

<ul>
<li><strong>Never prune a spring bloomer in late winter or early spring.</strong> Buds were set last summer. Prune right after flowering.</li>
<li><strong>If you do not know which it is, do not cut it.</strong> Ask. A missed season is a phone call.</li>
<li><strong>Keep them off warm south and west walls</strong> where reflected heat pushes them early into the next frost.</li>
<li><strong>If a bloom gets frosted, explain it the same week.</strong> Said early it is weather. Said after they complain it sounds like an excuse.</li>
<li><strong>Frosted flowers do not mean a damaged plant.</strong> It will bloom next year. Say that plainly.</li>
</ul>

<p><strong>What to tell a customer:</strong> we put the early ones where they stay cold longest. That is deliberate, and it is why they flower in the years their neighbor's do not.</p>
HTML,
  'title' => 'Spring-Blooming Plants & Late Frost | Brookstone',
  'desc' => 'Early bloomers belong on the cold side of a property — north and east, where they open after the frost. And why pruning in March removes the flowers entirely.',
];

$TERMS['Fall Color'] = [
  'short' => 'Turns color before leaf drop. Worth knowing that fall color here varies a lot year to year — a warm dry September pushes it late and dulls it — so a planting should not rest on it alone.',
  'public' => <<<'HTML'
<p>Fall color is the reward at the end of the season and it is less dependable here than in the places the photographs come from.</p>

<p>The color itself is a chemical process that depends on weather. Warm sunny days and cool — not freezing — nights produce the best displays. <strong>A warm, dry September pushes the whole thing late and mutes it, and a hard early freeze can end it in one night</strong>, taking the leaves down still green. Both happen here often enough that a good year is a good year rather than the norm.</p>

<p>Drought does the same thing from the other direction. A tree that has been short of water through August drops its leaves early to cut losses, which is a sensible thing for a tree to do and the end of its fall color. Watering through a dry late summer is the one thing within anybody's control.</p>

<p>None of which is an argument against planting for it — a good October here is genuinely worth having. It is an argument against a planting that depends on it. Build the bones on foliage, form and winter structure, and treat fall color as the thing that makes a good year better rather than the thing holding the design up.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Do not let a design rest on fall color.</strong> It is a bonus in this climate, not a reliable feature, and selling it as the centrepiece sets up a disappointing October.</p>

<ul>
<li><strong>Water through a dry late summer.</strong> Drought-stressed trees drop early and color poorly. This is the one controllable factor.</li>
<li><strong>Set expectations at the sale.</strong> Good years and quiet years. Said up front, a muted October is weather.</li>
<li><strong>Site for where it is seen.</strong> Fall color behind the garage is wasted — put it on the approach or in the window view.</li>
<li><strong>A hard early freeze takes leaves down green.</strong> Nothing is wrong with the tree; say so before anyone asks.</li>
</ul>

<p><strong>What to tell a customer:</strong> we will give them trees that color well, and some years will be better than others. The planting should look right in July and January too, and then October is the bonus it should be.</p>
HTML,
  'title' => 'Fall Color Trees & Shrubs | Brookstone Outdoors',
  'desc' => 'Fall color varies here — a warm dry September dulls it and an early freeze ends it. Why drought-stressed trees drop early, and what to design around instead.',
];

$TERMS['Winter Interest'] = [
  'short' => 'Holds something worth looking at once everything else has gone — berries, bark, seed heads or evergreen form. Winter is the longest season on this calendar and the one most plantings ignore.',
  'public' => <<<'HTML'
<p>Winter is the longest single season in this valley and it is the one most plantings have nothing to say about. Five months of bare sticks and frozen ground, viewed mostly from inside a warm house through a window.</p>

<p>Which is a strange thing to design around last, and it is the cheapest gap to fill. Winter interest comes in four forms and most of them cost nothing extra:</p>

<ul>
<li><strong>Bark and stems.</strong> Red-twig dogwood against snow, peeling birch or amur maple, the dark structure of a well-shaped tree.</li>
<li><strong>Persistent fruit.</strong> Berries that hold through January rather than dropping in October — crabapple, sumac, coralberry, rose hips.</li>
<li><strong>Seed heads and standing grasses.</strong> Left up rather than cut down, they catch snow and move in wind, which is the only motion in the yard for months.</li>
<li><strong>Evergreen mass.</strong> The shape that stays when everything else is outline.</li>
</ul>

<p>The one rule is placement. Winter interest has to be where it is actually seen — the view from the kitchen or the living room, and the walk from the car to the door. Nobody is strolling the back of the property in January, and a beautiful red-twig dogwood behind the garage is a beautiful plant nobody looks at.</p>

<p>And it has to survive the fall cleanup. Most of what makes a winter garden is the stuff a thorough autumn tidy removes.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Ask it on every design walk: what do you see from the kitchen window in February?</strong> Most people have never thought about it and the answer changes the plan.</p>

<ul>
<li><strong>Place winter interest in the sight lines</strong> — the window view and the approach from the drive. Nowhere else counts in January.</li>
<li><strong>⚠ Do not strip it at fall cleanup.</strong> Grasses, seed heads and held fruit are the winter garden. Cut grasses in late winter before new growth, not in October.</li>
<li><strong>If a customer wants everything cut clean in fall, tell them what they are giving up.</strong> Their call, but it should be a choice.</li>
<li><strong>Snow is the backdrop.</strong> Red stems, dark berries and strong silhouettes read against white. Subtle does not.</li>
</ul>

<p><strong>What to tell a customer:</strong> it is five months a year and it usually costs nothing extra — the same number of plants, chosen with January in mind.</p>
HTML,
  'title' => 'Winter Interest Plants | Brookstone Outdoors',
  'desc' => 'Bark, persistent fruit, standing seed heads and evergreen form. The longest season here and the one most plantings ignore — and the cleanup that erases it.',
];

/* ---------------------------------------------------------------- BATCH 6 */

$TERMS['Native'] = [
  'short' => 'Occurs naturally in this region. Generally lower-input once established — though native to Colorado and native to a 5,500-foot valley with alkaline soil are not the same claim.',
  'public' => <<<'HTML'
<p>"Native" is the most useful word on a plant tag and the easiest to over-read.</p>

<p><strong>Colorado runs from high desert at four thousand feet to alpine tundra above eleven thousand</strong>, across soils with nothing in common. A plant native to the eastern plains, or to a wet mountain meadow, or to the Front Range, can be entirely unsuited to a dry valley floor with alkaline ground. The useful question is never whether something is native — it is native to what, at what elevation, in what soil.</p>

<p>Where a plant is genuinely native to conditions like these, the advantages are real and not marketing. It has already solved the soil chemistry that makes other things go yellow. It expects this much precipitation. It is built for a seventy-five degree afternoon followed by a thirty-five degree night. And it supports the insects and birds that evolved alongside it, which introduced plants mostly do not.</p>

<p>Two things native does not mean. It does not mean no water — a native planting still needs two seasons of establishment watering like anything else, and the failures almost always trace to somebody believing otherwise. And it does not mean no maintenance; it means less intervention to stay healthy, not none.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Do not sell native as automatically low-water or low-maintenance.</strong> It is a strong indicator, not a specification, and it is wrong often enough to matter.</p>

<ul>
<li><strong>Ask the supplier what conditions it is actually native to.</strong> Mountain meadow natives want water we do not have.</li>
<li><strong>Two seasons of establishment water, same as anything.</strong> Care sheet. This is where native plantings fail.</li>
<li><strong>Lean native on pollinator work</strong> — the relationships are older and the benefit is real rather than a slogan.</li>
<li><strong>Know the local natives on our list and sell them on merit</strong> — apache plume, fernbush, rabbitbrush, mountain mahogany. Most competitors do not carry them.</li>
</ul>

<p><strong>What to tell a customer:</strong> a plant native to this ground is usually the lowest-input thing we can put in. Native to somewhere else in Colorado may be no advantage at all, and we check which one we are dealing with.</p>
HTML,
  'title' => 'Native Plants for the Western Slope | Brookstone',
  'desc' => 'Colorado runs from high desert to alpine tundra. Why the real question is native to what elevation and soil, and why natives still need two seasons of water.',
];

$TERMS['Hybrid'] = [
  'short' => 'Bred from two parents for a specific trait — disease resistance, hardiness, compact habit, alkaline tolerance. Often the more reliable choice where a straight species struggles here.',
  'public' => <<<'HTML'
<p>A hybrid exists because somebody wanted a plant to do something the species did not do well enough, and that is usually worth knowing about.</p>

<p>The traits bred for are the ones that cause problems: disease resistance, cold hardiness, a more compact mature size, tolerance of difficult soil. <strong>On a site that is genuinely hard — and alkaline ground at elevation qualifies — a plant bred for the problem frequently outperforms a straight species that merely tolerates it.</strong> That is the opposite of the reputation hybrids carry, and the reputation is mostly borrowed from a different argument about agriculture.</p>

<p>What hybrids are not is a shortcut past site conditions. A hybrid bred for disease resistance is still the wrong plant if it wants acid soil, and no amount of breeding changes what a plant needs from the ground it is in.</p>

<p>One practical note: most do not come true from seed. Seedlings off a hybrid revert toward one parent or the other, which matters if somebody plans to propagate and matters not at all otherwise. Named cultivars are propagated by cutting or graft for exactly that reason.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>On a difficult site, look at hybrids first.</strong> Alkaline tolerance, disease resistance and compact habit are all things somebody has already bred for.</p>

<ul>
<li><strong>Know what a given hybrid was bred for.</strong> That is the selling point and it is specific — not "it is improved."</li>
<li><strong>A hybrid does not escape site conditions.</strong> Wrong soil is still wrong soil.</li>
<li><strong>Compact cultivars solve spacing problems</strong> near walks and foundations. Often the right answer where the species is too big.</li>
<li><strong>Does not come true from seed.</strong> Only matters if the customer wants to propagate — mention it then.</li>
</ul>

<p><strong>What to tell a customer:</strong> hybrids get a bad name they have not earned in landscaping. Somebody spent years breeding out the exact problem this site has, and on hard ground that is usually the better plant.</p>
HTML,
  'title' => 'Hybrid Plants & Cultivars | Brookstone Outdoors',
  'desc' => 'Bred for disease resistance, hardiness or alkaline tolerance — often the better performer on hard ground. What hybrids solve, and what they cannot change.',
];

$TERMS['Exotic'] = [
  'short' => 'Introduced from outside the region. Plenty perform well here. Anything introduced gets checked against the Colorado noxious weed list before it goes on a plan.',
  'public' => <<<'HTML'
<p>Most of what grows in this valley's yards came from somewhere else, and a great deal of it does very well. Lilac, apple, peony and most of the shade trees on any street here are introduced, and nobody sensible argues they should not be.</p>

<p>So "exotic" is not a warning. It is a prompt to ask one question: <strong>does this plant spread on its own?</strong></p>

<p>A plant that thrives in this climate and also reproduces without help is not an ornamental — it is a future removal job, and frequently somebody else's. Seeds move on wind, water and birds, and the ditches and fence lines here are efficient at carrying them. A few introduced plants that were sold as ornamentals for decades are now the reason crews spend days on removal along the drainages.</p>

<p>Which is why anything introduced and heavily fruiting or seeding gets checked against the <strong>Colorado noxious weed list</strong> before it goes on a plan — List A, B or C. It takes a minute and it is not optional. Near a ditch, a fence line or open ground, that check matters more than anywhere else.</p>

<p>Everything that clears it is simply a plant, judged like any other on whether it suits the site.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Check anything introduced against the Colorado noxious weed list before it goes on a plan.</strong> Lists A, B and C. A minute, every time.</p>

<ul>
<li><strong>The question is spread, not origin.</strong> Introduced and well-behaved is fine. Introduced and self-seeding is a problem.</li>
<li><strong>Extra care near ditches, fence lines and open ground.</strong> What we plant there becomes the neighborhood's.</li>
<li><strong>If a customer brings a plant they sourced elsewhere, check it before installing</strong> — whatever they paid for it.</li>
<li><strong>Do not frame exotic as inferior.</strong> Most of the best plants on our list are introduced and the customer probably owns several.</li>
</ul>

<p><strong>What to tell a customer:</strong> where a plant comes from matters much less than whether it stays where we put it. That is the only thing we screen for, and we screen for it every time.</p>
HTML,
  'title' => 'Introduced & Exotic Plants | Brookstone Outdoors',
  'desc' => 'Most of what grows in this valley came from somewhere else and does well. The one question that matters is whether it spreads — and the noxious weed check.',
];

/* ---------------------------------------------------------------- BATCH 7 */

$TERMS['Disease-Resistant'] = [
  'short' => 'Bred or selected to shrug off the diseases that go around. Worth paying for on anything we maintain, because a susceptible variety becomes a recurring call rather than a one-time problem.',
  'public' => <<<'HTML'
<p>Disease resistance is the quietest money-saver on a plant tag. It does not show in the first year and it shows every year after.</p>

<p><strong>Disease runs along plant family lines</strong>, which is why it clusters. Fire blight moves through the rose family — apple, pear, crabapple, hawthorn, mountain ash, serviceberry. Cedar-apple rust needs a juniper and a rose-family host within about a mile. Plant several susceptible relatives on one property and they reinforce each other.</p>

<p>Resistance is not immunity. A resistant variety under enough pressure, in a wet spring, on a stressed tree, can still get sick — it just needs more to go wrong. That margin is the whole value, and it compounds over the twenty years a tree is in the ground.</p>

<p>It is worth most on anything that will be maintained, because a susceptible plant converts into labour. Pruning out blight in dry weather, sanitizing between cuts, hauling infected wood off site — that is a recurring visit, and it continues for the life of the plant. The resistant variety that cost a little more at install stops costing anything at all.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Specify resistant varieties on anything we maintain.</strong> A susceptible plant becomes our recurring time, and the customer eventually asks why.</p>

<ul>
<li><strong>Think in families.</strong> Rose family on one property — apple, crabapple, hawthorn, pear, mountain ash, serviceberry — shares fire blight and cedar-apple rust.</li>
<li><strong>Look at what is already on the property and next door</strong> before adding another host.</li>
<li><strong>Fire blight: blackened tips curled like a shepherd's crook.</strong> Prune well below in dry weather, sanitize between cuts, remove prunings. Never prune it wet.</li>
<li><strong>Resistance is a margin, not immunity.</strong> Do not promise a plant will never get sick.</li>
<li><strong>Stressed plants get sick.</strong> Water and correct siting are the first line.</li>
</ul>

<p><strong>What to tell a customer:</strong> the resistant variety costs a bit more once. The susceptible one costs a little every year, and the difference is not close over the life of a tree.</p>
HTML,
  'title' => 'Disease-Resistant Plants | Brookstone Outdoors',
  'desc' => 'Disease runs along plant family lines, which is why it clusters. Why resistance is a margin rather than immunity, and where it is worth the most.',
];

$TERMS['Fast-Growing'] = [
  'short' => 'Puts on size quickly, which is a trade and not a free gain. Fast growers tend to make weaker wood and live shorter lives, so they get chosen knowingly and given room.',
  'public' => <<<'HTML'
<p>Fast growth is the most requested trait in a landscape and the one with the clearest cost attached.</p>

<p><strong>Plants that grow quickly make lower-density wood, and lower-density wood breaks.</strong> That combination is how a shade tree planted for quick results becomes a split trunk in a wind event fifteen years later, and in this valley the wind event always comes. Fast growers also tend to live shorter lives — which is only a problem if somebody expected a permanent tree.</p>

<p>None of that makes them wrong. A screen somebody needs in this decade, a windbreak on acreage, something to hold the space while a slower tree establishes — these are legitimate reasons, and the fast grower is the correct plant for them.</p>

<p>What matters is choosing it knowingly. A fast-growing tree near a house, a drive, a power line or a septic field is a decision that should be made with its mature size and its wood strength on the table. Several of the fastest growers available here are also the ones whose roots find sewer laterals, which is a separate and more expensive problem.</p>

<p>The honest framing is that fast growth buys time and spends durability, and there are places where that trade is worth making and places where it absolutely is not.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Say what the trade is, every time.</strong> Quicker results, weaker wood, shorter life. Most customers accept it once they have heard it.</p>

<ul>
<li><strong>Near a structure, drive or power line: put mature size and wood strength in the estimate.</strong> Then it is their decision on the record.</li>
<li><strong>Cottonwood, willow, silver maple, boxelder</strong> — fast, and the ones whose roots find sewer laterals and leach fields. Ask where the lines run.</li>
<li><strong>Offer the pairing:</strong> a fast grower for now and a slow one beside it for later, with the fast one coming out eventually. That is a real design, not a compromise.</li>
<li><strong>Structural pruning in the early years</strong> reduces the split risk substantially. Schedule it at install.</li>
</ul>

<p><strong>What to tell a customer:</strong> fast growth buys time and spends durability. In the right place that is a good trade. Against the house it is not, and we will say so.</p>
HTML,
  'title' => 'Fast-Growing Trees & Shrubs | Brookstone Outdoors',
  'desc' => 'Fast growth makes weaker wood and shorter-lived plants. Where that trade is worth making, where it is not, and which fast growers find sewer lines.',
];

$TERMS['Long-Lived'] = [
  'short' => 'Measured in decades rather than years. Worth siting as though the decision is permanent, because for the next owner of the property it will be.',
  'public' => <<<'HTML'
<p>A long-lived plant is the opposite trade from a fast grower. It takes its time, it makes dense strong wood, and it outlasts the person who planted it.</p>

<p><strong>Which changes how carefully it should be placed.</strong> A short-lived plant in a slightly wrong spot is a twelve-year inconvenience. A long-lived tree in the wrong spot is a problem that outlives the mistake — it is still too close to the foundation in 2070, and by then removing it is a crane and a crew rather than a decision.</p>

<p>So the measurements matter more here than anywhere: mature width against the house, against the drive, against the property line. What is overhead. Where the sewer lateral runs. These are the same checks as for any tree and the consequences simply last longer.</p>

<p>The payoff is real. A long-lived tree is the single highest-value plant on a property — for shade, for the look of the place, and for what it does to the value of the lot. It is also the one thing in a landscape that gets better every decade without anybody doing anything, which almost nothing else does.</p>

<p>Worth planting one even late. The common objection is that somebody will not see it mature, and the answer is that nobody who planted the good trees in this valley saw them mature either.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Measure twice on anything long-lived.</strong> The mistake lasts longer than the people involved.</p>

<ul>
<li><strong>Mature width, overhead lines, sewer and septic, distance from the foundation.</strong> All four, written down, before the hole.</li>
<li><strong>Root flare at or above grade and visible.</strong> Planting depth kills more young trees than anything else, slowly.</li>
<li><strong>Do not let a long-lived tree go under a power line.</strong> Topping ruins it permanently — offer an ornamental.</li>
<li><strong>Sell the slow one honestly.</strong> Slower to establish, denser wood, far longer life. Often the better value and it needs saying out loud.</li>
</ul>

<p><strong>What to tell a customer:</strong> this is the decision on the property that lasts longest. Twenty minutes deciding where it goes is worth more than the difference between any two species on the list.</p>
HTML,
  'title' => 'Long-Lived Trees | Brookstone Outdoors',
  'desc' => 'A long-lived tree outlasts the mistake that sited it. Why the four measurements matter more here, and why planting one late is still worth doing.',
];

$TERMS['Low-Maintenance'] = [
  'short' => 'Does not need intervention to stay healthy — not fighting the soil, not disease-prone, not outgrowing its spot. It still gets watered and looked at. Low is not none.',
  'public' => <<<'HTML'
<p><strong>Low-maintenance does not mean no-maintenance</strong>, and the gap between those two is where most disappointment in a landscape lives.</p>

<p>What it actually means is that the plant is not fighting anything. It suits the soil it is in rather than tolerating it. It is not prone to the diseases that go around here. It is not going to outgrow its space and need cutting back twice a year. It does not depend on being pruned into a shape to look like anything. A plant that clears all of that needs very little from anybody.</p>

<p>What it still needs is water on a schedule for the first two seasons, water in a drought year after that, a feed occasionally, and somebody noticing if something changes. That is not a burden. It is also not nothing, and a customer told "low-maintenance" who heard "nothing" will conclude in year three that we sold them a difficult plant.</p>

<p>The honest framing is that low-maintenance is mostly a siting achievement rather than a plant property. The same shrub is low-maintenance in the right spot and a recurring problem twenty feet away in the wrong one.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Define it at the sale, in plain words.</strong> Low-maintenance means it will not need rescuing. It does not mean it needs nothing.</p>

<ul>
<li><strong>Say what it does need:</strong> establishment water, drought-year water, occasional feed, somebody looking at it. Four things, on the care sheet.</li>
<li><strong>Low-maintenance is mostly siting.</strong> Right plant, right spot. Half of what makes a planting easy is decided before anything goes in the ground.</li>
<li><strong>Ask what level of involvement they actually want</strong> before building the list. Some people want a Saturday job. There is no wrong answer and guessing produces the wrong plan.</li>
<li><strong>Do not label something low-maintenance to close a sale.</strong> It comes back in year three.</li>
</ul>

<p><strong>What to tell a customer:</strong> it will not need rescuing. It still needs water and somebody noticing. Said plainly at the sale it is reassuring; discovered later it sounds like fine print.</p>
HTML,
  'title' => 'Low-Maintenance Plants | Brookstone Outdoors',
  'desc' => 'Low-maintenance means not fighting the soil, disease or its own size. It still needs water and attention — it is mostly a siting result, not a plant trait.',
];

$TERMS['Non-Invasive'] = [
  'short' => 'Stays where it is planted. Carries more weight here than most people assume, because anything that spreads on its own in this climate becomes somebody\'s removal job downstream.',
  'public' => <<<'HTML'
<p>A non-invasive plant stays put. It does not run underground into the lawn, it does not seed itself across a bed, and it does not turn up two properties over.</p>

<p><strong>That matters more in this valley than the term suggests</strong>, because of how water moves here. Ditches and drainages run through and between properties, and they carry seed efficiently. A plant that spreads on its own on one property does not stay on that property — it ends up on a ditch bank, in a fence line, on open ground, and eventually it is somebody else's work to remove.</p>

<p>Colorado keeps a noxious weed list in three tiers, and some of what is on it was sold as an ornamental for decades. That is the pattern worth recognizing: the plants that become problems are usually the ones that performed beautifully, which is exactly why they got planted.</p>

<p>So anything that runs by rhizome or seeds heavily gets a second look before it goes on a plan, and a closer one near a ditch, a fence line or open ground. Most plants clear this easily. The ones that do not are worth catching at the design stage, when the cost is choosing something else rather than a removal contract.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Flag anything that runs or seeds heavily before it goes on a plan.</strong> Check it against the Colorado noxious weed list — A, B and C.</p>

<ul>
<li><strong>Extra scrutiny near ditches, fence lines and open ground.</strong> Water carries seed between properties here.</li>
<li><strong>Runners want containment at install</strong> — edging, a barrier, or a different plant. Do not plan to manage it later.</li>
<li><strong>A plant performing beautifully is not evidence it is safe.</strong> That is the profile of most of what ends up on the list.</li>
<li><strong>Customer-supplied plants get checked too</strong>, whatever they cost.</li>
<li><strong>If something already on the property is spreading, say so</strong> even if we did not plant it.</li>
</ul>

<p><strong>What to tell a customer:</strong> we screen for whether a plant stays where we put it. It takes a minute at design and it is the difference between a planting and a problem that spreads past the property line.</p>
HTML,
  'title' => 'Non-Invasive Plants | Brookstone Outdoors',
  'desc' => 'Ditches carry seed between properties here, so a plant that spreads does not stay yours. Why the worst offenders are the ones that performed beautifully.',
];

$TERMS['Pest-Resistant'] = [
  'short' => 'Not often troubled by the insects that go around. Worth knowing that most pest problems here follow stress — a plant short of water is a target in a way a healthy one is not.',
  'public' => <<<'HTML'
<p>Pest resistance is partly the plant and substantially the condition it is in.</p>

<p><strong>Most serious insect damage here follows stress.</strong> A healthy pine pushes attacking bark beetles out with resin; a pine that has been short of water for a season or two cannot make enough pitch and the beetles get in. Spider mites explode on plants that are hot and dry. Borers go for trees already weakened by sunscald or a trunk wound. In each case the insect is the thing that finishes it and the stress is the thing that opened the door.</p>

<p>Which makes water and correct siting the most effective pest control available, and far cheaper than treatment. Two or three deep waterings in a drought year protects a mature tree that would cost thousands to remove and replace.</p>

<p>Genuine resistance exists on top of that — varieties selected against specific pests, and plants whose foliage or chemistry simply does not appeal. It is worth having, particularly on anything that will be maintained, because a susceptible plant becomes a recurring treatment line rather than a one-time problem.</p>

<p>What it is not is a substitute for keeping a plant healthy. A stressed resistant plant gets eaten too.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Treat water as pest control, because here it mostly is.</strong> The stressed plant is the one that gets hit.</p>

<ul>
<li><strong>Deep water mature trees in a drought year.</strong> Two or three soakings at the drip line. Cheapest beetle prevention there is.</li>
<li><strong>Red or fading needles on a mature pine — check the trunk for pitch tubes and boring dust before quoting anything.</strong> Beetles change the conversation to removal, and the neighboring trees need looking at the same day.</li>
<li><strong>Spider mites mean hot and dry.</strong> Hose them off and fix the conditions before reaching for a treatment.</li>
<li><strong>Prevent trunk wounds.</strong> Mower and trimmer damage is a borer entry point — on our own routes too.</li>
<li><strong>Specify resistant varieties on maintained properties.</strong> A susceptible plant is our recurring time.</li>
</ul>

<p><strong>What to tell a customer:</strong> most of what eats a plant here goes after one that is already struggling. Keeping it watered and well sited does more than any spray, and it costs less.</p>
HTML,
  'title' => 'Pest-Resistant Plants | Brookstone Outdoors',
  'desc' => 'Most insect damage here follows stress — beetles take the pine that ran short of water. Why watering is the cheapest pest control, and what resistance adds.',
];

$TERMS['Pruning Required'] = [
  'short' => 'Needs cutting on a schedule to stay healthy or keep its shape. Not a defect — a commitment. The problem is never the pruning, it is nobody saying so at the point of sale.',
  'public' => <<<'HTML'
<p>Plenty of excellent plants need regular pruning. It is a schedule, not a flaw, and the trouble only starts when nobody mentions it.</p>

<p><strong>The most expensive version of that trouble is timing</strong>, and it costs more flowers in this valley than frost does.</p>

<p>Spring-blooming plants set their flower buds the previous summer, on last year's wood — lilac, forsythia, mockorange, quince, most viburnum. Prune those in late winter or early spring and the flowers come off before they open. The plant is perfectly healthy and simply never blooms, which is exactly how it gets written off as a bad plant. They get pruned right after they finish flowering.</p>

<p>Summer bloomers flower on the current year's growth — potentilla, butterfly bush, blue mist spirea, most hydrangea. Those get cut in late winter while dormant, and the harder they are cut the better they come back.</p>

<p>Beyond flowering, pruning does three jobs: it takes out dead and crossing wood before it becomes a wound, it keeps light and air moving through a plant that would otherwise shade out its own center, and on young trees it builds the structure that decides whether the tree splits in a storm twenty years later. That last one has a window — it has to happen while the branches are small — and it is the one most often missed.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Anything with a pruning requirement goes on the maintenance schedule at install</strong>, not the first time somebody notices it needs doing.</p>

<ul>
<li><strong>Know which way it sets buds before you cut.</strong> Spring bloomers after flowering. Summer bloomers in late winter, decisively.</li>
<li><strong>If you do not know, do not cut. Ask.</strong> A missed season is a phone call; a wrongly cut old lilac is worse.</li>
<li><strong>Structural pruning on young trees, years one to eight.</strong> Competing leaders out while they are finger-thick. Once a tight union is four inches across the decision has been made for us.</li>
<li><strong>Prune in dry weather on anything blight-prone</strong>, and sanitize between cuts.</li>
<li><strong>"Never blooms" is almost always pruning timing.</strong> Ask when it was last cut before looking at anything else.</li>
</ul>

<p><strong>What to tell a customer:</strong> this one needs cutting once a year and we will put it on the schedule. Done at the right time it flowers better every year. Done at the wrong time it never flowers at all, and that is the only real risk with it.</p>
HTML,
  'title' => 'Plants That Need Pruning | Brookstone Outdoors',
  'desc' => 'Spring bloomers set buds on last year\'s wood — prune in March and the flowers are gone. When to cut what, and the structural window on young trees.',
];

$TERMS['Slow-Growing'] = [
  'short' => 'Takes its time, and makes denser wood and a longer life for it. Costs more at install because it spent more years at the nursery, and generally repays that over the following forty.',
  'public' => <<<'HTML'
<p>A slow-growing plant is the quiet opposite of everything a fast grower offers, and in most permanent positions it is the better buy.</p>

<p><strong>Slow growth produces dense wood.</strong> Dense wood holds up in wind and wet snow, resists decay, and does not shed limbs. Slow growers also tend to live far longer, hold their shape without constant correction, and stay in proportion to the space they were planted in rather than outgrowing it in a decade.</p>

<p>The costs are real and worth being honest about. It costs more at the nursery, because it spent more years there before it was saleable — a slow-growing tree of any size represents more time than a fast one of the same size. And it asks for patience, which is the harder sell. A slow grower looks like a smaller plant for several years and then quietly becomes the best thing in the yard.</p>

<p>Where that trade works: anywhere permanent and anywhere near the house. A specimen tree, a foundation planting, a tree that is meant to be there in fifty years. Where it does not: a screen somebody needs now, or a position where something will have to come out anyway.</p>

<p>The pairing is often the right answer — a fast grower doing the job while a slow one establishes beside it, with the fast one removed when the slow one takes over. That is a design decision, not a compromise, and it should be made deliberately at install.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Sell the slow one on its merits rather than apologizing for the price.</strong> Denser wood, longer life, holds its shape, stays in scale. That is a better plant and it should sound like one.</p>

<ul>
<li><strong>Explain the price honestly:</strong> it spent more years at the nursery. The cost is time, not margin.</li>
<li><strong>Slow growers belong in permanent positions</strong> — specimens, foundations, the tree that defines the yard.</li>
<li><strong>Offer the pairing where somebody needs results now.</strong> Fast grower plus slow grower, with a removal planned. Say the removal is part of the plan.</li>
<li><strong>Set the expectation in years.</strong> Somebody expecting visible progress every season will be disappointed on schedule otherwise.</li>
</ul>

<p><strong>What to tell a customer:</strong> this one costs more and lasts three times as long. If the spot is permanent, it is the cheaper plant over the life of the yard.</p>
HTML,
  'title' => 'Slow-Growing Plants & Trees | Brookstone Outdoors',
  'desc' => 'Slow growth makes dense wood and a long life, and costs more because it took longer to grow. Where the trade is worth it, and the fast-plus-slow pairing.',
];

/* ---------------------------------------------------------------- BATCH 8 */

$TERMS['Flowering'] = [
  'short' => 'Produces flowers worth planting it for. The least reliable of the aesthetic traits here — bud hardiness and late frost both apply, and most plants flower for two or three weeks.',
  'public' => <<<'HTML'
<p>Flowering is where almost everybody starts, and there is nothing wrong with that. It is also the most conditional thing a plant does.</p>

<p><strong>A bloom depends on three things going right.</strong> The flower buds have to survive the winter, which for marginal plants is not guaranteed. The plant has to not be pruned at the wrong time, because on spring bloomers the buds were set the previous summer and a late-winter cut removes them. And a hard frost has to stay away after the plant has broken dormancy, which at this elevation is a real roll of the dice on anything early.</p>

<p>Then there is duration. Most plants flower for two or three weeks. Choosing a plant primarily for its bloom is choosing it for about five percent of the year, and the other ninety-five percent is leaves, form and bare structure.</p>

<p>None of which argues against flowers. It argues for two checks. <strong>Spread the bloom across the season</strong> rather than loading everything into May, so something is always doing it. And <strong>make sure the planting still works when nothing is in flower</strong> — if it does, every bloom is a bonus rather than the thing holding the design up.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>When a customer picks by flower, ask two things: where will you see it from, and when does it bloom?</strong> That usually changes the plant and improves the planting.</p>

<ul>
<li><strong>Spread the bloom window.</strong> Everything in May is a yard that is finished by June.</li>
<li><strong>Build the bones on foliage and form first.</strong> Flower on top of that, not instead of it.</li>
<li><strong>Do not guarantee bloom.</strong> Bud hardiness and late frost both apply — say it flowers most years, because that is the true version.</li>
<li><strong>Early bloomers go north and east</strong> so they open after the frost risk.</li>
<li><strong>Know which way it sets buds before pruning anything that flowers.</strong></li>
</ul>

<p><strong>What to tell a customer:</strong> most plants flower for two or three weeks and carry leaves for four months. Framed that way almost everyone gets more interested in foliage, and the planting comes out better.</p>
HTML,
  'title' => 'Flowering Plants & Shrubs | Brookstone Outdoors',
  'desc' => 'Bud hardiness, pruning timing and late frost all have to go right. Why most plants flower for three weeks, and why the planting has to work without them.',
];

$TERMS['Foliage Interest'] = [
  'short' => 'Leaves worth looking at in their own right — color, texture or size. The most reliable aesthetic trait there is, because it works every day of the season and does not depend on a frost date.',
  'public' => <<<'HTML'
<p>Foliage is the most underrated trait on this list and the one experienced designers lean on hardest.</p>

<p><strong>It is reliable in a way flower never is.</strong> Leaf color, leaf texture and leaf size are there every day of the growing season. They do not depend on buds surviving the winter, on a frost date, or on anybody having pruned at the right time. A border built on foliage contrast reads well in late July when nothing at all is in bloom — which is most of the season.</p>

<p>It works on contrast rather than on individual plants. A fine-textured grass beside a bold-leaved shrub, silver-gray against deep green, something upright next to something mounding. That is what gives a planting structure when there is no color in it, and it is why some beds look composed in August and others look like a collection.</p>

<p>Silver and gray foliage is worth a specific mention here. The hairs and waxy coatings that make leaves look silver are drought adaptations — they reflect light and slow water loss — so a great many of the best silver plants are also among the toughest things available on this ground. The look and the performance come from the same place.</p>

<p>Where flower is the event, foliage is the room it happens in.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Build every planting on foliage first.</strong> It is what carries the bed for four months and it is the easiest upgrade to any plant list.</p>

<ul>
<li><strong>Design on contrast:</strong> fine against bold, silver against green, upright against mounding. Three textures beats ten flowers.</li>
<li><strong>Silver and gray foliage is usually drought-adapted.</strong> Sell the look and the toughness together — same cause.</li>
<li><strong>When a customer is choosing by bloom, put a foliage plant in their hand.</strong> Most people have never been shown it and they respond immediately.</li>
<li><strong>Variegated plants scorch in full afternoon sun here.</strong> East exposure or filtered light.</li>
</ul>

<p><strong>What to tell a customer:</strong> what they will actually look at for four months is leaves. Choosing those deliberately is the difference between a bed that works all season and one that peaks for three weeks.</p>
HTML,
  'title' => 'Foliage Plants & Leaf Texture | Brookstone Outdoors',
  'desc' => 'Foliage works every day of the season and never depends on a frost date. How contrast carries a bed, and why silver leaves are usually drought-adapted.',
];

$TERMS['Fragrant'] = [
  'short' => 'Scented foliage or flowers. Real, and entirely positional — fragrance carries in still air and vanishes in wind, so a fragrant plant away from where people stand is one nobody smells.',
  'public' => <<<'HTML'
<p>Fragrance is the one plant quality that has to be close to work, and it is the one most often planted too far away to notice.</p>

<p><strong>Scent moves with air, and there is a lot of moving air here.</strong> On a still evening fragrance pools and drifts and fills a patio. In the afternoon wind it is gone before it reaches anybody. The practical consequence is that a fragrant plant forty feet out in a border is a plant nobody will ever smell, however good it is.</p>

<p>So they belong where people pass within a few feet: beside a door, along a walk, at the edge of a patio, under a window that gets opened, next to where somebody sits. A lilac at the corner of the drive, where people walk past it twice a day, does more than the same lilac in the middle of the lawn.</p>

<p>Two kinds worth separating. Flower fragrance arrives on its own and lasts as long as the bloom — a few weeks, usually in spring. Foliage fragrance releases when something brushes or crushes the leaves, which means it works all season but only on contact. Along a path edge, where people touch it going by, foliage fragrance is reliable in a way flower scent is not.</p>

<p>And evening is worth planning for. Several of the strongest-scented plants release most heavily at dusk, which is exactly when people are outside in summer here.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Within a few feet of where people are, or do not bother.</strong> Fragrance is a placement decision before it is a plant decision.</p>

<ul>
<li><strong>Doors, walks, patio edges, under openable windows, beside seating.</strong> Anywhere else the wind takes it.</li>
<li><strong>Foliage fragrance goes at path edges</strong> where people brush it. Works all season, unlike bloom scent.</li>
<li><strong>Ask about evening use.</strong> If they sit out after dark in summer, lead with the dusk-releasing plants.</li>
<li><strong>Ask before specifying heavy fragrance near a door.</strong> Some people find strong scent unpleasant, and a few are sensitive to it.</li>
<li><strong>Do not put strong fragrance next to an outdoor eating area</strong> without checking — it competes with food.</li>
</ul>

<p><strong>What to tell a customer:</strong> fragrance only works at close range in this wind. Put it where they walk and it is one of the best things in the yard; put it in the border and they will never notice it.</p>
HTML,
  'title' => 'Fragrant Plants & Placement | Brookstone Outdoors',
  'desc' => 'Scent carries in still air and vanishes in wind, so placement decides everything. Doors, walks and patio edges — and why foliage fragrance beats bloom scent.',
];

$TERMS['Ornamental'] = [
  'short' => 'Grown for how it looks rather than for a job it does. The broadest tag in this vocabulary, and most useful as a starting point for the question of what the plant is actually for.',
  'public' => <<<'HTML'
<p>Ornamental is the broadest word on this list. It means a plant is grown for its appearance — flower, form, bark, foliage, fruit — rather than for shade, screening, erosion control or a harvest.</p>

<p><strong>Which is worth saying plainly, because it is doing less work than it looks like it is.</strong> Nearly everything in a landscape is ornamental to some degree, so the tag on its own narrows very little. Where it earns its place is as a distinction: this plant is here because somebody wanted to look at it, not because it had a job to do.</p>

<p>That distinction matters more than it sounds. A functional planting is judged on whether it works — the screen screens, the bank holds. An ornamental is judged on whether somebody still likes it in year eight, which is a different and more personal standard. It means the usual advice applies with more force: put it where it is seen, choose it for more than one season, and make sure it suits the site, because an ornamental that struggles is failing at the only job it had.</p>

<p>In the tree catalog the word carries a second, more specific meaning — <a href="/material/plants/trees/deciduous/ornamental">ornamental trees</a> are small trees, generally under twenty-five feet, as distinct from shade trees. That is a size class rather than a style, and it is the reason the word appears in two places.</p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Treat this tag as a prompt, not an answer.</strong> If something is labeled ornamental, the next question is what it is actually for in this design.</p>

<ul>
<li><strong>Ornamental means judged on appearance</strong> — so it has to be sited where it is seen and chosen for more than one season.</li>
<li><strong>Do not lean on it in a plant list.</strong> It narrows almost nothing. Use habit, size, seasonal interest and tolerance instead.</li>
<li><strong>Watch the double meaning.</strong> Under Trees, "ornamental" means small tree, not decorative. Confirm which one the customer means.</li>
<li><strong>An ornamental that struggles has failed completely</strong> — there is no functional role to fall back on. Site conditions matter at least as much here as anywhere.</li>
</ul>

<p><strong>What to tell a customer:</strong> if its only job is to look good, it has to look good for more than three weeks and it has to be somewhere they will see it. That is the whole brief.</p>
HTML,
  'title' => 'Ornamental Plants | Brookstone Outdoors',
  'desc' => 'Grown for appearance rather than for a job. Why the broadest tag here narrows very little, and why ornamental means something different under Trees.',
];

/* ================================================================ LOAD */

$etm = \Drupal::entityTypeManager();
$storage = $etm->getStorage('taxonomy_term');

print $apply ? "MODE: APPLY\n" : "MODE: DRY-RUN (BOS_PC_APPLY=1 to write)\n";
printf("Copy supplied for %d terms.\n\n", count($TERMS));

// ---- Guard: markdown bold left inside an HTML value renders as asterisks.
$artifacts = [];
foreach ($TERMS as $name => $d) {
  foreach (['public', 'crew'] as $k) {
    if (strpos($d[$k], '**') !== FALSE) { $artifacts[] = "$name/$k"; }
  }
}
if ($artifacts) {
  print "ABORT — markdown ** found inside HTML (would render as literal asterisks):\n  "
    . implode("\n  ", $artifacts) . "\n";
  return;
}
print "✓ no markdown artifacts in any HTML value\n";

// ---- Guard: every internal link in the copy must resolve.
$links = [];
foreach ($TERMS as $name => $d) {
  if (preg_match_all('~href="(/[^"]+)"~', $d['public'] . $d['crew'], $m)) {
    foreach ($m[1] as $href) { $links[$href][] = $name; }
  }
}
foreach ($links as $href => $where) {
  $alias = \Drupal::service('path_alias.manager')->getPathByAlias($href);
  $ok = $alias !== $href;
  printf("%s internal link %-56s (in %s)\n", $ok ? '✓' : '✗ UNRESOLVED', $href, implode(', ', $where));
  if (!$ok) { print "ABORT — fix or remove the link before loading.\n"; return; }
}

// ---- Resolve every term by name. Report, never create.
$byName = [];
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $VID)->execute();
foreach ($storage->loadMultiple($tids) as $t) { $byName[mb_strtolower(trim($t->label()))] = $t; }

$matched = [];
$missing = [];
foreach ($TERMS as $name => $d) {
  $key = mb_strtolower(trim($name));
  isset($byName[$key]) ? $matched[$name] = $byName[$key] : $missing[] = $name;
}
printf("\n%d of %d matched a live term.\n", count($matched), count($TERMS));
if ($missing) {
  print "✗ NOT FOUND (reported, not created — the office owns term names):\n  "
    . implode("\n  ", $missing) . "\n";
  print "Live names in this vocabulary:\n  " . implode("\n  ", array_map(fn($t) => $t->label(), $byName)) . "\n";
  return;
}

// ---- Backup, then write.
$backup = [];
$changed = 0;
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

  // Metatags: title + description as supplied. og_description mirrors the
  // description so each page carries its own rather than the global line —
  // marketing did not supply one, and this is the spraying_locations precedent.
  $want = ['title' => $d['title'], 'description' => $d['desc'], 'og_description' => $d['desc']];
  if (array_intersect_key($tags, $want) != $want) {
    $term->set('field_meta_tags', json_encode(array_merge($tags, $want), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $deltas[] = 'meta';
  }

  if (!$deltas) { printf("  %-22s unchanged\n", $name); continue; }
  printf("  %-22s %s\n", $name, implode(', ', $deltas));
  $changed++;
  if ($apply) {
    $term->save();
    Cache::invalidateTags(['taxonomy_term:' . $term->id()]);
  }
}

if ($apply) {
  $f = '/tmp/pc_copy_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  print "\nPrevious values backed up to $f\n";
}
printf("\n%d terms changed%s.\n", $changed, $apply ? '' : ' (dry-run — nothing written)');
