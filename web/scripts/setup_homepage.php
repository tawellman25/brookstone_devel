<?php

/**
 * Wire up the homepage (per env; run after `drush en bos_homepage`):
 *  1. Point the front page at /home (was /user/login).
 *  2. Create the Careers Basic page at /careers (editable in the UI).
 *  3. Place the sitewide header phone block in the brookstone_olivero header.
 * Idempotent.
 *
 *   drush php:script web/scripts/setup_homepage.php
 */

use Drupal\block\Entity\Block;
use Drupal\node\Entity\Node;

// ── 1. Front page ────────────────────────────────────────────────────────────
$site = \Drupal::configFactory()->getEditable('system.site');
if ($site->get('page.front') !== '/home') {
  $site->set('page.front', '/home')->save();
  print "front page -> /home\n";
}
else {
  print "front page already /home\n";
}

// ── 2. Careers page ──────────────────────────────────────────────────────────
$existing = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['type' => 'page', 'title' => 'Careers']);
if (!$existing) {
  $body = <<<'HTML'
<p>We are looking for crew leaders, foremen, equipment operators and seasonal crew. Full-time, year-round work for the right people — we run snow removal through the winter, so this is not a job that ends in October.</p>
<p>Brookstone Outdoors has been working in Delta and Montrose counties for over thirty years. We run five departments, around twenty-five people, and twenty-one trucks with equipment and trailers. We are licensed and insured, we carry our own equipment, and we take care of the people who take care of our customers.</p>
<h3>How to apply</h3>
<p>Call the office at 970-835-9661, or use our <a href="/contact">contact form</a> and tell us what you do. We will get back to you.</p>
HTML;
  $node = Node::create([
    'type' => 'page',
    'title' => 'Careers',
    'body' => ['value' => $body, 'format' => 'full_html'],
    'status' => 1,
    'uid' => 1,
    'path' => ['alias' => '/careers'],
  ]);
  $node->save();
  print "created Careers page (nid {$node->id()}) at /careers\n";
}
else {
  print "Careers page exists\n";
}

// ── 3. Header phone block ────────────────────────────────────────────────────
// Placed in the SECONDARY MENU utility row (top-right, with account/search) —
// NOT the primary-nav row. Adding a flex sibling to the primary-nav container
// makes the desktop menu wrap, which Olivero's nav-resize.js then collapses to a
// hamburger. The secondary-menu row does not feed that calculation.
$phone = Block::load('bos_homepage_phone');
if ($phone && $phone->getRegion() !== 'secondary_menu') {
  $phone->setRegion('secondary_menu');
  $phone->setWeight(-50);
  $phone->save();
  print "moved phone block to secondary_menu\n";
}
elseif (!$phone) {
  Block::create([
    'id' => 'bos_homepage_phone',
    'theme' => 'brookstone_olivero',
    'region' => 'secondary_menu',
    'plugin' => 'bos_homepage_phone',
    'weight' => -50,
    'settings' => [
      'id' => 'bos_homepage_phone',
      'label' => 'Phone',
      'label_display' => '0',
      'provider' => 'bos_homepage',
    ],
    'visibility' => [],
  ])->save();
  print "placed phone block (brookstone_olivero:secondary_menu)\n";
}
else {
  print "phone block already in secondary_menu\n";
}

print "DONE\n";
