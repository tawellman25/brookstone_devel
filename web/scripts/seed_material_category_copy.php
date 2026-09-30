<?php

/**
 * @file
 * Load authored copy into material category terms, one batch at a time.
 *
 * Copy is pasted verbatim — not rewritten, not trimmed. Each batch names the
 * parent category its terms sit under, so terms are resolved unambiguously even
 * where a name repeats elsewhere in the vocabulary.
 *
 * Batches:
 *   shrubs          Evergreen Shrubs, Deciduous Shrubs   (under Shrubs)
 *   evergreen_genus Pine, Spruce, Fir, Juniper, Arborvitae (under Evergreens)
 *
 * Usage:
 *   drush php:script web/scripts/seed_material_category_copy.php        # dry run, all batches
 *   BOS_COPY_APPLY=1 drush php:script …                                # apply
 *   BOS_COPY_BATCH=evergreen_genus BOS_COPY_APPLY=1 drush php:script …  # one batch
 *
 * Page order is already correct on the public displays (full, client_view):
 * field_public_description (0) -> the item lists (10) -> field_call_to_action (20).
 *
 * Idempotent. Per environment: taxonomy terms are content and field instances
 * silently skip cim, so this script is the deploy path. Terms are matched by name
 * UNDER their parent and never created — if a name has changed it reports and
 * skips rather than making a duplicate.
 */

use Drupal\field\Entity\FieldConfig;

const VID = 'material_types';

$etm = \Drupal::entityTypeManager();
$ts = $etm->getStorage('taxonomy_term');
$apply = (bool) getenv('BOS_COPY_APPLY');
$only = trim((string) getenv('BOS_COPY_BATCH'));

// ---------------------------------------------------------------------------
// 1. Field instances the copy needs. Both storages already exist on
//    taxonomy_term (used by spraying_locations, backflow_uses and others), so
//    this adds instances only — no new storage.
// ---------------------------------------------------------------------------
$instances = [
  'field_short_description' => ['label' => 'Short Description', 'desc' => 'One-line teaser used on the parent category listing.'],
  'field_list_order'        => ['label' => 'List Order', 'desc' => 'Lower sorts first. Leave empty to sort by name.'],
];
foreach ($instances as $name => $info) {
  if (FieldConfig::loadByName('taxonomy_term', VID, $name)) {
    print "field ok: $name\n";
    continue;
  }
  if (!$etm->getStorage('field_storage_config')->load('taxonomy_term.' . $name)) {
    print "ABORT: no storage for taxonomy_term.$name — not creating one here.\n";
    return;
  }
  if (!$apply) { print "would add field instance: $name\n"; continue; }
  FieldConfig::create([
    'field_name' => $name,
    'entity_type' => 'taxonomy_term',
    'bundle' => VID,
    'label' => $info['label'],
    'description' => $info['desc'],
    'required' => FALSE,
  ])->save();
  print "added field instance: $name\n";
}

// Put them on the term form, and on admin_view so office can see/review the copy.
if ($apply) {
  $form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . VID . '.default');
  if ($form) {
    $changed = FALSE;
    foreach (['field_short_description' => 6, 'field_list_order' => 7] as $f => $w) {
      if (!$form->getComponent($f)) {
        $form->setComponent($f, [
          'type' => $f === 'field_list_order' ? 'number' : 'text_textarea',
          'weight' => $w,
          'region' => 'content',
          'settings' => $f === 'field_list_order' ? [] : ['rows' => 3, 'placeholder' => ''],
        ]);
        $changed = TRUE;
      }
    }
    if ($changed) { $form->save(); print "term form: added the new fields\n"; }
  }
  // admin_view gets the short description + the CTA copy, which it was missing,
  // so office reviews the whole page in one place.
  $av = $etm->getStorage('entity_view_display')->load('taxonomy_term.' . VID . '.admin_view');
  if ($av) {
    $changed = FALSE;
    if (!$av->getComponent('field_short_description')) {
      $av->setComponent('field_short_description', ['type' => 'basic_string', 'weight' => -1, 'label' => 'above', 'region' => 'content']);
      $changed = TRUE;
    }
    if (!$av->getComponent('field_call_to_action')) {
      $av->setComponent('field_call_to_action', ['type' => 'text_default', 'weight' => 20, 'label' => 'above', 'region' => 'content']);
      $changed = TRUE;
    }
    if ($changed) { $av->save(); print "admin_view: added short description + call to action\n"; }
  }
}

// ---------------------------------------------------------------------------
// 2. The copy.
// ---------------------------------------------------------------------------
$shrubCopy = [];

