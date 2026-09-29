<?php

declare(strict_types=1);

/**
 * The two fruit pages.
 *
 * Fruit Trees is a new material category under Deciduous — a third purpose
 * beside Shade and Ornamental. Making it a peer of Evergreens would repeat the
 * Junipers error (a genus filed beside a plant form), which is the one thing
 * this restructure was meant to stop doing.
 *
 * Fruit-Bearing is an existing plant characteristic that had no copy. Its body
 * goes in core description, where all 41 characteristic bodies live — not in a
 * second field meaning the same thing.
 *
 * Copy is verbatim. Nothing here is written or edited by Code.
 *
 * Idempotent. Dry run unless BOS_FRUIT_APPLY=1.
 */

$apply = getenv('BOS_FRUIT_APPLY') === '1';
print $apply ? "APPLYING\n\n" : "DRY RUN (set BOS_FRUIT_APPLY=1 to apply)\n\n";

$etm = \Drupal::entityTypeManager();
$terms = $etm->getStorage('taxonomy_term');

// ---------------------------------------------------------------- FRUIT TREES
$deciduous = NULL;
foreach ($terms->loadByProperties(['vid' => 'material_types', 'name' => 'Deciduous']) as $t) {
  $deciduous = $t;
}
if (!$deciduous) {
  print "ABORT: no Deciduous term\n";
  return;
}

$ft_header = <<<'HTML'
<p>Delta County has grown fruit for more than a century, and the reason it works here is close to the reason it is difficult. This valley has the sun, the diurnal swing and the season for good fruit. It also has a late frost that turns up often enough to matter.</p>

<p>So the first question about a fruit tree here is not what you like to eat. It is when the tree blooms — because a bloom that opens before the last hard freeze is a crop you do not get, and that is a property of the species rather than of anything you did.</p>

<p>Roughly in order of bloom: apricot first, then peach, then sweet cherry, then plum, then pear, and apple last. <strong>Read that as a reliability ranking and it is nearly exact.</strong> Apricots are the heartbreak tree of this valley — they will give you a spectacular crop and then miss three years running. Apples bloom late enough to clear most frosts, which is not a coincidence and not unrelated to why this county is covered in apple orchards.</p>

<p>Here is what we stock and plant.</p>
HTML;

$ft_footer = <<<'HTML'
<h2>Most of them need a partner</h2>

<p>The thing that catches first-time fruit growers is pollination. Most apples will not set a decent crop from their own pollen — they need a second, different apple variety blooming at the same time, within bee range. Sweet cherries are generally the same. Pears usually want a partner, and so do most Japanese plums.</p>

<p>Peaches, apricots, tart cherries and most European plums are self-fruitful and will crop on their own. Which means one peach tree is a reasonable plan and one apple tree usually is not — and that is the single most common reason somebody has a healthy, beautiful apple tree that has never produced anything.</p>

<h2>Rootstock decides the size, and the wait</h2>

<p>A fruit tree is two plants joined together: the variety on top, which decides what the fruit tastes like, and the rootstock underneath, which decides almost everything else. The same Honeycrisp is an eight-foot tree or a twenty-five-foot tree depending on what it is grafted onto.</p>

<ul>
<li><strong>Dwarf</strong> — eight to ten feet, bears in two or three years, prunes and picks from the ground. Needs permanent staking; the root system does not hold a loaded tree up on its own.</li>
<li><strong>Semi-dwarf</strong> — twelve to eighteen feet, bears in three or four years. The usual right answer for a yard.</li>
<li><strong>Standard</strong> — twenty feet and up, five to eight years before it bears, and it will outlive you. A ladder tree.</li>
</ul>

<p>Rootstock also affects how a tree handles the ground it is in, and on alkaline soil that is not a small detail — the wrong rootstock goes chlorotic here the same way an ornamental would. Worth asking about rather than buying on variety alone.</p>

<h2>What a fruit tree actually asks of you</h2>

<p>This is the part that gets skipped at the point of sale, and it is the difference between an orchard and a row of sad trees.</p>

<p>A fruit tree is pruned every year, in dormancy, for light and structure rather than for shape — an open center on peaches and plums, a central leader on apples and pears. An unpruned fruit tree gets dense, stops ripening fruit in the middle, and eventually breaks under its own crop. It is also thinned in early summer, which feels wrong and is not: a tree carrying too much fruit gives you small fruit, broken limbs, and nothing at all the following year.</p>

<p>And it is sprayed on a schedule. Codling moth is the reason for wormy apples and pears on the Western Slope, and controlling it means timed applications rather than spraying when you notice damage — by then the larva is inside the fruit. Peach leaf curl is prevented with a dormant application and cannot be fixed once leaves are out. Fire blight moves fast in apples and pears in a wet spring.</p>

