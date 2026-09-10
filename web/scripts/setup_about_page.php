<?php

/**
 * @file
 * Idempotent setup for the public /about trust page.
 *
 * Creates (only if missing — never clobbers later UI edits):
 *   1. a published `page` node "About Brookstone Outdoors" with the approved copy
 *      in the body (full_html), editable in the admin UI without a deploy;
 *   2. the /about path alias;
 *   3. a "About" menu_link_content in `main` (weight 6 — after Services w5, so it
 *      does not displace or outrank Services) and in `footer` (beside Contact).
 *
 * Content, not config — run per environment (dev, then live), like the other BOS
 * content setup scripts (e.g. setup_teammate_employment_landing.php):
 *   ddev drush php:script web/scripts/setup_about_page.php          (dev)
 *   drush php:script web/scripts/setup_about_page.php               (live)
 *
 * The two /about/credentials links from the copy are intentionally omitted — that
 * child page is not built yet (shipping a 404 is worse). Add them when it exists.
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\menu_link_content\Entity\MenuLinkContent;

$aliasManager = \Drupal::service('path_alias.manager');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

// ---------------------------------------------------------------------------
// The approved copy (verbatim). Nowdoc — no interpolation. Ampersands escaped
// for valid HTML; they render as "&". The two credentials links are omitted.
// ---------------------------------------------------------------------------
$body = <<<'HTML'
<div class="about-hero">
  <blockquote class="about-hero__hook">This company has been taking care of property in Delta and Montrose counties since 1995. In March of 2025 it changed hands — from the couple who built it to two of the people who had been doing the work.</blockquote>
  <p class="about-hero__cta"><a class="bo-btn" href="/request-estimate">Get a Free Estimate</a> <span class="about-hero__call">or call <a href="tel:9708359661">970-835-9661</a></span></p>
  <p class="about-hero__trust">Founded 1995 · Locally owned · Licensed and insured</p>
  <p class="about-hero__trust">Six departments, roughly 21 trucks, and a shop on Austin Road</p>
</div>

<section class="about-sec">
  <h2>The short version</h2>
  <p>We design and build outdoor spaces, and then we take care of them.</p>
  <p>That is the whole company, and it is why the sign says <em>Creating and Maintaining Your Outdoor Spaces</em>. One half of the business <a href="/services/landscaping">designs and installs</a> patios, walls, plantings, <a href="/services/sprinkler-system">sprinkler systems</a> and <a href="/lighting">lighting</a>. The other half <a href="/services/landscape-lawn-care">mows, sprays and prunes</a>, <a href="/winterize">winterizes those sprinklers</a> and turns them back on in the spring, and <a href="/services/snow-removal">plows the snow</a> off of it all winter.</p>
  <p>Most people meet us through the second half. Somebody needs a sprinkler head fixed or a lawn kept up, we do it for a few years without incident, and eventually there is a conversation about the back yard. That is the usual order and we like it that way — by the time we are quoting a patio, you have watched our trucks come and go on your street for three seasons and you already know whether we show up.</p>
  <p>Keeping both halves under one company is not only a convenience. A landscape is never finished — irrigation drifts out of adjustment, plants mature and outgrow their spacing, soil compacts, and what was right for a property in year one is wrong for it in year six. Because our crews work across all of it, we can look at a property as one thing instead of a stack of unrelated work orders. The mowing crew notices the head that is spraying the fence. The irrigation tech notices the tree that is failing. That is worth more over ten years than any single job we would do for you.</p>
</section>

<section class="about-sec">
  <h2>How this company got here</h2>
  <p><strong>Steve and Eunice Ward started it in 1995.</strong> They called it S&amp;E Ward's Landscape Management, they ran it for thirty years, and in a valley this size that name meant something specific: they answered the phone, they finished the job, and they were still there the next season. That reputation was built one property at a time over three decades, and there is no shortcut to it.</p>
  <p><strong>In March of 2025, they sold the business to two of their employees.</strong> Todd and Gerald bought it on March 25, 2025, with the support of <a href="https://www.region10.net/" target="_blank" rel="noopener">Region 10</a>, the regional economic development district in Montrose. Neither of us came in from outside. Todd came up on the design, installation and irrigation side. Gerald's family has deep agricultural roots in this area, and he came up running maintenance crews and field operations. Between the two of us that covers both halves of the company, and we learned both of them on these trucks.</p>
  <p><strong>The name changed with the ownership.</strong> S&amp;E Ward's became Brookstone Outdoors. The company did not start over; it changed hands and changed names on the same day.</p>
  <p><strong>We bought the S&amp;E Ward's name along with the business, and we kept trading under it for the first few months.</strong> A thirty-year-old name in a valley this size is not something you throw in a dumpster on a Monday morning, so we let the two overlap while the transition settled. If you hired S&amp;E Ward's in the spring or summer of 2025, you hired this company.</p>
  <p><strong>Steve and Eunice are retired, and it was a friendly handoff.</strong> Steve is still a phone call away, and we still call him. Thirty years of knowing which properties have ditch water and which have city water, where the old lines run, and what was tried in 1998 and did not work is not the sort of thing that transfers in a closing document. It transfers over the phone, one question at a time, and we are fortunate to still be able to ask.</p>

  <h3>Before any of that</h3>
  <p>One of us has been doing this work in this valley since 1985.</p>
  <p>Todd was born and raised in Cedaredge. He installed his first sprinkler system here in 1985, as a high schooler running a landscaping outfit he called Cedaredge Landscaping. He still has one of the flyers.</p>
  <p>Forty-one years is long enough to learn things about this particular valley that do not appear in any catalog. Which subdivisions were plumbed in a hurry and where those lines run. Which slopes hold and which ones move. What actually survives a winter here as opposed to what the tag on the pot claims. Which properties are on ditch water, when the ditch goes dry, and what that means for the plant list. That knowledge is the reason a company like this one is worth buying rather than starting.</p>

  <h3>Why we are telling you this</h3>
  <p>Because otherwise the arithmetic on this website does not work.</p>
  <p>We say we have been doing this since 1995, and if you looked us up you would find a company name that nobody around here had heard before 2025. That combination is normally a warning sign. Plenty of one-season outfits buy a truck, print a name, and claim a history they do not have.</p>
  <p>So here is the actual history, with a date on it. You are welcome to verify any of it. Ask anyone who has been in this valley a while about S&amp;E Ward's, and then ask them what happened to it.</p>
</section>

<section class="about-sec">
  <h2>What changed and what did not</h2>
  <p>A change of ownership in a company this size is not invisible, and we are not going to pretend it was.</p>
  <p><strong>Some of the crew stayed through the transition and some moved on.</strong> That is what happens when a business changes hands, it happened here, and any company that tells you otherwise is describing a transition that did not occur. What we can tell you is that the people running the departments today know these properties, and that the two people who own the company learned the work on these crews rather than reading about it.</p>
  <p><strong>The standards did not change, because the standards were the reason to buy it.</strong> Nobody buys a thirty-year-old service business in a small market and then changes how it treats customers. The reputation is the asset. Damaging it would be the only genuinely stupid thing we could do.</p>
  <p><strong>What did change is the equipment behind the work.</strong> We have put real money into the fleet, into the systems that schedule and document every job, and into the departments that were running thin. The company is doing more work than it was two years ago, and handling more work takes trucks, people and organization that were not all here in 2024.</p>
  <p><strong>What we are still working on.</strong> We are not going to claim we get every route perfect. In a business that runs six departments across two counties, some weeks the mowing crew arrives on Thursday instead of Tuesday, and when a storm lands the snow schedule rearranges everything behind it. When that happens, call the office. We would rather hear about it than have you tell your neighbor about it.</p>
</section>

<section class="about-sec">
  <h2>What we run</h2>
  <p>This section is here for the property managers and HOA boards, who need to know whether a company can actually absorb their work before they put it out to bid. Homeowners are welcome to skip it, though it explains a few things about pricing.</p>
  <p><strong>Six departments.</strong> Landscape, irrigation, spray, maintenance, lighting and snow. Each one has its own crews, its own equipment and its own scheduling. That is why we can be at your property in July for mowing, in October for winterization, and in January with a plow, and have it be the same company each time rather than three subcontractors with our name on the invoice.</p>
  <p><strong>What that covers:</strong></p>
  <ul>
    <li>Landscape design, installation and renovation</li>
    <li>Hardscape — patios, retaining walls, rock work and water features</li>
    <li>Irrigation design, installation and repair, spring startup, mid-season checkups and winterization</li>
    <li>Lawn and property maintenance for residential, commercial and HOA accounts</li>
    <li>Mowing, aeration, dethatching, pruning and seasonal cleanup</li>
    <li>Lawn, tree and shrub fertilization</li>
    <li>Weed, insect and plant-health treatments</li>
    <li>Landscape and exterior lighting</li>
    <li>Snow and ice management</li>
  </ul>
  <p><strong>Ice, not only snow.</strong> For a commercial lot or an HOA common area the plowing is the easy part. What generates the claim is the refreeze at two in the afternoon on a sunny January day, in the low spot by the entrance, after everyone decided the storm was over. Ice management is a scheduled, documented service and we treat it as one.</p>
  <p><strong>Roughly 21 trucks with equipment and trailers,</strong> working out of the shop on Austin Road, which sits in the middle of the Surface Creek and Delta corridor where most of our work is. The fleet is USDOT registered, which means it is subject to inspection and maintenance requirements rather than running on the honor system.</p>
  <p><strong>More than 2,500 properties in our records.</strong> Since we started keeping track in 2017 we have done work at over twenty-five hundred addresses across Delta and Montrose counties. That is not a current customer count and we are not going to dress it up as one — it includes one-time sprinkler repairs and jobs finished years ago. It is simply the number of properties in this valley our trucks have actually been to.</p>
  <p><strong>Roughly two dozen employees,</strong> which is enough to hold multiple crews on a large installation without abandoning the maintenance routes for a week. Capacity is the thing that usually breaks on a big residential or commercial job, and it usually breaks in August.</p>
  <p><strong>Nearly all of the work is done by our own crews.</strong> The one exception is electrical. When a job requires a licensed electrician — a lighting system that needs a new circuit at the panel, most often — we bring one in, because that is work an electrician ought to be doing. Everything else is ours: design, installation, irrigation, spray, maintenance, lighting and snow. We do not sell your job to somebody else and take a margin on it.</p>
  <p><strong>Every job is scheduled, tracked and documented in our own software.</strong> We built it, we run the entire company on it, and it is not something we bought off a shelf and half configured. For a homeowner what that means is that the person answering the phone can see your property, your history and what was done last time. For an HOA board or a property manager it means service records, application records and job documentation you can put in front of your own board without waiting on us to reconstruct it from memory.</p>
  <p><strong>We are licensed and insured, and we will prove it before you ask.</strong> Certificate of insurance, applicator license, backflow certification and W-9 on request, usually within a day.</p>
</section>

<section class="about-sec">
  <h2>Where we work</h2>
  <p>Delta and Montrose counties, on the Western Slope. The bulk of our work is in <strong>Cedaredge, Delta, Austin, Eckert and Orchard City</strong> on the Surface Creek side, out into the North Fork in <strong>Hotchkiss, Paonia and Crawford</strong>, and south through <strong>Olathe and Montrose</strong>.</p>
  <p>We route by geography, and this is worth understanding because it affects what we can promise you. Crews work an area at a time — one truck, one setup, one trip through a neighborhood. It is the reason maintenance pricing here is what it is, and it is the reason we schedule by week rather than by date on routed services.</p>
  <p>If you are outside those areas, call anyway and we will give you a straight answer about whether we can serve you well. Sometimes the answer is no. A property forty minutes off of every route we run gets visited last, gets rescheduled first when weather compresses the week, and ends up disappointed — and we would rather tell you that in September than show you in July.</p>
  <p><strong>Design-build installation is different.</strong> An installation is a crew on site for days or weeks, not a truck passing through, so the geography matters far less. For a project we will travel.</p>
</section>

<section class="about-sec">
  <h2>A few things we are not</h2>
  <p><strong>We are not the cheapest, and we do not try to be.</strong> There is always somebody with a mower in a pickup who will do it for less. Sometimes that is genuinely the right call for a small lot, and we will tell you so. What you are paying for here is a licensed applicator, a covered payroll, a truck that shows up when the first storm lands, and a company that will still be answering this phone number in five years.</p>
  <p><strong>We are not a design firm that hands you a drawing.</strong> We design it and then our own crews build it, which means the person drawing the wall has to stand next to it when it goes in. That is a useful constraint on a designer.</p>
  <p><strong>We do not sell you work you do not need.</strong> If you call about aerating in November, we will tell you to wait until spring. If your irrigation controller has six more good years in it, we will say so. We would rather book the right job next season than the wrong one this week, and we are going to be here next season.</p>
</section>

<div class="about-close">
  <h2>Come see the work</h2>
  <p>Thirty-one years in, the reason to hire us is the same as it was in 1995. We answer the phone, we finish the job, and we are still here the next season.</p>
  <p class="about-close__cta"><a class="bo-btn" href="/request-estimate">Get a Free Estimate</a> <a class="about-close__phone" href="tel:9708359661">970-835-9661</a></p>
  <p class="about-close__strip">Delta and Montrose counties · Licensed and insured · Founded 1995</p>
  <p class="about-close__links"><a href="/services">Our services</a> · <a href="/careers">Work with us</a> · <a href="/contact">Contact</a></p>
  <p class="about-close__signoff">Thank you for choosing Brookstone Outdoors.</p>
</div>
HTML;

// ---------------------------------------------------------------------------
// 1. Node — create only if /about is not already a node.
// ---------------------------------------------------------------------------
$existingSource = $aliasManager->getPathByAlias('/about');
$node = NULL;
if ($existingSource !== '/about' && preg_match('#^/node/(\d+)$#', $existingSource, $m)) {
  $node = $nodeStorage->load($m[1]);
}

if ($node) {
  echo "• Node already exists at /about (nid {$node->id()}) — left untouched (no clobber).\n";
}
else {
  $node = Node::create([
    'type' => 'page',
    'title' => 'About Brookstone Outdoors',
    'uid' => 1,
    'status' => 1,
    'body' => ['value' => $body, 'format' => 'full_html'],
  ]);
  $node->save();
  echo "• Created page node nid {$node->id()} \"About Brookstone Outdoors\".\n";
}

// ---------------------------------------------------------------------------
// 2. Path alias /about → the node.
// ---------------------------------------------------------------------------
$source = '/node/' . $node->id();
$aliasExists = \Drupal::entityTypeManager()->getStorage('path_alias')->getQuery()
  ->condition('alias', '/about')
  ->condition('path', $source)
  ->accessCheck(FALSE)
  ->range(0, 1)
  ->execute();
if ($aliasExists) {
  echo "• Alias /about → {$source} already present.\n";
}
else {
  PathAlias::create(['path' => $source, 'alias' => '/about', 'langcode' => 'en'])->save();
  echo "• Created alias /about → {$source}.\n";
}

// ---------------------------------------------------------------------------
// 3. Menu links — About in main (w6, after Services w5) + footer (beside Contact).
//    menu_link_content (content), matching how BOS stores non-derived links.
// ---------------------------------------------------------------------------
$mlcStorage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
foreach (['main' => 6, 'footer' => 0] as $menuName => $weight) {
  $found = $mlcStorage->getQuery()
    ->condition('menu_name', $menuName)
    ->condition('link.uri', 'internal:/about')
    ->accessCheck(FALSE)
    ->range(0, 1)
    ->execute();
  if ($found) {
    echo "• Menu link \"About\" already in {$menuName}.\n";
    continue;
  }
  MenuLinkContent::create([
    'title' => 'About',
    'link' => ['uri' => 'internal:/about'],
    'menu_name' => $menuName,
    'weight' => $weight,
    'expanded' => FALSE,
  ])->save();
  echo "• Added menu link \"About\" to {$menuName} (weight {$weight}).\n";
}

echo "Done. /about is live in this environment.\n";