$shrubCopy['Evergreen Shrubs'] = [
  'order' => 10,
  'short' => 'Holds its foliage year-round, which carries a planting through the months when nothing else does. Also the harder half of the shrub palette here, for reasons worth knowing before you buy one.',
  'title' => 'Evergreen Shrubs for Colorado | Brookstone Outdoors',
  'meta' => 'Winter burn, not cold, is what kills evergreen shrubs here. Why needled beats broadleaf on the Western Slope, and the late-fall soak almost nobody does.',
  'public' => <<<'HTML'
<p>An evergreen shrub is doing its job in February. That is the whole case for it. Five months of the year a deciduous planting is bare sticks, and whatever holds its foliage is carrying the structure of the yard — the mass at the corner of the house, the line along the walk, the thing that keeps a bed from reading as an empty rectangle under snow.</p>

<p>What gets left out of that pitch is that February is also when an evergreen is under the most stress it will face all year, and the reason is not the cold.</p>

<p>An evergreen keeps its leaves, so it keeps losing water through them all winter. When the ground is frozen the roots cannot replace it. Add a dry west wind and the kind of intense high-altitude winter sun we get here, and the plant dries out from the top down — foliage goes brown and crispy, worst on the side facing the weather. That is winter burn, and it is the single most common way an evergreen shrub fails in this valley. It has nothing to do with hardiness ratings.</p>

<p>Which shapes the whole list below.</p>
HTML,
  'cta' => <<<'HTML'
<h2>Needled beats broadleaf here, and it is not close</h2>

<p>Junipers, mugo pine, dwarf spruce and the other needled and scaled evergreens hold up in this climate because they are built for it — small surface area, waxy coating, and in most cases an origin somewhere just as dry and windy.</p>

<p>Broadleaf evergreens have more leaf surface losing more water, and they show it. Boxwood is the one people ask for most and it is genuinely marginal here: it will burn on the south and west sides and look bronzed and rough by March. That does not mean do not plant it. It means plant it on an east exposure, out of the wind, where it has a chance — and buy a hardy cultivar rather than whatever is cheapest.</p>

<h2>Two things that decide whether an evergreen shrub makes it</h2>

<p><strong>Where it goes.</strong> North and east exposures, sheltered from the prevailing west wind, away from reflected heat off a light wall or rock mulch. A broadleaf evergreen on an exposed southwest corner is a plant we will be replacing.</p>

<p><strong>A deep soak in late fall, before the ground freezes.</strong> This is the most useful thing anybody can do for an evergreen here and almost nobody does it. The plant is going to spend four months losing water it cannot replace — sending it into winter fully watered is the difference between green in March and brown in March. If the winter runs dry with no snow cover, water again on a warm day when the ground has thawed.</p>

<h2>One more thing, and it is about deer</h2>

<p>In February, an evergreen is one of very few green things available. Browse pressure that a planting shrugs off in July is a different matter when nothing else is growing, and arborvitae and yew in particular are near the top of what deer will look for. If you have deer, protect evergreen shrubs through their first winters at minimum, and expect to keep protecting the palatable ones.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Siting is most of the job on an evergreen shrub.</strong> The right plant in the wrong exposure fails here and it fails visibly, on the side facing the street.</p>

<ul>
<li><strong>Broadleaf evergreens go north or east, sheltered.</strong> Boxwood, holly, Oregon grape. Never an exposed southwest corner, never over light rock mulch.</li>
<li><strong>Needled and scaled evergreens are the safe recommendation</strong> — juniper, mugo, dwarf spruce. When a customer wants evergreen mass and the site is exposed, steer here.</li>
<li><strong>The late-fall soak goes on the care sheet and on the maintenance schedule.</strong> Deep watering before ground freeze, every evergreen, every year. It prevents the failure we get called about in March.</li>
<li><strong>Winter burn is not dead.</strong> Do not quote a replacement in March. Browned foliage usually pushes new growth in spring; wait until June before calling it. Cutting a burned evergreen back hard in March is how a recoverable plant becomes a replacement.</li>
<li><strong>Protect evergreens from browse the first two winters minimum.</strong> Arborvitae and yew, longer. They are what deer eat when nothing else is green.</li>
</ul>

<p><strong>What to tell a customer:</strong> evergreens hold the yard together in winter and they work harder for it than anything else out there. Watered going into fall and put in the right spot, they are reliable. On an exposed corner with no fall water, they brown out and it looks like our plant selection.</p>

<p><strong>If a customer asks for boxwood:</strong> sell it, and tell them the truth about exposure in the same breath. Somebody who hears it beforehand treats March bronzing as weather. Somebody who does not treats it as a defect.</p>
HTML,
];

$shrubCopy['Deciduous Shrubs'] = [
  'order' => 20,
  'short' => 'Drops its leaves in fall and comes back in spring. The larger, easier and more forgiving half of the shrub palette here, and where nearly all of the flowering lives.',
  'title' => 'Deciduous Shrubs for Colorado | Brookstone Outdoors',
  'meta' => 'The easier half of the shrub palette here: dormant plants do not winter-burn. Which shrubs to prune after flowering, which in late winter, and why it matters.',
  'public' => <<<'HTML'
<p>These are the workhorses. Nearly every flowering shrub is here, nearly all the fall color, most of the native palette, and most of the plants that establish quickly and forgive a mistake.</p>

<p>They are also, counterintuitively, the easier half to grow on the Western Slope — and for a reason worth understanding. A deciduous shrub drops its leaves and goes dormant, so through the four hardest months it is losing almost no water. An evergreen beside it is still transpiring through frozen ground into a dry wind. That is why winter burn is an evergreen problem and not a deciduous one, and it is why the toughest, lowest-water plants on this list are deciduous.</p>

<p>What you trade for it is five months of bare branches. Whether that is a cost depends entirely on whether the planting was designed for it.</p>
HTML,
  'cta' => <<<'HTML'
<h2>Bare is a look, if somebody planned for it</h2>

<p>A deciduous shrub in January is not nothing. Red-twig dogwood against snow is one of the better things in a winter yard. Coralberry and chokeberry hold fruit. Ninebark peels. A well-shaped lilac or viburnum has a silhouette worth looking at, and the whole bed reads as structure rather than absence.</p>

<p>What does not work is a planting that was chosen entirely in May, from whatever was in flower in May, with no thought given to the other eleven months. That is the yard that looks wonderful for three weeks and forgettable after.</p>

<h2>The one thing people get wrong: when to prune</h2>

<p>This costs more flowers in this valley than frost does, and it is entirely avoidable.</p>

<p><strong>Spring bloomers set their flower buds the previous summer.</strong> Lilac, forsythia, mockorange, quince, most viburnum. Prune those in late winter or early spring and you are cutting off this year's flowers before they open. They get pruned right after they finish blooming, which gives them the rest of the season to set next year's buds.</p>

<p><strong>Summer bloomers flower on new growth.</strong> Potentilla, butterfly bush, blue mist spirea, most hydrangea. Those get pruned in late winter while dormant, and the harder you cut them the better they come back.</p>

<p>Get it backwards and the plant is perfectly healthy and simply never flowers — which is exactly how it gets diagnosed as a bad plant.</p>

<h2>The native palette lives here</h2>

<p>Apache plume, fernbush, rabbitbrush, mountain mahogany, boulder raspberry, leadplant. These are shrubs that were growing on this ground before anybody irrigated it, and once established they ask for very little — no soil amendment, no fighting the pH, and water at a level the site can actually supply. If low-water is the goal, this is where the good answers are, and they are nearly all deciduous.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Know which way a shrub sets buds before you put a saw on it.</strong> Pruning a spring bloomer at the wrong time is the most common way we cost a customer a season of flowers, and it is entirely preventable.</p>

<ul>
<li><strong>Spring bloomers — prune right after flowering.</strong> Lilac, forsythia, mockorange, quince, most viburnum. Buds were set last summer on last year's wood.</li>
<li><strong>Summer bloomers — prune in late winter, dormant, and be decisive.</strong> Potentilla, butterfly bush, blue mist spirea. They flower on new growth and respond to a hard cut.</li>
<li><strong>If you do not know which it is, do not cut it.</strong> Ask. A season of missed bloom is a phone call; a wrong guess on a lilac somebody's grandmother planted is a worse one.</li>
<li><strong>Lead with deciduous on exposed sites.</strong> Dormant plants do not winter-burn. When a customer wants mass on a windy southwest corner, this is the honest answer.</li>
<li><strong>Know the natives on the list and sell them on merit.</strong> Apache plume, fernbush, rabbitbrush, mountain mahogany. Real low-water performers that look intentional when placed well, and most competitors do not carry them.</li>
<li><strong>Do not let a fall cleanup strip the winter interest.</strong> Red twigs, held fruit, seed heads and good structure are the January view. Cut what needs cutting and leave the rest.</li>
</ul>

<p><strong>What to tell a customer:</strong> deciduous shrubs are the easier plant here, not the lesser one. Wider palette, more flowering, better fall color, and they do not burn out over winter. The trade is five months of bare branches, and a planting built with that in mind still looks like something in January.</p>

<p><strong>If a customer says a shrub "never blooms":</strong> ask when it was last pruned before you look at anything else. Nine times in ten it is timing, not the plant, and it is a free fix.</p>
HTML,
];

