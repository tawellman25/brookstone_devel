<?php

/**
 * @file
 * Load the authored copy into the Evergreen Shrubs and Deciduous Shrubs
 * material categories, and add the two field instances the copy needs.
 *
 * Source: "Evergreen Shrubs and Deciduous Shrubs — category copy" (2026-09-29).
 * Copy is pasted verbatim — not rewritten, not trimmed.
 *
 * Page order is already correct on the public displays (full, client_view):
 * field_public_description (0) -> the item lists (10) -> field_call_to_action (20).
 *
 * Idempotent. Per environment: taxonomy terms are content and field instances
 * silently skip cim, so this script is the deploy path. Terms are matched by name
 * UNDER the Shrubs parent and never created — if a name has changed, it reports
 * and stops rather than guessing or making a duplicate.
 */

use Drupal\field\Entity\FieldConfig;

const VID = 'material_types';
const PARENT = 'Shrubs';

$etm = \Drupal::entityTypeManager();
$ts = $etm->getStorage('taxonomy_term');
$apply = (bool) getenv('BOS_SHRUBS_APPLY');

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
$copy = [];

$copy['Evergreen Shrubs'] = [
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

$copy['Deciduous Shrubs'] = [
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
$orderOnly = ['Roses' => 30];

// ---------------------------------------------------------------------------
// 3. Seed.
// ---------------------------------------------------------------------------
$resolve = function (string $name) use ($ts) {
  $found = $ts->loadByProperties(['vid' => VID, 'name' => $name]);
  $under = [];
  foreach ($found as $t) {
    $parents = $ts->loadParents($t->id());
    $p = $parents ? reset($parents) : NULL;
    if ($p && $p->label() === PARENT) { $under[] = $t; }
  }
  return $under;
};

print "\n";
foreach ($copy as $name => $c) {
  $hits = $resolve($name);
  if (count($hits) !== 1) {
    printf("SKIP %s — found %d terms named that under %s. The office owns the name; not guessing.\n",
      $name, count($hits), PARENT);
    continue;
  }
  $term = reset($hits);
  $set = [
    'field_short_description' => ['value' => $c['short'], 'format' => 'plain_text'],
    'field_public_description' => ['value' => $c['public'], 'format' => 'full_html'],
    'field_call_to_action' => ['value' => $c['cta'], 'format' => 'full_html'],
    'field_teammate_description' => ['value' => $c['crew'], 'format' => 'full_html'],
    'field_list_order' => $c['order'],
  ];
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

foreach ($orderOnly as $name => $order) {
  $hits = $resolve($name);
  if (count($hits) !== 1) { printf("SKIP %s (order) — %d matches\n", $name, count($hits)); continue; }
  $term = reset($hits);
  if (!$term->hasField('field_list_order')) { continue; }
  if ((int) ($term->get('field_list_order')->value ?? 0) === $order) { printf("%-18s order already %d\n", $name, $order); continue; }
  if ($apply) { $term->set('field_list_order', $order)->save(); }
  printf("%-18s %s list order -> %d\n", $name, $apply ? 'set' : 'would set', $order);
}

print $apply ? "\nDone.\n" : "\nDry run. Set BOS_SHRUBS_APPLY=1 to apply.\n";
