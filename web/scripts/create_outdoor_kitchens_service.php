<?php
/**
 * Create the Outdoor Kitchens service under Landscaping.
 *
 * Modelled on Patios, its stated sibling: not a work-order service
 * (field_work_order_service = 0), field_service_bundle = landscaping,
 * department 1.
 *
 * The URL comes free — the `services_paths` pathauto pattern is
 * /[term:parent:url:path]/[term:name], so creating it under Landscaping
 * produces /services/landscaping/outdoor-kitchens.
 *
 * Weight 4 puts it immediately after Patios (3). It ties with Retaining Walls,
 * which the child-list view breaks by name, and "Outdoor Kitchens" sorts before
 * "Retaining Walls" — so it lands where it should without renumbering eight
 * siblings for one insert.
 *
 * field_list_order is deliberately left EMPTY. The siblings' values are already
 * inconsistent (three pairs of duplicates) and no view that lists these terms
 * sorts by it — the child list sorts by weight then name, checked — so a made-up
 * number would be noise.
 *
 * Idempotent: a second run reports the existing term and writes nothing.
 * Dry run unless BOS_OK_APPLY=1.
 */
$apply = getenv('BOS_OK_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (BOS_OK_APPLY=1 to apply)');

$etm = \Drupal::entityTypeManager();
$ts = $etm->getStorage('taxonomy_term');

$parent = $ts->load(364);
if (!$parent || $parent->label() !== 'Landscaping') {
  printf("  ABORT: tid 364 is not Landscaping (got: %s)\n", $parent ? $parent->label() : 'nothing');
  return;
}

$existing = $ts->loadByProperties(['vid' => 'services', 'name' => 'Outdoor Kitchens']);
if ($existing) {
  $t = reset($existing);
  printf("  already exists: tid %d at %s — nothing written\n", $t->id(), $t->toUrl()->toString());
  return;
}

// The body cross-links Patios; refuse rather than publish a dead link.
$patios = $ts->loadByProperties(['vid' => 'services', 'name' => 'Patios']);
$patiosAlias = $patios ? reset($patios)->toUrl()->toString() : '';
if ($patiosAlias !== '/services/landscaping/patios') {
  printf("  ABORT: the Patios cross-link in the body resolves to '%s', not /services/landscaping/patios\n", $patiosAlias);
  return;
}
print "  Patios cross-link resolves\n";

$subtitle = 'A masonry and plumbing project with a grill in it, built for a climate that freezes';

$summary = 'A masonry and plumbing project with a grill in it. What actually gets used, why the water lines decide its lifespan, and where the smoke ends up.';

$body = <<<'HTML'
<p>An outdoor kitchen is a masonry structure with utilities running through it that happens to have a grill in it. Almost everything that goes wrong with one is a masonry problem or a water problem, and both are settled long before anybody picks a countertop.</p>

<p><strong>Start with what actually gets used, because most outdoor kitchens are built larger than the way they end up being lived in.</strong> The things people reach for every single time are a grill, a stretch of counter beside it to set a platter down, somewhere to put a drink, and enough light to cook by once the sun is gone. Those four are the kitchen. Sinks and refrigerators get used less often than anybody expects, they carry the freeze risk below, and they are where the budget goes. We would rather build those four properly than six things adequately.</p>

<p><strong>Water is the one thing that will end an outdoor kitchen here.</strong> Any supply line holding water through a hard freeze splits, and a line buried inside a masonry island is not something anybody reaches with a shovel in March. So the sink gets built to be closed down easily — supply that drains, a trap somebody can get at — and we shut it down each fall with the same rig that blows out sprinkler systems. Supply lines clear with air; a trap holds water by design and takes RV antifreeze instead. None of that is a reason to skip the sink. It is the reason the sink still works in year ten.</p>

<p><strong>Then the wind, which is really the siting decision.</strong> Grill smoke goes where the wind takes it, and on this side of the valley the wind has a direction. A grill placed without thinking about that puts smoke across the seating every evening, and a patio like that does not get used twice. The same exposure that carries the smoke is what makes a west-facing kitchen unusable on a July evening at this elevation, which is exactly when somebody wanted to be out there. We stand on the ground and work out where the wind comes from and where the sun sets before anything gets laid out.</p>

