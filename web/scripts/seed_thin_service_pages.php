<?php

declare(strict_types=1);

/**
 * The thin service pages — marketing's 11 rewrites, 3 October.
 *
 * Four fields per page:
 *   field_subtitle                       -> Subtitle
 *   field_service_public_desc (summary)  -> card summary on the PARENT page
 *   field_service_public_desc (value)    -> the body
 *   field_meta_tags                      -> meta description
 *
 * ⚠ ONLY THE SEVEN PAGES WITH NO [CONFIRM:] MARKER ARE LOADED. The other four
 * are HELD, not pasted-and-unpublished: they are existing LIVE pages, and
 * unpublishing them to stage new copy would take working content off the site
 * to fix a thinness problem. Held copy is useless on a dark page.
 *
 * The guard below aborts if a [CONFIRM marker ever reaches a value, so the
 * marker cannot be published by accident.
 *
 *   drush php:script web/scripts/seed_thin_service_pages.php
 *   BOS_THIN_APPLY=1 drush php:script web/scripts/seed_thin_service_pages.php
 */

use Drupal\Core\Cache\Cache;

$apply = getenv('BOS_THIN_APPLY') === '1';
$etm = \Drupal::entityTypeManager();

// Held until Todd answers. Named so the script reports them every run.
$HELD = [
  'Cooley Spruce Gall Treatment' => 'spring or fall application?',
  'Pine Needle Scale' => 'what is applied and when — crawler spray, dormant oil, or both?',
  'Dethatching' => 'does the crew haul the debris away, or leave it?',
  'Holiday Decorations' => 'who owns the lights; do you return mid-season for a failed strand; what month do installs start?',
];

$P = [];

$P['Backflow Testing and Certification'] = [
  'sub' => 'Annual testing by an ABPA-certified tester, with the report filed for you',
  'card' => 'Most water providers require an annual backflow test from a certified tester. We test the assembly, file the report with your provider, and explain any repair.',
  'meta' => 'Annual backflow testing by an ABPA-certified tester in Delta and Montrose counties. We file the report with your water provider and explain any repair.',
  'body' => <<<'HTML'
<p>A backflow prevention assembly keeps water from your irrigation system, or anything else connected to your plumbing, from flowing backward into the public water supply. Most water providers require every assembly to be tested once a year, and most accept the result only from a certified tester.</p>

<h2>Who performs the test</h2>
<p>Tests are performed by Todd, who is certified as a backflow assembly tester through the American Backflow Prevention Association, certification #06-2512234. That number appears on every test report we file.</p>

<h2>What the test involves</h2>
<p>The assembly is tested with calibrated gauge equipment while the system is charged and under pressure. That is why backflow testing is a spring service rather than a fall one: it happens after the system has been started for the season, not while it is being drained for winter. Customers on our spring start-up schedule can have the annual test done in the same season.</p>

<h2>Filing the report</h2>
<p>After the test, we file the report directly with your water provider. You do not need to submit anything yourself, and you receive a copy for your records.</p>

<h2>If the assembly does not pass</h2>
<p>Most failures come from worn internal parts, such as check discs, seats, springs, or a relief valve diaphragm, and those can be rebuilt. Rebuilds are within our scope wherever the assembly is located. Where an assembly needs to be replaced, we replace and install assemblies outside the foundation, which covers irrigation systems, yard hydrants, and pond and pasture connections. Replacement of an assembly inside the foundation of a building is licensed plumbing work under Colorado's plumbing code, and in that case we refer you to a licensed plumbing contractor. Fire suppression assemblies are tested and repaired only, never replaced by us.</p>

<p>For background on the assembly types and where each one is used, see <a href="/services/backflow-prevention">Backflow Prevention</a>.</p>
HTML,
];

