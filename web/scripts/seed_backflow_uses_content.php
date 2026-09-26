<?php

declare(strict_types=1);

/**
 * Populate all 14 backflow_uses terms with the authoritative marketing copy
 * ("Backflow Uses - 14 Terms ALL FIELDS.md", rebuilt 2026-09-26):
 *   field_list_order         (int)   landing-card order
 *   field_short_description   (text)  landing-card teaser
 *   field_public_description  (html)  term page body            [full_html]
 *   field_teammate_description(html)  teammate_view only        [full_html]
 *   field_meta_tags           (json)  title / description / og:description
 *
 * Terms are matched by the stable field_use_code (env-independent — tids differ
 * per env). Values are the authored copy and are written verbatim (this is the
 * source of record for these pages), so the script is idempotent and safe to
 * re-run. full_html is used on the two body fields because the copy carries
 * <h3>/<ul>/<a class="button"> that basic_html would strip.
 *
 *   drush php:script web/scripts/seed_backflow_uses_content.php
 */

$BODY_FORMAT = 'full_html';
$SHORT_FORMAT = 'basic_html';

$DATA = [];

$DATA['IRRIGATION'] = [
  'order' => 10,
  'short' => 'The most common backflow connection on any residential property, and the reason most homeowners have an assembly at all. The risk is not the water you put in — it is the fertilized, sprayed, animal-walked soil the heads are sitting in when pressure drops.',
  'public' => <<<'HTML'
<p>An irrigation system is the most common backflow connection on a residential property, and it is the reason most homeowners have an assembly at all.</p>

<p>The risk is not the water you put into the system. It is what the system is sitting in. Sprinkler heads sit at ground level in soil that has been fertilized, sprayed, walked on by animals and soaked by whatever ran across it. Valve boxes hold standing water. A cracked lateral pulls in whatever is around it. When pressure drops in the main, all of that has somewhere to go.</p>

<p>Most irrigation systems can only backflow by suction rather than pressure, which is why vacuum breakers are common on them — and why the assembly has to sit above the highest head on the property to work at all. Systems with a booster pump are a different case, because a pump can push.</p>

<p>This is the connection we work on most. Irrigation assemblies sit outside the foundation, so we handle them end to end — test, repair, replace and install. The test is done in the spring with the system charged, which makes it the same trip as your startup.</p>

<p>Which assembly your provider will accept depends on their cross-connection control program and the code in force where you are. <a href="/services/backflow-prevention">The seven device types we see on the Western Slope</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>On every irrigation service call</h3>
<ul>
  <li><strong>Check the assembly height against the highest head on the property.</strong> This is the most common defect we find and it is invisible unless somebody looks. A head uphill of the assembly is not protected. Report it — relocation is legitimate work, not a nitpick.</li>
  <li><strong>Look for a booster pump.</strong> A pump anywhere on the system means a vacuum breaker is the wrong device for the connection, whatever is currently installed.</li>
  <li><strong>Note the freeze exposure.</strong> Brass full of standing water in the open. If the property is not on a winterization route, that is the note to leave.</li>
  <li><strong>No device record? Make one.</strong> Make, model, serial, size, location, photos.</li>
</ul>
<h3>The scheduling point</h3>
<p>The annual test needs a charged system, so it happens at spring startup and not at the fall blowout. Same trip, two services. If a customer asks for the test in October, explain why it does not work and book the startup instead.</p>
HTML,
  'title' => 'Sprinkler System Backflow Preventer | Delta & Montrose CO',
  'desc' => 'Why every sprinkler system needs a backflow assembly, which type usually goes on yours, and certified annual testing across Delta and Montrose counties.',
  'og' => 'The most common backflow connection on any property — and the reason most homeowners have an assembly at all.',
];