// Roses keeps its place in the order even though its copy is not in this batch.
$shrubOrderOnly = ['Roses' => 30];

// --- Batch: evergreen genus categories, under Trees > Evergreens -------------
$evergreenCopy = [];

$evergreenCopy['Pine'] = [
  'order' => 10,
  'short' => 'The most adaptable conifers on this ground — deep-rooted, alkaline-tolerant and genuinely drought-hardy once established. Also where most of our best windbreak and screening trees are.',
  'title' => 'Pine Trees for Western Colorado | Brookstone',
  'meta' => 'Deep-rooted, alkaline-tolerant and the best windbreak trees here. How to identify a pine by needle count, and why watering is the only real beetle prevention.',
  'public' => <<<'HTML'
<p>If one group of conifers belongs on the Western Slope, it is the pines. They root deep rather than wide, they handle alkaline soil without going chlorotic, and once a pine is established it will go through a dry summer that would set a spruce back badly. Most of the best windbreak and screening trees on this list are here.</p>

<p>Telling them apart is easier than it looks. <strong>Pines carry their needles in bundles, and the number in a bundle is the identification.</strong> Two to a bundle on Austrian, Scotch, Bosnian and piñon; usually three on ponderosa; five on limber, bristlecone and southwestern white. Pull one bundle and count — it narrows the field in a second, and it is the first thing anybody who works with conifers learns.</p>

<p>Here is what we stock and plant.</p>
HTML,
  'cta' => <<<'HTML'
<h2>The thing that actually kills pines here is not drought</h2>

<p>It is beetles, and beetles follow drought stress. That distinction matters because it is the difference between a problem you can prevent and one you cannot.</p>

<p>A healthy pine pushes out attacking bark beetles with resin. A pine that has been short of water for a season or two cannot produce enough pitch to do it, and the beetles get in. This is not theoretical here — the drought in the early 2000s put enormous numbers of piñon under stress across this part of the state, ips beetles moved into the weakened trees, and whole hillsides of piñon went red and died within a couple of years. The healthy, watered trees in town largely came through it.</p>

<p><strong>Which makes supplemental water the single most effective beetle control there is</strong>, and it is worth understanding the timing. A mature pine is not a shrub — it wants a deep, slow soak out at the drip line a few times through a dry summer, not a daily sprinkle at the trunk. And it wants water in a dry winter too, on a warm day when the ground has thawed. Two or three deep waterings in a drought year is not much, and it is most of what stands between a pine and the beetles.</p>

<p>If you have pines on your property and have not watered them because pines are drought-tolerant, that is the thing worth changing. Drought-tolerant means they survive a dry spell. It does not mean they survive several in a row with a beetle flight in the middle.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Pine is the safe recommendation on a hard site.</strong> Exposed, alkaline, windy, low water — pines take it better than anything else we carry except juniper.</p>

<ul>
<li><strong>Count the needles per bundle to identify one on site.</strong> Two: Austrian, Scotch, Bosnian, piñon. Three: ponderosa. Five: limber, bristlecone, southwestern white.</li>
<li><strong>Space for mature width, and mean it.</strong> A ponderosa or an Austrian is a big tree. Most crowded conifer plantings we are called to fix were spaced for the pot.</li>
<li><strong>Deep water at the drip line, not the trunk.</strong> Feeder roots are out under the canopy edge. Water at the trunk mostly wets the trunk.</li>
<li><strong>Two seasons of establishment water minimum</strong>, then supplemental water in drought years for the life of the tree. Put it on the care sheet.</li>
<li><strong>Red or fading needles on a mature pine — look before you quote.</strong> Check for pitch tubes and boring dust on the trunk. Beetles in a mature pine change the conversation from treatment to removal, and neighbouring trees need looking at the same day.</li>
<li><strong>Winter watering is not optional in a dry, snowless winter.</strong> Warm day, ground thawed, deep soak.</li>
</ul>

<p><strong>What to tell a customer:</strong> pines are drought-tolerant, not drought-proof, and a stressed pine is a beetle target. Two or three deep waterings in a dry summer protects a tree that would cost thousands to remove and replace. This is the easiest sell in the catalog because it is plainly true.</p>
HTML,
];

