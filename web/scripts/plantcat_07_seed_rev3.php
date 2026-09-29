<?php

declare(strict_types=1);

/**
 * Plant Character Categories — Rev. 3 copy, all fields, verbatim.
 *
 * Replaces the Code-written drafts in field_public_description. Rev. 3 splits
 * the teaching either side of the list: a short frame above, the list, then the
 * rest of the writing with one short ask at the very bottom. field_call_to_action
 * holds that bottom half — the field name understates what is in it, which the
 * copy file notes and accepts.
 *
 * Also adds the field_meta_tags instance so each category carries its own title
 * and description rather than inheriting the global taxonomy pattern.
 *
 * Nothing here is written or edited by Code. Text is pasted as supplied.
 *
 * OVERWRITES public_description, because the drafts it replaces were mine and
 * were always meant to be replaced. Idempotent — re-running writes the same
 * values. Dry run unless BOS_PC_APPLY=1.
 */

const CAT_VID = 'plant_character_categories';

$apply = getenv('BOS_PC_APPLY') === '1';
print $apply ? "APPLYING\n\n" : "DRY RUN (set BOS_PC_APPLY=1 to apply)\n\n";

$etm = \Drupal::entityTypeManager();

// --- per-term metatags need a field instance -------------------------------
if (!$etm->getStorage('field_config')->load('taxonomy_term.' . CAT_VID . '.field_meta_tags')) {
  if ($apply) {
    $etm->getStorage('field_config')->create([
      'field_name' => 'field_meta_tags',
      'entity_type' => 'taxonomy_term',
      'bundle' => CAT_VID,
      'label' => 'Meta tags',
      'description' => 'Per-page SEO title and description. Overrides the global taxonomy pattern.',
    ])->save();
    $form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . CAT_VID . '.default');
    if ($form) {
      $form->setComponent('field_meta_tags', ['type' => 'metatag_firehose', 'weight' => 50, 'region' => 'content', 'settings' => ['sidebar' => TRUE]])->save();
    }
  }
  print "  field_meta_tags instance " . ($apply ? "created\n" : "would be created\n");
}
else {
  print "  field_meta_tags instance exists\n";
}

$copy = [];

