<?php

/**
 * Deciduous / Shade Trees / Ornamental Trees category copy (marketing, 2026-09-30).
 *
 * These three pages were already loaded from an earlier draft, so this DIFFS the
 * stored copy against the document field by field and writes only what actually
 * differs — the same approach the 2026-09-29 fruit-page pass used, where
 * reloading verbatim would have re-broken a cross-link.
 *
 * Terms are resolved by URL ALIAS, never by name. The office has renamed this
 * branch twice (Fruit Trees -> Fruit -> Fruit Trees; Deciduous -> Deciduous
 * Trees), and a script that matches on a name either aborts or creates a
 * duplicate the next time they do. A script may own the copy; the office owns
 * the name.
 *
 * Stored text format is PRESERVED per field (defaulting to full_html only when
 * the field is empty) — the copy carries <h2>, <ul> and <a class="button">,
 * which basic_html would strip.
 *
 * Metatags are MERGED into the stored JSON, so any key not named here survives.
 *
 * Usage:
 *   drush php:script web/scripts/seed_deciduous_tree_category_copy.php          # dry run
 *   BOS_COPY_APPLY=1 drush php:script web/scripts/seed_deciduous_tree_category_copy.php
 */

$apply = getenv('BOS_COPY_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$aliasMgr = \Drupal::service('path_alias.manager');

printf("mode: %s\n\n", $apply ? 'APPLY' : 'DRY RUN');

// ---------------------------------------------------------------------------
// The copy, verbatim from the document.
// ---------------------------------------------------------------------------

$deciduous_public = <<<'HTML'
<p>Almost every tree that does a job here is deciduous. The shade over a patio, the flowering tree by the front door, the apple in the back corner — all of them drop their leaves in October and start again in April.</p>

<p>They are also the easier half of the tree catalog on this ground, for a reason that is not obvious. A deciduous tree is dormant through the hardest four months, losing almost no water while the ground is frozen. An evergreen beside it is still transpiring into a dry January wind and cannot replace what it loses. That is why winter burn is an evergreen problem, and why the deciduous list is longer, cheaper and more forgiving.</p>

<p>The trade is five months of bare branches, and the first real decision is not which tree — it is which of three jobs you are buying.</p>
HTML;

$deciduous_cta = <<<'HTML'
<h2>Three jobs, three different trees</h2>

<p><strong>A shade tree is infrastructure.</strong> Forty to seventy feet at maturity, planted for cooling, structure and the value it puts on a property. It needs real room, it takes twenty years to do its job properly, and where it goes is a decision somebody lives with for decades.</p>

<p><strong>An ornamental is scale.</strong> Under about twenty-five feet, and therefore the right answer in all the places a shade tree is wrong — close to the house, under a power line, in a courtyard, beside an entry. Bought for flowers, bark, form or fall colour rather than for canopy.</p>

<p><strong>A fruit tree is a commitment.</strong> It produces something, and it asks for annual pruning, thinning and a spray calendar in return. Entirely doable, and not a plant to buy without knowing that.</p>

<h2>One thing all three have in common</h2>

<p>They are bare from roughly November to April. A planting built only from deciduous trees is a planting with nothing in it for five months — which is fine if that was the plan, and a surprise if it was not. Some evergreen mass, or shrubs with winter bark and fruit, is what keeps the yard from reading as empty in January.</p>

<p>If you are not sure which of the three a spot wants, that is a short conversation and usually the site answers it.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$deciduous_crew = <<<'HTML'
<p><strong>Establish the job before the species, every time.</strong> Shade, ornamental or fruit is the first question on any tree conversation and it eliminates most of the catalog in one answer.</p>

<ul>
<li><strong>Shade:</strong> is there room for forty to seventy feet, in every direction, forever? If not, it is an ornamental conversation.</li>
<li><strong>Ornamental:</strong> near the house, under wires, tight space, or wanted for flowers rather than canopy.</li>
<li><strong>Fruit:</strong> do they actually want to pick something, and did anybody explain the pruning and spray calendar?</li>
<li><strong>Lead with deciduous on exposed sites.</strong> Dormant trees do not winter-burn. It is the honest recommendation on a windy southwest corner.</li>
<li><strong>Raise the winter question on any all-deciduous plan.</strong> "What does this look like in January?" Most people have not thought about it, and it usually adds an evergreen to the job.</li>
</ul>

<p><strong>What to tell a customer:</strong> deciduous trees are the easier and wider half of what we carry, and they give you flowers, shade and fall colour that evergreens cannot. What they do not give you is anything to look at between November and April, so a yard needs a little of both.</p>
HTML;

$shade_public = <<<'HTML'
<p>A shade tree is the highest-value plant decision anybody makes on a property, and it is the one with the longest tail. Everything else in a yard can be changed in a weekend or a season. A tree planted this spring is making its case in 2060, and where it goes is a choice somebody lives with for decades — usually somebody who was not there when it was planted.</p>

<p>It is also the only thing in this catalog that measurably changes how a house works. A mature canopy on the west and southwest side takes the afternoon sun off the wall and the windows in July, and that shows up on a power bill rather than only in a photograph. East shade is pleasant. <strong>West shade is functional</strong>, and it is worth deciding where a tree goes on that basis before deciding which tree it is.</p>

<p>Here is what we plant.</p>
HTML;

$shade_cta = <<<'HTML'
<h2>Mature size is the entire game</h2>

<p>Nearly every serious problem with a shade tree traces back to the same thing: it was planted where it fit at the time. Fifteen or twenty years later the consequences arrive all at once, and they are expensive.</p>

<p>Before a shade tree goes in the ground, four things get measured:</p>

<ul>
<li><strong>Overhead.</strong> A power line means a shade tree is the wrong plant. The utility will eventually top it, and a topped shade tree is permanently ruined — structurally weakened and ugly for the rest of its life. Under wires, plant an ornamental.</li>
<li><strong>Underground.</strong> Sewer laterals, septic tanks and leach fields. Cottonwood, willow and silver maple are the classic offenders here — their roots find a joint in a line and grow into it, and the repair bill dwarfs what the tree cost.</li>
<li><strong>Distance from the house.</strong> Roughly half the mature canopy width from the foundation, minimum. Closer than that and you are pruning it off the roof every few years.</li>
<li><strong>Drives and walks.</strong> Surface roots lift concrete. It is slow and it is not repairable without taking the tree or the slab.</li>
</ul>

<h2>Fast growth is a trade, and here so is the species list</h2>

<p>Cottonwood, willow, boxelder and silver maple grow quickly, which is exactly why people plant them. They also make weak, low-density wood, they tend to be shorter-lived, and they are the trees we get called about after a wind event with a limb through something. That does not make them the wrong choice — a windbreak on acreage, a riparian corner, a fast screen while something slower fills in — but they get chosen knowingly, and with a bit of room around them.</p>

<p>And the soil narrows the list further. Alkaline ground here locks up iron, and several of the most-requested shade trees show it badly — silver and red maple, birch, pin oak. Those go yellow between green veins, decline over years, and no amount of feeding fixes it. There are better choices that want to be here, and they are on the list above.</p>

<h2>The detail that kills more young trees than anything else</h2>

<p>Planting depth. A tree set too deep, with the root flare buried under soil or mulch, declines slowly for five or ten years and nobody connects the two. <strong>The flare — where the trunk widens into the roots — belongs at or slightly above finished grade, visible.</strong> If you cannot see where the trunk widens, it is too deep. It is the most common installation error in the trade and it is entirely avoidable.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$shade_crew = <<<'HTML'
<p><strong>Walk the site and look up, look down, and measure before you quote a shade tree.</strong> Everything expensive about shade trees is decided on install day.</p>

<ul>
<li><strong>Look up first. Power lines mean no shade tree.</strong> Offer an ornamental and explain what topping does. Do not let a customer talk us into it.</li>
<li><strong>Ask where the sewer lateral and the leach field run.</strong> No cottonwood, willow or silver maple anywhere near either. If they do not know, that is a call to make before the hole is dug.</li>
<li><strong>Half the mature canopy width off the foundation, minimum.</strong> Measure it, do not eyeball it.</li>
<li><strong>Root flare at or slightly above grade, visible, every tree.</strong> If the flare is not showing when we leave, we planted it wrong. Check the nursery ball too — they are often already too deep in the pot and have to be lifted.</li>
<li><strong>Do not volcano-mulch.</strong> Mulch pulled back off the trunk, a flat ring, not a cone. Mulch against the bark rots it.</li>
<li><strong>Chlorosis-prone species need the soil conversation before the sale</strong> — silver and red maple, birch, pin oak. If a customer wants one anyway, the iron treatment goes in the estimate as a recurring line item, not as a rescue in year three.</li>
<li><strong>Selling a fast grower: say what the trade is</strong> and put mature size in writing if it is near a structure, a drive or a line.</li>
</ul>

<p><strong>What to tell a customer:</strong> a shade tree is the best money in a landscape and the most expensive thing to get wrong. Twenty minutes deciding where it goes is worth more than the difference between any two species on the list.</p>
HTML;

$orn_public = <<<'HTML'
<p>An ornamental tree is defined by scale before anything else. Under about twenty-five feet at maturity, which sounds like a limitation and is the entire point — it is what makes them the right tree for all the places a shade tree cannot go.</p>

<p><strong>The clearest case is a power line.</strong> A large tree under wires will eventually be topped by the utility, and a topped tree is finished: the structure is gone, the regrowth is weak and badly attached, and it never looks right again. Anything under the lines should be a tree that never reaches them. That single constraint decides more front-yard plantings in this valley than any aesthetic preference does.</p>

<p>The same logic applies near a foundation, in a courtyard, beside an entry, between a walk and a drive, or anywhere the canopy has somewhere it is not allowed to go. And within that constraint the list is the most interesting one we carry — this is where the spring flowering, the fall colour, the berries and the winter bark live.</p>
HTML;

$orn_cta = <<<'HTML'
<h2>Most of this list is one plant family, and that matters</h2>

<p>Crabapple, hawthorn, mountain ash, serviceberry and ornamental pear are all in the rose family, along with every apple, pear, plum and cherry. Family is usually an academic detail. Here it is practical, because <strong>disease runs along family lines.</strong></p>

<p>Two things move through that group in this county. <strong>Fire blight</strong> is a bacterial infection that moves fast in a warm wet spring — shoot tips blacken and curl over like a shepherd's crook, and it can take large limbs in a season. <strong>Cedar-apple rust</strong> needs a juniper and a rose-family host within about a mile of each other and shuttles between them year after year, spotting leaves and weakening the tree.</p>

<p>Neither is a reason to avoid these trees — they are among the best small trees available here. It is a reason to look at what is already on the property before planting a row of them, to choose resistant varieties where the pressure is real, and to know that a crabapple by the drive and an apple orchard down the road are part of the same conversation. <a href="/material/plants/trees/deciduous/fruit">Fruit trees</a> covers the other end of it.</p>

<h2>Check what is underneath before you check the flowers</h2>

<p>Several of the best ornamentals fruit, and fruit falls. A crabapple or a mulberry in a border is a good decision; the same tree over a patio, a walk, a drive or a parking space means a few weeks of stain, wasps and a slick surface every autumn for the life of the tree.</p>

<p>Look at what is under the canopy before choosing the tree. It is the easiest mistake to avoid and one of the most annoying to live with.</p>

<h2>One form question worth asking early</h2>

<p>Many of these are available as either a single-trunk tree or a multi-stem clump, and they are different plants in a design. Single-stem reads formal and lifts the canopy for walking under. Multi-stem is wider, lower and more naturalistic, and it usually reads better against a house or in a bed. Decide which before ordering — it is not something that can be changed later.</p>

<h2>And one tree we will sell you with a caveat</h2>

<p>Ornamental pear — usually sold as Bradford or under one of the other Callery cultivars — is on this list, and it got planted across the country by the million for good reasons. It grows fast, holds a neat symmetrical shape without being pruned into one, flowers heavily and early, and tolerates poor soil and neglect.</p>

<p><strong>It also has a structural problem that shows up around year fifteen or twenty.</strong> The branches leave the trunk at very tight angles, often several of them crowding out of nearly the same point, and bark gets trapped in those tight unions instead of wood knitting together. Those joints are weak. A mature Callery pear caught by a wind event or a heavy wet snow frequently splits — sometimes losing a third of the canopy, sometimes straight down the middle, and usually beyond saving.</p>

<p>The good news is that this is largely preventable, and preventable early. <strong>Structural pruning in the first five to eight years</strong> — taking out competing leaders while they are finger-thick and thinning the tightest unions before they get big — produces a very different tree at twenty. It costs little and it has to happen before the problem is visible, which is why it almost never happens.</p>

<p>So we will plant one, and we will tell you it needs that pruning and put it on the schedule. If you would rather not take that on, <strong>hawthorn and serviceberry</strong> give you spring flower and four-season interest on this ground with better structure and no such conversation. Both are on the list above.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML;

$orn_crew = <<<'HTML'
<p><strong>Ornamental is the answer whenever a shade tree will not fit.</strong> Lead with the constraint, not with the flowers — the constraint is what makes the recommendation right.</p>

<ul>
<li><strong>Under power lines, always ornamental.</strong> Never let a customer put a shade tree under wires, however much they want it. Explain topping once and most people understand immediately.</li>
<li><strong>Check what is underneath before you check the bloom.</strong> Nothing that drops fruit over a patio, walk, drive or parking. Crabapple and mulberry are the usual culprits.</li>
<li><strong>⚠ Rose family: crabapple, hawthorn, mountain ash, serviceberry, ornamental pear.</strong> Fire blight and cedar-apple rust both run through this group. Look at the property and the neighbours for junipers and apples before planting several.</li>
<li><strong>Fire blight is blackened shoot tips curled like a shepherd's crook.</strong> Prune well below the damage in dry weather, sanitise between every cut, and get the prunings off site. Do not prune it in wet weather — that spreads it.</li>
<li><strong>Ask single-stem or multi-stem at the design stage.</strong> It changes the look substantially and it cannot be changed after.</li>
<li><strong>Do not oversell redbud.</strong> It is marginal at elevation and in exposed sites. Sheltered, east-facing, it is lovely; on an open west corner it struggles.</li>
<li><strong>⚠ Ornamental pear sells with structural pruning attached, or it does not sell.</strong> Tight branch angles with included bark; mature trees split in wind and wet snow. Put the first five to eight years of structural pruning on the maintenance schedule at install, priced — not as advice. If the customer declines the pruning, offer hawthorn or serviceberry instead and say why.</li>
<li><strong>Structural pruning means taking competing leaders out while they are finger-thick.</strong> Once a tight union is four inches across the decision has been made for us.</li>
</ul>

<p><strong>What to tell a customer:</strong> an ornamental is not a compromise on a shade tree, it is a different tool. It goes where a big tree would eventually become a problem, and it gives you flowers and interest at eye level where you actually see them.</p>
HTML;

$pear_public = <<<'HTML'
<p>Callery pear, sold as Bradford and under a handful of other cultivar names, is one of the most widely planted ornamental trees in the country and it earned that the honest way. It grows quickly, holds a tidy symmetrical form with no pruning at all, covers itself in white flower before almost anything else opens, colours well in fall, and tolerates poor soil, compaction and neglect.</p>

<p><strong>What it does not do is hold itself together at maturity.</strong> The branches come off the trunk at very narrow angles, and several of them often crowd out of nearly the same point. In a union that tight, bark gets pinched between the branch and the trunk instead of wood growing together — so the joint looks solid and is not. Somewhere around fifteen to twenty years, a wind event or a heavy wet snow finds that weakness, and the tree splits. Often it takes a third of the canopy. Sometimes it goes down the middle and the tree is finished.</p>

<p>This is not a defect you discover late and then manage. It is built into how the tree grows, and by the time you can see it the branches are too large to correct.</p>

<p><strong>It is, however, preventable — early.</strong> Structural pruning in the first five to eight years, while the competing leaders are still finger-thick, produces a fundamentally different tree at twenty. Take out the rival leaders, open the tightest unions, and establish one dominant trunk before the tree decides for itself. It is a small job done on a schedule and it is the whole difference.</p>

<p>We will plant one and we will put that pruning on the schedule with it. If you would rather not carry a maintenance commitment on an ornamental tree, <a href="/material/plants/trees/deciduous/ornamental">hawthorn and serviceberry</a> give you spring flower, fall colour and fruit for the birds on this ground, with structure that looks after itself.</p>
HTML;

// ---------------------------------------------------------------------------
// What goes where. Terms keyed by URL alias.
// ---------------------------------------------------------------------------

$terms = [
  '/material/plants/trees/deciduous' => [
    'label' => 'Deciduous Trees (parent)',
    'field_short_description' => 'Trees that drop their leaves and grow them back. Nearly all the shade, nearly all the flowering, and all of the fruit — split three ways by what you want the tree to do.',
    'field_public_description' => $deciduous_public,
    'field_call_to_action' => $deciduous_cta,
    'field_teammate_description' => $deciduous_crew,
    'meta' => [
      'title' => 'Deciduous Trees for Colorado | Brookstone Outdoors',
      'description' => 'Shade, ornamental or fruit — three different trees for three different jobs. Why deciduous is the easier half of the tree catalog on the Western Slope.',
    ],
  ],
  '/material/plants/trees/deciduous/shade' => [
    'label' => 'Shade Trees',
    'field_list_order' => 10,
    'field_short_description' => 'The big ones — forty to seventy feet at maturity. The only thing you can plant that measurably cools a house, and the longest-lived decision on a property.',
    'field_public_description' => $shade_public,
    'field_call_to_action' => $shade_cta,
    'field_teammate_description' => $shade_crew,
    'meta' => [
      'title' => 'Shade Trees for Western Colorado | Brookstone',
      'description' => 'West-side shade is what cools a house. Placing a shade tree around power lines, sewer laterals and foundations, and the planting depth that kills young trees.',
    ],
  ],
  '/material/plants/trees/deciduous/ornamental' => [
    'label' => 'Ornamental Trees',
    'field_list_order' => 20,
    'field_short_description' => 'Small trees, generally under twenty-five feet. The right answer everywhere a shade tree is wrong — near the house, under a power line, in a courtyard, beside a door.',
    'field_public_description' => $orn_public,
    'field_call_to_action' => $orn_cta,
    'field_teammate_description' => $orn_crew,
    'meta' => [
      'title' => 'Ornamental Trees for Colorado | Brookstone Outdoors',
      'description' => 'Small trees for under power lines, near the house and in tight spaces. Plus the fire blight and cedar-apple rust running through the rose-family list.',
    ],
  ],
];

// The document also sets Fruit Trees to list order 30 so the three siblings sort
// shade / ornamental / fruit.
$fruitOrder = ['/material/plants/trees/deciduous/fruit' => 30];

$changes = 0;
$same = 0;
$problems = 0;
// Every value this script would overwrite, written before anything changes.
$backup = [];
$backupFile = sys_get_temp_dir() . '/deciduous_copy_backup_' . date('Ymd_His') . '.json';

$resolveTerm = function (string $alias) use ($aliasMgr, $etm) {
  $internal = $aliasMgr->getPathByAlias($alias);
  if ($internal === $alias || !preg_match('#^/taxonomy/term/(\d+)$#', $internal, $m)) {
    return NULL;
  }
  return $etm->getStorage('taxonomy_term')->load($m[1]);
};

/**
 * Normalise away differences that do not change the rendered page, so a re-run
 * is a no-op and the office's own editor saves are not churned.
 *
 * Two kinds of noise: CKEditor 5 stamps a data-list-item-id on every <li> when
 * somebody edits the field in the admin UI (~38 chars each — on this branch
 * that alone accounted for a 366-char "difference" that was nothing), and the
 * stored copy is minified between block tags while the document has newlines.
 */
$normalise = function (string $s): string {
  $s = preg_replace('/\s+data-list-item-id="[^"]*"/', '', $s);
  $s = preg_replace('/>\s+</', '><', $s);
  return trim(preg_replace('/\s+/u', ' ', $s));
};

$setText = function ($entity, string $field, string $value, string $label) use (&$changes, &$same, &$backup, $apply, $normalise) {
  if (!$entity->hasField($field)) {
    printf("    %-30s ** NO SUCH FIELD **\n", $field);
    return;
  }
  $item = $entity->get($field);
  $current = $item->isEmpty() ? '' : (string) $item->first()->getValue()['value'];
  // Preserve whatever format the office/earlier load used; full_html only when new.
  $format = $item->isEmpty() ? 'full_html' : ($item->first()->getValue()['format'] ?? 'full_html');
  if ($normalise($current) === $normalise($value)) {
    printf("    %-30s identical%s\n", $field,
      trim($current) === trim($value) ? sprintf(' (%d chars)', strlen($current))
        : ' (differs only by editor artifacts / whitespace — left alone)');
    $same++;
    return;
  }
  printf("    %-30s DIFFERS  stored %d -> new %d chars  [format %s]\n", $field, strlen($current), strlen($value), $format);
  $changes++;
  $backup[] = ['entity_type' => $entity->getEntityTypeId(), 'id' => $entity->id(), 'field' => $field, 'format' => $format, 'value' => $current];
  if ($apply) {
    $entity->set($field, ['value' => $value, 'format' => $format]);
  }
};

foreach ($terms as $alias => $spec) {
  printf("=== %s  (%s) ===\n", $spec['label'], $alias);
  $term = $resolveTerm($alias);
  if (!$term) {
    printf("    ** could not resolve this alias — skipped, nothing guessed **\n\n");
    $problems++;
    continue;
  }
  printf("    tid %s, name %s\n", $term->id(), var_export((string) $term->label(), TRUE));

  foreach (['field_short_description', 'field_public_description', 'field_call_to_action', 'field_teammate_description'] as $f) {
    if (isset($spec[$f])) {
      $setText($term, $f, $spec[$f], $spec['label']);
    }
  }

  if (isset($spec['field_list_order'])) {
    $cur = $term->get('field_list_order')->isEmpty() ? NULL : (int) $term->get('field_list_order')->value;
    if ($cur === $spec['field_list_order']) {
      printf("    %-30s identical (%d)\n", 'field_list_order', $cur);
      $same++;
    }
    else {
      printf("    %-30s DIFFERS  stored %s -> new %d\n", 'field_list_order', var_export($cur, TRUE), $spec['field_list_order']);
      $changes++;
      if ($apply) { $term->set('field_list_order', $spec['field_list_order']); }
    }
  }

  // Merge metatags so any other stored key survives.
  if (!empty($spec['meta']) && $term->hasField('field_meta_tags')) {
    $raw = $term->get('field_meta_tags')->isEmpty() ? '' : (string) $term->get('field_meta_tags')->value;
    $stored = $raw ? (json_decode($raw, TRUE) ?: []) : [];
    $merged = $stored;
    foreach ($spec['meta'] as $k => $v) {
      $merged[$k] = $v;
    }
    // The document gives no og_description; keep it in step with description,
    // which is what the earlier load did.
    $merged['og_description'] = $spec['meta']['description'];
    ksort($merged);
    $before = $stored; ksort($before);
    if (json_encode($before) === json_encode($merged)) {
      printf("    %-30s identical\n", 'field_meta_tags');
      $same++;
    }
    else {
      printf("    %-30s DIFFERS\n", 'field_meta_tags');
      foreach ($merged as $k => $v) {
        $old = $before[$k] ?? NULL;
        if ($old !== $v) {
          printf("        %-16s %s\n                      -> %s\n", $k, var_export(substr((string) $old, 0, 58), TRUE), var_export(substr((string) $v, 0, 58), TRUE));
        }
      }
      $changes++;
      if ($apply) { $term->set('field_meta_tags', json_encode($merged)); }
    }
  }

  if ($apply) {
    $term->save();
    print "    saved\n";
  }
  print "\n";
}

// Fruit Trees: list order only, so the three siblings sort correctly.
foreach ($fruitOrder as $alias => $order) {
  $term = $resolveTerm($alias);
  printf("=== Fruit Trees sort order (%s) ===\n", $alias);
  if (!$term) { print "    ** could not resolve — skipped **\n\n"; $problems++; continue; }
  $cur = $term->get('field_list_order')->isEmpty() ? NULL : (int) $term->get('field_list_order')->value;
  if ($cur === $order) { printf("    field_list_order identical (%d)\n\n", $cur); $same++; continue; }
  printf("    field_list_order DIFFERS  stored %s -> new %d\n", var_export($cur, TRUE), $order);
  $changes++;
  if ($apply) { $term->set('field_list_order', $order)->save(); print "    saved\n"; }
  print "\n";
}

// The Ornamental Pear item's public copy. Its crew copy has no home on a
// material (field_care_instructions is customer-facing and anon-readable), so
// it is deliberately NOT written anywhere here.
print "=== Ornamental Pear item ===\n";
$pearAlias = '/material/plants/trees/deciduous/ornamental/ornamental-pear';
$internal = $aliasMgr->getPathByAlias($pearAlias);
if (!preg_match('#^/material/(\d+)$#', $internal, $pm)) {
  print "    ** could not resolve the pear alias — skipped **\n";
  $problems++;
}
else {
  $pear = $etm->getStorage('material')->load($pm[1]);
  printf("    id %s, %s\n", $pear->id(), var_export((string) $pear->label(), TRUE));
  $item = $pear->get('field_description');
  $cur = $item->isEmpty() ? '' : (string) $item->first()->getValue()['value'];
  $fmt = $item->isEmpty() ? 'full_html' : ($item->first()->getValue()['format'] ?? 'full_html');
  $summary = $item->isEmpty() ? '' : (string) ($item->first()->getValue()['summary'] ?? '');
  if ($normalise($cur) === $normalise($pear_public)) {
    printf("    %-30s identical%s\n", 'field_description',
      trim($cur) === trim($pear_public) ? sprintf(' (%d chars)', strlen($cur)) : ' (editor artifacts only)');
    $same++;
  }
  else {
    printf("    %-30s DIFFERS  stored %d -> new %d chars  [format %s, summary %s]\n",
      'field_description', strlen($cur), strlen($pear_public), $fmt, $summary === '' ? 'empty' : strlen($summary) . ' chars');
    $changes++;
    $backup[] = ['entity_type' => 'material', 'id' => $pear->id(), 'field' => 'field_description', 'format' => $fmt, 'summary' => $summary, 'value' => $cur];
    if ($apply) {
      $pear->set('field_description', ['value' => $pear_public, 'format' => $fmt, 'summary' => $summary]);
      $pear->save();
      print "    saved\n";
    }
  }
}

if ($backup) {
  file_put_contents($backupFile, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  printf("\nprevious values of every changed field saved to: %s\n", $backupFile);
}
printf("\n%d field(s) would change, %d already identical, %d problem(s)\n", $changes, $same, $problems);
if (!$apply) {
  print "\nNothing written. Re-run with BOS_COPY_APPLY=1 to apply.\n";
}
