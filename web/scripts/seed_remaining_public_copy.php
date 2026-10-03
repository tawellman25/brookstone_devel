<?php

declare(strict_types=1);

/**
 * Marketing's answer to the 3 October content-coverage gap list:
 * Lighting PARENT, Patios, and the eleven material category bodies - each with
 * a call to action - plus the two internal links that were taking a redirect.
 *
 * ⚠ DOES NOT TOUCH THE TWO LIGHTING CHILD PAGES. Exterior Lighting (1648) and
 * Landscape Lighting (1647) are governed by seed_lighting_split_copy.php, which
 * carries Todd's 3 October SPLIT decision. This file's Lighting copy is the
 * PARENT term 1505 only, and the split document explicitly says the parent needs
 * no change. The guard below asserts it rather than trusting the term names.
 *
 *   drush php:script web/scripts/seed_remaining_public_copy.php
 *   BOS_COPY_APPLY=1 drush php:script web/scripts/seed_remaining_public_copy.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_COPY_APPLY') === '1';
$etm = \Drupal::entityTypeManager();

// Terms this script must never write to, whatever else happens.
$PROTECTED = [1647 => 'Landscape Lighting', 1648 => 'Exterior Lighting'];

// vid => [term name => [body, cta]]
$COPY = ['services' => [], 'material_types' => []];

$COPY['services']['Lighting'] = [<<<'HTML'
<p>Lighting is the one thing in a landscape that changes what the property is for. A yard with no light is finished at dusk. The same yard lit properly is somewhere people are still sitting at nine o'clock in September, which on this side of the divide is most of the evenings worth having.</p>

<p>It is also the shortest job we do. Most low-voltage systems go in over a day or two, on an existing landscape, with no demolition and very little disturbance — a trencher slit through turf closes up in a week. There is no other improvement that changes a property this much for this little disruption.</p>

<p><strong>Three things get lit, and they are different jobs.</strong></p>

<ul>
<li><strong>Getting around after dark.</strong> Steps, grade changes, the walk from where people park to the door. This is the half nobody asks for and everybody uses, and it is the part that matters most on a property with a slope or a long drive.</li>
<li><strong>What the place looks like at night.</strong> Uplighting a mature tree, grazing a stone wall, washing the front of the house. Done well this is not more light — it is less light, aimed carefully, with the fixtures out of sight.</li>
<li><strong>Being able to use the space.</strong> Patio, outdoor kitchen, seating. Enough to eat by and talk by, and no more than that.</li>
</ul>

<p>What separates a system that still looks right in year five is almost entirely in the parts that do not show. Fixtures live outside through winters here, under snow, through freeze and thaw and a lot of UV at this elevation. <strong>Brass and copper age and keep working; thin-wall aluminum and plastic fail at the lens seal and the socket, and they tend to fail in the second or third season, which is after anybody is still thinking about who installed them.</strong> The wire gauge and the transformer sizing decide whether the far end of a run is as bright as the near end, and that is a calculation, not a guess.</p>

<p>We design it, install it, and we are here to service it — which matters on a system that lives outdoors, because something will eventually need a connection remade or a fixture reaimed after a tree has grown into it.</p>

<p>Seasonal and holiday lighting is a separate conversation and a separate crew window. If that is what you are after, the holiday pages below are the place to start.</p>
HTML, <<<'HTML'
<p>The best way to decide anything about lighting is to stand in the yard after dark. We will come out in the evening, walk it with you, and show you what a fixture actually does in that spot — which is more useful than any photograph. <a href="/request-estimate">Request an estimate</a> and we will set a time after sundown.</p>
HTML];

$COPY['services']['Patios'] = [<<<'HTML'
<p>A patio is the floor of an outdoor room, and almost everything that goes wrong with one traces back to a decision made before anybody set a stone.</p>

<p><strong>Size is the first.</strong> Most patios are built too small, and it is not a budget decision — it is that a rectangle chalked on the ground looks enormous and furnished looks cramped. A table that seats six, with chairs pulled out and room to walk behind them, needs more space than people expect. We lay it out on the ground with the actual furniture before anything is excavated, because moving a chalk line costs nothing and moving a patio costs everything.</p>

<p><strong>Then what goes under it</strong>, which is the part that decides whether it is still flat in ten years. On this ground that means excavating to the right depth, compacting a base in lifts rather than all at once, and getting the drainage right so that water leaves rather than collects. Freeze and thaw is the test here — water that sits under a patio in November lifts it by March. A patio that heaves is almost never a problem with the surface. It is a problem with what was or was not done below it.</p>

<p><strong>Then the material</strong>, and this is the part everybody starts with. Pavers come up and go back down, which matters if a utility ever has to be reached, and an individual damaged unit can be replaced. Stamped concrete is a single pour and costs less to cover a large area, and a crack in it is a repair rather than a swap. Natural stone sets by hand and looks like nothing else, and it takes longer. All three work here. Which one is right depends on the space, the house and what you want to spend, and that is a conversation rather than a catalog.</p>

<p>The things worth deciding at the same time, because they are far cheaper now than later: anything that needs a sleeve or a conduit run under the slab, where the <a href="/services/lighting">lighting</a> goes, and whether a future shade structure needs footings. Cutting into a finished patio to add one of those is the most avoidable expense in this trade.</p>

<p>We build the whole thing — layout, excavation, base, surface, and the planting and lighting that make it feel like part of the property rather than a slab behind it.</p>
HTML, <<<'HTML'
<p>Patios book out, and the ground has to be workable. A patio for next summer is a conversation worth having over the winter, when there is time to get the design right. <a href="/request-estimate">Request an estimate</a>.</p>
HTML];

$COPY['material_types']['Plants'] = [<<<'HTML'
<p>Everything living that goes onto a job is in here — trees, shrubs, perennials, grasses, groundcovers, ferns, vines, annuals and roses. This is the top of the plant catalog and the other categories sit underneath it.</p>

<p>Plant selection out here is a narrower problem than it is in most of the country, and narrower is useful. Soil across the valley floor runs alkaline, commonly pH 7.5 to 8.2, which locks up iron and takes a long list of otherwise excellent plants off the table — they go yellow between green veins, decline for years, and nobody can feed them out of it. Water is allocated and it is seasonal. The frost dates are not generous at either end, and a warm week in April regularly costs somebody their fruit crop. Deer work the edges of most properties, and the wind does the rest.</p>

<p>What survives all of that is a smaller list than the catalogs suggest, and it is the list in here. A plant on this page is one we have put in the ground on properties in Delta and Montrose counties and watched through winters.</p>

<p>Two ways to use it. Browse by category below if you know roughly what you are after — a tree, a hedge, something for a bank. Or work from <a href="/material/plants/characteristics">plant characteristics</a> if you are starting from the problem instead: dry shade, a slope, alkaline ground, deer pressure, a spot that never drains.</p>
HTML, <<<'HTML'
<p>A plant list is the easy part. Knowing what the ground is doing in a particular corner of a particular property is the part that takes a site visit. <a href="/request-estimate">Ask us to come and look</a> before you buy anything for a difficult spot.</p>
HTML];

$COPY['material_types']['Trees'] = [<<<'HTML'
<p>A tree is the longest-lived decision on a property and usually the most valuable. It is also the one where a mistake is hardest to walk back — a shrub in the wrong place is a Saturday, a forty-foot tree in the wrong place is a crane.</p>

<p>Which is why the measurements come before the species. Mature width against the house, the drive and the property line. What is overhead, because a tree under a power line gets topped eventually and topping ruins it permanently. Where the sewer lateral and the leach field run, because several of the fastest-growing trees available here are the ones whose roots find them. Those four checks take twenty minutes and they matter more than the choice between any two good trees.</p>

<p>The split below is how people actually shop. Evergreens hold their foliage and carry the structure of a property through five months when nothing else does. Deciduous trees drop their leaves, which is the easier life on this ground — a dormant plant does not lose water through a dry January wind — and they divide again into shade trees, the smaller ornamental trees, and fruit.</p>

<p>Alkaline soil is the filter that decides most of this. Several of the best-known street trees in the country go chlorotic here and spend fifteen years looking sick. They are not in this catalog.</p>
HTML, <<<'HTML'
<p>If you are putting in a tree that is meant to be there in fifty years, it is worth twenty minutes on site to get the position right. <a href="/request-estimate">We will come and walk it</a> — no charge for the conversation.</p>
HTML];

$COPY['material_types']['Shrubs'] = [<<<'HTML'
<p>Shrubs do the structural work in a landscape. They are what fills the space between the trees and the ground, what screens a view, what softens a foundation, and what a bed looks like in February when the perennials are gone.</p>

<p>The question that decides a shrub is mature size, and it is the one most often got wrong. A plant that comes off the truck at two feet and is rated to eight has made a promise, and in five years it keeps it — against the walk, over the window, into its neighbor. Spacing a bed to mature size looks sparse the first year, correct in year three, and like the drawing by year five. That gap is where most of the pruning-forever problems in this valley get created.</p>

<p>Beyond size, the divisions below are the practical ones. Evergreen shrubs hold foliage and keep a bed from emptying out over winter, and they work hardest at the time of year when they are most exposed. Deciduous shrubs give you flowering, fall color and a wider range of plants that tolerate this soil. Roses are their own thing and have their own page.</p>

<p>Worth knowing before you choose for a screen: a great many shrubs sold for screening are solid at eye level for eight years and then go bare at the bottom as they mature. The useful question is never how tall it gets. It is how dense it is at the height you actually need it.</p>
HTML, <<<'HTML'
<p>Shrub spacing is the thing customers push back on hardest and the thing worth trusting us on. <a href="/request-estimate">Have us lay out a bed</a> and you will see why the gaps look generous.</p>
HTML];

$COPY['material_types']['Evergreens'] = [<<<'HTML'
<p>An evergreen is doing its job in February, and February is the whole argument for planting one. Five months of the year everything else is bare, and what holds foliage is holding the shape of the property.</p>

<p><strong>February is also when it is under the most stress, and the cause is not cold.</strong> An evergreen keeps its leaves, so it keeps losing water through them all winter — and when the ground is frozen the roots cannot replace what is lost. Add a dry wind and the intense winter sun we get at this elevation and the plant dries out from the exposed side inward. The foliage goes brown and brittle. That is winter burn, it has nothing to do with hardiness ratings, and it is why the same plant thrives on one side of a house and fails on the other.</p>

<p>It decides what works here. Needled and scaled plants — pines, spruce, junipers — have less surface area losing water and a waxy coating holding it in, and most of them evolved somewhere just as dry. Broadleaf evergreens have more leaf exposed and show it. They can be grown here, on a north or east exposure, out of the wind, and they are a poor bet anywhere else.</p>

<p>Two things decide the outcome more than the plant choice does: where it goes, and whether it went into winter watered. A deep soak before the ground freezes is the most useful thing anyone can do for an evergreen in this valley, and almost nobody does it.</p>
HTML, <<<'HTML'
<p>Exposure is most of the job with evergreens, and it is not something you can judge from a catalog. <a href="/request-estimate">Have us look at the spot</a> before you commit to a plant.</p>
HTML];

$COPY['material_types']['Roses'] = [<<<'HTML'
<p>Roses have a reputation here for being fussy, and most of it was earned by the wrong roses being planted in the wrong places thirty years ago.</p>

<p>The ones that struggle are the high-bred hybrid teas — grafted, thirsty, prone to every leaf disease going, and needing to be hilled up and protected every winter to survive one. They can be grown here by somebody who enjoys growing them. They are a bad recommendation for anybody who does not.</p>

<p>The ones that work are the shrub and landscape roses bred for cold and for disease resistance, growing on their own roots rather than grafted. On their own roots matters more than it sounds: if a hard winter kills the top, the plant comes back as itself rather than as the rootstock. Those roses tolerate this soil, flower for months rather than weeks, and ask for about as much attention as any other flowering shrub.</p>

<p>Three things decide whether a rose does well here regardless of which one it is. Full sun — six hours minimum, and east-facing is better than west because the foliage dries early. Water at the base rather than over the top, because wet leaves overnight is how leaf disease starts. And air moving through the plant, which is a spacing decision made at install, not a pruning problem solved later.</p>
HTML, <<<'HTML'
<p>If a rose has disappointed you before, it was probably the variety and not you. <a href="/request-estimate">Tell us what happened</a> and we will point you at something that behaves differently.</p>
HTML];

$COPY['material_types']['Annuals'] = [<<<'HTML'
<p>Annuals live one season and then they are finished, which is the honest trade and also the entire point. Nothing else gives you continuous color from the last frost to the first one, and nothing else lets you change your mind every year.</p>

<p>They earn their place where a permanent planting cannot work hard enough — containers at an entry, a bed against the house that is seen every day, the stretch of a commercial frontage that has to look deliberate from May to October. For a wedding, a party or a listing photo, they are the only plant that delivers on a date.</p>

<p>The honest version of what they cost is water and attention. An annual has a shallow root system and about four months to do everything, so it does not ride out a dry week the way an established perennial does — in July here, a container can need water daily. They want feeding, because the same speed that makes them useful empties the pot. And deadheading keeps most of them going; without it a lot of annuals quit in August.</p>

<p>Put them where they are seen and where they are easy to reach with a hose, and keep the permanent structure of the planting in perennials and shrubs underneath. Annuals are the layer on top, not the plan.</p>
HTML, <<<'HTML'
<p>If you want color that holds from May to frost without thinking about it, we plant and maintain seasonal displays on a schedule. <a href="/request-estimate">Ask about it</a>.</p>
HTML];

$COPY['material_types']['Perennials'] = [<<<'HTML'
<p>A perennial dies back to the ground every winter and comes up again from the same roots. You buy it once, it gets better for several years, and most of them eventually make two or three plants out of one.</p>

<p>That is the case for building a bed on them, and the thing to understand is the timeline. The old line about perennials — sleep, creep, leap — describes the first three years accurately. A bed planted this spring looks thin this summer, decent next summer, and like the intention in year three. A customer who expects year three in year one will pull it out in year two.</p>

<p>What they ask for is less than people think and different from what people expect. Two seasons of establishment watering, same as anything. Cutting back once a year — and later in winter rather than in the fall, because the standing stems and seed heads are most of what a bed has to look at in January. Dividing somewhere between year four and year seven, when the center thins and the growth moves to the edges, which is routine rather than a failure.</p>

<p>The real skill is bloom succession. A bed where everything flowers in June is a bed that is finished by July. Spreading the bloom from the first warm weeks to hard frost takes no more plants and no more money — it just takes choosing them in a particular order.</p>
HTML, <<<'HTML'
<p>Bloom succession is the difference between a bed that peaks for three weeks and one that works all season, and it costs nothing extra to get right. <a href="/request-estimate">Have us design it</a>.</p>
HTML];

$COPY['material_types']['Groundcovers'] = [<<<'HTML'
<p>A groundcover does what turf does without the mowing, the water or the edging. It closes bare soil, keeps weeds from getting a start, and holds ground that would otherwise wash.</p>

<p>Its real value here is the awkward ground, and most properties have some. Slopes too steep to get a mower onto safely. Dry shade under a mature tree where grass has never grown and never will. The strip between a walk and a drive that is too narrow to water without soaking the concrete. Those are places where a lawn is a permanent argument, and a groundcover settles it.</p>

<p><strong>It is not a lawn substitute where people walk.</strong> Almost nothing low and spreading takes foot traffic the way turf does, and a groundcover used as a shortcut wears through in one season. If there is a route across it, stepping stones go in at install rather than getting added after the damage.</p>

<p>Two things to know before choosing one. Spreading is the point, so it needs containing where it meets a lawn or a neighbor — that is an edging decision made at install. And weed suppression only starts once the canopy closes. For the first two seasons a groundcover bed needs weeding like any other bed, and the ones that fail are almost always the ones abandoned in year one.</p>
HTML, <<<'HTML'
<p>If there is a piece of ground on your property that has beaten grass twice, it is a groundcover problem rather than a lawn problem. <a href="/request-estimate">Show it to us</a>.</p>
HTML];

$COPY['material_types']['Ferns'] = [<<<'HTML'
<p>Ferns are a narrow category here and worth being straight about. This is high desert. The air is dry, the light is intense at this elevation, and most of the ferns people picture come from woodland with humidity we do not have.</p>

<p>What that leaves is a real but short list — the ferns adapted to dry shade and to rock, and they are genuinely useful in the one situation nothing else solves well. North side of a house. Under a deep overhang. The shaded gap between a wall and a fence. Those spots defeat most flowering plants, which need light to do anything, and a fern does not need light because it is not trying to flower.</p>

<p>What they do need is consistent moisture at the root and shelter from wind, and in a dry spring here that means supplemental water on a schedule rather than whenever somebody remembers. They also want organic matter in the soil, which our ground does not supply, so a fern planting is one of the few places where amending the bed is genuinely the right answer.</p>

<p>Used where they belong they are the best texture available in deep shade. Used as a general landscape plant in this climate they disappoint, and we would rather say so here than after you have bought them.</p>
HTML, <<<'HTML'
<p>Deep shade is the hardest brief on most properties and there are more answers to it than ferns. <a href="/request-estimate">Tell us about the spot</a> and we will give you the full list.</p>
HTML];

$COPY['material_types']['Grasses'] = [<<<'HTML'
<p>Ornamental grasses do something no other plant does: they move. In a valley with this much wind that is not a small thing — a planting with grasses in it is never entirely still, and the difference between that and a static bed is most of why some landscapes feel alive.</p>

<p>They are also among the toughest plants on this list. A great many of the good ones are prairie and steppe plants adapted to exactly this — alkaline ground, hard sun, a long dry stretch in late summer, and cold. Established, most of them want less water than almost anything else here that still looks like something.</p>

<p>Their best season is the one most plants have given up on. Grasses carry through fall as the seed heads mature and the foliage turns, and they hold into winter — standing tan and catching snow when the beds around them are bare. That makes them the backbone of the January view, and it is the reason the right time to cut them back is late winter, just before new growth, rather than at fall cleanup.</p>

<p><strong>One thing to get right before anything is planted: clumping or running.</strong> A clumping grass expands slowly from one crown and stays where it was put. A running grass travels by rhizome and ends up in the lawn and in its neighbors. Both are sold as ornamental grasses and they are completely different commitments. In a mixed bed, the clumping ones are the only safe answer.</p>
HTML, <<<'HTML'
<p>Grasses are the most underused plant in this valley given how well they suit it. <a href="/request-estimate">Ask us where they would work</a> on your property.</p>
HTML];

$COPY['material_types']['Vines'] = [<<<'HTML'
<p>A vine covers vertical surface, which on a small property is often the only space left. It is also the plant most often bought without the one decision that determines whether it ever climbs.</p>

<p><strong>A vine has no structure of its own, and how it attaches decides what it needs to attach to.</strong> Some wrap their whole stem around something and need a post or a wire — they cannot grip a flat wall or a wide beam. Some climb with thin coiling tendrils that need something slender to catch: wire, netting, thin lattice, never a four-by-four. Some attach directly to a flat surface with aerial roots or adhesive pads and need no structure at all. And some do not climb — they lean, and somebody ties them in every year, which is a maintenance line rather than a plant.</p>

<p>Match the vine to the support before planting, not after. A twining vine on a flat wall will never get hold of it and will sit there looking like a failed plant.</p>

<p>Two cautions worth having before you buy. A mature vine is heavy and wet snow makes it far heavier — a trellis held to siding with two screws comes off the wall in a storm, so the support gets lagged into framing. And the self-clinging vines should not go on wood siding, stucco, or mortar that might ever need repointing. On sound masonry or a standoff frame they are fine. Anywhere else they are a repair bill arriving in eight years.</p>
HTML, <<<'HTML'
<p>The support is part of the plant, not an accessory, and it is cheaper to build right than to redo. <a href="/request-estimate">Have us spec both together</a>.</p>
HTML];

// The two internal links that were taking a redirect hop.
$LINKS = [
  ['services', 'Landscaping', 'field_service_public_desc', '"/lighting"', '"/services/lighting"'],
  ['services', 'Fall Cleanup', 'field_service_public_desc', '"/lighting/landscape-lighting"', '"/services/lighting/landscape-lighting"'],
];

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_COPY_APPLY=1 to write)\n\n";

$BODY = ['services' => 'field_service_public_desc', 'material_types' => 'field_public_description'];
$CTA = 'field_call_to_action';

// Guards.
foreach ($COPY as $vid => $terms) {
  foreach ($terms as $name => [$body, $cta]) {
    foreach (['body' => $body, 'cta' => $cta] as $k => $html) {
      if (strpos($html, '**') !== FALSE) { print "ABORT — markdown ** in $name/$k\n"; return; }
      if (preg_match_all('~href="(/[^"]+)"~', $html, $m)) {
        foreach ($m[1] as $href) {
          if (\Drupal::service('path_alias.manager')->getPathByAlias($href) === $href
            && !\Drupal::service('path.validator')->isValid($href)) {
            print "ABORT — unresolved link $href (in $name/$k)\n"; return;
          }
        }
      }
    }
  }
}
print "✓ no markdown artifacts; every internal link resolves\n";

$byName = [];
foreach (['services', 'material_types'] as $vid) {
  foreach ($etm->getStorage('taxonomy_term')->loadMultiple(\Drupal::entityQuery('taxonomy_term')
    ->accessCheck(FALSE)->condition('vid', $vid)->execute()) as $t) {
    $byName[$vid][mb_strtolower(trim($t->label()))] = $t;
  }
}

$backup = []; $changed = 0; $touched = [];
foreach ($COPY as $vid => $terms) {
  foreach ($terms as $name => [$body, $cta]) {
    $t = $byName[$vid][mb_strtolower($name)] ?? NULL;
    if (!$t) { print "ABORT — term not found: $vid/$name (reported, not created)\n"; return; }
    if (isset($PROTECTED[(int) $t->id()])) {
      printf("ABORT — refusing to write to protected term %d (%s). That page belongs to the lighting SPLIT.\n",
        $t->id(), $PROTECTED[(int) $t->id()]);
      return;
    }
    $touched[] = (int) $t->id();
    $backup[$t->id()] = ['name' => $name, 'body' => $t->get($BODY[$vid])->value,
      'cta' => $t->hasField($CTA) ? $t->get($CTA)->value : NULL];
    $d = [];
    foreach ([$BODY[$vid] => $body, $CTA => $cta] as $f => $v) {
      if (!$t->hasField($f)) { printf("  %-14s ⚠ no %s on this bundle — skipped\n", $name, $f); continue; }
      $old = (string) ($t->get($f)->value ?? '');
      if (trim($old) === trim($v)) { continue; }
      $t->set($f, ['value' => $v, 'format' => 'full_html']);
      $d[] = ($f === $CTA ? 'cta' : 'body') . ' ' . ($old === '' ? '(was empty)' : sprintf('(%d→%d)', mb_strlen($old), mb_strlen($v)));
    }
    if (!$d) { printf("  %-14s unchanged\n", $name); continue; }
    printf("  %-14s %s\n", $name, implode(', ', $d));
    $changed++;
    if ($apply) { $t->save(); Cache::invalidateTags(['taxonomy_term:' . $t->id()]); }
  }
}

// Link hops.
print "\nLINK HOPS\n";
foreach ($LINKS as [$vid, $name, $fld, $from, $to]) {
  $t = $byName[$vid][mb_strtolower($name)] ?? NULL;
  if (!$t) { printf("  %-14s term not found\n", $name); continue; }
  $v = (string) ($t->get($fld)->value ?? '');
  $n = substr_count($v, 'href=' . $from);
  if (!$n) { printf("  %-14s no %s link found (already fixed, or wording changed)\n", $name, trim($from, '"')); continue; }
  printf("  %-14s %s -> %s (%d)\n", $name, trim($from, '"'), trim($to, '"'), $n);
  $changed++;
  if ($apply) {
    $t->set($fld, ['value' => str_replace('href=' . $from, 'href=' . $to, $v), 'format' => $t->get($fld)->format]);
    $t->save(); Cache::invalidateTags(['taxonomy_term:' . $t->id()]);
  }
}

printf("\nProtected terms untouched: %s\n", implode(', ', array_map(
  fn($id) => $PROTECTED[$id] . " ($id)", array_keys($PROTECTED))));
if (array_intersect($touched, array_keys($PROTECTED))) { print "✗ PROTECTION BREACHED\n"; return; }

if ($apply) {
  $f = '/tmp/remaining_copy_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  print "Previous values backed up to $f\n";
}
printf("\n%d changes%s.\n", $changed, $apply ? '' : ' (dry-run — nothing written)');