// ---------------------------------------------------------------- 10 ENV TOL
$copy['Environmental Tolerance'] = [
  'public' => <<<'HTML'
<p>Tolerance is not a yes or no. It is a range, and a planting site is a stack of conditions the plant has to clear all at once — soil chemistry, available water, hours of direct sun, winter low, wind, and how fast the ground drains. A plant can be perfect on six of those and fail on the seventh.</p>

<p>The one that catches people here is soil pH. Ground across most of Delta and Montrose counties runs alkaline, commonly in the 7.5 to 8.2 range. At that pH, iron and manganese are present in the soil but chemically locked up where roots cannot take them, and the plant starves in the middle of plenty. It is the reason a long list of trees sold as dependable elsewhere are a poor bet here.</p>

<p>These are the tolerances we sort by, and what each one means on ground like this.</p>
HTML,
  'cta' => <<<'HTML'
<p>Once you can see the pH problem you will see it everywhere in this valley. Leaves gone pale yellow while the veins stay green, worst on the newest growth at the tips — that is iron chlorosis, and silver and red maple, pin oak, river birch and quaking aspen are where it shows up most. All of them are excellent trees somewhere with different dirt.</p>

<p>Treatment exists and it is a treadmill. Chelated iron greens a plant up for a season, sometimes two. It does not change the pH, so it repeats for the life of the plant. A soil acidifier moves the number temporarily and then the native ground and the irrigation water pull it back. Which is why the plant list is the answer and the amendment is not.</p>

<p>Two others are worth knowing before you shop. A hardiness zone is an average winter low, so it tells you nothing about a hard freeze in the middle of May — and a late frost is what actually does the damage here, catching a plant after it has broken dormancy and spent its energy on new growth. And drought tolerance is earned rather than issued: a drought-tolerant plant is drought-tolerant once its roots are deep, and getting there takes two full seasons of regular water.</p>

<p>None of these decides anything on its own. A site is the combination, which is why a plant list that worked beautifully at a friend's place in Grand Junction can struggle at yours, twenty minutes away and eight hundred feet up. If you are not sure what your site actually is, that is the first thing we work out — soil, water source, drainage, exposure — and the plant list comes after it rather than before.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Read the site before you read the plant list.</strong> Every one of these is a site question first.</p>

<ul>
<li><strong>Look for chlorosis on what is already there.</strong> Yellow leaves with green veins on an existing tree tells you the pH before anyone tests it. If the neighbour's maple is yellow, do not put a maple in.</li>
<li><strong>Ask where the water comes from.</strong> Domestic tap, a ditch share, or a pressurised irrigation tap are three different water budgets, and a ditch on a rotation schedule is not water on demand.</li>
<li><strong>Get the elevation.</strong> Cedaredge at 6,200 and Delta at 4,900 are not the same growing conditions no matter what the zone map says. Crawford and the higher benches are harder again.</li>
<li><strong>Check drainage before you believe a well-drained requirement.</strong> Dig a hole, fill it, come back in an hour. Heavy ground that holds water kills more plants here than cold does.</li>
<li><strong>Note the wind exposure.</strong> An open west or south-west face dries a plant out faster than the calendar suggests and it beats up anything with soft new growth.</li>
</ul>

<p><strong>What to tell a customer:</strong> a drought-tolerant plant still gets watered on schedule for the first two seasons. Say it at the sale, say it again at the walk-through, and write it on the care sheet. The single most common reason a xeric planting fails here is that somebody believed the label in year one.</p>

<p><strong>What not to promise:</strong> that we can amend our way out of alkaline soil. We can improve a bed. We cannot change the pH of a site permanently, and a plant that needs acid ground is a plant on life support here.</p>
HTML,
  'title' => 'Environmental Tolerance in Plants | Brookstone Outdoors',
  'desc' => 'Alkaline soil locks up iron, late frosts beat hardiness zones, and drought tolerance takes two seasons to earn. How site conditions decide plants here.',
];

// ---------------------------------------------------------------- 20 HABIT
$copy['Growth Habit'] = [
  'public' => <<<'HTML'
<p>Growth habit is what a plant does with space over time. It is the difference between a shrub that stays a tidy mound for fifteen years and one that quietly becomes eight feet wide and swallows the walk.</p>

<p>The mistake almost everybody makes is buying by the pot. A plant arrives at eighteen inches across and gets planted three feet from the next one, because at eighteen inches across that looks about right. Five years later they are grown into each other, the air stops moving between them, and somebody is cutting out every third plant and living with the holes.</p>

<p>Here are the habits we sort by, and what each one commits you to.</p>
HTML,
  'cta' => <<<'HTML'
<p>The honest version of all of this is uncomfortable to sell and worth saying anyway: <strong>a new bed spaced correctly looks sparse, and a new bed that looks full is overplanted.</strong> Year one looks thin. Year three looks right. Year five is what the design was drawn for. Almost every crowded, tired planting we are asked to fix was full and satisfying on the day it went in.</p>

<p>Habit also sets the limits on what pruning can do. You can shape a plant within its habit — a little tighter, a little more open — but you cannot make a spreading plant upright or a lanky plant dense. Shearing something into a form it does not want produces a plant that is bare inside, thin at the base, and needs shearing again every eight weeks forever.</p>

<p>And habit tells you what a plant needs from you structurally. A vining plant needs something to hold, and how it climbs decides what that has to be — a twiner wraps around a post, a tendril climber needs wire or lattice thin enough to grab, a clinger attaches directly to a wall and will hold on to mortar, and a scrambler does not climb at all and simply leans until you tie it in.</p>

<p>Of everything in a plant selection, habit is the hardest part to undo. Water can be adjusted, soil can be improved, a bad pruning cut grows out in a season. A shrub planted three feet off a walk when it wanted six is something a person lives with or pays to fix. If you are laying out a bed and want a second opinion on spacing before anything goes in the ground, that is a short conversation and a cheap one.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Space to mature width. Every time.</strong> The nursery tag is optimistic and the customer's eye is impatient. Our name is on it in year five, not year one.</p>

<ul>
<li><strong>Mature width, not mature height, sets the spacing.</strong> Two plants at half their combined mature widths apart, measured centre to centre. Closer only when the design intends a mass or a hedge.</li>
<li><strong>Half of mature width from any hard edge</strong> — walk, drive, foundation, fence. A plant that has to be cut back off a sidewalk every year was planted in the wrong place.</li>
<li><strong>Check habit against the job before you check the flower.</strong> Groundcover under a window, upright at a corner, spreading on a bank. The form does the work; the bloom is a bonus.</li>
<li><strong>For anything vining, confirm the support exists and can hold it.</strong> A mature vine is heavy and wet snow makes it heavier. A trellis screwed to siding with two anchors comes off the wall.</li>
<li><strong>Do not put clingers on wood siding, stucco, or anything with mortar we would have to repoint.</strong> Masonry in good condition, or a standoff frame.</li>
</ul>

<p><strong>What to tell a customer:</strong> when they say it looks empty, do not apologise and do not add plants. Tell them what it looks like in year three, and tell them that a bed that looks full on install day is one we will be thinning at their expense. Most people accept that immediately if they hear it before the install rather than after.</p>

<p><strong>What not to do:</strong> shear a plant into a habit it does not have because a customer asked. Explain what that commits them to first. If they still want it, it goes on the maintenance schedule as a recurring visit, not as a favour.</p>
HTML,
  'title' => 'Plant Growth Habit & Spacing | Brookstone Outdoors',
  'desc' => 'Mature width sets the spacing, not the pot. Why a correctly spaced bed looks sparse in year one, and what pruning can and cannot change about a plant.',
];