$evergreenCopy['Spruce'] = [
  'order' => 20,
  'short' => 'Dense, formal and the classic Colorado conifer — and thirstier than most people expect. Spruce wants more water and better siting than pine, and the reason is worth knowing before you plant one.',
  'title' => 'Spruce Trees for Western Colorado | Brookstone',
  'meta' => 'Blue spruce is native to wet mountain drainages, not a valley floor at 5,000 feet. Why lower branches die first, and what to plant on a hot dry site instead.',
  'public' => <<<'HTML'
<p>A spruce is the tree most people picture when they picture a conifer: dense, symmetrical, stiff-needled, holding a perfect cone shape from the ground up. Colorado blue spruce is the state tree and it is genuinely native here.</p>

<p><strong>Which is exactly where the trouble starts.</strong> Native to Colorado does not mean native to a 5,000-foot valley floor. Blue spruce grows wild in cool, moist mountain drainages between roughly 6,000 and 11,000 feet — along streams, in the bottoms, where its shallow roots find steady water all season. That is not the site conditions of an open, sunny lawn in Delta, and the tree knows the difference even when the buyer does not.</p>

<p>Planted somewhere that suits it, a spruce is superb and long-lived. Planted in a hot, dry, exposed spot because it is native and therefore assumed tough, it spends fifteen years looking acceptable and then starts dying from the bottom up.</p>

<p>Here is what we carry.</p>
HTML,
  'cta' => <<<'HTML'
<h2>Cytospora, and why lower branches die first</h2>

<p>If you have seen an older blue spruce in this area with dead branches at the bottom and healthy growth up top, sometimes with a sticky white-grey resin bleeding down the trunk, that is cytospora canker. It is the most common serious problem on spruce in this region and it is essentially a disease of stressed trees.</p>

<p>It is not curable. Infected branches get removed and the tree carries on for years, often decades, but the shape is permanently changed and it works upward over time. Trees usually begin showing it around fifteen to twenty years old, which is long enough that nobody connects it to how the tree was sited and watered when it went in.</p>

<p>So everything useful here happens at planting and in the watering can:</p>

<ul>
<li><strong>Water like it is a mountain streamside tree</strong>, because it is one. Spruce is the least drought-tolerant conifer we sell.</li>
<li><strong>Give it room and air.</strong> Crowded spruces with no air movement between them hold moisture on the foliage and stress each other.</li>
<li><strong>Do not wound the trunk.</strong> String trimmers and mower decks at the base are a common entry point.</li>
<li><strong>Avoid the hottest, most exposed corner of a property.</strong> That site wants a pine or a fir.</li>
</ul>

<h2>If the site is wrong for spruce, say so before you buy one</h2>

<p>Somebody who wants the blue spruce look on a hot, dry site is usually better served by a <strong>white fir</strong> — the colour is comparable, the form is softer and more graceful, and it takes heat and drought considerably better without the cytospora history. It is the single most useful substitution in this catalog and almost nobody asks for it by name.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Spruce is a siting decision more than a species decision.</strong> The right spruce in the wrong spot fails slowly and visibly, and it fails on our reputation fifteen years later.</p>

<ul>
<li><strong>Do not put a spruce on a hot, dry, exposed site.</strong> Offer white fir instead and explain why. It is the best substitution we have and it usually makes the customer happier.</li>
<li><strong>"It's native so it's tough" is the objection to expect.</strong> The answer: native to wet mountain drainages at 8,000 feet, not to a sunny lawn at 5,000. Same state, different plant conditions.</li>
<li><strong>Space for air movement.</strong> Spruces planted tight hold moisture and stress each other. This is a cytospora contributor.</li>
<li><strong>Keep trimmers and mowers off the trunk</strong> — on our own maintenance routes too. Bark wounds are an infection route.</li>
<li><strong>Dead lower branches plus resin bleeding on the trunk is cytospora.</strong> Not curable. Prune out infected limbs in dry weather, sanitise between cuts, and be straight with the customer about what happens next.</li>
<li><strong>Spruce is the thirstiest conifer we sell.</strong> It does not belong on a xeric plan, whatever else is on it.</li>
</ul>

<p><strong>What to tell a customer:</strong> a spruce in a good spot is a hundred-year tree. In the wrong spot it looks fine for fifteen years and then starts losing its bottom, and nothing fixes that. The site conversation is worth having before the species conversation.</p>
HTML,
];

$evergreenCopy['Fir'] = [
  'order' => 30,
  'short' => 'The most underused large conifer in this valley. White fir takes heat, drought and alkaline soil better than blue spruce does, and it is usually the better tree for the site.',
  'title' => 'Fir Trees for Western Colorado | Brookstone',
  'meta' => 'White fir gives you the blue spruce look with far better heat and drought tolerance. The most underused large conifer here, and why Douglas fir is not a fir.',
  'public' => <<<'HTML'
<p>Firs get asked for less often than pine or spruce, and on most Western Slope sites at least one of them is the better tree.</p>

<p><strong>White fir</strong> — also sold as concolor fir — is the one worth knowing about. It has the soft blue-grey colour people want from a blue spruce, in longer, softer, flatter needles that are pleasant to stand next to rather than sharp. It grows into an elegant, slightly open pyramid instead of a dense cone. And it handles heat, drought and alkaline ground substantially better than blue spruce, without the cytospora history that follows spruce around this region.</p>

<p>The other is <strong>Douglas fir</strong>, which is not a true fir at all — it is <em>Pseudotsuga</em>, its own genus, and the common name has been misleading people for a century. It is a fine tree on a cooler, moister site with decent drainage, and it is the less adaptable of the two on an exposed valley-floor property.</p>

<p>Both want better drainage than a spruce will tolerate. Neither wants to sit in heavy wet ground.</p>
HTML,
  'cta' => <<<'HTML'
<h2>The substitution worth knowing about</h2>

<p>The most common large-conifer request we get is some version of "a blue spruce." Often the site is a hot, open, south or west exposure on dry ground — which is close to the worst place to put one.</p>

<p><strong>White fir answers that request better than a blue spruce does.</strong> Similar colour, softer texture, a more graceful form, and a tree far more likely to still look good in thirty years on that particular spot. It costs about the same and nobody ever asks for it, because it is not the tree everybody grew up hearing about.</p>

<p>That is most of what this page exists to say. If you are picturing a big blue-grey conifer for an open yard, look at white fir before you settle on spruce.</p>

<h2>What both firs need</h2>

<p>Drainage, first. A fir in heavy ground that holds water after a storm is a fir with a root problem coming, and that is true even of the tough one. If the ground is slow, plant high — on a graded berm or a raised bed — rather than amending a hole.</p>

<p>Then the usual two seasons of establishment water, deep and at the drip line, before either of them is asked to be drought-tolerant.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>White fir is the substitution to reach for when somebody asks for a blue spruce on a hot, dry site.</strong> Learn to make this recommendation smoothly — it is better for the customer and it is a better tree.</p>

<ul>
<li><strong>Lead with the colour and the feel.</strong> Blue-grey like a spruce, soft needles instead of sharp. People respond to touching one.</li>
<li><strong>Then the honest reason:</strong> it handles heat, drought and our soil better, and it does not carry the cytospora problem spruce has here.</li>
<li><strong>Douglas fir wants a cooler, moister, better-drained site.</strong> Do not treat the two firs as interchangeable — white fir is the tougher one by a good margin.</li>
<li><strong>Perc-test on heavy ground.</strong> Neither fir tolerates wet feet. Slow drainage means planting high, not amending the hole.</li>
<li><strong>Douglas fir's genus is <em>Pseudotsuga</em>, not <em>Abies</em>.</strong> The catalog is correct as written. Do not "fix" it.</li>
</ul>

<p><strong>What to tell a customer:</strong> if they came in wanting a blue spruce, do not argue with the spruce — show them a white fir and let them look at it. Most people choose it once they have seen one, and the ones who still want a spruce get a spruce and an honest conversation about siting.</p>
HTML,
];