$DATA['HOSE_BIBB'] = [
  'order' => 20,
  'short' => 'The outdoor tap is the most-used cross-connection on most properties and the one nobody thinks about. A hose in a flower bed, a sprayer with chemical in it, an end sitting in a stock tank. Protection costs almost nothing. Most properties built before the code required it do not have any.',
  'public' => <<<'HTML'
<p>The most-used cross-connection on most properties is the outdoor tap, and it is the one nobody thinks about.</p>

<p>A hose lying in a flower bed, a garden sprayer with chemical in it, a hose end sitting in a bucket or a stock tank — every one of those is a direct path from something unpleasant into the house supply, and all it takes is a pressure drop somewhere on the street while the hose is connected. It is the simplest backflow scenario there is and by a wide margin the most common.</p>

<h3>What goes on it</h3>

<p>Most hose connections are handled by a <a href="/services/backflow-prevention/hose-bibb-vacuum-breaker-hbvb">hose bibb vacuum breaker</a> — the short brass fitting that threads on between the tap and the hose. It costs very little, it goes on in about a minute, and it covers the ordinary case.</p>

<p>It has real limits. It stops suction but does nothing about a pump on the other end, and it is not a testable assembly, so it will not satisfy a provider that requires certified annual testing on the connection. Where either of those applies, the tap steps up to a <a href="/services/backflow-prevention/pressure-vacuum-breaker-pvb">pressure vacuum breaker</a> or a <a href="/services/backflow-prevention/reduced-pressure-rp">reduced pressure assembly</a>.</p>

<h3>Wall hydrants</h3>

<p>A wall hydrant is the same connection in colder packaging. The frost-free ones run the valve seat back inside the heated wall, and many are sold with an integral vacuum breaker in the head. Many are also twenty years old with that breaker long since seized, which is worth a look rather than an assumption.</p>

<p>The issue on any of this is almost never cost. It is that people do not know the connection counts.</p>

<p><strong>Scope on this one depends on where the tap is.</strong> An exterior sillcock or wall hydrant we handle end to end — test, repair, replace, install. A hose connection inside the building is test and repair only, because replacement inside the foundation is licensed plumbing work. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Get a Free Estimate</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Walk the perimeter</h3>
<p>Count the outdoor taps. On most properties built before the code required it, none of them have a breaker. It takes a minute to see and a minute to fix.</p>
<ul>
  <li><strong>Missing entirely</strong> — by far the most common finding.</li>
  <li><strong>Dripping from the vent under pressure</strong> — the seal is gone, replace it.</li>
  <li><strong>A pump on the other end</strong> — a transfer pump, a pressure washer, a sprayer. The breaker does not cover that connection and the tap needs a real assembly.</li>
  <li><strong>Frost-free wall hydrant</strong> — many have an integral breaker in the head, and many of those have been seized for years. Look rather than assume.</li>
</ul>
<h3>Fix it standing there</h3>
<p>If there is one on the truck, put it on. A work order for a four dollar part costs more to process than the part, and the customer is protected today.</p>
<p>Device records: commercial and high-hazard properties yes, residential no — a house can have six and nobody needs that list.</p>
HTML,
  'title' => 'Hose Bibb Backflow Preventer for Outdoor Taps | Colorado',
  'desc' => 'The outdoor tap is the most common cross-connection on any property. What a hose bibb vacuum breaker does, and when the tap needs more than one.',
  'og' => 'A hose in a flower bed is a direct path into the house supply. Protection costs almost nothing.',
];

$DATA['DOMESTIC'] = [
  'order' => 30,
  'short' => 'One assembly on the service line, protecting the public main from everything inside the building. That is containment — and it protects the street, not the drinking fountain down the hall. What is inside the building decides whether containment alone is enough.',
  'public' => <<<'HTML'
<p>Some properties are protected at the point the water enters the building rather than at each individual fixture. That is containment — one assembly on the service line, protecting the public main from everything inside.</p>

<p>Which approach a provider requires depends on what is in the building and how much of it they can inspect. A straightforward commercial building may be handled at the service line. A building with a boiler, a kitchen and a lab in it will usually need protection at the service line <em>and</em> at the individual connections inside, because containment protects the street but does nothing for the drinking fountain down the hall.</p>

<p>The hazard classification of the building as a whole is what sets the device.</p>

<p><strong>Scope on this one:</strong> we test and repair. Assemblies inside the building are not ours to replace — that is licensed plumbing work — but a failure is far more often a rebuild than a replacement, and rebuilds we do on the spot. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>What you are assessing</h3>
<p>Containment — one assembly on the service line protecting the public main from everything inside. The question is never just the assembly. It is whether containment alone is enough for what is in the building.</p>
<ul>
  <li><strong>Walk the building before you judge the service line.</strong> A boiler, a kitchen, a lab or an irrigation system inside means the property needs protection at the service line <em>and</em> at those connections.</li>
  <li><strong>Containment protects the street, not the tenant.</strong> A DCVA at the service line does nothing for the drinking fountain down the hall. Say that plainly when a property manager assumes one assembly covers the building.</li>
  <li><strong>Vault installs:</strong> pump it down first, never alone, stop and call if anything seems off.</li>
</ul>
<h3>Scope</h3>
<p>Inside the foundation is test and repair only. A body that is finished is licensed plumbing work — tell them what it has to be replaced with and test the new one the day it is in.</p>
HTML,
  'title' => 'Building Supply Backflow Prevention | Delta & Montrose CO',
  'desc' => 'How containment protects the public main from everything inside a building, when it is not enough on its own, and certified testing in Delta and Montrose.',
  'og' => 'One assembly on the service line protects the street. It does nothing for the drinking fountain down the hall.',
];