// CONTINUED BELOW

// ---------------------------------------------------------------- 30 SEASONAL
$copy['Seasonal Interest'] = [
  'public' => <<<'HTML'
<p>The growing season here is short. Depending on elevation you get something between four and five months where a planting is actively doing what it was designed to do, and roughly seven where it is doing whatever it does when nothing is green.</p>

<p>That ratio is the whole argument for planning seasonal interest deliberately. Most yards get planned in May, at a nursery, surrounded by everything that happens to be in flower in May. The result performs beautifully for about three weeks and then goes quiet, and the owner spends the rest of the year looking at a green wall they stopped noticing in July.</p>

<p>These are the seasonal windows we plan against.</p>
HTML,
  'cta' => <<<'HTML'
<p>The fix is to stack the calendar rather than load one end of it. Something opening early, something carrying midsummer, something turning in October, and — the one that gets skipped — something with structure in January. Winter is the longest single season on this calendar and most plantings simply give up on it.</p>

<p>Winter interest is not a consolation prize either. Red-twig dogwood against snow, ornamental grasses holding their seed heads until you cut them in March, berries that stay on the branch, the bark on a river birch or an amur maple, the mass of an evergreen holding a shape when everything around it has gone to sticks. That is the view from the kitchen window for five months and it costs nothing extra to plan for.</p>

<p>One caution on spring bloom at elevation. An early flowering plant can open ahead of the last hard frost and lose the entire year's display overnight, and that is placement rather than a defective plant. The same variety on a north or east exposure breaks dormancy later, misses the frost, and flowers reliably — while the one on a warm south wall gets fooled by a warm week in April every few years.</p>

<p>None of this costs more. A planting that works in all four seasons is frequently the same number of plants at the same price, chosen in a different order. And if you already have a yard that looks good for a month and flat the rest of the year, that is usually fixable by adding to what is there rather than starting over — most of the gap is in fall and winter, and those are the cheapest windows to fill.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>The question that sells this: "what do you see from the kitchen window in February?"</strong> Ask it on every design walk. Most people have never thought about it and the answer changes the plan.</p>

<ul>
<li><strong>Cover all four windows before you refine any one of them.</strong> Early spring, summer, fall, winter. A plan heavy in one window and empty in another is not finished, however good the plant list looks.</li>
<li><strong>Place early bloomers on north and east exposures</strong> where they break dormancy later. South and west walls hold heat, push growth early, and hand it to the next hard frost.</li>
<li><strong>Put winter interest where it is actually seen</strong> — the view from inside the house, and the approach from the drive. Winter structure behind the garage is wasted.</li>
<li><strong>Leave grasses and seed heads standing over winter.</strong> They are the winter interest. Cut them back in late winter before new growth starts, not in the fall cleanup.</li>
<li><strong>Fall color here is not reliable year to year.</strong> A warm, dry September pushes it late and dulls it. Do not let a design depend entirely on it.</li>
</ul>

<p><strong>What to tell a customer:</strong> if an early bloomer gets frosted, explain what happened the same week rather than waiting to be asked. A frosted bloom is a weather event, the plant is fine, and it will flower next year. Said early it is information. Said after they complain it sounds like an excuse.</p>

<p><strong>On fall cleanup:</strong> when a customer wants everything cut to the ground in October, tell them what they are giving up. Some still want it clean and that is their call — but it should be a choice, not something that happens because nobody mentioned it.</p>
HTML,
  'title' => 'Seasonal Interest in Plantings | Brookstone Outdoors',
  'desc' => 'A short season and a long winter. How to stack spring, summer, fall and winter interest, and why early bloomers belong on a north-facing wall.',
];