<p>None of that is difficult. All of it is a calendar, and a calendar is the thing most homeowners do not keep. We plant fruit trees, we prune them, and we run spray programs on them — and if you would rather handle it yourself, ask us for the schedule and we will write it down.</p>

<h2>Fruit as a landscape decision, rather than a harvest</h2>

<p>If what you want is the look of fruit — color on the branch in October, berries against snow, birds in the yard — without the ladder and the spray calendar, that is a different decision and there are better plants for it. <a href="/material/plants/characteristics/aesthetic-features/fruit-bearing">Fruit-bearing as a landscape characteristic</a> covers what fruit does to a planting, including the part about never putting one over a patio.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$ft_crew = <<<'HTML'
<p><strong>Ask two questions before anything else: do they want fruit they will actually pick, and is there room for two trees.</strong> Those answers settle most of the selection.</p>

<ul>
<li><strong>Never sell a single apple or sweet cherry</strong> without explaining pollination. One apple tree is the most common fruit tree mistake we see, and it does not show up as a complaint for three or four years.</li>
<li><strong>Talk them out of apricot unless they know what they are getting.</strong> It will crop beautifully and then miss several years running. Some people want it anyway and that is fine — as long as they heard it from us first.</li>
<li><strong>Semi-dwarf is the default recommendation.</strong> Bears in three or four years, picks without a ladder, no permanent staking. Dwarf only where space is genuinely tight, and say that it must stay staked for life.</li>
<li><strong>Check rootstock against the soil.</strong> Alkaline ground chloroses the wrong rootstock the same as any other plant. Ask the supplier.</li>
<li><strong>Wrap the trunk the first three winters.</strong> Smooth young fruit tree bark is the textbook sunscald casualty here, and rabbits girdle it at snow line. Both are preventable and neither is repairable.</li>
<li><strong>Put the pruning and spray schedule on the maintenance plan at install</strong>, not the year the fruit comes in wormy.</li>
</ul>

<p><strong>What to tell a customer:</strong> a fruit tree is the most work of anything we plant. Pruned every winter, thinned every June, sprayed on a calendar. It is entirely doable and it is not optional — an unmaintained fruit tree produces small wormy fruit and eventually breaks itself. Say it at the sale.</p>

<p><strong>Years to bearing, plainly:</strong> nobody is picking fruit next summer. Two to three years on dwarf, three to four on semi-dwarf, five or more on standard. Said up front it is a reasonable wait. Discovered later it is a complaint.</p>
HTML;

$existing = NULL;
foreach ($terms->loadByProperties(['vid' => 'material_types', 'name' => 'Fruit Trees']) as $t) {
  $existing = $t;
}
if (!$existing) {
  printf("  create  Fruit Trees under Deciduous (%s)\n", $deciduous->id());
  if ($apply) {
    $existing = $terms->create(['vid' => 'material_types', 'name' => 'Fruit Trees', 'parent' => [$deciduous->id()]]);
    $existing->save();
    // Sibling aliases in this vocabulary are manual, not generated — match them.
    $alias = $etm->getStorage('path_alias')->create([
      'path' => '/taxonomy/term/' . $existing->id(),
      'alias' => '/material/plants/trees/deciduous/fruit-trees',
      'langcode' => 'en',
    ]);
    $alias->save();
    \Drupal::keyValue('pathauto_state.taxonomy_term')->set($existing->id(), 0);
    printf("    tid %s at %s\n", $existing->id(), $existing->toUrl()->toString());
  }
}
else {
  printf("  exists  Fruit Trees tid %s at %s\n", $existing->id(), $existing->toUrl()->toString());
}

if ($apply && $existing) {
  $existing->set('field_public_description', ['value' => $ft_header, 'format' => 'full_html']);
  $existing->set('field_call_to_action', ['value' => $ft_footer, 'format' => 'full_html']);
  $existing->set('field_teammate_description', ['value' => $ft_crew, 'format' => 'full_html']);
  if ($existing->hasField('field_meta_tags')) {
    $existing->set('field_meta_tags', json_encode([
      'title' => 'Fruit Trees for Delta County | Brookstone Outdoors',
      'description' => 'Apricots bloom first and lose the crop most years; apples bloom last and are the reliable one. Pollination, rootstock and what a fruit tree asks of you here.',
    ]));
  }
  $existing->save();
  print "    copy + metatags written\n";
}

// -------------------------------------------------------------- FRUIT-BEARING
$fb_short = 'Produces fruit, berries or hips as part of what it contributes to a planting. Whether that is an asset or a nuisance comes down to one question: does the fruit hold on the branch, or drop — and what is underneath it.';

$fb_body = <<<'HTML'
<p>Fruit on a landscape plant is a different proposition from fruit on a fruit tree. Nobody is picking it, most of it is small or sour or strictly for the birds, and its whole value is what it does to the look of a planting and who it brings into the yard.</p>