$P['Winter Pruning'] = [
  'sub' => 'Dormant-season pruning, when the structure of the plant is easiest to see',
  'card' => 'Pruning deciduous trees, shrubs, and fruit trees while they are dormant, when the branch structure is visible and the plant is under the least stress.',
  'meta' => 'Dormant-season pruning for trees, shrubs, and fruit trees in Delta and Montrose counties, plus which spring-blooming shrubs should wait until after bloom.',
  'body' => <<<'HTML'
<p>Most deciduous trees and shrubs are best pruned while they are dormant, after the leaves have dropped and before the buds open in spring. On the Western Slope that window runs through the winter months and into early spring.</p>

<h2>Why dormant season</h2>
<p>Without leaves, the full branch structure of a tree is visible. Crossing limbs, weak attachments, and dead wood are easy to identify, and cuts can be placed precisely. Insects and disease organisms are largely inactive in cold weather, so fresh cuts are less exposed than they would be in summer. When growth resumes in spring, the tree directs that growth into the branches that remain.</p>

<h2>Snow and wind</h2>
<p>Removing dead, weak, or poorly attached limbs before heavy snow reduces the chance of a branch failing onto a roof, fence, or driveway. Wet spring snow is often what brings down a limb that has been weak for years.</p>

<h2>Fruit trees</h2>
<p>Fruit trees are pruned while dormant to open the canopy to light and air, remove crossing and upright shoots, and keep the tree at a height that can be maintained and harvested. Late winter, before bud break, is the standard time for this work.</p>

<h2>What should not be pruned in winter</h2>
<p>Shrubs that bloom in spring, such as lilac and forsythia, set their flower buds the previous summer. Pruning them in winter removes this year's flowers. Those shrubs are pruned shortly after they finish blooming instead, and we will tell you which plants on your property fall into that group rather than prune them on the wrong schedule.</p>

<p>Summer work is covered under <a href="/services/landscape-lawn-care/pruning/summer-pruning">Summer Pruning</a>. Dormant pruning pairs well with a <a href="/services/landscape-lawn-care/spraying/dormant-oil">dormant oil</a> application in late winter.</p>
HTML,
];

$P['Landscape Upgrade'] = [
  'sub' => 'Renovating an existing landscape, one phase or all at once',
  'card' => 'Improving a landscape that already exists: replacing what no longer works, keeping what does, and planning the work in phases when that suits the budget.',
  'meta' => 'Renovating an existing landscape in Delta and Montrose counties: keeping what works, replacing what does not, and phasing larger work across seasons.',
  'body' => <<<'HTML'
<p>A landscape upgrade starts from what is already in the ground. Some of it is worth keeping, such as mature trees, a sound patio, or a working irrigation main. Some of it has reached the end of its life or no longer suits the way the property is used. The work is deciding which is which, and then replacing only what needs it.</p>

<h2>Common upgrades</h2>
<ul>
<li>Converting thirsty lawn areas to <a href="/services/landscaping/xeriscaping">xeriscape</a> or rock</li>
<li>Updating an old sprinkler system, including converting beds from spray heads to drip</li>
<li>Replacing overgrown or failing shrubs and trees</li>
<li>Adding a <a href="/services/landscaping/patios">patio</a>, walkway, or retaining wall</li>
<li>Adding <a href="/services/lighting/landscape-lighting">landscape lighting</a></li>
</ul>

<h2>How the work is planned</h2>
<p>We walk the property with you first and note what stays, what goes, and what each change involves. Larger upgrades can be organized into phases, with the work that has to come first, usually grading, drainage, and irrigation, scheduled before the work that depends on it. Each phase is priced separately, so the project can be completed over more than one season if that is the better fit.</p>

<p>For a full redesign rather than an update, see <a href="/services/landscaping/design">Landscape Design</a>.</p>
HTML,
];