// ---------------------------------------------------------------- 40 WILDLIFE
$copy['Wildlife Interaction'] = [
  'public' => <<<'HTML'
<p>Start with the honest definition, because the labels oversell. <strong>Deer-resistant means deer eat it last.</strong> It does not mean they will not eat it. A deer in February, on a hard winter, with the benches grazed down, will eat a great many things the book says it will not — and a newly planted shrub with soft nursery growth on it is a more appealing meal than the same plant will be in five years.</p>

<p>Pressure also varies enormously inside a few miles. A property backing onto open ground, a bench edge, or a drainage corridor gets hit in a way a house in the middle of town simply does not. The same plant list succeeds on one street and gets stripped on the next.</p>

<p>These are the wildlife traits we sort by, and what each one is actually worth.</p>
HTML,
  'cta' => <<<'HTML'
<p>Rabbits are the more serious problem and they get less attention. Deer browse the top and disfigure a plant. Rabbits work at snow line in winter and girdle bark — a complete ring chewed off the trunk — and a girdled plant does not recover. It leafs out in spring on stored energy, looks fine into June, and then dies. Young trees with smooth bark are the usual casualty, and the damage happens under snow where nobody sees it until it is done.</p>

<p>The other direction is attraction, which comes down to food and cover. Fruit, seed and dense branching bring birds in. Worth knowing that this pulls against low maintenance — fruit ripens, drops, and stains what it lands on, which is a feature in a border and a nuisance over a patio or a parking area.</p>

<p>There is no plant list that makes a yard deer-proof, and anyone who says otherwise has not worked through a hard February here. What a good list does is shift the odds far enough that a planting gets established, and once it is established most of it stops being worth a deer's attention. The first two winters are the ones that matter, and they are the cheapest to protect.</p>

<p>If you have lost plantings to browse before, tell us what and where. That history describes your property rather than an average one, and it is better information than any resistance rating in any catalog.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Never say deer-proof.</strong> Not in conversation, not in an estimate, not on a plant list. Say deer-resistant, and say what that means, because the one February a hungry herd goes through a planting is the conversation that follows.</p>

<ul>
<li><strong>Ask about browse pressure on every design walk.</strong> "Do you see deer in the yard, and how often?" The customer knows, and their answer is better information than any regional map.</li>
<li><strong>Note what backs the property.</strong> Open ground, a bench edge, a ditch corridor or a drainage means high pressure regardless of what is planted next door.</li>
<li><strong>Protect new plantings for the first two winters even when the list is deer-resistant.</strong> Nursery growth is soft and palatable in a way mature growth is not. This is the cheapest insurance on a job.</li>
<li><strong>Trunk guards on smooth-barked young trees, every winter, up past expected snow depth.</strong> Rabbit girdling is invisible until spring and there is no repair for it. Set the guard height off snow line, not ground level.</li>
<li><strong>Keep fruiting plants off patios, walks and parking.</strong> Bird-attracting is a good thing in a border and a complaint over a seating area.</li>
</ul>

<p><strong>What to tell a customer:</strong> deer-resistant is a ranking, not a guarantee, and the ranking gets shorter in a hard winter. Say this before the install. A customer who was told up front treats browse as weather; a customer who was not treats it as our plant selection.</p>

<p><strong>If a planting gets browsed:</strong> get out and look at it before quoting a replacement. Browsed tops usually recover. A girdled trunk does not, and telling the difference is the whole job.</p>
HTML,
  'title' => 'Deer, Rabbits & Plant Selection | Brookstone Outdoors',
  'desc' => 'Deer-resistant means eaten last, not never. Browse pressure on the Western Slope, and why rabbit girdling at snow line is the bigger risk to a new planting.',
];