<p>Done well it is one of the better tools available here. A crabapple holding red fruit through December, serviceberry in June, rose hips after the flowers are gone, currants, viburnum, sumac — these carry a planting through the months when there is nothing else to look at. <strong>In a climate with a four-month growing season and a five-month winter, fruit is one of very few things that works in the off months.</strong> It is also the most direct way to bring birds in, which is usually the point of a bird planting rather than the feeder.</p>

<p><strong>The distinction that decides everything is whether the fruit holds or drops.</strong> Persistent fruit stays on the branch through winter, dries in place, and either feeds birds or simply looks good against snow. Dropping fruit hits the ground, ferments, stains, draws wasps, and turns a walk slick for a few weeks every fall. Two plants can be described identically on a tag and behave completely differently on this one point, and it is worth asking before anything goes in.</p>

<p>Which leads to the only hard rule on this page. <strong>Never plant anything that drops fruit over a patio, a walk, a drive, a parking area or anywhere people sit.</strong> The same crabapple that is a good decision in a border twenty feet away is a bad one over a seating area, and the difference shows up for a few weeks every autumn for the entire life of the tree. Check what is underneath before you check anything else.</p>

<p>One more thing worth knowing. A fruiting plant that birds like is a plant birds distribute, and a few of them have escaped into the drainages and fence lines around here that way. Anything introduced and heavily fruiting gets checked against the Colorado noxious weed list before it goes on a plan — a plant that thrives in this climate and spreads on its own is not an ornamental, it is somebody's future removal job.</p>

<p>If what you are actually after is fruit you intend to eat, that is a different set of questions entirely — bloom timing, pollination partners, rootstock, and a pruning and spray calendar. <a href="/material/plants/trees/deciduous/fruit-trees">Fruit trees</a> covers that side of it.</p>
HTML;

$fb_crew = <<<'HTML'
<p><strong>Ask what is underneath it. Before anything else, every time.</strong> Fruit drop over hard surface is the most avoidable callback in the catalog and it happens every fall for the life of the plant.</p>

<ul>
<li><strong>Nothing that drops fruit over a patio, walk, drive, parking or seating.</strong> No exceptions worth making. Stain, wasps, and a slip hazard on a wet walk.</li>
<li><strong>Know which of ours hold and which drop.</strong> It is the single most useful thing to be able to answer on the spot, and it is not on most tags. Persistent fruit is the one you want near the house.</li>
<li><strong>Sell fruit as winter interest, because that is what it is.</strong> Berries against snow is one of the few things that works here in January. Tie it to the seasonal conversation rather than the flower conversation.</li>
<li><strong>Bird plantings need cover as well as fruit.</strong> A fruiting plant on its own in the open gets picked and abandoned. Dense branching nearby is half of it.</li>
<li><strong>Check heavy fruiters against the noxious weed list</strong>, particularly near ditches, fence lines and open ground. Birds move seed a long way.</li>
<li><strong>Set expectations on edibility.</strong> Most ornamental fruit is small, sour or not worth eating, and somebody always asks. Say so plainly — it is not a defect.</li>
</ul>

<p><strong>What to tell a customer:</strong> if they like the look of fruit, we can do that well and it will carry the planting through winter. If they want to eat it, that is a fruit tree and a different conversation — pollination partners, a ladder, and a spray schedule. Both are good answers. Mixing them up is where people end up disappointed.</p>

<p><strong>If a customer wants an ornamental crabapple and also wants apples:</strong> those are two trees, and the crabapple may well improve the apple crop by pollinating it. That is a good upsell and it is also true.</p>
HTML;

$fb = NULL;
foreach ($terms->loadByProperties(['vid' => 'plant_characteristics', 'name' => 'Fruit-Bearing']) as $t) {
  $fb = $t;
}
if (!$fb) {
  print "\n  MISS: no Fruit-Bearing characteristic\n";
}
else {
  printf("\n  Fruit-Bearing tid %s at %s\n", $fb->id(), $fb->toUrl()->toString());
  if ($apply) {
    $fb->set('field_short_description', ['value' => '<p>' . $fb_short . '</p>', 'format' => 'full_html']);
    // Body in core description — where all 41 characteristic bodies live.
    $fb->set('description', ['value' => $fb_body, 'format' => 'full_html']);
    $fb->set('field_teammate_description', ['value' => $fb_crew, 'format' => 'full_html']);
    if ($fb->hasField('field_meta_tags')) {
      $fb->set('field_meta_tags', json_encode([
        'title' => 'Fruit-Bearing Plants & Berries | Brookstone Outdoors',
        'description' => 'Fruit that holds on the branch is winter interest. Fruit that drops is stain, wasps and a slick walk. Why what is underneath decides the plant, not the tag.',
      ]));
    }
    $fb->save();
    print "    short description, body and crew notes written\n";
  }
}
print "\nDONE.\n";
