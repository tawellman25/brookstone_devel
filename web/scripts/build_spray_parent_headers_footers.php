<?php

declare(strict_types=1);

/**
 * Step 1 — headers and footers for the ten spray parent pages.
 *
 * Eight are landing VIEWS (header area above the child list, footer area below,
 * both full_html): header replaced, footer added on the `default` display so
 * page_1 inherits. Two are basic `page` NODES (chemicals, stages-weed-growth) —
 * their body becomes header + (any existing in-body child links) + footer.
 *
 * All copy is verbatim from "Spray Parent Views - Header and Footer Copy.md".
 * class="button" is kept as-is: it IS the theme's CTA class (the whole
 * /services/backflow-prevention tree uses exactly class="button"; Olivero styles
 * it). Nodes matched by URL alias; views by id — environment-independent.
 * Idempotent.
 *
 *   drush php:script web/scripts/build_spray_parent_headers_footers.php
 */

$etm = \Drupal::entityTypeManager();

/* ---------- VIEW COPY ---------- */

$H_FREQUENCY = <<<'HTML'
<p>How often a property gets treated is the single biggest factor in what the weed control actually costs you, and the right answer is not the same for every property.</p>

<p>Weeds are not a one-time problem. Every schedule below is a different trade between how often we are there and how much pressure you are willing to live with between visits.</p>
HTML;
$F_FREQUENCY = <<<'HTML'
<h2>What actually decides the schedule</h2>

<p><strong>How much pressure the property is under.</strong> A lot backing onto open ground, a ditch bank, or a neighbour who does not spray is under constant reseeding pressure. A fenced yard surrounded by other maintained yards is not.</p>

<p><strong>What is growing there.</strong> Annual weeds come from seed and can be intercepted before they emerge. Perennials come back from roots and need a different timing and usually more than one pass. A property with bindweed is a different program from a property with crabgrass.</p>

<p><strong>What the place is for.</strong> A commercial frontage that has to look right every day of the week is not the same job as a back pasture.</p>

<h2>The honest version</h2>

<p>More frequent is more effective and it costs more. Less frequent is cheaper and you will see more weeds in the gaps. Anyone who tells you otherwise is selling you something.</p>

<p>What we would rather do is look at the property, tell you which schedule actually fits it, and say so if a lighter program would do the job. An oversold program is one you cancel in year two, which is worse for both of us than the right one in year one.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$H_WIND_SPEED = <<<'HTML'
<p>Wind is the most common reason a spray gets rescheduled, and the reason is not the one most people assume.</p>

<p>Three to seven miles an hour is close to ideal. Under ten is workable. Over ten and we stop, because above that the droplets stop landing where they were aimed and start landing somewhere else.</p>

<p><strong>And dead calm is not the best case.</strong> It is often the worst — which takes some explaining.</p>
HTML;
$F_WIND_SPEED = <<<'HTML'
<h2>Why still air is a warning, not an ideal</h2>

<p>When there is no wind at all, it frequently means a <strong>temperature inversion</strong> — a layer of cool air trapped beneath warmer air above it, with no vertical mixing happening.</p>

<p>In an inversion, spray droplets do not disperse and settle. They hang as a concentrated cloud and then move sideways, slowly, a long way, wherever that layer of air goes. A still evening can carry an application further off target than a breezy afternoon will.</p>

<p>Inversions usually set in around dusk and break up after sunrise, once the ground warms and the air starts moving vertically again. Which is why a crew that arrives at seven and waits an hour is not wasting your time — they are waiting for the air to start mixing.</p>

<h2>What else we control</h2>

<p>Wind is not the only variable. Nozzle choice changes droplet size, and larger droplets fall faster and drift less. Wand and boom height matter — the higher the spray starts, the further it can travel before it lands. Those are decisions made on site, based on that morning's conditions.</p>

<h2>Why it is on your paperwork</h2>

<p>Every application records the wind speed at the time. That record is how we show the work was done under conditions the product label allows — and when we tell you we could not spray today, it means there is a number behind the decision rather than an opinion.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$H_WIND_DIR = <<<'HTML'
<p>Wind speed tells you how far a spray can travel. Direction tells you where it goes. Neither number means much alone, which is why an application record carries both.</p>
HTML;
$F_WIND_DIR = <<<'HTML'
<h2>The same breeze is fine one way and unacceptable the other</h2>

<p>Picture a six mile an hour breeze on a property with a vegetable garden along the east fence. Spraying the west side with that breeze blowing west is a routine application. Spraying the east side with the same breeze puts herbicide in the tomatoes.</p>

<p>Same day, same wind, same product, same tech. Direction is the only thing that changed, and it changed the answer from yes to no.</p>