// ---------------------------------------------------------------- 50 SPECIAL
$copy['Special Uses'] = [
  'public' => <<<'HTML'
<p>Most plants are chosen because somebody likes them. These are chosen because something needs doing, and that reversal matters — when a plant is filling a functional role, failing at the job is visible in a way that a plant simply being a bit disappointing is not. A screen that never screens is a standing reminder.</p>

<p>So these get specified rather than picked: what height, at what density, by when, against what. The look comes second, and a surprising amount of the time the right answer involves something other than a plant.</p>

<p>These are the jobs we select for.</p>
HTML,
  'cta' => <<<'HTML'
<p>Two of these fail in ways that are worth knowing before you start.</p>

<p><strong>Screening fails on density, not height.</strong> The question is never how tall a plant gets — it is how dense it is at the height you actually need it. A lot of plants sold for screening are perfectly solid at eye level for six or eight years and then go bare at the bottom as they mature, and by then the thing you were screening is visible again through a row of trunks. Where a screen has to work at ground level, that gets designed for at the start rather than discovered in year ten.</p>

<p><strong>Containers face a harder winter here than anything in the ground.</strong> A root ball in the ground is insulated on every side by soil that never approaches air temperature. A root ball in a pot is surrounded by air and freezes through. The working rule is to plant a container two zones hardier than the site — so a zone 5 location wants a zone 3 plant in a pot if it is staying outside all winter. Most container plantings that die here were never going to survive, whatever anyone did with the watering.</p>

<p>Erosion control and pollinator plantings have their own rules, and both are covered on their pages above. The common thread is that a functional planting gets judged years after the install, long after anyone is looking at the plant list — which is a good reason to specify it properly at the start.</p>

<p>If you have a problem you are trying to solve rather than a look you are trying to get, start by describing the problem. We will work back from it.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Establish the job before the plant.</strong> Every one of these has a specification hiding behind it, and the specification is what makes the selection right or wrong.</p>

<ul>
<li><strong>Screening: ask what height, and screening what, from where.</strong> Stand where the customer stands and look at what they want gone. Then check mature density at that height — not just mature height.</li>
<li><strong>Erosion: the planting is not the whole solution.</strong> Anything steep needs soil stabilised while roots establish. Price it that way and say why.</li>
<li><strong>Containers: two zones hardier than the site</strong> for anything overwintering outside. Otherwise it is an annual and should be sold as one.</li>
<li><strong>Containers need water when the ground does not.</strong> They dry out in days in July and they need occasional water on warm winter days too. Say this — it is the reason most container plantings die here.</li>
<li><strong>Pollinator beds: spread the bloom window, do not mass one species.</strong> Something in flower from the first warm weeks to hard frost.</li>
<li><strong>Check any pollinator planting against the spray schedule.</strong> If that property is on a program, the two have to be coordinated or we are working against ourselves.</li>
</ul>

<p><strong>What to tell a customer:</strong> a screen takes years, and the number depends on what we plant and what they are willing to pay for at install. Give them the real timeline. Somebody expecting privacy next summer from a five-gallon install is going to be unhappy in a predictable way.</p>

<p><strong>What not to promise:</strong> that a planting alone holds a steep slope through a heavy runoff year. Say what the planting does and what the stabilisation does.</p>
HTML,
  'title' => 'Plants for Screening & Slopes | Brookstone Outdoors',
  'desc' => 'Screening, erosion control, containers and pollinators. Why screens go bare at the bottom, and why a container plant must be two zones hardier than the site.',
];