$DATA['AGRICULTURAL'] = [
  'order' => 40,
  'short' => 'Stock tanks, waterers and ag supply lines are health hazard connections and are treated that way. A float valve sitting in a tank animals drink from is a direct path. On the Western Slope there is a second wrinkle: a lot of ag property has both domestic and ditch water, and where those two can meet is the whole point.',
  'public' => <<<'HTML'
<p>Stock tanks, livestock waterers and agricultural supply lines are health hazard connections and are treated that way. A float valve sitting in a tank that animals drink from is a direct path, and the tank is not clean.</p>

<p>Agricultural connections also tend to involve chemical injection at some point — medicators, supplements, or a sprayer filled from the same line. Once any of that is on the system the classification is settled.</p>

<p>On the Western Slope there is a second wrinkle. A lot of ag property has both a domestic line and a ditch or raw water supply, and the point where those two can meet is exactly what cross-connection control exists to prevent. If a property has both, the separation between them matters more than any single device.</p>

<p>These connections sit outside the foundation, so we handle them end to end — test, repair, replace and install. It is also the work we know best, because it is the same water and the same ditch systems our irrigation crews are in all season.</p>

<p>Which assembly your provider will accept depends on their cross-connection control program and the code in force where you are. <a href="/services/backflow-prevention">The seven device types we see on the Western Slope</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>What to look at first</h3>
<ul>
  <li><strong>Float valves sitting below the water line in the tank.</strong> That is a submerged inlet and a direct path from a tank animals drink out of into the supply. Extremely common, and it is a health hazard connection.</li>
  <li><strong>Medicators, supplement injectors, or a sprayer filled from the same line.</strong> Any of those settles the classification.</li>
  <li><strong>Does the property have both domestic and ditch water?</strong> A great many do out here. Where those two systems can meet is exactly what cross-connection control exists to prevent, and it is worth more attention than any single device.</li>
</ul>
<h3>Why this is our work</h3>
<p>These connections sit outside the foundation, so we handle them end to end. It is also the same water and the same ditch systems our irrigation crews are in all season — we know this ground better than anyone who tests out of a truck from town.</p>
HTML,
  'title' => 'Livestock & Ag Water Backflow Prevention | Western Slope',
  'desc' => 'Stock tanks, waterers and agricultural supply lines are health hazard connections. What that means, and why ditch and domestic water must stay separate.',
  'og' => 'A float valve in a tank animals drink from is a direct path. And most ag ground out here has two water systems.',
];

$DATA['POND'] = [
  'order' => 50,
  'short' => 'A fill line from the building supply into standing surface water — algae, fish, landscape runoff and whatever the wildlife adds. A recirculating pump means it can push, not just siphon. The usual failure is a fill line somebody added later and never protected, because nobody thought of it as plumbing.',
  'public' => <<<'HTML'
<p>A pond or water feature topped up from the building supply is a connection between drinking water and standing surface water — with algae, fish, fertilizer runoff from the surrounding landscape, and whatever the wildlife contributes.</p>

<p>Features with a recirculating pump can push, which moves them out of suction-only territory. Features that are treated for algae are carrying chemicals as well.</p>

<p>The common failure here is not the assembly. It is a fill line that was added later by somebody topping the feature off with a hose, and never got protected at all because nobody thought of it as plumbing.</p>

<p>We build water features and we maintain them, and on our own installs the fill line is part of the build rather than an afterthought. These sit outside the foundation, so we handle them end to end — test, repair, replace and install.</p>

<p>Which assembly your provider will accept depends on their cross-connection control program and the code in force where you are. <a href="/services/backflow-prevention">The seven device types we see on the Western Slope</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>The failure is almost never the assembly</h3>
<p>It is a fill line somebody added later, with a hose or a hard connection, that never got protected because nobody thought of it as plumbing. Look for it specifically — it will not be on any drawing.</p>
<ul>
  <li><strong>Recirculating pump?</strong> Then it can push, not just siphon, and suction-only protection is not enough.</li>
  <li><strong>Treated for algae?</strong> Chemicals on the feature side change the classification.</li>
  <li><strong>Auto-fill float</strong> — check whether the inlet sits above the water line or under it.</li>
</ul>
<h3>On our own installs</h3>
<p>The fill line is part of the build, not an afterthought. If we built the feature and the fill is unprotected, that is ours to fix and we do not charge to argue about it.</p>
HTML,
  'title' => 'Pond & Water Feature Backflow Prevention | Delta CO',
  'desc' => 'Why a fill line into a pond or water feature needs protection, what a recirculating pump changes, and certified testing across Delta and Montrose counties.',
  'og' => 'The usual failure is a fill line somebody added later, because nobody thought of it as plumbing.',
];