<h2>What our tech checks before starting</h2>

<p>Whatever is downwind, and how far away. Vegetable gardens and fruit trees. A neighbour's ornamental beds. Open water — a pond, a ditch, an irrigation head running. Livestock and anything they graze. Beehives. An organic operation, where one drift event can cost a grower their certification.</p>

<p>Sometimes the answer is to spray a different part of the property that morning. Sometimes it is a granular product that cannot drift at all. Occasionally it is to come back another day.</p>

<h2>Why it ends up on your paperwork</h2>

<p>Colorado licenses commercial applicators and requires them to keep records of their applications. Beyond the requirement, the record does a specific job, and it works both ways.</p>

<p><strong>If someone downwind has a problem, the record answers the question.</strong> A neighbour's shrubs brown out three weeks after we treated your lawn and they want to know why. The first thing anyone asks is whether our application could have reached them. A record showing a light wind blowing away from that property is an answer rather than an assurance.</p>

<p><strong>And it protects you.</strong> Drift comes from somewhere — roadside spraying, an agricultural operation, a neighbour with a tank sprayer and no licence. If something on your property is damaged and it was not us, our record is part of showing that.</p>

<p>Honestly, we would keep it whether or not we were required to. Drift is the most common thing that goes wrong in this trade, and a written account of the conditions is the only thing that settles the argument afterward.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$H_SOIL = <<<'HTML'
<p>How wet the ground is on the day changes whether a treatment works, and on some products it changes whether we should be applying it at all.</p>
HTML;
$F_SOIL = <<<'HTML'
<h2>Why it matters more than it sounds like it should</h2>

<p><strong>A pre-emergent needs water to do anything.</strong> It forms a barrier in the top layer of soil, and it needs moisture to move down and settle into place. Applied to bone-dry ground with no rain or irrigation behind it, it sits on the surface and degrades. That is a wasted application and a season of weeds nobody planned for.</p>

<p><strong>Saturated ground is the opposite problem.</strong> Water sitting on the surface, or ground so full it cannot take any more, means anything applied runs off rather than staying where it was put. On a slope, or anywhere near a ditch or a pond, that is the condition where an application ends up somewhere it was never meant to go.</p>

<p><strong>And drought-stressed plants absorb differently.</strong> A weed shutting down under heat and no water is not actively growing, and a systemic herbicide relies on active growth to move through the plant. The same product on the same weed gives a different result on a dry August afternoon than after a good rain.</p>

<h2>What that means in practice</h2>

<p>It is one of the reasons a date gets moved. If you have just irrigated heavily, or we are coming off a week of rain, or the ground has been dry for a month with nothing forecast, the right call is sometimes to wait a few days rather than apply something that will not work.</p>

<p>We record the soil condition on every application, so when a treatment underperforms there is a record of what the ground was doing at the time rather than a guess.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$H_CARRIER = <<<'HTML'
<p>Almost nothing is applied at full strength. The active ingredient is mixed into a carrier that dilutes it and spreads it evenly over the area being treated.</p>
HTML;
$F_CARRIER = <<<'HTML'
<h2>Why the carrier is not just filler</h2>

<p>Water is the carrier for the large majority of what we apply, and there are good reasons for that — it dilutes accurately, it distributes evenly, and it adds nothing of its own to what ends up on your property.</p>

<p>Some products call for something else. An oil-based carrier sticks to waxy leaf surfaces that water beads off. A surfactant helps a spray wet and spread rather than sitting in droplets. Those are label decisions, not preferences, and mixing a product with the wrong carrier makes it less effective and can make it illegal to apply.</p>

<p>The carrier is recorded on the application because it is part of what was actually put down — and because two applications of the same product with different carriers are not the same treatment.</p>

<p>More on how it gets applied: <a href="/services/landscape-lawn-care/spraying/methods">spraying methods</a>.</p>
HTML;

$H_LOCATION = <<<'HTML'
<p>A lawn, a gravel drive, a fence line and a pasture are four different jobs, and treating them as one is how people end up with dead grass along a driveway edge.</p>

<p>The surface decides the product, the method, and what happens at the boundary with whatever is next to it.</p>
HTML;
$F_LOCATION = <<<'HTML'
<h2>Selective and non-selective, which is the whole distinction</h2>

<p>On a lawn, the product has to kill the weeds and leave the grass — that is a selective herbicide, and it works because it targets something broadleaf plants do and grasses do not.</p>

<p>On a gravel drive or a parking lot, there is nothing worth keeping, so the product can be non-selective and longer-lasting. Those two categories are not interchangeable, and the place they cause trouble is the boundary between them.</p>

<h2>The edges are where the care goes</h2>