// ---------------------------------------------------------------- 60 MAINT
$copy['Maintenance & Behavior'] = [
  'public' => <<<'HTML'
<p>Every planting is a maintenance commitment. The only question is how large a one, and whether anybody said so at the start.</p>

<p>The first thing to understand is that <strong>fast growth is a trade, not a free gain.</strong> Plants that put on size quickly tend to do it with weak, low-density wood, and they tend not to live as long. That combination is how a shade tree planted for quick results becomes a split trunk in a wind event fifteen years later. Fast growers have their place — a screen somebody needs in this decade, a temporary planting while something slower fills in — but they get chosen knowingly, and often with a replacement already in the plan.</p>

<p>These are the behaviors we sort by, and what each one costs in upkeep.</p>
HTML,
  'cta' => <<<'HTML'
<p><strong>Low-maintenance does not mean no-maintenance.</strong> It means the plant does not need intervention to stay healthy: not fighting the soil, not prone to the diseases that go around here, not outgrowing its spot, not dependent on being pruned to look like anything. It still gets watered, it still gets fed occasionally, and somebody still looks at it.</p>

<p><strong>And "pruning required" is not a defect.</strong> It is a schedule, and plenty of excellent plants carry one. The problem is never that a plant needs pruning — it is that nobody told the owner, or that it gets cut at the wrong time of year and loses a season of flower because next year's buds were set on last year's wood. A spring bloomer pruned in early spring has just had its flowers removed.</p>

<p><strong>Non-invasive carries more weight here than most people assume.</strong> A plant that spreads aggressively in this climate, with our water and our soil, is one somebody will eventually be removing — from the bed it was planted in, and sometimes from the ditch bank downstream of it. Anything that runs or seeds heavily gets a second look before it goes on a plan, and a check against the state noxious weed list.</p>

<p>All of which is worth settling before the plant list rather than after. There is a good planting available at almost any level of upkeep — the failure is not choosing a demanding plant, it is choosing one that does not match what anybody is actually willing to do, and finding out in year three. So be blunt with us. Some people want an hour in the yard on a Saturday and something to work on. Some want it handled and never thought about again. Those are two different plant lists, and there is no wrong answer.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Set the maintenance expectation at the sale, in writing, on the care sheet.</strong> A plant that needs work is fine. A plant that needs work the customer never heard about is a complaint with our name on it.</p>

<ul>
<li><strong>Selling a fast grower: say what the trade is.</strong> Quicker results, shorter life, weaker wood. If it is going near a structure, a drive or a power line, say that too and get it in writing.</li>
<li><strong>Anything with a pruning requirement goes on the maintenance schedule at install</strong>, not the first time somebody notices it needs doing.</li>
<li><strong>Know which way a plant sets buds before you cut it.</strong> Prune a spring bloomer in fall or early spring and you cut off the flowers — the buds were set last summer. Spring bloomers get pruned right after they flower.</li>
<li><strong>Flag anything that spreads by runner or seeds heavily</strong> before it goes on a plan, especially near a ditch, a fence line or open ground. Check it against the Colorado noxious weed list.</li>
<li><strong>Disease resistance is worth paying for on anything we maintain.</strong> A susceptible variety becomes a recurring call, and the recurring call is our time.</li>
</ul>

<p><strong>What to tell a customer:</strong> low-maintenance means it will not need rescuing, not that it needs nothing. Water, an occasional feed, and somebody looking at it. Said plainly at the sale it is reassuring. Discovered later it sounds like fine print.</p>

<p><strong>If a customer wants a fast grower against the house:</strong> put the mature size and the wood-strength issue in the estimate. Then it is their decision on the record rather than our recommendation.</p>
HTML,
  'title' => 'Plant Maintenance & Growth Rate | Brookstone Outdoors',
  'desc' => 'Fast growth trades against strong wood and long life. What low-maintenance really means, and when to prune a spring bloomer without losing the flowers.',
];

