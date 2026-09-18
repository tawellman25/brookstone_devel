<?php

/**
 * Index page /services/landscape-lawn-care/spraying/chemicals — a made Basic page
 * (its children are sub-lists, not entities). Currently links to Signal Words;
 * add more chemical reference sub-lists here as they are created.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_spray_chemicals_index.php
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

$ALIAS = '/services/landscape-lawn-care/spraying/chemicals';
$body = '<p>Reference information on the chemicals used in our licensed spray applications. Choose a topic below.</p>'
  . "\n<ul>\n"
  . '  <li><a href="/services/landscape-lawn-care/spraying/chemicals/signal-words">Signal Words</a> — what the Caution, Warning and Danger labels mean.</li>' . "\n"
  . "</ul>";

$am = \Drupal::service('path_alias.manager');
$sys = $am->getPathByAlias($ALIAS);
$node = NULL;
if (preg_match('#^/node/(\d+)$#', $sys, $m)) {
  $node = Node::load($m[1]);
}
if (!$node) {
  $node = Node::create(['type' => 'page', 'uid' => 1, 'status' => 1]);
}
$node->setTitle('Spray Chemicals');
$node->set('body', ['value' => $body, 'format' => 'full_html']);
if ($node->hasField('field_meta_tags')) {
  $node->set('field_meta_tags', ['value' => serialize([
    'title' => 'Spray Chemicals | Brookstone Outdoors',
    'description' => 'Reference on the chemicals used in our licensed spray applications in Delta & Montrose counties, CO — including what the signal words on the label mean.',
  ])]);
}
$node->path->pathauto = 0;
$node->save();
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$existing = $aliasStorage->loadByProperties(['path' => '/node/' . $node->id()]);
if ($existing) {
  $aliasStorage->delete($existing);
}
PathAlias::create(['path' => '/node/' . $node->id(), 'alias' => $ALIAS, 'langcode' => 'und'])->save();

print "index Basic page node {$node->id()} at $ALIAS\nDONE.\n";