<p>A driveway that runs into a lawn, a gravel strip along a bed, a fence line with somebody's garden on the other side — those transitions are where a non-selective product does damage that shows up two weeks later and takes a season to grow back.</p>

<p>That is a directed application with a wand, deliberately and slowly, rather than a broadcast pass. It is slower, it is the difference between a clean edge and a brown stripe, and it is most of the skill in this work.</p>

<h2>Where extra rules apply</h2>

<p>Some ground carries requirements beyond the usual. <strong>Pasture and arena</strong> mean livestock, which means product selection and re-entry intervals matter and get communicated before we leave. <strong>Anywhere near open water</strong> — ditches, ponds, retention basins — carries label restrictions that are not optional. <strong>Any property where children and pets use the area daily</strong> gets a re-entry interval in plain language, not buried in paperwork.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$H_METHODS = <<<'HTML'
<p>The equipment decides how precisely a product can be placed, how fast the work goes, and how much can drift on the way down. It is recorded on every application because it is part of what was actually done.</p>
HTML;
$F_METHODS = <<<'HTML'
<h2>Liquid or granular, first</h2>

<p>Before the equipment question there is a simpler one. <strong>Granular products cannot drift.</strong> They are heavier than air, they fall where the spreader throws them, and on a marginal wind day they are frequently the reason work continues at all.</p>

<p>Liquid gets better coverage on foliage and works faster on something already growing. Where the label allows either, conditions on the day often decide it.</p>

<h2>Why the equipment changes down the property</h2>

<p>A ride-on or pull-behind unit covers open turf quickly and evenly, and evenness is the point — a lawn treated in overlapping passes by hand shows every one of them by July.</p>

<p>A backpack or pump sprayer is what gets used where precision beats speed: fence lines, bed edges, cracks, spot treatments, anywhere a boundary has to be respected. Slower per square foot, and the right tool for the ten percent of the property where the mistakes happen.</p>

<p>Spreaders handle granular, and a push spreader exists for the same reason a backpack does — there are places a machine cannot go without hitting something.</p>

<h2>What that means for you</h2>

<p>If the crew is on your property with more than one piece of equipment, that is not indecision. Open turf and a fence line are different jobs and they take different tools.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$H_SIGNAL = <<<'HTML'
<p>Every pesticide product carries one word on the front of the label — Caution, Warning, or Danger. It is assigned by the EPA and it describes the acute toxicity of the product as it comes in the container.</p>

<p>If it appears on your application notice, this is what it means.</p>
HTML;
$F_SIGNAL = <<<'HTML'
<h2>The part people misread</h2>

<p>The signal word describes the <strong>concentrate</strong>, not what ends up on your lawn. Almost nothing is applied at full strength — it is diluted into a carrier, often substantially, before it goes anywhere near your property.</p>

<p>So a product carrying a Warning label is not putting something with a Warning label on your grass. It means the applicator handling the container is working with something that demands respect, which is a large part of what the licence is for.</p>

<h2>What actually protects you</h2>

<p>Not the word on the label — the <strong>re-entry interval</strong>. That is the time that has to pass before people or animals go back onto a treated area, it is on the label, and it is enforceable. It is the number worth asking about, and we tell you before we leave.</p>

<p>A Caution product with a longer re-entry interval deserves more of your attention than a Warning product with a short one. The signal word tells you about the jug. The re-entry interval tells you about your afternoon.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

/* ---------- NODE COPY (chemicals, stages) ---------- */

$H_CHEMICALS = <<<'HTML'
<p>Reference information on the products used in our licensed applications — what the words on a label mean and how to read what was applied to your property.</p>
HTML;
$F_CHEMICALS = <<<'HTML'
<h2>The label is the law</h2>

<p>That is not a figure of speech. Under federal law it is an offence to use a pesticide product in a way inconsistent with its labeling — the rate, the timing, the site, the conditions, the re-entry interval. A label is not manufacturer guidance. It is an enforceable document, and the applicator is the one holding the licence.</p>

<p>Which is why a licensed applicator will occasionally tell you no. If the label says not to apply above a given wind speed, or near water, or on a site type, that is where the conversation ends.</p>

<h2>What we tell you</h2>

<p>Every application we make is recorded — what was applied, where, at what rate, by whom, under what conditions — and you get a notice of it. If anything on that notice is unclear, the pages here explain it, and if they do not, ask us.</p>

<p>We are licensed by the Colorado Department of Agriculture to apply these products commercially. <a href="/about-us/credentials">Our licences and certifications</a>.</p>
HTML;
// The child navigation list to keep between header and footer on the chemicals node.
$CHEMICALS_CHILDREN = <<<'HTML'
<ul>
  <li><a href="/services/landscape-lawn-care/spraying/chemicals/signal-words">Signal Words</a> — what the Caution, Warning and Danger labels mean.</li>