// ---------------------------------------------------------------- 70 AESTHETIC
$copy['Aesthetic Features'] = [
  'public' => <<<'HTML'
<p>This is where most people start, and there is nothing wrong with that. You are going to look at this every day for twenty years and you should like what you see. The useful correction is not to start somewhere else — it is to understand that aesthetic traits are the most conditional thing about a plant.</p>

<p><strong>Flowering is the least reliable of the four.</strong> A bloom depends on the buds surviving winter, on the plant not being pruned at the wrong time, and — here especially — on a late frost staying away after the plant has broken dormancy. Most plants flower for two or three weeks. Choosing a plant primarily for its flower is choosing it for about five percent of the year.</p>

<p>These are the aesthetic traits we sort by.</p>
HTML,
  'cta' => <<<'HTML'
<p><strong>Foliage is the most reliable of the four, and it is the one most homeowners underweight.</strong> Leaf color, leaf texture and leaf size are there every day of the growing season, they do not depend on a frost date, and they are what actually carries a planting. A border built on foliage contrast reads well in July when nothing is in flower — which is most of the time. Experienced designers lean on this far harder than people expect them to.</p>

<p><strong>Fragrance is real but positional.</strong> It carries in still air and disappears in wind, and there is a great deal of wind here. A fragrant plant forty feet from where anybody sits is a fragrant plant nobody smells. They belong beside a door, a patio or a walk — somewhere a person passes within a few feet of it.</p>

<p><strong>And fruit is an asset or a liability depending entirely on what is underneath it.</strong> The same crabapple that is a good decision in a border is a bad one over a patio, a walk or a parking area, and the difference shows up for a few weeks every fall for the life of the tree.</p>

<p>None of this argues against choosing plants because you like them. It argues for checking two things first: that the thing you like will still be doing it in August, and that you will be standing somewhere you can see it. If you have a picture of something you want — a yard you drove past, something from a magazine, a photo from a trip — bring it. Half the time we can get close with something better suited to this ground than whatever was in the photo, and that conversation goes much faster with an image than a description.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>When a customer picks by flower, ask two questions: where will you see it from, and when does it bloom?</strong> Most of the time this changes the plant, and the customer is happier for it.</p>

<ul>
<li><strong>Build the bones on foliage, then add flower.</strong> A planting that only works in bloom is empty most of the season. Foliage contrast — color, texture, leaf size — is what carries it.</li>
<li><strong>Fragrant plants go within a few feet of a door, a patio or a walk.</strong> Anywhere else and the wind takes it.</li>
<li><strong>Never put a fruiting plant over a patio, walk, drive or parking area.</strong> Check what is underneath before it goes on the plan, not after.</li>
<li><strong>Spread the bloom across the season rather than massing one window.</strong> Same argument as seasonal interest, and it comes up here first because this is where customers start.</li>
<li><strong>Do not sell a flower we cannot guarantee.</strong> Bud hardiness and late frost both apply. Say the plant flowers most years, because that is the true version.</li>
</ul>

<p><strong>What to tell a customer:</strong> most plants flower for two or three weeks. What they will actually be looking at for four months is leaves. Framed that way, almost everyone gets more interested in foliage, and the planting ends up better.</p>

<p><strong>On the nursery visit:</strong> whatever is flowering the day a customer walks the yard will dominate their list. Remind them what is not in bloom that day and what will be in six weeks.</p>
HTML,
  'title' => 'Flowers, Foliage, Fragrance & Fruit | Brookstone',
  'desc' => 'Why foliage carries a planting and flower only visits. Where fragrant plants must sit to be noticed, and what never to plant over a patio or a walk.',
];