$evergreenCopy['Juniper'] = [
  'order' => 40,
  'short' => 'The toughest conifers we carry. Alkaline soil, drought, wind, poor ground and deer pressure — junipers handle all of it, and most of them are native to conditions like these.',
  'title' => 'Juniper Trees for Western Colorado | Brookstone',
  'meta' => 'The toughest conifers here — alkaline soil, drought, wind and deer. Plus the cedar-apple rust connection that matters when you plant one near an orchard.',
  'public' => <<<'HTML'
<p>If a site has defeated everything else, a juniper will probably grow there. They tolerate alkaline soil without complaint, they are genuinely drought-hardy once established, they take wind, they root in ground that barely qualifies as soil, and deer mostly leave them alone. Several of them are native to exactly this country — you can see them on the mesas and the dry benches without anybody having planted or watered them.</p>

<p>That toughness makes them the backbone of low-water planting here. Where a spruce needs a favourable site and regular water, a juniper needs neither, which is why they end up along driveways, on slopes, in windbreaks and on the hot exposed corners where nothing else held.</p>

<p>The trees are below. <strong>The genus runs much wider than this page</strong> — junipers range from forty-foot trees down to groundcovers six inches tall that spread eight feet, and the shrub and groundcover forms are in their own categories.</p>
HTML,
  'cta' => <<<'HTML'
<h2>One caution, and it matters in this county</h2>

<p>Junipers are the alternate host for <strong>cedar-apple rust</strong> and its relatives. The fungus cannot complete its life cycle on either host alone — it needs a juniper and an apple, crabapple or hawthorn within roughly a mile of each other, and it moves back and forth between them year after year.</p>

<p>On the juniper it is mostly cosmetic: odd brown galls that swell and put out bright orange gelatinous horns after a wet spring, alarming to look at and largely harmless to the tree. <strong>On the apple it is not cosmetic.</strong> It spots the leaves, weakens the tree and can mark the fruit.</p>

<p>In a county with this much orchard in it, that is worth a moment's thought before a juniper windbreak goes in. It runs both directions — if you are planting apples, what is already in the fencerows matters too. It is not a reason to avoid junipers, which are among the best-adapted plants available here. It is a reason to look around first, and to mention it to a neighbour with an orchard rather than have them work it out later.</p>

<h2>What they ask for</h2>

<p>Very little, and that is the point. Two seasons of establishment water like anything else, then largely left alone. The most common way to kill an established juniper here is overwatering it — a juniper on a lawn irrigation schedule in heavy ground sits wet and rots, and it dies looking overwatered rather than dry.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Juniper is the answer on the sites that beat everything else.</strong> Hot, dry, windy, alkaline, deer-pressured — this is where it goes.</p>

<ul>
<li><strong>⚠ Check what is nearby before siting a juniper windbreak.</strong> Junipers host cedar-apple and cedar-hawthorn rust, which needs an apple, crabapple or hawthorn within about a mile. In this county that is a real question, not a theoretical one.</li>
<li><strong>It runs the other way too.</strong> On a fruit tree job, look at the fencerows and the neighbouring properties for junipers before planting apples. Mention it either way — a customer who hears it from us treats it as expertise.</li>
<li><strong>Orange gelatinous galls on a juniper after a wet spring are rust.</strong> Largely harmless to the juniper. The conversation is about what is downwind of it.</li>
<li><strong>Do not put an established juniper on a turf irrigation schedule.</strong> Overwatering in heavy ground kills more of them here than drought does. Its own zone, or none.</li>
<li><strong>The genus spans forms.</strong> If a customer wants "a juniper," establish whether they mean a tree, a shrub or a groundcover before you pull a price — the three are in different categories and are not close in size or cost.</li>
</ul>

<p><strong>What to tell a customer:</strong> junipers are the most reliable thing we can put on a difficult site, and they are close to maintenance-free once established. The one thing worth knowing is the rust connection to apples, and the answer is usually just placement.</p>
HTML,
];

$evergreenCopy['Arborvitae'] = [
  'order' => 50,
  'short' => 'Fast, columnar and inexpensive, which is why it is the most requested screening plant — and among the least suited to this climate. It works in specific conditions and struggles outside them.',
  'title' => 'Arborvitae for Western Colorado | Brookstone',
  'meta' => 'The most requested screening plant, and among the least suited here: winter burn and deer. Where arborvitae works, and what to plant when it will not.',
  'public' => <<<'HTML'
<p>Arborvitae is the plant most people have in mind when they want a screen: a narrow green column, quick to fill in, inexpensive, and available everywhere. <em>Thuja</em>, a cypress relative, not a juniper and not a cedar despite what it gets called.</p>

<p><strong>It is also one of the least well-suited screening plants for this part of Colorado</strong>, and it is worth saying that plainly before somebody plants thirty of them.</p>

<p>Two problems, and both are specific to conditions here. It wants consistent, even moisture, and its flat sprays of foliage lose water steadily through winter while the ground is frozen — so a dry, windy, sunny January turns it brown and crisp, worst on the exposed side, and it does not always recover. And of everything in this catalog, arborvitae is close to the top of what deer will eat. A hedge of it near open ground can be stripped to a browse line in one hard winter.</p>
HTML,
  'cta' => <<<'HTML'
<h2>Where it does work</h2>

<p>None of that makes it a bad plant. It makes it a plant with conditions, and inside those conditions it does the job as well as anything.</p>

<p>Arborvitae works on a <strong>sheltered site with irrigation and no serious deer pressure</strong> — an enclosed back yard, a north or east side, a courtyard, somewhere out of the prevailing west wind and on a regular watering schedule. In town, fenced, watered, it can be an excellent screen for decades.</p>

<p>What it does not survive is the situation it gets bought for most often: an exposed property line on the edge of town, backing onto open ground, planted and then largely left alone.</p>

<h2>What to plant instead when the site is exposed</h2>

<p>If you need a screen on an open, windy, deer-visited property, there are better answers and they are not more expensive over the life of the planting:</p>

<ul>
<li><strong>Upright juniper</strong> — the closest thing to an arborvitae's form and habit, and vastly better adapted. Drought-hardy, wind-hardy, and deer largely ignore it.</li>
<li><strong>Rocky Mountain juniper</strong> — native, taller, a genuine windbreak tree rather than a hedge.</li>
<li><strong>Austrian pine</strong> — where there is room for a wider screen and time to grow it.</li>
</ul>

<p>We will sell and plant arborvitae where it suits the site, and we would rather tell you up front where it does not. A screen that fails in year four costs more than the right plant did.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Qualify the site before quoting arborvitae. Every time.</strong> This is the plant most likely to come back on us, and the failure is entirely predictable at the point of sale.</p>

<ul>
<li><strong>Three questions: is it sheltered, is it irrigated, are there deer?</strong> Anything other than yes-yes-no and we should be offering an upright juniper instead.</li>
<li><strong>Exposed property line backing onto open ground is the worst case</strong> and it is the most common request. Wind burn plus browse. Do not just take the order.</li>
<li><strong>Offer upright juniper as the direct substitute.</strong> Same columnar habit, same screening job, far better adapted, and deer mostly leave it. Lead with that rather than with arborvitae's problems.</li>
<li><strong>If the customer still wants arborvitae on a marginal site,</strong> sell it with the conditions written into the estimate — irrigation required, winter watering, deer protection. Then it is their informed decision, on the record.</li>
<li><strong>Winter browning is not automatically dead.</strong> Wait until June before quoting replacements. Some of it pushes new growth.</li>
<li><strong>Never quote arborvitae as a low-maintenance screen.</strong> It is not one here.</li>
</ul>

<p><strong>What to tell a customer:</strong> arborvitae is the plant everybody pictures for a screen, and in a sheltered, watered yard it is a good one. On an open windy lot with deer around it is a four-year plant. Then show them an upright juniper.</p>
HTML,
];


