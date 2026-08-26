<?php

/**
 * @file
 * Idempotent: create the teammate "Employment" landing page (site_landing_page:
 * teammate) at /teammates/employment featuring the Team Handbook, plus a
 * teammate-navigation menu link to it. Content (entity + menu link) — run per
 * environment (dev then live); does not sync via config.
 *
 * Run: (ddev) drush php:script web/scripts/setup_teammate_employment_landing.php
 *      (live) drush php:script web/scripts/setup_teammate_employment_landing.php
 */

use Drupal\menu_link_content\Entity\MenuLinkContent;

$alias = '/teammates/employment';
$slp_storage = \Drupal::entityTypeManager()->getStorage('site_landing_page');

// --- 1. The Employment landing page (idempotent on the alias). ---
$existing = $slp_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'teammate')
  ->condition('title', 'Employment')
  ->execute();

$body = <<<HTML
<h2>Employment &amp; Team Resources</h2>
<p>Welcome to the team. This is your starting point for how we work at Brookstone Outdoors &mdash; begin with the Team Handbook below.</p>

<h3>Team Handbook</h3>
<p>Our policies, expectations, and the way we do things &mdash; from time off and pay to conduct and safety. This is the same handbook you received in print; the online copy is kept in step with it.</p>
<p><a href="/teammates/training/handbook"><strong>Open the Team Handbook &rarr;</strong></a></p>

<h3>More resources</h3>
<p>We&rsquo;ll add more here over time &mdash; benefits, time-off requests, pay questions, forms, and safety references. If you need something that isn&rsquo;t here yet, ask the office.</p>
HTML;

if ($existing) {
  $slp = $slp_storage->load(reset($existing));
  print "Employment landing page already exists (id " . $slp->id() . ") — leaving content as-is.\n";
}
else {
  $slp = $slp_storage->create([
    'type' => 'teammate',
    'title' => 'Employment',
    'status' => 1,
    'field_description' => ['value' => $body, 'format' => 'full_html'],
    'path' => ['alias' => $alias],
  ]);
  $slp->save();
  print "Created Employment landing page (id " . $slp->id() . ") at " . $alias . "\n";
}

// --- 2. Teammate-navigation menu link, nested UNDER "Office". ---
$uri = 'internal:' . $alias;
$mlc_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

// Resolve the "Office" link so we can parent Employment beneath it (UUID differs
// per env, so look it up rather than hardcode).
$office_ids = $mlc_storage->getQuery()->accessCheck(FALSE)
  ->condition('menu_name', 'teammate-navigation')
  ->condition('title', 'Office')
  ->execute();
$parent = $office_ids ? 'menu_link_content:' . $mlc_storage->load(reset($office_ids))->uuid() : '';

$link_ids = $mlc_storage->getQuery()->accessCheck(FALSE)
  ->condition('menu_name', 'teammate-navigation')
  ->condition('link.uri', $uri)
  ->execute();

if ($link_ids) {
  $link = $mlc_storage->load(reset($link_ids));
  print "Teammate menu link already exists (id " . $link->id() . ").\n";
}
else {
  $link = MenuLinkContent::create([
    'title' => 'Employment',
    'link' => ['uri' => $uri],
    'menu_name' => 'teammate-navigation',
    'expanded' => FALSE,
    'enabled' => TRUE,
  ]);
  print "Created teammate-navigation menu link 'Employment' → " . $alias . "\n";
}

$link->set('weight', 1);
if ($parent !== '') {
  $link->set('parent', $parent);
  print "Nested under Office (" . $parent . ").\n";
}
else {
  print "WARNING: 'Office' link not found — Employment left at top level.\n";
}
$link->save();

print "Done.\n";