// ---------------------------------------------------------------- 80 ORIGIN
$copy['Origin'] = [
  'public' => <<<'HTML'
<p>Origin is useful shorthand and a bad rule. It is worth knowing where a plant comes from, and it is worth not treating that as the answer.</p>

<p><strong>"Native to Colorado" is a very large claim.</strong> This state runs from high desert at four thousand feet to alpine tundra above eleven thousand, across soils that have nothing in common. A plant native to the eastern plains, or to a wet mountain meadow, or to the Front Range, may be entirely unsuited to a 5,500-foot valley with alkaline soil and eight inches of precipitation. The useful question is never "is it native" — it is native to what, at what elevation, in what ground.</p>

<p>Here are the three origins, and what each one tells you.</p>
HTML,
  'cta' => <<<'HTML'
<p>Where a plant is genuinely native to conditions like these, it is usually the low-input choice, and that is worth designing around. It has already solved the soil chemistry, it expects the precipitation, it is adapted to the swing between a 75-degree afternoon and a 35-degree night, and it supports the insects and birds that evolved alongside it.</p>

<p><strong>Hybrids carry an undeserved reputation and are often the better performer here.</strong> A hybrid exists because somebody selected for something — disease resistance, a more compact habit, better cold tolerance, tolerance of alkaline soil. On a site that is genuinely difficult, a plant bred for the problem frequently beats a straight species that merely tolerates it.</p>

<p><strong>Introduced plants get checked before they go on a plan.</strong> Plenty are excellent here and have been grown in this valley for a century. The check is against the Colorado noxious weed list, because a plant that thrives in this climate and also spreads on its own is not a feature — it is a removal job for somebody, and often for a neighbour downstream.</p>

<p>If a native or low-water planting is what you are after, say so at the start. It works considerably better as something that shapes the plan from the beginning than as a substitution exercise applied to a plan already drawn — and done properly it is one of the better-looking things we build.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Do not sell "native" as automatically low-water or automatically low-maintenance.</strong> It is a strong indicator, not a specification, and it is wrong often enough to matter.</p>

<ul>
<li><strong>Native to where?</strong> A plant native to a mountain meadow wants water we do not have. A plant native to the eastern plains has not met our soil. Ask the supplier what conditions it is actually from.</li>
<li><strong>On a difficult site, look at hybrids first.</strong> Alkaline tolerance, disease resistance and compact habit are all things somebody has bred for, and a bred solution usually beats a tolerated one.</li>
<li><strong>Check anything introduced against the Colorado noxious weed list before it goes on a plan.</strong> List A, B and C. This is not optional and it takes a minute.</li>
<li><strong>Be careful near ditches, fence lines and open ground.</strong> Whatever we plant there is the neighbourhood's problem too if it moves.</li>
<li><strong>A native planting still gets watered to establish.</strong> Two seasons, same as anything else. Native does not mean plant it and leave.</li>
</ul>

<p><strong>What to tell a customer:</strong> if they want a native planting — and more people do each year, for good reasons — explain what that gets them and what it does not. Lower water once established, better for pollinators and birds, well suited to the soil. It does not mean no watering, no weeding and no pruning, and it does not mean the plant palette is unlimited.</p>

<p><strong>If a customer brings a plant they found elsewhere:</strong> check the noxious weed list before agreeing to install it, whatever it cost them.</p>
HTML,
  'title' => 'Native, Hybrid & Introduced Plants | Brookstone',
  'desc' => 'Native to Colorado covers desert to tundra. Why the question is native to what elevation and soil, and when a hybrid outperforms the straight species.',
];

// --- write ----------------------------------------------------------------
$terms = [];
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => CAT_VID]) as $t) {
  $terms[$t->label()] = $t;
}

$written = 0;
foreach ($copy as $name => $c) {
  $term = $terms[$name] ?? NULL;
  if (!$term) {
    printf("  MISS   %s — no such term\n", $name);
    continue;
  }
  $term->set('field_public_description', ['value' => $c['public'], 'format' => 'full_html']);
  $term->set('field_call_to_action', ['value' => $c['cta'], 'format' => 'full_html']);
  $term->set('field_teammate_description', ['value' => $c['crew'], 'format' => 'full_html']);
  if ($term->hasField('field_meta_tags')) {
    $term->set('field_meta_tags', json_encode(['title' => $c['title'], 'description' => $c['desc']]));
  }
  printf("  %-24s public %4d · below-list %4d · crew %4d · metatags\n",
    $name, strlen($c['public']), strlen($c['cta']), strlen($c['crew']));
  $written++;
  if ($apply) {
    $term->save();
  }
}
printf("\n  %d categories %s.\n", $written, $apply ? 'written' : 'pending');