// ---------------------------------------------------------------------------
// 3. Seed.
// ---------------------------------------------------------------------------
/**
 * Resolve a term, preferring its URL alias.
 *
 * The office renames these categories, and the environments genuinely disagree:
 * live calls one "Fruit Trees" under "Deciduous Trees" while dev calls the same
 * page "Fruit" under "Deciduous". The ALIAS is identical on both and is what the
 * public and the cross-links use, so it is the stable identifier. Name-under-
 * parent stays as the fallback for terms where no alias is given.
 */
$resolve = function (string $name, string $parent, ?string $alias = NULL) use ($ts) {
  if ($alias) {
    $path = \Drupal::service('path_alias.manager')->getPathByAlias($alias);
    if ($path !== $alias && preg_match('#^/taxonomy/term/(\d+)$#', $path, $m)) {
      $t = $ts->load((int) $m[1]);
      if ($t && $t->bundle() === VID) { return [$t]; }
    }
    return [];
  }
  $found = $ts->loadByProperties(['vid' => VID, 'name' => $name]);
  $under = [];
  foreach ($found as $t) {
    $parents = $ts->loadParents($t->id());
    $p = $parents ? reset($parents) : NULL;
    if ($p && $p->label() === $parent) { $under[] = $t; }
  }
  return $under;
};


// --- Batch: Fruit, under Trees > Deciduous -----------------------------------
// The office named these children Fruit / Shade / Ornamental, not "Fruit Trees"
// / "Shade Trees" / "Ornamental Trees" as the source doc assumes, so the term is
// matched and left under its own name. The public URL is therefore
// /material/plants/trees/deciduous/fruit, and the cross-link on the
// Fruit-Bearing characteristic page already points there correctly.
$fruitCopy = [];

$fruitCopy['Fruit Trees'] = [
  'alias' => '/material/plants/trees/deciduous/fruit',
  'order' => 30,
  'short' => 'Trees grown for a harvest rather than for shade or for looks. In this valley the first question is not what you like to eat — it is when the tree blooms.',
  'title' => 'Fruit Trees for Delta County | Brookstone Outdoors',
  'meta' => 'Apricots bloom first and lose the crop most years; apples bloom last and are the reliable one. Pollination, rootstock and what a fruit tree asks of you here.',
  'public' => <<<'HTML'
<p>Delta County has grown fruit for more than a century, and the reason it works here is close to the reason it is difficult. This valley has the sun, the diurnal swing and the season for good fruit. It also has a late frost that turns up often enough to matter.</p>

<p>So the first question about a fruit tree here is not what you like to eat. It is when the tree blooms — because a bloom that opens before the last hard freeze is a crop you do not get, and that is a property of the species rather than of anything you did.</p>

<p>Roughly in order of bloom: apricot first, then peach, then sweet cherry, then plum, then pear, and apple last. <strong>Read that as a reliability ranking and it is nearly exact.</strong> Apricots are the heartbreak tree of this valley — they will give you a spectacular crop and then miss three years running. Apples bloom late enough to clear most frosts, which is not a coincidence and not unrelated to why this county is covered in apple orchards.</p>

<p>Here is what we stock and plant.</p>
HTML,
  'cta' => <<<'HTML'
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

<p>None of that is difficult. All of it is a calendar, and the calendar is the part most people do not keep.</p>

<h2>We prune and spray them too</h2>

<p>We plant fruit trees, we prune them, and we run spray programs on them, for homeowners with three trees and for properties with thirty.</p>

<p>Dormant pruning runs January through March, before bud break — a narrow window, and one of the few jobs in a yard that genuinely cannot be done late. The dormant spray goes on in the same stretch. After that the calendar is codling moth timing through the summer on apples and pears, and thinning in early June.</p>

<p>If you would rather do it yourself, ask and we will write out the schedule for the varieties you have. Knowing when is most of it.</p>

<h2>One thing to look at before you plant: what is already nearby</h2>

<p>Cedar-apple rust needs two hosts to survive — a juniper, and an apple, crabapple or hawthorn — within roughly a mile of each other. Neither host alone keeps it going, and it moves back and forth between them year after year. On a juniper it is mostly cosmetic. On an apple it spots the leaves, weakens the tree and can mark the fruit.</p>

<p>So before apples go in, it is worth a look at the fencerows, the windbreaks and the neighbouring properties. Junipers are everywhere in this county and they are excellent plants — this is not a reason to remove one. It is a reason to know what you are working with, and in some cases to choose a rust-resistant apple variety rather than fight it every spring. <a href="/material/plants/trees/evergreens/juniper">More on junipers</a>.</p>

<h2>Fruit as a landscape decision, rather than a harvest</h2>

<p>If what you want is the look of fruit — color on the branch in October, berries against snow, birds in the yard — without the ladder and the spray calendar, that is a different decision and there are better plants for it. <a href="/material/plants/characteristics/aesthetic-features/fruit-bearing">Fruit-bearing as a landscape characteristic</a> covers what fruit does to a planting, including the part about never putting one over a patio.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
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
HTML,
];

// Its two siblings keep their place in the order; their copy is a separate batch.
// Shade and Ornamental carry their own copy in the 'deciduous' batch, which
// sets their order there.
$fruitOrderOnly = [];
$fruitOrderAliases = [];


// --- Batch: the Deciduous branch, under Trees --------------------------------
// Resolved by alias: live names these Shade / Ornamental while the source doc
// calls them Shade Trees / Ornamental Trees. Renaming is the office's call, so
// the terms keep their names and the copy does not depend on them.
$deciduousCopy = [];