$DATA['POOL'] = [
  'order' => 60,
  'short' => 'A large volume of chemically treated water with a permanent connection to the house. An automatic fill is not a hose somebody remembers to disconnect. The cleanest answer is often an air gap, which is not an assembly at all and has nothing to test or fail.',
  'public' => <<<'HTML'
<p>A pool is a large volume of chemically treated water, and the fill line is the connection between it and the house.</p>

<p>The hazard is straightforward — chlorinated and stabilized pool water is not drinking water, and a pool with an automatic fill has a permanent connection rather than a hose somebody remembers to disconnect. Spas add heat and a higher chemical concentration in a smaller volume.</p>

<p>The cleanest protection on a fill line is often an air gap, which is not an assembly at all — just a physical break between the fill outlet and the highest water level, with nothing to test and nothing to fail. Where an air gap is not practical, this is health hazard territory.</p>

<p><strong>Scope on this one depends on where the assembly is.</strong> On an outdoor equipment pad we handle it end to end — test, repair, replace, install. In an indoor pool or spa room we test and repair, but replacement inside the foundation is licensed plumbing work. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Find out how it actually fills</h3>
<p>Ask. An automatic fill is a permanent connection and is treated as one. A hose the owner drags out is a different conversation and usually a hose bibb breaker problem.</p>
<ul>
  <li><strong>Air gap first.</strong> Where the fill outlet sits above the highest water level with a real physical break, there is nothing to test and nothing to fail. It is the cleanest answer and it is often already possible with a small change.</li>
  <li><strong>Check the actual gap.</strong> A fill spout that has settled, or a water line that runs higher than it used to, closes a gap that was fine at install.</li>
  <li><strong>Spas</strong> — more heat, higher chemical concentration, smaller volume.</li>
</ul>
<h3>Scope</h3>
<p>Outdoor equipment pad, we handle end to end. Indoor pool room is test and repair only.</p>
HTML,
  'title' => 'Pool Fill Backflow Prevention & Air Gaps | Montrose CO',
  'desc' => 'How a pool or spa fill line is protected, why an air gap is often the cleanest answer, and certified testing for pool assemblies in Delta and Montrose.',
  'og' => 'An automatic fill is a permanent connection, not a hose somebody remembers to disconnect.',
];

$DATA['FERTIGATION'] = [
  'order' => 70,
  'short' => 'An injector changes what the system is. It stops being a line full of yard water and becomes a line full of concentrate — a health hazard, with the injection equipment itself able to push. If you are thinking about adding one, price the assembly before the injector. It is frequently the larger number.',
  'public' => <<<'HTML'
<p>Adding a fertilizer or chemical injector to an irrigation system changes what the system is, from a water-protection standpoint. It stops being a line full of yard water and becomes a line full of concentrate.</p>

<p>This is a health hazard connection, and it is treated as one everywhere. An injector also introduces the possibility of backpressure, because the injection equipment itself can push. Health hazard plus backpressure is the combination that calls for the highest level of protection available, which in practice means a <a href="/services/backflow-prevention/reduced-pressure-rp">reduced pressure assembly</a>.</p>

<p>If you are considering adding injection to an existing system, the backflow assembly almost certainly has to change with it. That is worth pricing before the injector, not after — and it is a conversation worth having with us first, because the assembly is frequently the larger of the two numbers.</p>

<p>Which assembly your provider will accept depends on their cross-connection control program and the code in force where you are. <a href="/services/backflow-prevention">The seven device types we see on the Western Slope</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>The trigger to watch for</h3>
<p>A customer who mentions adding an injector, a fertilizer system, or "feeding through the sprinklers" has just changed their hazard classification, and almost none of them know it. Catch it in the conversation, before the equipment is bought.</p>
<ul>
  <li><strong>Health hazard plus backpressure</strong> — injection equipment can push on its own. That combination goes to an RP.</li>
  <li><strong>Price the assembly before the injector.</strong> It is frequently the larger of the two numbers and it is a bad surprise after the fact.</li>
  <li><strong>Existing systems:</strong> if you find an injector on a system with a vacuum breaker, that is a live compliance problem. Report it the same day.</li>
</ul>
<h3>Scope</h3>
<p>Outside the foundation, so we handle it end to end — test, repair, replace, install.</p>
HTML,
  'title' => 'Fertigation Backflow Prevention | Chemical Injection CO',
  'desc' => 'Adding an injector to an irrigation system changes its hazard class. What protection fertigation requires, and why to price the assembly before the injector.',
  'og' => 'An injector turns a line full of yard water into a line full of concentrate. That changes everything.',
];