<p><strong>What goes underneath decides whether it is still square in ten years.</strong> An island is a rigid masonry structure sitting on a slab, and if the slab moves the countertop cracks and the doors stop closing. That means excavating to depth, compacting the base in lifts, and getting water away from underneath — the same work that keeps a <a href="/services/landscaping/patios">patio</a> flat, held to a higher standard because what is sitting on this one does not flex.</p>

<p>Surfaces have to survive the same winter. Granite and properly sealed concrete handle freeze and thaw and the UV we get at this elevation. Tile is the one to be careful about: the tile itself is usually fine and the grout is what fails, because water gets into it, freezes, and lifts it out joint by joint over a few seasons. Worth knowing before choosing a surface for how it looks in a photograph.</p>

<p>And the utilities go in first. Gas, water, power and a drain all have to be run before the hardscape is set, and any one of them added afterwards means cutting a finished patio apart. That is the most avoidable expense in this kind of work, and it is decided on the first walk rather than in the middle of the build. Most of this work is completed by our own crews. When a project requires work outside our licensing—such as installing a new electrical circuit—we coordinate with an appropriately licensed professional.</p>
HTML;

$crew = <<<'HTML'
<p><strong>The two questions that decide the whole job are asked standing in the yard: where does the wind come from, and what is the water doing.</strong> Everything else is a preference.</p>

<ul>
<li><strong>Talk them down in scope before you talk them up in budget.</strong> Grill, counter, drink surface, light. A customer who gets those four right is happy; one who gets six things on a stretched budget is not. This is the rare upsell that is worth refusing.</li>
<li><strong>Any water in the island has to drain and the trap has to be reachable.</strong> Supply clears with air; a trap takes RV antifreeze. If the shutoff is awkward to get at it will not get done — and put the kitchen on the fall blowout route at handover.</li>
<li><strong>Stand where the seating will be and work out where the smoke goes.</strong> Then move the grill. Smoke across the table is the complaint you cannot fix afterwards.</li>
<li><strong>West-facing at this elevation is brutal at dinner time.</strong> Raise shade at design, not after they have stopped using it.</li>
<li><strong>Every sleeve and conduit goes in before the hardscape.</strong> Gas, water, power, drain, and anything a future shade structure needs footings for. Cutting a finished patio apart is the call nobody wants to make.</li>
<li><strong>Base and compaction to the same standard as a patio or better.</strong> The island does not flex, so movement shows up as a cracked top rather than a settled paver.</li>
<li><strong>Steer away from tile unless they insist and understand the grout.</strong> Then put it on the estimate so the conversation in year four is a reminder rather than an argument.</li>
<li><strong>Use the approved licensing line, not your own version.</strong> We hold no electrical license and the public copy says so plainly. Never imply otherwise on a site visit.</li>
</ul>

<p><strong>What to tell a customer:</strong> this is a masonry and plumbing job with a grill in it. Get the base, the water and the wind right and it lasts twenty years. Get those wrong and no appliance saves it.</p>
HTML;

$values = [
  'vid' => 'services',
  'name' => 'Outdoor Kitchens',
  'parent' => [364],
  'weight' => 4,
  'status' => 1,
  'field_subtitle' => $subtitle,
  'field_service_public_desc' => ['value' => $body, 'summary' => $summary, 'format' => 'full_html'],
  'field_service_crew_desc' => ['value' => $crew, 'format' => 'full_html'],
  // Mirrors Patios: a design-build sub-service, not a work-order service.
  'field_work_order_service' => 0,
  'field_service_bundle' => 'landscaping',
  'field_department' => 1,
  'field_meta_tags' => json_encode([
    'title' => 'Outdoor Kitchens & Grill Islands | Brookstone',
    'description' => 'Outdoor kitchens in Delta and Montrose counties. Why the water lines decide the lifespan, what actually gets used, and why wind is the siting decision.',
    'og_description' => 'Outdoor kitchens in Delta and Montrose counties. Why the water lines decide the lifespan, what actually gets used, and why wind is the siting decision.',
  ]),
];

printf("  creating:  %s under %s (weight 4, after Patios)\n", $values['name'], $parent->label());
printf("  subtitle:  %d chars\n", strlen($subtitle));
printf("  card line: %d chars\n", strlen($summary));
printf("  body:      %d chars\n", strlen($body));
printf("  crew copy: %d chars\n", strlen($crew));

if (!$apply) {
  print "\nnothing written\n";
  return;
}
$term = $ts->create($values);
$term->save();
printf("\n  created tid %d at %s\n", $term->id(), $term->toUrl()->toString());
print "done\n";