$deciduousCopy['Deciduous Trees'] = [
  'alias' => '/material/plants/trees/deciduous',
  'short' => 'Trees that drop their leaves and grow them back. Nearly all the shade, nearly all the flowering, and all of the fruit — split three ways by what you want the tree to do.',
  'title' => 'Deciduous Trees for Colorado | Brookstone Outdoors',
  'meta' => 'Shade, ornamental or fruit — three different trees for three different jobs. Why deciduous is the easier half of the tree catalog on the Western Slope.',
  'public' => <<<'HTML'
<p>Almost every tree that does a job here is deciduous. The shade over a patio, the flowering tree by the front door, the apple in the back corner — all of them drop their leaves in October and start again in April.</p>

<p>They are also the easier half of the tree catalog on this ground, for a reason that is not obvious. A deciduous tree is dormant through the hardest four months, losing almost no water while the ground is frozen. An evergreen beside it is still transpiring into a dry January wind and cannot replace what it loses. That is why winter burn is an evergreen problem, and why the deciduous list is longer, cheaper and more forgiving.</p>

<p>The trade is five months of bare branches, and the first real decision is not which tree — it is which of three jobs you are buying.</p>
HTML,
  'cta' => <<<'HTML'
<h2>Three jobs, three different trees</h2>

<p><strong>A shade tree is infrastructure.</strong> Forty to seventy feet at maturity, planted for cooling, structure and the value it puts on a property. It needs real room, it takes twenty years to do its job properly, and where it goes is a decision somebody lives with for decades.</p>

<p><strong>An ornamental is scale.</strong> Under about twenty-five feet, and therefore the right answer in all the places a shade tree is wrong — close to the house, under a power line, in a courtyard, beside an entry. Bought for flowers, bark, form or fall colour rather than for canopy.</p>

<p><strong>A fruit tree is a commitment.</strong> It produces something, and it asks for annual pruning, thinning and a spray calendar in return. Entirely doable, and not a plant to buy without knowing that.</p>

<h2>One thing all three have in common</h2>

<p>They are bare from roughly November to April. A planting built only from deciduous trees is a planting with nothing in it for five months — which is fine if that was the plan, and a surprise if it was not. Some evergreen mass, or shrubs with winter bark and fruit, is what keeps the yard from reading as empty in January.</p>

<p>If you are not sure which of the three a spot wants, that is a short conversation and usually the site answers it.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Establish the job before the species, every time.</strong> Shade, ornamental or fruit is the first question on any tree conversation and it eliminates most of the catalog in one answer.</p>

<ul>
<li><strong>Shade:</strong> is there room for forty to seventy feet, in every direction, forever? If not, it is an ornamental conversation.</li>
<li><strong>Ornamental:</strong> near the house, under wires, tight space, or wanted for flowers rather than canopy.</li>
<li><strong>Fruit:</strong> do they actually want to pick something, and did anybody explain the pruning and spray calendar?</li>
<li><strong>Lead with deciduous on exposed sites.</strong> Dormant trees do not winter-burn. It is the honest recommendation on a windy southwest corner.</li>
<li><strong>Raise the winter question on any all-deciduous plan.</strong> "What does this look like in January?" Most people have not thought about it, and it usually adds an evergreen to the job.</li>
</ul>

<p><strong>What to tell a customer:</strong> deciduous trees are the easier and wider half of what we carry, and they give you flowers, shade and fall colour that evergreens cannot. What they do not give you is anything to look at between November and April, so a yard needs a little of both.</p>
HTML,
];

$deciduousCopy['Shade Trees'] = [
  'alias' => '/material/plants/trees/deciduous/shade',
  'order' => 10,
  'short' => 'The big ones — forty to seventy feet at maturity. The only thing you can plant that measurably cools a house, and the longest-lived decision on a property.',
  'title' => 'Shade Trees for Western Colorado | Brookstone',
  'meta' => 'West-side shade is what cools a house. Placing a shade tree around power lines, sewer laterals and foundations, and the planting depth that kills young trees.',
  'public' => <<<'HTML'
<p>A shade tree is the highest-value plant decision anybody makes on a property, and it is the one with the longest tail. Everything else in a yard can be changed in a weekend or a season. A tree planted this spring is making its case in 2060, and where it goes is a choice somebody lives with for decades — usually somebody who was not there when it was planted.</p>

<p>It is also the only thing in this catalog that measurably changes how a house works. A mature canopy on the west and southwest side takes the afternoon sun off the wall and the windows in July, and that shows up on a power bill rather than only in a photograph. East shade is pleasant. <strong>West shade is functional</strong>, and it is worth deciding where a tree goes on that basis before deciding which tree it is.</p>

<p>Here is what we plant.</p>
HTML,
  'cta' => <<<'HTML'
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
HTML,
  'crew' => <<<'HTML'
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
HTML,
];

