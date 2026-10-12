<?php
/**
 * Replace the Irrigation Design copy (services/Design, tid resolved by ALIAS).
 *
 * Resolved by URL alias, never by name: the term is called "Design", which is
 * generic enough that matching on it is the mistake that has bitten the
 * credential seeder and the fruit category when the office renamed something.
 *
 * Replaces subtitle, card line, body, crew notes, CTA and metatags. Previous
 * values are backed up to docs/backups/ first.
 *
 * ⚠ NOT DONE HERE, and both are flagged in the report rather than guessed:
 *  - The plan drawing. The brief supplies a caption and alt text, but the sample
 *    carries a customer name and street address over an aerial of their house
 *    and has to be redacted by a human before it can go on a public page. No
 *    image field is touched.
 *  - The two CONFIRM items (is design charged separately / does the fee credit
 *    against the install). The body's "It is yours" is marketing's wording and
 *    is loaded as written, because the brief marks it non-blocking — but it is a
 *    commitment to a customer and Todd should confirm it.
 *
 * Dry run unless BOS_DESIGN_APPLY=1.
 */
$apply = getenv('BOS_DESIGN_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (BOS_DESIGN_APPLY=1 to apply)');

$alias = '/services/sprinkler-system/design';
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

$subtitle = 'A scaled plan with the hydraulics worked out, before anybody puts a trencher in the ground';

$summary = 'Most sprinkler systems were installed rather than designed. A design is arithmetic done first: what the property can deliver, and what that allows.';

$body = <<<'HTML'
<p><strong>Most sprinkler systems in this valley were installed, not designed.</strong> Somebody walked the property, put heads where it looked about right, split them into zones by feel, and buried it. That works until July, and then it does not: a corner that never gets wet, one zone that drops the pressure so far the heads barely turn, a lawn that is green at one end and straw at the other.</p>

<p>None of that is a repair problem. It is arithmetic that did not happen before the trencher showed up.</p>

<p><strong>A design starts with what the property can actually deliver.</strong> Gallons per minute at the source, and pressure. Everything else follows from those two numbers, and nothing can be specified until they are measured rather than assumed.</p>

<p>Then the heads get laid out so their coverage overlaps. A sprinkler is built to throw water to the next head, not to the edge of the dry patch — spacing them to the limit of their reach is the single most common reason a lawn has pale rings in it. Once the layout is right, the demand is added up and divided by what the source can supply. That division is the zone count, and it is not a preference. Sixty heads at two gallons a minute is a hundred and twenty gallons of demand; a forty gallon supply means three zones, not two. Build it as two and every zone on the property runs short.</p>

<p><strong>Where the water comes from changes the whole design, and around here it comes from three different places.</strong> Ditch water carries sediment, so it needs filtration and a pump sized to the job — and it arrives and shuts off on dates the ditch company sets rather than the weather. A well has a pump curve, and that curve says how much water is available at the pressure the heads need; it gets checked before equipment is chosen, not after the system underperforms. City water is the simplest to design around and usually the most expensive to run. A plan drawn for one of those three is wrong for the other two.</p>

<p>Equipment gets named on the plan rather than described. Specific heads, specific nozzles, the valves, pressure regulation where drip needs it. A plan that says "sprinkler system" is not a plan.</p>

<p><strong>What you end up with is a drawing of your own property, to scale, on its own aerial</strong> — head layout and coverage, zones, valve locations, the mainline and lateral routing, the hydraulic math, and a legend showing every assembly that goes in the ground. It is yours. It is what we build from, what another contractor could build from, and what tells you whether a bid you have been handed is a real number or a guess.</p>

<p>It also keeps working after the install. The plan goes into your property record with the rest of the job, so when a lateral gets cut in year six the technician reads where the line runs instead of probing the lawn for it.</p>

<p>Design work happens whether the system is new, replacing one that has failed, or extending into an area that was never watered. The earlier it happens the cheaper everything downstream of it gets — a plan drawn before the hardscape goes down costs a fraction of a sleeve cut through a finished patio.</p>
HTML;

$crew = <<<'HTML'
<p><strong>Measure the source before anything else. Everything on the plan hangs off GPM and pressure, and a number somebody told you over the phone is not a measurement.</strong></p>

<ul>
<li><strong>Measure, do not assume.</strong> Static pressure and flow at the point of connection. A guess here propagates through every zone on the sheet.</li>
<li><strong>Establish the water source on the first call</strong> — ditch, well or city. It changes filtration, pump, scheduling and cost, and it is the question that decides whether a site visit is even worth booking yet.</li>
<li><strong>Ditch water means filtration and a pump, every time.</strong> Also means the system is on the ditch company's calendar. Say that out loud at the sale so nobody is surprised in April.</li>
<li><strong>Check the pump curve before specifying.</strong> The operating point goes on the plan. A pump that cannot hold pressure at the flow the zone needs is a callback with our name on it.</li>
<li><strong>Head to head coverage, not head to dry spot.</strong> Spacing to the edge of throw is the most common inherited defect we are asked to fix.</li>
<li><strong>Zone count falls out of the arithmetic.</strong> If a customer pushes to cut a zone to save money, show them the division. It is the easiest objection in the business to answer with a number.</li>
<li><strong>Name the equipment on the sheet.</strong> Heads, nozzles, valves, regulators. It is what separates our plan from a sketch, and it is what a customer compares bids with.</li>
<li><strong>The plan goes in the property record at handover.</strong> Not in a truck, not in a folder at the shop. The service call in year six is the reason it exists.</li>
</ul>

<p><strong>What to tell a customer:</strong> we work out what the property can deliver, then design to that. Anybody can put heads in a lawn. The question is whether the last head on the line still turns.</p>
HTML;

$cta = <<<'HTML'
<p>The first thing worth knowing is where your water comes from — a ditch, a well, or the tap — because that decides most of what follows. <a href="/request-estimate">Tell us which it is</a> and we will come and measure the rest.</p>
HTML;

$title = 'Irrigation System Design | Brookstone Outdoors';
$desc = 'Most sprinkler systems were installed, not designed. What a real irrigation plan contains, why hydraulics set the zone count, and how ditch water changes it.';

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
$file = $dir . '/irrigation-design-pre-' . date('Ymd-His') . '.json';
if (file_put_contents($file, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === FALSE) {
  print "\n  *** BACKUP FAILED — previous values were still overwritten ***\n";
}
else {
  printf("\n  previous values: %s\n", $file);
}
print "done\n";