$DATA['FIRE'] = [
  'order' => 80,
  'short' => 'Water that has stood in steel pipe for years, sometimes with antifreeze or foam in it. Fire systems are one of the few connections where backpressure is a given rather than a possibility — a standpipe has elevation behind it and a fire pump has far more. The fire marshal and the water provider both have a say.',
  'public' => <<<'HTML'
<p>A fire sprinkler system holds water that has been standing in steel pipe, sometimes for years. It is not drinking water anymore, and on systems carrying antifreeze or foam it is considerably further from it.</p>

<p>Fire systems are also one of the few connections where backpressure is a given rather than a possibility. A standpipe in a multi-story building has elevation behind it and a fire pump has a great deal more, which means the system can push back toward the main without any help from a pressure drop on the street.</p>

<h3>What is usually on one</h3>

<p>A plain wet system with nothing added to it is commonly protected by a <a href="/services/backflow-prevention/double-check-valve-assembly-dcva">double check valve assembly</a> — often a detector check, which is the same assembly with a metered bypass so the provider can see whether water is moving through a system that should be sitting still.</p>

<p>Add antifreeze, foam or any other additive and the classification changes to a health hazard, which takes it to a <a href="/services/backflow-prevention/reduced-pressure-rp">reduced pressure assembly</a>. Fire lines also carry their own approval requirements on top of the cross-connection rules, and the assembly has to be listed for fire service — the flow characteristics matter in a way they do not on an irrigation line. This is one of the few connections where the fire marshal and the water provider both have a say, and they do not always say the same thing.</p>

<h3>Testing one</h3>

<p>The certification to test a backflow assembly is not application-specific — a certified cross-connection control technician can test a fire line assembly the same as any other, and we do. What makes fire line testing different is the coordination. Testing the assembly means taking fire protection out of service for the duration, which means notifying the fire department and the alarm monitoring company before the valve closes, and confirming the system is back in service afterward. On a commercial building that is a scheduled event, not a stop on a route.</p>

<h3>When one fails</h3>

<p>A failed assembly starts the same sixty-day clock as any other in Colorado — repaired or replaced and retested, or the supplier is required to act. On a fire line that clock is tighter than it sounds, because the two outcomes are not the same job.</p>

<p><strong>Most failures are repairs, and we do those.</strong> An assembly that will not hold is usually failing on rubber: check discs, seats, springs, o-rings, a relief valve diaphragm. Those are rebuilt in place with a kit for that make and model and retested the same visit. Nothing is cut and the body stays where it is.</p>

<p><strong>We do not replace assemblies inside the foundation, and a fire riser is inside the foundation.</strong> Replacement there is licensed plumbing work under Colorado's code, and on a fire service it is also work the fire marshal has an interest in. What we will do is tell you the body is finished, tell you what it has to be replaced with to satisfy both the provider and the marshal, point you to a licensed plumbing contractor, and test the new one the day it is back in service.</p>

<p>The reason to test early in the cycle rather than late is exactly this. A repair happens on the spot. A replacement means a second trade scheduled inside sixty days, on a system that has to come out of service to do it — a much easier phone call in March than in the last week of the clock.</p>

<p>We test fire line assemblies, repair them, and file the report with your provider. Schedule with enough lead time to get the notifications done properly.</p>

<p><a class="button" href="/request-estimate">Schedule a fire line test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Notify before you close anything</h3>
<p>Closing a shutoff on a fire service takes fire protection out of service and will trigger a response if nobody was told. <strong>Fire department and the alarm monitoring company, before the valve moves.</strong> Confirm the system is back in service before you leave the property, and note who you spoke to.</p>
<p>This is a scheduled event on a commercial building, not a stop on a route. Book it with lead time.</p>
<h3>What you are likely to find</h3>
<ul>
  <li><strong>A DCVA, often a detector check</strong> with a metered bypass so the provider can see movement in a system that should be still.</li>
  <li><strong>Antifreeze or foam in the system</strong> changes it to a health hazard and an RP.</li>
  <li><strong>Riser rooms are inside the foundation</strong> — test and repair, never replace.</li>
</ul>
<h3>Why early in the season</h3>
<p>A repair happens on the spot. A replacement means a second trade scheduled inside sixty days on a system that has to come out of service. Much easier in March.</p>
HTML,
  'title' => 'Fire Sprinkler Backflow Testing | Delta & Montrose CO',
  'desc' => 'Certified testing and repair of fire line backflow assemblies, with the fire department and alarm notifications handled. Delta and Montrose, Colorado.',
  'og' => 'Water that has stood in steel pipe for years, on a system that can push back under its own pressure.',
];

$DATA['KITCHEN'] = [
  'order' => 90,
  'short' => 'More cross-connections than any other room in a building — dish machines, pre-rinse sprayers, carbonators, ice machines, mop sinks, steam equipment. They are not equally risky, and one of them can put dissolved copper in the drinking water. Kitchens are protected at several points, not one.',
  'public' => <<<'HTML'
<p>A commercial kitchen has more cross-connections in it than any other room in a normal building — dish machines, pre-rinse sprayers, steam equipment, carbonators, ice machines, mop sinks, grease traps.</p>

<p>They are not equally risky. A soda carbonator can push carbon dioxide back into a copper line and produce dissolved copper, which is a genuine poisoning route and an unusual enough failure that plenty of people have never heard of it. A mop sink with a hose on it is the same hazard as an outdoor tap. A dish machine with sanitizer in it is a chemical connection.</p>

<p>Kitchens are usually protected at several points rather than one, which is why an inventory of what is actually installed matters more here than almost anywhere else. We keep that inventory for every property we test — type, make, model, serial, size and location, connection by connection.</p>

<p><strong>Scope on this one:</strong> we test and repair. Assemblies inside the building are not ours to replace — that is licensed plumbing work — but a failure is far more often a rebuild than a replacement, and rebuilds we do on the spot. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Walk the whole room and count</h3>
<p>Kitchens are protected at several points, not one, and the inventory is the deliverable. Dish machine, pre-rinse sprayer, steam equipment, carbonator, ice machine, mop sink, grease trap, any hose connection.</p>
<ul>
  <li><strong>The carbonator is the one to know about.</strong> CO2 pushed back into a copper line produces dissolved copper — a genuine poisoning route, and unusual enough that plenty of people in the trade have never heard of it. Check what the carbonator connection is protected by.</li>
  <li><strong>Mop sink with a hose on it</strong> is the same hazard as an outdoor tap and gets missed every time.</li>
  <li><strong>Dish machine with sanitizer</strong> is a chemical connection.</li>
</ul>
<h3>Scope and scheduling</h3>
<p>Inside the foundation: test and repair only. And schedule around service — nobody wants us under the dish machine at noon.</p>
HTML,
  'title' => 'Commercial Kitchen Backflow Prevention | Western Colorado',
  'desc' => 'Dish machines, carbonators, ice machines and mop sinks are all cross-connections. How commercial kitchens are protected, and certified annual testing.',
  'og' => 'More cross-connections than any other room in a building, and they are not equally dangerous.',
];

