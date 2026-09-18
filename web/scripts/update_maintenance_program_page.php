<?php

/**
 * Reworks the Residential Maintenance page (node 105, Basic page):
 *   - new body copy (service front-half + terms back-half; CTA -> /contact)
 *   - H1/title -> "Residential Maintenance Program"
 *   - per-page SEO title + meta description (Metatag field on node.page)
 *   - alias /maintenance/residential_contract -> /services/landscape-lawn-care/maintenance-program
 *   - 301 redirect from the old path
 *
 * Text/redirect only — no intake form is built (CTA is a contact link + phone).
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/update_maintenance_program_page.php
 */

use Drupal\redirect\Entity\Redirect;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\path_alias\Entity\PathAlias;

$NID = 105;
$NEW_ALIAS = '/services/landscape-lawn-care/maintenance-program';
$OLD_PATH = '/maintenance/residential_contract';
$out = [];

$body = <<<'HTML'
<blockquote><p>One agreement, one crew, and a property that gets looked after all season instead of whenever somebody remembers to call.</p></blockquote>

<p><a href="/contact" class="button button--primary">Request a Maintenance Agreement</a> &nbsp; or call <a href="tel:9708359661"><strong>970-835-9661</strong></a></p>

<p><em>Serving Delta and Montrose counties since 1995</em><br>
<em>Licensed applicators &middot; Scheduled routes &middot; Billed on completed work</em></p>

<h2>What the program covers</h2>
<p>You select the services you want each season. Nothing is bundled that you did not ask for.</p>

<h3>Lawn</h3>
<ul>
  <li><a href="/services/landscape-lawn-care/mowing">Mowing</a> — weekly, every other week, twice a month, every three weeks, monthly, or on call</li>
  <li>Aeration and dethatching</li>
  <li><a href="/services/landscape-lawn-care/fertilizing">Fertilization</a></li>
</ul>

<h3>Weeds, insects and plant health</h3>
<ul>
  <li><a href="/services/landscape-lawn-care/spraying">Licensed weed control</a> — lawns, beds, gravel, driveways, tree rings, pastures</li>
  <li>Insect and plant-health treatments</li>
  <li>Tree and shrub fertilization</li>
</ul>

<h3>Beds, trees and shrubs</h3>
<ul>
  <li>Pruning</li>
  <li>Bed weeding and maintenance</li>
</ul>

<h3>Seasonal</h3>
<ul>
  <li><a href="/services/landscape-lawn-care/yard-cleanup/spring-cleanup">Spring cleanup</a></li>
  <li><a href="/services/landscape-lawn-care/yard-cleanup/fall-cleanup">Fall cleanup</a></li>
</ul>

<p>Irrigation service, <a href="/winterize">sprinkler winterization</a> and <a href="/services/snow-removal">snow removal</a> are handled on their own agreements. If you want them lined up with your maintenance schedule, say so and we will coordinate the timing.</p>

<h2>Why a program instead of calling when something needs doing</h2>
<p><strong>Because most of this work has a window, and the window is short.</strong></p>
<p>Pre-emergent has to go down before the weeds germinate, not after you notice them. Aeration on a cool-season lawn wants early fall, and doing it in November accomplishes very little. Dormant oil goes on while the tree is dormant, which is a matter of weeks. Grub prevention is a calendar application, not a response to damage.</p>
<p>A homeowner calling when they think of it will miss most of those windows, every year, without ever knowing it. On a program, somebody is watching the calendar for the property instead of waiting for the phone to ring.</p>
<p><strong>The other reason is routing.</strong> Crews work an area at a time — one truck, one setup, one trip through a neighborhood. Program customers are on that route. A one-off call gets fit in where there is room, which in July is not many places.</p>
<p><strong>And seasonal capacity fills.</strong> Some services have limited availability once the season is staffed and booked. Contracts submitted early hold a place; contracts submitted in May get what is left.</p>

<h2>How to start</h2>
<p>Tell us about the property and which services you are interested in, and we will send you a Maintenance Agreement to review and sign.</p>
<p><a href="/contact" class="button button--primary">Request a Maintenance Agreement</a> &nbsp; or call <a href="tel:9708359661"><strong>970-835-9661</strong></a></p>
<p><em>Maintenance is priced by the property. We will look at it and give you a number before anything is scheduled.</em></p>
<p><strong>Agreements for the coming season are accepted through the winter. Early submission is strongly recommended — some services have limited availability once seasonal capacity is reached.</strong></p>

<h2>What happens next</h2>
<p><strong>We look at the property.</strong> Pricing depends on what is actually there — the size of the lawn, how much bed work there is, how many trees, and how the property is laid out. If you are already a customer, we usually already know.</p>
<p><strong>You get an agreement to review.</strong> It lists the services you selected, what each one costs, and how they are scheduled. Nothing is performed that is not on it.</p>
<p><strong>You sign it and we build it into the season.</strong> Your property goes onto a route. You will hear from the office about timing as the season gets organized.</p>

<h2>How the maintenance season works</h2>
<p>Residential maintenance contracts apply to services performed during the <strong>annual maintenance season</strong>, which generally runs from <strong>March 1 through October 31</strong>, weather permitting.</p>
<p>Service timing and frequency are influenced by:</p>
<ul>
  <li>Weather and soil conditions</li>
  <li>Seasonal demand</li>
  <li>Safety considerations</li>
  <li>Operational and routing efficiency</li>