$P['Pinyon Pine Ips Beetle'] = [
  'sub' => 'Preventive cover sprays for pinyon pine, three times a season',
  'card' => 'Ips beetles bore under the bark of pinyon pine and can kill a tree within a season. Prevention is the only treatment, applied as three cover sprays a year.',
  'meta' => 'Ips beetles can kill a pinyon pine within one season. Preventive cover sprays around Memorial Day, July 1, and September 1 in Delta and Montrose counties.',
  'body' => <<<'HTML'
<p>The pinyon ips beetle is a small bark beetle that bores into the trunk and branches of pinyon pine and breeds in the layer just beneath the bark. A heavy attack can kill a mature tree within a single season. Pinyon is common throughout Delta and Montrose counties, and ips beetle is one of the most frequent reasons healthy-looking pinyon die here.</p>

<h2>Prevention, not cure</h2>
<p>Once beetles are established under the bark of a tree, spraying will not save it. Treatment is preventive: the bark is protected before the beetles arrive.</p>

<h2>How we treat</h2>
<p>We apply a cover spray to the trunk and main branches three times a season: the first around Memorial Day, the second around July 1, and the third around September 1. That schedule covers the period when the beetles are flying and looking for trees to attack.</p>

<h2>Signs of an attack</h2>
<ul>
<li>Needles fading from green to yellow, then to red-brown, often starting at the top of the tree</li>
<li>Small holes in the bark, or reddish boring dust in bark crevices and at the base of the trunk</li>
<li>Small masses of pitch on the bark</li>
</ul>

<h2>Reducing the risk</h2>
<p>Ips beetles favor trees that are already stressed, especially by drought. Deep watering of pinyon during dry periods helps. Fresh-cut pinyon wood and branches should not be stacked near living trees, because beetles breed in the cut material and move from there to the standing trees.</p>

<p>All applications are made under Brookstone Outdoors' Colorado Department of Agriculture commercial pesticide applicator license.</p>
HTML,
];

$P['Dormant Oil'] = [
  'sub' => 'A late-winter spray that targets overwintering insect eggs',
  'card' => 'Horticultural oil applied before trees and shrubs leaf out, smothering insect eggs and overwintering pests before they can develop in spring.',
  'meta' => 'Dormant oil smothers overwintering insect eggs on trees and shrubs before bud break. Late-winter applications in Delta and Montrose counties.',
  'body' => <<<'HTML'
<p>Dormant oil is a horticultural oil applied to trees and shrubs in late winter or early spring, before the buds open. It coats the bark and twigs and smothers the insect eggs laid there the previous year. As temperatures rise in spring, the eggs begin taking in oxygen to develop; the oil prevents that, and the insects do not hatch.</p>

<h2>What it controls</h2>
<p>Dormant oil is most useful against pests that spend the winter on the plant as eggs or immature insects, including scale insects, mites, and aphid eggs. It is a common part of a fruit tree program and is also used on ornamental trees and shrubs.</p>

<h2>Timing and weather</h2>
<p>The application has to be made after the plant is fully dormant and before bud break, on a day warm enough for the oil to spread evenly, with no hard freeze expected immediately afterward. On the Western Slope that window usually falls in late winter or early spring, and the exact day depends on the weather.</p>

<h2>Where it is not used</h2>
<p>Oil strips the waxy coating that gives Colorado blue spruce its blue color. It is not applied to blue spruce, or to other plants known to be sensitive to it.</p>

<p>Dormant oil pairs well with <a href="/services/landscape-lawn-care/pruning/winter-pruning">winter pruning</a>. All applications are made under Brookstone Outdoors' Colorado Department of Agriculture commercial pesticide applicator license.</p>
HTML,
];

