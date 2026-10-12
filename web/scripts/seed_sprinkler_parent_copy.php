<?php
/**
 * Replace the Sprinkler Systems PARENT page copy (tid resolved by ALIAS).
 *
 * Todd's brief, 12 October: more optimistic, push the benefits of owning a
 * system, lead into the child services, keep the history. Marketing's version
 * keeps both history paragraphs and makes them argue — Lessler's own stated
 * reason for the 1871 patent, a lawn watered "without requiring the constant
 * attention of an attendant", is what a customer is still buying.
 *
 * Replaces subtitle, card line, body, crew notes, CTA and metatags. Previous
 * values backed up to docs/backups/ first.
 *
 * Resolved by URL alias, never by name.
 *
 * ⚠ The body NAMES the child services rather than linking them all — the child
 * card list renders directly below it, so the names are one click away either
 * way, and marketing only links paths it has verified. The one link in the copy
 * is /request-estimate in the CTA, and the script reports it.
 *
 * Dry run unless BOS_SSPARENT_APPLY=1.
 */
$apply = getenv('BOS_SSPARENT_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (BOS_SSPARENT_APPLY=1 to apply)');

$alias = '/services/sprinkler-system';
$path = \Drupal::service('path_alias.manager')->getPathByAlias($alias);
if (!preg_match('#/taxonomy/term/(\d+)#', $path, $m)) {
  printf("  ABORT: %s does not resolve to a term (got %s)\n", $alias, $path);
  return;
}
$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($m[1]);
if (!$term || $term->bundle() !== 'services') {
  print "  ABORT: that is not a services term.\n";
  return;
}
printf("  term: %s (tid %d)\n\n", $term->label(), $term->id());

$subtitle = 'Water on the days you are not thinking about it, where it is needed and nowhere else';

$summary = 'The yard gets watered whether or not you are home, at the hour water soaks in, in measured amounts. It is what makes a real landscape possible here.';

$body = <<<'HTML'
<p>In December of 1871 a man named Joseph Lessler in Buffalo, New York patented what the Patent Office recorded as an <em>Improvement in lawn-sprinklers</em>. It was a stand, a nozzle and a length of hose running to the hydrant. The reason he gave for building it is the part worth reading: he wanted a way to water a lawn <em>"without requiring the constant attention of an attendant."</em></p>

<p><strong>A hundred and fifty years later that is still the whole proposition.</strong> Not a greener lawn — a lawn that gets watered on the Tuesday you are in Grand Junction, the week you are on holiday, and the stretch in August when it is a hundred degrees and nobody wants to be standing in the yard holding a hose.</p>

<p><strong>And a system waters better than a person does, which is the part people do not expect.</strong> It runs before dawn, when water goes into the soil instead of evaporating off it and when foliage has all morning to dry — which is most of what keeps leaf disease out of a lawn. It runs for a measured number of minutes rather than until somebody remembers to move the hose. And it puts water where the plant uses it: drip at the root of a shrub bed, spray on turf, nothing at all on the walk.</p>

<p>That is also why a properly designed system uses <em>less</em> water than hand watering, not more. A hose runs at whatever it runs at for as long as you forget about it. A zone runs for eleven minutes and shuts off.</p>

<p><strong>Zones are the other half of it.</strong> A lawn, a shrub bed, a row of young trees and a vegetable garden want four different amounts of water on four different schedules. One hose gives them all the same thing, which means something on the property is always either drowning or dry. Separating them is the difference between a yard that mostly works and one that works everywhere.</p>

<p>On this ground it is also what makes a real landscape possible at all. High desert, alkaline soil, a hundred and fifty frost-free days and a July that takes water out of the ground faster than anything puts it back — without irrigation the list of things that will live here is short and tough. With it, you can have the yard you actually wanted, and the planting and hardscape you paid for is protected rather than watched.</p>

<p>And if your water comes off a ditch, a system is how you use the share you are entitled to. Ditch water arrives on the ditch company's schedule rather than the weather's; a pump, filtration and storage turn an allocation you might otherwise miss into water on the property when the plants need it.</p>

<p><strong>The clicking you hear across a hayfield in July is the other half of the history.</strong> Sixty years after Lessler, a citrus grower in Glendora, California named Orton Englehart worked out the impact drive — a spring-loaded arm that knocks the head around in a circle, one click at a time, covering ground no fixed nozzle could reach, and doing it on water pressure instead of a motor. He sold the design to Clem and Mary LaFetra, who put it on the market as Rain Bird. We still specify those heads, and you can still hear his invention from the road.</p>

<p><strong>A sprinkler system is something you own for twenty years, so we look after the whole of it.</strong> It gets designed before anything is trenched, installed, started up each spring and the backflow assembly tested while the system is charged, checked mid-season when the heat arrives, repaired when a lateral gets cut or a valve sticks, and blown out each fall before the ground freezes. Seven points of contact across the life of a system, all from the same company, working from the plan we drew for your property. The pages below cover each of them.</p>
HTML;