</ul>
<p>For this reason, <strong>specific service dates cannot be guaranteed.</strong> Services are scheduled based on route planning and seasonal conditions rather than fixed calendar dates. Adjustments may be made throughout the season so that work is performed safely and effectively.</p>

<h2>Service selection and scope of work</h2>
<p>Only the services <strong>specifically selected in the signed Maintenance Agreement</strong> are included in the contract.</p>
<ul>
  <li>Services performed in prior years are <strong>not automatically included</strong> and must be selected again each season.</li>
  <li>Services not selected will not be performed.</li>
  <li>Some services require early selection to ensure availability.</li>
  <li>Late contract submission may limit scheduling options.</li>
  <li>Billing is based on <strong>completed services</strong>, unless otherwise noted.</li>
</ul>
<p>The agreement is structured to give homeowners flexibility while ensuring services are planned, staffed and executed properly.</p>
<p><strong>The re-selection rule is the one worth reading twice.</strong> Every season starts from a blank agreement. If you had aeration last year and do not select it this year, it will not happen, and nobody will call to ask. That is deliberate — it means you are never billed for something you did not ask for — but it does mean the agreement needs a real look each spring rather than a signature.</p>

<h2>What we do not promise</h2>
<p>Brookstone provides professional landscape and property maintenance services, <strong>not guaranteed outcomes.</strong> Results are influenced by weather, soil conditions, plant health, irrigation performance, and other natural factors beyond our control.</p>
<p>Our goal is consistent, responsible maintenance performed to industry standards — not shortcuts or unrealistic promises.</p>
<p>The signed Maintenance Agreement remains the governing document. This page explains how the program works and sets expectations before services begin; where the two differ, the agreement controls.</p>

<h2>Get on the schedule</h2>
<p>We have been maintaining property in Delta and Montrose counties since 1995 — Cedaredge, Delta, Austin, Eckert, Orchard City, Hotchkiss, Paonia, Crawford, Olathe and Montrose.</p>
<p><a href="/contact" class="button button--primary">Request a Maintenance Agreement</a> &nbsp; &middot; &nbsp; <a href="tel:9708359661"><strong>970-835-9661</strong></a></p>
<p>Thank you for choosing Brookstone Outdoors.</p>
HTML;

// Ensure a real (configurable) Metatag override field on Basic pages — the
// built-in "metatag" field is computed/read-only. This enables per-page SEO on
// every Basic page going forward.
if (!FieldStorageConfig::loadByName('node', 'field_meta_tags')) {
  FieldStorageConfig::create([
    'field_name' => 'field_meta_tags',
    'entity_type' => 'node',
    'type' => 'metatag',
    'cardinality' => 1,
  ])->save();
  $out[] = 'created field storage node.field_meta_tags';
}
if (!FieldConfig::loadByName('node', 'page', 'field_meta_tags')) {
  FieldConfig::create([
    'field_name' => 'field_meta_tags',
    'entity_type' => 'node',
    'bundle' => 'page',
    'label' => 'Meta tags',
  ])->save();
  $out[] = 'created field node.page.field_meta_tags';
}
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('node', 'page', 'default');
if (!$fd->getComponent('field_meta_tags')) {
  $fd->setComponent('field_meta_tags', ['type' => 'metatag_firehose', 'weight' => 100, 'region' => 'content'])->save();
  $out[] = 'added field_meta_tags to page form display';
}

$node = \Drupal::entityTypeManager()->getStorage('node')->load($NID);
if (!$node) {
  print "ERROR: node $NID not found\n";
  return;
}
$node->setTitle('Residential Maintenance Program');
$node->set('body', ['value' => $body, 'format' => 'full_html']);
// Per-page SEO override.
$node->set('field_meta_tags', ['value' => serialize([
  'title' => 'Lawn & Property Maintenance Program | Brookstone Outdoors',
  'description' => 'Seasonal lawn and property maintenance across Delta and Montrose counties. Mowing, fertilization, weed control, pruning and cleanup on one agreement.',
])]);
// Disable pathauto so nothing auto-generates; manage the alias explicitly below.
$node->path->pathauto = 0;
$node->save();

// Deterministic single alias: remove every existing alias for this node, then
// create exactly one (avoids the duplicate/stale-alias mess from path-field sets).
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$existing = $aliasStorage->loadByProperties(['path' => '/node/' . $NID]);
if ($existing) {
  $aliasStorage->delete($existing);
}
PathAlias::create(['path' => '/node/' . $NID, 'alias' => $NEW_ALIAS, 'langcode' => 'und'])->save();
$out[] = "node $NID updated: title, body, metatag; single alias $NEW_ALIAS";

// 301 redirect from the old path.
if (\Drupal::moduleHandler()->moduleExists('redirect')) {
  $src = ltrim($OLD_PATH, '/');
  $existing = \Drupal::entityTypeManager()->getStorage('redirect')
    ->loadByProperties(['redirect_source__path' => $src]);
  if (!$existing) {
    Redirect::create([
      'redirect_source' => ['path' => $src, 'query' => []],
      'redirect_redirect' => ['uri' => 'internal:' . $NEW_ALIAS],
      'language' => 'und',
      'status_code' => 301,
    ])->save();
    $out[] = "301 redirect created: $src -> $NEW_ALIAS";
  }
  else {
    $out[] = "redirect for '$src' already exists";
  }
}

print implode("\n", $out) . "\nDONE.\n";