$P['Aspen Twig Gall'] = [
  'sub' => 'Round swellings on aspen twigs, reduced with a spring systemic treatment',
  'card' => 'Round galls on aspen twigs are caused by a small fly. They rarely threaten the tree, and a spring systemic treatment reduces how many form.',
  'meta' => 'Round galls on aspen twigs are caused by the poplar twig gall fly. A spring systemic treatment reduces how many form. Delta and Montrose counties.',
  'body' => <<<'HTML'
<p>The round, woody swellings that form on aspen twigs are caused by the poplar twig gall fly. The fly lays its eggs in new, tender growth in spring, and the twig forms a gall around the developing larva. The galls are unsightly, but they rarely threaten the life of the tree.</p>

<h2>What it does to a tree</h2>
<p>A few galls make little difference to a healthy aspen. Heavy infestations year after year can distort and stunt the affected branches, and on young trees and prominent landscape specimens that is usually the reason people choose to treat.</p>

<h2>How we treat</h2>
<p>We treat with a systemic pesticide in spring, which the tree carries into the new growth where the eggs are laid. Treatment does not always eliminate the galls. It does reduce the number that form, and that is the result to expect.</p>

<h2>What to look for</h2>
<ul>
<li>Round, woody swellings on the current or previous year's twigs</li>
<li>Small exit holes in older galls where the adult flies emerged</li>
</ul>

<p>All applications are made under Brookstone Outdoors' Colorado Department of Agriculture commercial pesticide applicator license.</p>
HTML,
];

$P['Pre-emergent'] = [
  'sub' => 'A spring barrier that stops weed seeds before they sprout',
  'card' => 'Pre-emergent herbicide stops weed seeds from establishing before they come up. It has to go down early in spring, and contracts are needed by March 1.',
  'meta' => 'Pre-emergent stops weed seeds before they sprout. It must go down early in spring; contracts after March 1 cannot be guaranteed an application that year.',
  'body' => <<<'HTML'
<p>Pre-emergent herbicide works on weed seeds rather than on weeds. Applied before the seeds germinate, it forms a barrier at the soil surface that stops new seedlings from establishing. It has no effect on weeds that are already growing, which are handled with <a href="/services/landscape-lawn-care/spraying/weed-control">post-emergent weed control</a>.</p>

<h2>Timing is the whole treatment</h2>
<p>Because it has to be in place before the seeds sprout, pre-emergent is applied early in spring. An application made after germination does very little. For that reason, contracts received after March 1 cannot be guaranteed an application in the current season.</p>

<h2>Moisture</h2>
<p>Pre-emergent needs moisture to move into the top layer of soil and form the barrier. Its effectiveness depends on that moisture arriving after the application, from irrigation or precipitation.</p>

<h2>What to expect</h2>
<p>Pre-emergent reduces the number of weeds that come up during the season. Complete eradication is not realistic. Some seeds will germinate outside the treated window or in areas the barrier did not fully reach, and those are treated as they appear.</p>

<p>All applications are made under Brookstone Outdoors' Colorado Department of Agriculture commercial pesticide applicator license.</p>
HTML,
];

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_THIN_APPLY=1 to write)\n\n";

// Guard: a marker must never reach a field.
foreach ($P as $n => $d) {
  foreach ($d as $k => $v) {
    if (strpos((string) $v, '[CONFIRM') !== FALSE) { print "ABORT — [CONFIRM marker in $n/$k\n"; return; }
    if (strpos((string) $v, '**') !== FALSE) { print "ABORT — markdown ** in $n/$k\n"; return; }
  }
  if (mb_strlen($d['meta']) > 158) { printf("ABORT — %s meta is %d chars.\n", $n, mb_strlen($d['meta'])); return; }
}
printf("✓ %d pages, no [CONFIRM markers, no markdown, every meta ≤158\n", count($P));

// Every internal link must resolve.
$pv = \Drupal::service('path.validator');
foreach ($P as $n => $d) {
  if (preg_match_all('~href="(/[^"]+)"~', $d['body'], $m)) {
    foreach (array_unique($m[1]) as $h) {
      if (!$pv->isValid($h)) { print "ABORT — $h does not resolve (in $n)\n"; return; }
    }
  }
}
print "✓ every internal link resolves\n";