$deciduousCopy['Ornamental Trees'] = [
  'alias' => '/material/plants/trees/deciduous/ornamental',
  'order' => 20,
  'short' => 'Small trees, generally under twenty-five feet. The right answer everywhere a shade tree is wrong — near the house, under a power line, in a courtyard, beside a door.',
  'title' => 'Ornamental Trees for Colorado | Brookstone Outdoors',
  'meta' => 'Small trees for under power lines, near the house and in tight spaces. Plus the fire blight and cedar-apple rust running through the rose-family list.',
  'public' => <<<'HTML'
<p>An ornamental tree is defined by scale before anything else. Under about twenty-five feet at maturity, which sounds like a limitation and is the entire point — it is what makes them the right tree for all the places a shade tree cannot go.</p>

<p><strong>The clearest case is a power line.</strong> A large tree under wires will eventually be topped by the utility, and a topped tree is finished: the structure is gone, the regrowth is weak and badly attached, and it never looks right again. Anything under the lines should be a tree that never reaches them. That single constraint decides more front-yard plantings in this valley than any aesthetic preference does.</p>

<p>The same logic applies near a foundation, in a courtyard, beside an entry, between a walk and a drive, or anywhere the canopy has somewhere it is not allowed to go. And within that constraint the list is the most interesting one we carry — this is where the spring flowering, the fall colour, the berries and the winter bark live.</p>
HTML,
  'cta' => <<<'HTML'
<h2>Most of this list is one plant family, and that matters</h2>

<p>Crabapple, hawthorn, mountain ash, serviceberry and ornamental pear are all in the rose family, along with every apple, pear, plum and cherry. Family is usually an academic detail. Here it is practical, because <strong>disease runs along family lines.</strong></p>

<p>Two things move through that group in this county. <strong>Fire blight</strong> is a bacterial infection that moves fast in a warm wet spring — shoot tips blacken and curl over like a shepherd's crook, and it can take large limbs in a season. <strong>Cedar-apple rust</strong> needs a juniper and a rose-family host within about a mile of each other and shuttles between them year after year, spotting leaves and weakening the tree.</p>

<p>Neither is a reason to avoid these trees — they are among the best small trees available here. It is a reason to look at what is already on the property before planting a row of them, to choose resistant varieties where the pressure is real, and to know that a crabapple by the drive and an apple orchard down the road are part of the same conversation. <a href="/material/plants/trees/deciduous/fruit">Fruit trees</a> covers the other end of it.</p>

<h2>Check what is underneath before you check the flowers</h2>

<p>Several of the best ornamentals fruit, and fruit falls. A crabapple or a mulberry in a border is a good decision; the same tree over a patio, a walk, a drive or a parking space means a few weeks of stain, wasps and a slick surface every autumn for the life of the tree.</p>

<p>Look at what is under the canopy before choosing the tree. It is the easiest mistake to avoid and one of the most annoying to live with.</p>

<h2>One form question worth asking early</h2>

<p>Many of these are available as either a single-trunk tree or a multi-stem clump, and they are different plants in a design. Single-stem reads formal and lifts the canopy for walking under. Multi-stem is wider, lower and more naturalistic, and it usually reads better against a house or in a bed. Decide which before ordering — it is not something that can be changed later.</p>

<p><a class="button" href="/request-estimate?c=plantchar">Request an Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'crew' => <<<'HTML'
<p><strong>Ornamental is the answer whenever a shade tree will not fit.</strong> Lead with the constraint, not with the flowers — the constraint is what makes the recommendation right.</p>

<ul>
<li><strong>Under power lines, always ornamental.</strong> Never let a customer put a shade tree under wires, however much they want it. Explain topping once and most people understand immediately.</li>
<li><strong>Check what is underneath before you check the bloom.</strong> Nothing that drops fruit over a patio, walk, drive or parking. Crabapple and mulberry are the usual culprits.</li>
<li><strong>⚠ Rose family: crabapple, hawthorn, mountain ash, serviceberry, ornamental pear.</strong> Fire blight and cedar-apple rust both run through this group. Look at the property and the neighbours for junipers and apples before planting several.</li>
<li><strong>Fire blight is blackened shoot tips curled like a shepherd's crook.</strong> Prune well below the damage in dry weather, sanitise between every cut, and get the prunings off site. Do not prune it in wet weather — that spreads it.</li>
<li><strong>Ask single-stem or multi-stem at the design stage.</strong> It changes the look substantially and it cannot be changed after.</li>
<li><strong>Do not oversell redbud.</strong> It is marginal at elevation and in exposed sites. Sheltered, east-facing, it is lovely; on an open west corner it struggles.</li>
</ul>

<p><strong>What to tell a customer:</strong> an ornamental is not a compromise on a shade tree, it is a different tool. It goes where a big tree would eventually become a problem, and it gives you flowers and interest at eye level where you actually see them.</p>
HTML,
];

$batches = [
  'shrubs' => ['parent' => 'Shrubs', 'copy' => $shrubCopy, 'order_only' => $shrubOrderOnly],
  'evergreen_genus' => ['parent' => 'Evergreens', 'copy' => $evergreenCopy, 'order_only' => []],
  'fruit' => ['parent' => 'Deciduous Trees', 'copy' => $fruitCopy, 'order_only' => $fruitOrderOnly, 'order_aliases' => $fruitOrderAliases],
  'deciduous' => ['parent' => 'Trees', 'copy' => $deciduousCopy, 'order_only' => []],
];
if ($only !== '') {
  if (!isset($batches[$only])) {
    printf("Unknown batch '%s'. Known: %s\n", $only, implode(', ', array_keys($batches)));
    return;
  }
  $batches = [$only => $batches[$only]];
}

foreach ($batches as $batchName => $batch) {
  $parent = $batch['parent'];
  $batch += ['order_aliases' => []];
  printf("\n--- %s (under %s) ---\n", $batchName, $parent);
foreach ($batch['copy'] as $name => $c) {
  $hits = $resolve($name, $parent, $c['alias'] ?? NULL);
  if (count($hits) !== 1) {
    printf("SKIP %s — found %d terms named that under %s. The office owns the name; not guessing.\n",
      $name, count($hits), $parent);
    continue;
  }
  $term = reset($hits);
  $set = [
    'field_short_description' => ['value' => $c['short'], 'format' => 'plain_text'],
    'field_public_description' => ['value' => $c['public'], 'format' => 'full_html'],
    'field_call_to_action' => ['value' => $c['cta'], 'format' => 'full_html'],
    'field_teammate_description' => ['value' => $c['crew'], 'format' => 'full_html'],
  ];
  // A parent category takes no list order of its own.
  if (array_key_exists('order', $c)) {
    $set['field_list_order'] = $c['order'];
  }
  $changes = [];
  foreach ($set as $f => $val) {
    if (!$term->hasField($f)) { $changes[] = "$f (field missing)"; continue; }
    $cur = $term->get($f)->isEmpty() ? NULL : $term->get($f)->first()->getValue();
    $curVal = is_array($cur) ? ($cur['value'] ?? NULL) : $cur;
    $newVal = is_array($val) ? $val['value'] : $val;
    if ((string) $curVal !== (string) $newVal) {
      $changes[] = $f;
      if ($apply) { $term->set($f, $val); }
    }
  }
  // Metatags: merge, so any other stored tag survives.
  // Metatag stores this field as JSON (verified against the stored value, not
  // assumed — an unserialize() here silently fails and makes the script re-write
  // the field on every run).
  if ($term->hasField('field_meta_tags')) {
    $raw = (string) $term->get('field_meta_tags')->value;
    $tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
    if (!is_array($tags)) { $tags = []; }
    $want = ['title' => $c['title'], 'description' => $c['meta'], 'og_description' => $c['meta']];
    if (array_intersect_key($tags, $want) != $want) {
      $changes[] = 'field_meta_tags';
      if ($apply) { $term->set('field_meta_tags', json_encode($want + $tags)); }
    }
  }
  if (!$changes) { printf("%-18s already current\n", $name); continue; }
  if ($apply) { $term->save(); }
  printf("%-18s %s: %s\n", $name, $apply ? 'updated' : 'would update', implode(', ', $changes));
}

foreach ($batch['order_only'] as $name => $order) {
  $hits = $resolve($name, $parent, ($batch['order_aliases'][$name] ?? NULL));
  if (count($hits) !== 1) { printf("SKIP %s (order) — %d matches\n", $name, count($hits)); continue; }
  $term = reset($hits);
  if (!$term->hasField('field_list_order')) { continue; }
  if ((int) ($term->get('field_list_order')->value ?? 0) === $order) { printf("%-18s order already %d\n", $name, $order); continue; }
  if ($apply) { $term->set('field_list_order', $order)->save(); }
  printf("%-18s %s list order -> %d\n", $name, $apply ? 'set' : 'would set', $order);
}
}

print $apply ? "\nDone.\n" : "\nDry run. Set BOS_COPY_APPLY=1 to apply.\n";