$DATA['BOILER'] = [
  'order' => 100,
  'short' => 'A connection between the building supply and a closed loop of hot, usually treated water. Whether it is a health hazard depends entirely on what is in the loop — and a system that gets treated later without the assembly being reconsidered is a real and common problem. Boilers generate backpressure by design.',
  'public' => <<<'HTML'
<p>A boiler needs makeup water, which means a connection between the building supply and a closed loop of hot, often chemically treated water.</p>

<p>Whether it is a health hazard comes down to what is in the loop. Treated boiler water carries corrosion inhibitors and conditioners that nobody should drink. An untreated system is a lower classification — but "untreated" has to be true and stay true, and a system that gets treated later without the assembly being reconsidered is a real and common problem.</p>

<p>A boiler also generates backpressure by design. Heat expands water and the loop is pressurized, so the system can push back toward the supply without any help from a pump.</p>

<p><strong>Scope on this one:</strong> we test and repair. Assemblies inside the building are not ours to replace — that is licensed plumbing work — but a failure is far more often a rebuild than a replacement, and rebuilds we do on the spot. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Ask one question</h3>
<p><strong>"Is the loop treated?"</strong> That single answer sets the classification. Treated water carries corrosion inhibitors and conditioners and is a health hazard. Untreated is a lower class — but untreated has to be true and stay true.</p>
<ul>
  <li><strong>The common failure is a system that got treated later</strong> without anybody reconsidering the assembly. Ask whether a treatment program has been added since the last visit, and note the answer on the record.</li>
  <li><strong>Backpressure is designed in.</strong> Heat expands water and the loop is pressurized — it can push back toward the supply with no help from a pump.</li>
  <li><strong>Check the makeup line specifically</strong>, not just the boiler.</li>
</ul>
<h3>Scope</h3>
<p>Mechanical room is inside the foundation. Test and repair only.</p>
HTML,
  'title' => 'Boiler Makeup Water Backflow Prevention | Montrose CO',
  'desc' => 'Why a boiler makeup line needs backflow protection, how treatment chemicals change the hazard class, and certified testing in Delta and Montrose counties.',
  'og' => 'Whether it is a health hazard comes down to one question: is the loop treated?',
];

$DATA['COOLING_TOWER'] = [
  'order' => 110,
  'short' => 'An open basin of warm water, exposed to air, dosed with biocide to stop it growing things. Every part of that is a reason to keep it out of the drinking water. Add circulation pumps and you have health hazard plus active backpressure — the clearest case in this whole list.',
  'public' => <<<'HTML'
<p>A cooling tower is an open basin of warm water, exposed to the air, dosed with biocide to keep it from growing things. Every part of that description is a reason to keep it out of the drinking water.</p>

<p>Warm standing water is where Legionella lives, which is why tower water is treated and why the treatment chemicals are aggressive. Both the biology and the chemistry put this firmly in the health hazard category.</p>

<p>Towers also run on circulation pumps, so backpressure is not theoretical. Health hazard with active backpressure is the clearest case in this whole list, and it goes to a <a href="/services/backflow-prevention/reduced-pressure-rp">reduced pressure assembly</a> nearly every time.</p>

<p><strong>Scope on this one:</strong> we test and repair. The tower may be on the roof, but the makeup assembly is almost always inside, and assemblies inside the building are not ours to replace — that is licensed plumbing work. A failure is far more often a rebuild than a replacement, and rebuilds we do on the spot. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Stay out of the drift</h3>
<p>Tower water is warm, aerated and is where Legionella lives. Do not work in the drift plume, and do not open anything that will aerosolize basin water near your face. If the tower is running and you need to be at the basin, get it shut down first.</p>
<h3>What you are assessing</h3>
<ul>
  <li><strong>Health hazard plus active backpressure</strong> — circulation pumps make this the clearest RP case on the whole list. If you find anything less on the makeup line, that is a finding.</li>
  <li><strong>The tower may be on the roof, the makeup assembly usually is not.</strong> Find the assembly, not the tower.</li>
  <li><strong>Treatment chemicals are aggressive</strong>, and they are on the wrong side of that assembly.</li>
</ul>
<h3>Scope</h3>
<p>Inside the foundation: test and repair only.</p>
HTML,
  'title' => 'Cooling Tower Backflow Prevention | Delta & Montrose CO',
  'desc' => 'Warm open water dosed with biocide, moved by pumps — the clearest case for maximum backflow protection there is. Certified testing across Western Colorado.',
  'og' => 'Health hazard plus active backpressure. The clearest case on the list.',
];