$crew = <<<'HTML'
<p><strong>Irrigation touches every other department, which makes the irrigation crew the best set of eyes in the company.</strong> You are on properties that nobody else visits between April and October.</p>

<ul>
<li><strong>Sell the whole life of the system, not the visit you are on.</strong> A customer who has us for startup and nothing else is one freeze away from a repair they blame on us. Startup, backflow test, mid-season check and blowout is the set.</li>
<li><strong>Book the backflow test with the spring startup.</strong> Same trip, system already charged. It cannot be done at blowout, and explaining that in April is easier than in October.</li>
<li><strong>Teach the controller at handover.</strong> A system run on the wrong schedule performs like a bad system, and the customer blames the hardware. Five minutes at the panel prevents a season of complaints.</li>
<li><strong>Deep and less often, every property, every time.</strong> Daily shallow cycles are the most common setting we have to correct.</li>
<li><strong>Flag what you see.</strong> You are looking at lawns, beds and trees nobody else sees mid-season. A failing tree, a bed going backwards, a patio heaving — pass it on.</li>
<li><strong>Ditch properties need the filtration conversation every spring.</strong> Sediment is the leading cause of nuisance callbacks on those systems.</li>
<li><strong>The plan is the asset.</strong> Work from it, mark it up when something changes, and get the change back into the property record. A plan that is six years out of date is worse than none.</li>
</ul>

<p><strong>What to tell a customer:</strong> the system is not the point. Water arriving where it is needed, on the days nobody is thinking about it, is the point. Everything we do to it serves that.</p>
HTML;

$cta = <<<'HTML'
<p>Whether you are putting in a system, inheriting one that somebody else buried, or just want the one you have to stop surprising you, the first step is the same — we come out and look at what is actually there. <a href="/request-estimate">Ask us to take a look</a>.</p>
HTML;

$title = 'Sprinkler Systems & Irrigation | Brookstone Outdoors';
$desc = 'Water on the days you are not thinking about it. Why a designed system uses less water than a hose, what zones solve, and the seven services behind one.';

$backup = [];
$report = function (string $f, string $old, string $new) {
  printf("  %-28s %5d -> %5d chars\n", $f, strlen($old), strlen($new));
};

// Subtitle.
$backup['field_subtitle'] = (string) $term->get('field_subtitle')->value;
$report('field_subtitle', $backup['field_subtitle'], $subtitle);

// Public description: body + the card summary.
$item = $term->get('field_service_public_desc')->first();
$backup['field_service_public_desc'] = ['value' => $item ? (string) $item->value : '', 'summary' => $item ? (string) $item->summary : ''];
$report('field_service_public_desc', $backup['field_service_public_desc']['value'], $body);
$report('  …summary (card line)', $backup['field_service_public_desc']['summary'], $summary);

$backup['field_service_crew_desc'] = (string) $term->get('field_service_crew_desc')->value;
$report('field_service_crew_desc', $backup['field_service_crew_desc'], $crew);
if ($backup['field_service_crew_desc'] === $backup['field_service_public_desc']['value']) {
  print "    (the crew copy was byte-identical to the public body — replacing both fixes that)\n";
}

$backup['field_call_to_action'] = (string) $term->get('field_call_to_action')->value;
$report('field_call_to_action', $backup['field_call_to_action'], $cta);

// The old copy carried a dead property-photo URL; say whether it is going.
foreach (['public' => $backup['field_service_public_desc']['value'], 'crew' => $backup['field_service_crew_desc']] as $k => $v) {
  if (preg_match_all('#<a[^>]+href="([^"]+)"#', $v, $mm)) {
    foreach ($mm[1] as $href) {
      printf("    old %s copy linked: %s  (removed)\n", $k, $href);
    }
  }
}

$rawTags = (string) $term->get('field_meta_tags')->value;
$tags = $rawTags !== '' ? (json_decode($rawTags, TRUE) ?: []) : [];
if ($rawTags !== '' && !$tags) {
  print "  ABORT: metatags are in an unrecognised format — not JSON.\n";
  return;
}
$backup['field_meta_tags'] = $tags;
$tags['title'] = $title;
$tags['description'] = $desc;
$tags['og_description'] = $desc;
print "  field_meta_tags              title + description set (merged)\n";

if (!$apply) {
  print "\nnothing written\n";
  return;
}

$term->set('field_subtitle', $subtitle);
$term->set('field_service_public_desc', ['value' => $body, 'summary' => $summary, 'format' => 'full_html']);
$term->set('field_service_crew_desc', ['value' => $crew, 'format' => 'full_html']);
$term->set('field_call_to_action', ['value' => $cta, 'format' => 'full_html']);
$term->set('field_meta_tags', json_encode($tags));
$term->save();

$dir = DRUPAL_ROOT . '/../docs/backups';
@mkdir($dir, 0775, TRUE);
$file = $dir . '/sprinkler-parent-pre-' . date('Ymd-His') . '.json';
if (file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === FALSE) {
  print "\n  *** BACKUP FAILED — previous values were still overwritten ***\n";
}
else {
  printf("\n  previous values: %s\n", $file);
}
print "done\n";