// Resolve by URL, not by name. The office owns term names and marketing's
// "Landscape Upgrade" is live as "Upgrade" — keying on the name aborted the
// first run. A URL is the stable handle.
$URLS = [
  'Backflow Testing and Certification' => '/services/sprinkler-system/backflow-testing-and-certification',
  'Winter Pruning' => '/services/landscape-lawn-care/pruning/winter-pruning',
  'Landscape Upgrade' => '/services/landscaping/upgrade',
  'Pinyon Pine Ips Beetle' => '/services/landscape-lawn-care/spraying/pinyon-pine-ips-beetle',
  'Dormant Oil' => '/services/landscape-lawn-care/spraying/dormant-oil',
  'Aspen Twig Gall' => '/services/landscape-lawn-care/spraying/aspen-twig-gall',
  'Pre-emergent' => '/services/landscape-lawn-care/spraying/pre-emergent',
];
$am = \Drupal::service('path_alias.manager');
$byName = [];
foreach ($P as $n => $d) {
  if (!isset($URLS[$n])) { print "ABORT — no URL recorded for $n\n"; return; }
  $inner = $am->getPathByAlias($URLS[$n]);
  if ($inner === $URLS[$n]) { print "ABORT — {$URLS[$n]} does not resolve\n"; return; }
  $t = $etm->getStorage('taxonomy_term')->load((int) str_replace('/taxonomy/term/', '', $inner));
  if (!$t || $t->bundle() !== 'services') { print "ABORT — {$URLS[$n]} is not a services term\n"; return; }
  if ($t->label() !== $n) { printf("  note: \"%s\" is live as \"%s\" — using the URL, name left alone\n", $n, $t->label()); }
  $byName[$n] = $t;
}
printf("✓ %d of %d resolved by URL\n\n", count($byName), count($P));

$backup = []; $changed = 0;
foreach ($P as $name => $d) {
  $t = $byName[$name];
  $item = $t->get('field_service_public_desc')->first();
  $backup[$t->id()] = ['name' => $name, 'subtitle' => $t->get('field_subtitle')->value,
    'body' => $item ? $item->value : NULL, 'summary' => $item ? $item->summary : NULL,
    'meta' => $t->get('field_meta_tags')->value];
  $deltas = [];
  $oldBody = (string) ($item->value ?? '');
  if (trim($oldBody) !== trim($d['body']) || trim((string) ($item->summary ?? '')) !== trim($d['card'])) {
    $t->set('field_service_public_desc', ['value' => $d['body'], 'summary' => $d['card'], 'format' => 'full_html']);
    $deltas[] = sprintf('body %d→%d, card summary', mb_strlen($oldBody), mb_strlen($d['body']));
  }
  if (trim((string) ($t->get('field_subtitle')->value ?? '')) !== trim($d['sub'])) {
    $t->set('field_subtitle', $d['sub']); $deltas[] = 'subtitle';
  }
  $raw = (string) ($t->get('field_meta_tags')->value ?? '');
  $tags = $raw !== '' ? (json_decode($raw, TRUE) ?: []) : [];
  $want = ['description' => $d['meta'], 'og_description' => $d['meta']];
  if (array_intersect_key($tags, $want) != $want) {
    $t->set('field_meta_tags', json_encode(array_merge($tags, $want), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $deltas[] = 'meta';
  }
  if (!$deltas) { printf("  %-36s unchanged\n", $name); continue; }
  printf("  %-36s %s\n", $name, implode(', ', $deltas));
  $changed++;
  if ($apply) { $t->save(); Cache::invalidateTags(['taxonomy_term:' . $t->id()]); }
}

print "\nHELD — not pasted, because they carry a [CONFIRM marker and are LIVE pages.\n";
print "Unpublishing a working page to stage held copy would be worse than leaving it thin.\n";
foreach ($HELD as $n => $q) { printf("  %-32s %s\n", $n, $q); }

if ($apply) {
  $f = '/tmp/thin_pages_backup_' . date('Ymd_His') . '.json';
  file_put_contents($f, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  print "\nPrevious values backed up to $f\n";
}
printf("\n%d of 11 pages updated%s. 4 held.\n", $changed, $apply ? '' : ' (dry-run — nothing written)');
