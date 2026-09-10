<?php

/**
 * @file
 * Idempotent setup + copy sync for the public /about trust page.
 *
 * Creates (if missing) and keeps in sync:
 *   1. a published `page` node "About Brookstone Outdoors" whose body holds the
 *      approved copy (full_html), editable in the admin UI without a deploy. On
 *      re-run the body is SYNCED to the canonical copy below — this script is the
 *      deploy mechanism for a full copy revision (a one-word tweak can still be
 *      made in the UI between deploys);
 *   2. the /about path alias;
 *   3. a "About" menu_link_content in `main` (weight 6 — after Services w5) and
 *      `footer` (beside Contact).
 *
 * Content, not config — run per environment (dev, then live):
 *   ddev drush php:script web/scripts/setup_about_page.php          (dev)
 *   drush php:script web/scripts/setup_about_page.php               (live)
 *
 * Copy = Todd's full rewrite (2026-09-11), verbatim. The kicker is a styled
 * subhead paragraph (.about-kicker), NOT an <h2>. The /about/credentials links
 * stay omitted until that child page exists (it 404s today). Em-dashes are the
 * author's unspaced form (word—word) and are preserved as written.
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\menu_link_content\Entity\MenuLinkContent;

$aliasManager = \Drupal::service('path_alias.manager');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

// ---------------------------------------------------------------------------
// The approved copy (verbatim). Nowdoc — no interpolation. Ampersands escaped
// for valid HTML; they render as "&". LINK SPEC applied: named phrase, named
// section, first occurrence only, words unchanged.
// ---------------------------------------------------------------------------
$body = <<<'HTML'
<p class="about-kicker">Creating and Maintaining Outdoor Spaces Since 1995</p>

<section class="about-sec about-intro">
  <p class="about-hero__lead">Brookstone Outdoors provides complete landscape construction, irrigation, property maintenance, plant health, lighting, and snow-management services throughout Delta and Montrose counties.</p>
  <p>We design and build outdoor spaces—and then we take care of them.</p>
  <p>That combination is what makes our company different. The same team that understands how a landscape was constructed can maintain it, adjust its irrigation, care for its plants, improve it as it matures, and manage the property through every season.</p>
  <p class="about-hero__cta"><a class="bo-btn" href="/request-estimate">Get a Free Estimate</a> <a class="about-hero__call" href="tel:9708359661">970-835-9661</a></p>
  <p class="about-hero__trust">Locally owned · Licensed and insured · Serving Western Colorado since 1995</p>
</section>

<section class="about-sec">
  <h2>Local Roots and a New Name</h2>
  <p>Brookstone Outdoors continues the company Steve and Eunice Ward began in 1995 as S&amp;E Ward's Landscape Management.</p>
  <p>For 30 years, Steve and Eunice built the business by answering the phone, standing behind the work, and returning season after season. In March 2025, they sold the company to two longtime employees, Todd and Gerald.</p>
  <p>They did not come from outside the company. They learned the properties, customers, crews, and day-to-day work from within the business.</p>
  <p>Todd leads the landscape design, construction, and irrigation side of the company. A Cedaredge native, he installed his first sprinkler system in the valley in 1985 while still in high school.</p>
  <p>Gerald, whose family has deep agricultural roots in the area, handles sales, leads spraying operations, maintenance, and field production.</p>
  <p>The ownership and name changed, but the company's local experience, customer relationships, and commitment to the work continued. If you hired S&amp;E Ward's during the transition, you were working with the company that is now Brookstone Outdoors.</p>
</section>

<section class="about-sec">
  <h2>One Company for the Entire Property</h2>
  <p>A landscape is never truly finished.</p>
  <p>Plants mature. Irrigation systems fall out of adjustment. Soil compacts. Drainage problems appear. Trees need pruning and treatment. Hardscapes settle, and properties change as the people using them change.</p>
  <p>That is why Brookstone Outdoors operates as one full-service company rather than a collection of unrelated contractors.</p>
  <p><a href="/services/landscaping">Our landscape crews</a> can design and build the property. <a href="/services/sprinkler-system">Our irrigation department</a> can keep the water going where it belongs. <a href="/services/landscape-lawn-care">Our maintenance and plant-health teams</a> can care for the lawn, trees, shrubs, and planting beds. <a href="/lighting">Our lighting team</a> can extend the use of the property after dark, and <a href="/services/snow-removal">our snow crews</a> can keep commercial and community properties accessible through the winter.</p>
  <p>Because these departments work together, problems are more likely to be noticed before they become expensive. A mowing crew can report a damaged sprinkler head. An irrigation technician can identify a struggling tree. A maintenance supervisor can spot drainage or landscape problems that should be addressed by the construction team.</p>
  <p>For the customer, that means fewer contractors to coordinate and one company responsible for understanding the property.</p>
</section>

<section class="about-sec">
  <h2>What We Do</h2>
  <p>Brookstone Outdoors provides:</p>
  <ul>
    <li>Landscape design, installation, and renovation</li>
    <li>Patios, retaining walls, rockwork, outdoor kitchens and water features</li>
    <li>Irrigation design, installation, repair, startup, checkups, and <a href="/winterize">winterization</a></li>
    <li>Residential, commercial, and HOA property maintenance</li>
    <li>Mowing, aeration, dethatching, pruning, and seasonal cleanup</li>
    <li>Lawn, tree, and shrub fertilization</li>
    <li>Weed, insect, and plant-health treatments</li>
    <li>Landscape, holiday and exterior lighting</li>
    <li>Commercial snow and ice management</li>
  </ul>
  <p>Most of this work is completed by our own crews. When a project requires work outside our licensing—such as installing a new electrical circuit—we coordinate with an appropriately licensed professional.</p>
</section>

<section class="about-sec">
  <h2>Built to Serve Properties of Every Size</h2>
  <p>Brookstone Outdoors operates six specialized departments from our shop on Austin Road. With approximately two dozen employees and a fleet of roughly 21 trucks, along with dedicated equipment and trailers, we can serve individual homeowners while also supporting commercial properties, HOAs, and larger construction projects.</p>
  <p>Since our current property records began in 2017, our crews have performed work at more than 2,500 addresses across Delta and Montrose counties. That includes long-term maintenance accounts, landscape installations, seasonal services, and individual repairs.</p>
  <p>Every job is scheduled and documented through BOS—the Brookstone Operating System—our own business-management platform. It gives our office and field teams access to property information, service history, work orders, application records, and job documentation.</p>
  <p>For homeowners, that means we can see what was done previously and provide better continuity from one visit to the next. For property managers and HOA boards, it provides the service records and documentation needed to manage larger properties responsibly.</p>
</section>

<section class="about-sec">
  <h2>Where We Work</h2>
  <p>We serve Delta and Montrose counties on Colorado's Western Slope.</p>
  <p>Our regular service area includes Cedaredge, Delta, Austin, Eckert, Orchard City, Hotchkiss, Paonia, Crawford, Olathe, and Montrose.</p>
  <p>Recurring maintenance and service work is routed geographically so our crews can operate efficiently and provide dependable service. Larger landscape and irrigation installations are scheduled differently, allowing our construction crews to travel farther when the project is a good fit.</p>
  <p>If your property is outside our regular service area, call us. We will give you a straightforward answer about whether we can serve it well.</p>
</section>

<section class="about-sec">
  <h2>Practical Advice. Long-Term Work.</h2>
  <p>We believe customers deserve honest recommendations, even when the recommendation is to wait.</p>
  <p>If equipment still has useful life, we will tell you. If a service would be more effective in another season, we will recommend the better timing. If a project needs a different specialist, we will say so.</p>
  <p>We are not trying to complete one transaction and disappear. We want to understand the property, do the work correctly, and still be the company you call next season.</p>
  <p>That was the foundation of S&amp;E Ward's Landscape Management, and it remains the foundation of Brookstone Outdoors:</p>
  <p>Answer the phone. Do the work right. Be here next season.</p>
</section>

<div class="about-close">
  <h2>Let's Talk About Your Property</h2>
  <p>Whether you need a sprinkler repaired, a property maintained, or an entirely new outdoor space designed and built, Brookstone Outdoors is ready to help.</p>
  <p class="about-close__cta"><a class="bo-btn" href="/request-estimate">Get a Free Estimate</a> <a class="about-close__phone" href="tel:9708359661">970-835-9661</a></p>
  <p class="about-close__strip">Delta and Montrose counties · Locally owned · Licensed and insured</p>
</div>
HTML;

// ---------------------------------------------------------------------------
// 1. Node — create if /about is not already a node; else sync the body.
// ---------------------------------------------------------------------------
$existingSource = $aliasManager->getPathByAlias('/about');
$node = NULL;
if ($existingSource !== '/about' && preg_match('#^/node/(\d+)$#', $existingSource, $m)) {
  $node = $nodeStorage->load($m[1]);
}

if ($node) {
  $current = $node->get('body')->value ?? '';
  if (trim($current) === trim($body)) {
    echo "• Node at /about (nid {$node->id()}) body already matches canonical copy.\n";
  }
  else {
    $node->set('body', ['value' => $body, 'format' => 'full_html']);
    $node->save();
    echo "• Synced body of /about node (nid {$node->id()}) to canonical copy.\n";
  }
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

echo "Done. /about is in sync in this environment.\n";
