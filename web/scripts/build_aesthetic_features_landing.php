<?php

/**
 * Prototype category landing page: /material/plants/characteristics/aesthetic-features
 *
 * A short description + an auto-generated list of links to the child terms
 * (Flowering, Foliage Interest, Fruit-Bearing, Fragrant, Ornamental), each with
 * the first sentence of its own description. Demonstrates the pattern for the
 * ~30 missing category pages (see docs/sitemap-category-pages.md).
 *
 * Built as a Basic page (node) so the intro copy is editable; the child links
 * are generated from the live terms at build time. Idempotent (find-or-create
 * by alias). Entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_aesthetic_features_landing.php
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

$ALIAS = '/material/plants/characteristics/aesthetic-features';
$CATEGORY_KEY = 1; // Aesthetic Features (field_characteristic_category)
$H1 = 'Aesthetic Features';
$INTRO = 'Aesthetic features describe how a plant looks and what it brings to a landscape — its flowers, foliage, fruit, fragrance and overall ornamental character. Use the categories below to explore plants chosen for visual and sensory appeal.';

$am = \Drupal::service('path_alias.manager');

// Build the child link list from the live terms.
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)
  ->condition('vid', 'plant_characteristics')
  ->condition('field_characteristic_category', $CATEGORY_KEY)
  ->sort('name')
  ->execute();
$items = '';
foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadMultiple($tids) as $t) {
  $alias = $am->getAliasByPath('/taxonomy/term/' . $t->id());
  $desc = trim(strip_tags($t->getDescription() ?? ''));
  // First sentence as a teaser.
  $teaser = '';
  if ($desc !== '' && preg_match('/^(.*?[.!?])(\s|$)/u', $desc, $m)) {
    $teaser = ' — ' . $m[1];
  }
  $items .= '  <li><a href="' . $alias . '"><strong>' . htmlspecialchars($t->label()) . '</strong></a>' . htmlspecialchars($teaser) . "</li>\n";
}

$body = '<p>' . $INTRO . "</p>\n<ul class=\"category-children\">\n" . $items . "</ul>\n"
  . '<p><a href="/material/plants/characteristics">&larr; All plant characteristics</a></p>';

// Find-or-create the node by its alias.
$sys = $am->getPathByAlias($ALIAS);
$node = NULL;
if (preg_match('#^/node/(\d+)$#', $sys, $mm)) {
  $node = Node::load($mm[1]);
}
if (!$node) {
  $node = Node::create(['type' => 'page', 'uid' => 1, 'status' => 1]);
}
$node->setTitle($H1);
$node->set('body', ['value' => $body, 'format' => 'full_html']);
if ($node->hasField('field_meta_tags')) {
  $node->set('field_meta_tags', ['value' => serialize([
    'title' => 'Aesthetic Features — Plants | Brookstone Outdoors',
    'description' => 'Plants chosen for visual and sensory appeal — flowering, foliage interest, fruit-bearing, fragrant and ornamental shrubs for Delta & Montrose CO landscapes.',
  ])]);
}
$node->path->pathauto = 0;
$node->save();

// Deterministic single alias.
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$existing = $aliasStorage->loadByProperties(['path' => '/node/' . $node->id()]);
if ($existing) {
  $aliasStorage->delete($existing);
}
PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $ALIAS, 'langcode' => 'und'])->save();

print "node {$node->id()} — $ALIAS (" . count($tids) . " child links)\nDONE.\n";