</ul>
HTML;

$H_STAGES = <<<'HTML'
<p>The same herbicide on the same weed gives a different result depending on when in its life it is hit. Timing is not a refinement on weed control — it is most of it.</p>
HTML;
$F_STAGES = <<<'HTML'
<h2>Why young is easy and seeded is too late</h2>

<p>A young weed is small, soft, and moving water and nutrients fast. Anything applied to it gets taken up and distributed quickly, which is why a seedling dies from a fraction of what a mature plant shrugs off.</p>

<p>A mature weed has hardened leaf surfaces, a bigger root system, and more stored energy to survive an injury. Still controllable, but it takes more, and more of it ends up in your soil to achieve the same outcome.</p>

<p>A flowering weed is on a clock. Once it sets seed, killing the plant no longer solves the problem — the seed is already in the ground and it will be there for years. <strong>Bindweed seed can stay viable in soil for decades.</strong> Controlling a plant at flowering prevents one season of weeds. Missing it buys you many.</p>

<h2>Which is why we are sometimes early</h2>

<p>The best day to treat a weed is usually before you have noticed it. A crew turning up when the lawn looks fine is not making work — that is the visit that keeps it looking fine in July, and it uses less product to do it.</p>

<p>It is also the argument for a program rather than a call-out. By the time a weed problem is visible from the kitchen window, the cheap window has closed.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

/* ---------- APPLY: 8 VIEWS ---------- */

$VIEWS = [
  'land_spray_frequency' => [$H_FREQUENCY, $F_FREQUENCY],
  'land_wind_speed' => [$H_WIND_SPEED, $F_WIND_SPEED],
  'land_wind_direction' => [$H_WIND_DIR, $F_WIND_DIR],
  'soil_moisture_page' => [$H_SOIL, $F_SOIL],
  'land_carrier' => [$H_CARRIER, $F_CARRIER],
  'land_spray_location' => [$H_LOCATION, $F_LOCATION],
  'land_spray_methods' => [$H_METHODS, $F_METHODS],
  'land_signal_words' => [$H_SIGNAL, $F_SIGNAL],
];

$area = function (string $html): array {
  return [
    'area' => [
      'id' => 'area',
      'table' => 'views',
      'field' => 'area',
      'relationship' => 'none',
      'group_type' => 'group',
      'admin_label' => '',
      'plugin_id' => 'text',
      'empty' => TRUE,
      'tokenize' => FALSE,
      'content' => ['value' => $html, 'format' => 'full_html'],
    ],
  ];
};

foreach ($VIEWS as $vid => [$h, $f]) {
  $view = $etm->getStorage('view')->load($vid);
  if (!$view) {
    print "  MISSING view $vid — skipped\n";
    continue;
  }
  $display = $view->get('display');
  $display['default']['display_options']['header'] = $area($h);
  $display['default']['display_options']['footer'] = $area($f);
  // Force page_1 to inherit the default header/footer (don't override them).
  if (isset($display['page_1'])) {
    $display['page_1']['display_options']['defaults']['header'] = TRUE;
    $display['page_1']['display_options']['defaults']['footer'] = TRUE;
    unset($display['page_1']['display_options']['header'], $display['page_1']['display_options']['footer']);
  }
  $view->set('display', $display);
  $view->save();
  print "  view $vid: header replaced + footer added\n";
}

/* ---------- APPLY: 2 NODES ---------- */

$NODES = [
  '/services/landscape-lawn-care/spraying/chemicals' => $H_CHEMICALS . "\n" . $CHEMICALS_CHILDREN . "\n" . $F_CHEMICALS,
  '/services/landscape-lawn-care/spraying/stages-weed-growth' => $H_STAGES . "\n" . $F_STAGES,
];
$aliasManager = \Drupal::service('path_alias.manager');
foreach ($NODES as $alias => $body) {
  $internal = $aliasManager->getPathByAlias($alias);
  if (!preg_match('#^/node/(\d+)$#', $internal, $m)) {
    print "  NODE alias $alias did not resolve to a node — skipped\n";
    continue;
  }
  $node = $etm->getStorage('node')->load((int) $m[1]);
  if (!$node) {
    print "  node for $alias missing — skipped\n";
    continue;
  }
  $fmt = $node->get('body')->format ?: 'full_html';
  $node->set('body', ['value' => $body, 'format' => $fmt]);
  $node->save();
  printf("  node/%d (%s): body = header + footer\n", $node->id(), basename($alias));
}

print "DONE.\n";