$DATA['MEDICAL'] = [
  'order' => 120,
  'short' => 'The highest-consequence category in cross-connection control. Chairs, aspirators, autoclaves, sterilizers, analyzers and lab sinks all connect to the building supply, and much of it is pumped. These facilities are protected at the service line and again at each piece of equipment, and the inventory runs long.',
  'public' => <<<'HTML'
<p>Medical, dental and laboratory connections are the highest-consequence category in cross-connection control, and they are regulated accordingly.</p>

<p>Dental chairs, aspirators, autoclaves, sterilizers, lab sinks, analyzers and dialysis equipment all connect to the building supply, and what sits on the other side of those connections runs from chemical to biological. Much of the equipment is pumped, so backpressure is normal rather than exceptional. There is no category where the consequence of getting it wrong is higher.</p>

<h3>How these buildings are protected</h3>

<p>Almost always twice. A <a href="/services/backflow-prevention/reduced-pressure-rp">reduced pressure assembly</a> at the service line contains the building and protects the public main. Then individual assemblies protect the potable water inside the building from each piece of equipment, because containment at the street does nothing for the drinking fountain down the hall.</p>

<p>That produces a long inventory. A single dental practice can have a dozen protected connections, and a clinic considerably more. Every one of them is on the same annual cycle, and every one of them has to appear on a report the provider receives directly.</p>

<h3>What we do</h3>

<p>We test them — all of them, in one scheduled block, worked around patients rather than through them — repair what fails, and file the reports with your provider.</p>

<p>The part that is worth more than the test is the record. We keep what is installed on your property: type, make, model, serial number, size and location, connection by connection. When an assembly fails, Colorado gives you sixty days to repair or replace it and retest before the provider is required to act, and that sixty days is a much shorter conversation when somebody already knows exactly what the assembly is and what it can be replaced with.</p>

<p>For a facility with more than a handful of connections, that inventory is the difference between an annual appointment and an annual scramble.</p>

<p><strong>Scope on this one:</strong> we test and repair. Assemblies inside the building are not ours to replace — that is licensed plumbing work — but a failure is far more often a rebuild than a replacement, and rebuilds we do on the spot. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Schedule facility testing</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Schedule around patients, not through them</h3>
<p>Get the whole facility done in one booked block, arranged with the office rather than dropped on them. These are the accounts where being easy to work with is worth more than being cheap.</p>
<h3>What you are walking into</h3>
<ul>
  <li><strong>Protected twice</strong> — an RP at the service line containing the building, then individual assemblies at each piece of equipment. Containment at the street does nothing for the drinking fountain down the hall.</li>
  <li><strong>Long inventory.</strong> A single dental practice can have a dozen connections; a clinic considerably more. Chairs, aspirators, autoclaves, sterilizers, lab sinks, analyzers, dialysis equipment.</li>
  <li><strong>Do not interrupt equipment</strong> without the facility's sign-off on timing. Some of it cannot simply be shut down.</li>
</ul>
<h3>The record is the product here</h3>
<p>Every connection on the same annual cycle, every one on a report the provider receives directly. For a facility this size the inventory is the difference between an appointment and a scramble.</p>
HTML,
  'title' => 'Medical & Dental Backflow Testing | Delta & Montrose CO',
  'desc' => 'The highest-consequence category in cross-connection control. How clinics and dental practices are protected, and facility-wide certified annual testing.',
  'og' => 'Protected at the service line and again at every piece of equipment. The inventory runs long.',
];

$DATA['INDUSTRIAL'] = [
  'order' => 130,
  'short' => 'A car wash, a machine shop coolant loop, a plating line — the hazard depends entirely on the process. What they share is water that has been used for something, is often dosed, and is nearly always moved by a pump. Process lines also change, and a new chemical step changes the classification whether or not anyone tells the provider.',
  'public' => <<<'HTML'
<p>Industrial process water covers everything from a car wash to a machine shop coolant loop to a plating line, and the hazard depends entirely on what the process is.</p>

<p>What they have in common is that the water on the process side has been used for something, is frequently treated or dosed, and is nearly always moved by a pump — which means backpressure. That combination puts most industrial connections in the highest protection category by default, with the burden on the facility to demonstrate otherwise rather than the other way round.</p>

<p>Process lines also change. A facility that adds a chemical step to an existing line has changed its hazard classification whether or not anybody has told the water provider.</p>

<p><strong>Scope on this one:</strong> we test and repair. Assemblies inside the building are not ours to replace — that is licensed plumbing work — but a failure is far more often a rebuild than a replacement, and rebuilds we do on the spot. <a href="/services/backflow-prevention">The device types, and where our work stops</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Ask what the process is, and whether it changed</h3>
<p>The hazard depends entirely on what the water is used for, and the facility is the only one who knows. A car wash, a coolant loop and a plating line are three different answers.</p>
<ul>
  <li><strong>Nearly always pumped</strong>, which means backpressure, which means most industrial connections default to the highest protection class with the burden on the facility to show otherwise.</li>
  <li><strong>Process lines change.</strong> A new chemical step changes the classification whether or not anybody told the water provider. Ask specifically what has changed since the last test and record the answer.</li>
  <li><strong>Lockout and access</strong> are the facility's procedures, not ours. Follow theirs.</li>
</ul>
<h3>Scope</h3>
<p>Inside the foundation: test and repair only.</p>
HTML,
  'title' => 'Industrial Process Water Backflow Prevention | Colorado',
  'desc' => 'Car washes, coolant loops and plating lines all carry different hazards. Why most industrial connections default to the highest protection class in Colorado.',
  'og' => 'Used water, usually dosed, nearly always pumped. The burden is on the facility to show otherwise.',
];

$DATA['MOBILE'] = [
  'order' => 140,
  'short' => 'The connections most likely to be missed, because nobody thinks of a hydrant meter or a construction hookup as plumbing. A water truck, a temporary service, a food truck, an event connection — something unknown attached to the public main, usually with a hose. Worth asking about before the connection, not after.',
  'public' => <<<'HTML'
<p>Temporary connections are the ones most likely to be missed, because nobody thinks of a hydrant meter or a construction hookup as plumbing.</p>

<p>A water truck filling from a hydrant, a construction site on a temporary service, a food truck, an event connection — all of them attach something unknown to the public main, often with a hose, often without anybody who knows the rules watching. And a tank on a truck is below the hydrant, which makes suction the obvious failure.</p>

<p>Most water providers require backflow protection on any hydrant meter or temporary service for exactly this reason. It is usually the provider's own equipment and their own rule, and it is worth asking about before the connection rather than after.</p>

<p>Which assembly your provider will accept depends on their cross-connection control program and the code in force where you are. <a href="/services/backflow-prevention">The seven device types we see on the Western Slope</a>.</p>

<p><a class="button" href="/request-estimate">Schedule a backflow test</a> or call <a href="tel:9708359661">970-835-9661</a></p>
HTML,
  'teammate' => <<<'HTML'
<h3>Usually the provider's equipment and the provider's rule</h3>
<p>Hydrant meters and temporary construction services normally come with backflow protection supplied by the water provider, and the rule is theirs to enforce. Our job is mostly to know it exists and to ask before the connection rather than after.</p>
<ul>
  <li><strong>A tank on a truck sits below the hydrant</strong>, which makes suction the obvious failure. Nobody thinks of a fill hose as plumbing.</li>
  <li><strong>If we are the ones connecting</strong> — a water truck, an event, a temporary line on a job site — the protection is on us, and it is on us before the hose goes on.</li>
  <li><strong>Report any unprotected temporary connection you see</strong>, ours or anyone's. That is the kind of thing a water provider remembers about the company that called it in.</li>
</ul>
HTML,
  'title' => 'Hydrant Meter & Temporary Backflow Protection | Colorado',
  'desc' => 'Water trucks, construction services, food trucks and event connections attach something unknown to the public main. What providers require, and why.',
  'og' => 'Nobody thinks of a hydrant meter as plumbing, which is exactly why it gets missed.',
];

// ---- apply ----------------------------------------------------------------

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$terms = $storage->loadByProperties(['vid' => 'backflow_uses']);
$byCode = [];
foreach ($terms as $t) {
  $code = $t->hasField('field_use_code') ? (string) $t->get('field_use_code')->value : '';
  if ($code !== '') {
    $byCode[$code] = $t;
  }
}

$done = 0;
$missing = [];
foreach ($DATA as $code => $d) {
  if (!isset($byCode[$code])) {
    $missing[] = $code;
    continue;
  }
  $t = $byCode[$code];
  $t->set('field_list_order', (int) $d['order']);
  $t->set('field_short_description', ['value' => $d['short'], 'format' => $SHORT_FORMAT]);
  $t->set('field_public_description', ['value' => $d['public'], 'format' => $BODY_FORMAT]);
  $t->set('field_teammate_description', ['value' => $d['teammate'], 'format' => $BODY_FORMAT]);
  $t->set('field_meta_tags', json_encode([
    'title' => $d['title'],
    'description' => $d['desc'],
    'og_description' => $d['og'],
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  $t->save();
  printf("+ %-14s tid=%-5d order=%-3d  %s\n", $code, $t->id(), $d['order'], $d['title']);
  $done++;
}

printf("\nUpdated %d / %d terms.\n", $done, count($DATA));
if ($missing) {
  print "!! No term found for use codes: " . implode(', ', $missing) . "\n";
}
print "DONE.\n";
