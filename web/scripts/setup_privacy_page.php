<?php

/**
 * @file
 * Idempotent setup for the /privacy page (a standard `page` node, mirroring
 * /about). Body is a short interim placeholder until Todd supplies the approved
 * Privacy Policy text — the footer must NOT go live until this holds a real
 * policy (Google Ads + Meta + sitewide GTM require one).
 *
 *   ddev drush php:script web/scripts/setup_privacy_page.php      (dev)
 *   drush php:script web/scripts/setup_privacy_page.php           (live)
 */

use Drupal\node\Entity\Node;
use Drupal\path_alias\Entity\PathAlias;

$aliasManager = \Drupal::service('path_alias.manager');
$nodeStorage = \Drupal::entityTypeManager()->getStorage('node');

$placeholder = '<p><em>Our full Privacy Policy is being finalized.</em> In the meantime, for any question about the information Brookstone Outdoors collects or how it is used, contact <a href="mailto:office@brookstoneoutdoors.com">office@brookstoneoutdoors.com</a> or call <a href="tel:9708359661">970-835-9661</a>.</p>';

$existingSource = $aliasManager->getPathByAlias('/privacy');
$node = NULL;
if ($existingSource !== '/privacy' && preg_match('#^/node/(\d+)$#', $existingSource, $m)) {
  $node = $nodeStorage->load($m[1]);
}

if ($node) {
  echo "• /privacy node exists (nid {$node->id()}) — left untouched (won't clobber real text).\n";
}
else {
  $node = Node::create([
    'type' => 'page',
    'title' => 'Privacy Policy',
    'uid' => 1,
    'status' => 1,
    'body' => ['value' => $placeholder, 'format' => 'full_html'],
  ]);
  $node->save();
  echo "• Created /privacy page node nid {$node->id()} (interim placeholder body).\n";
}

$source = '/node/' . $node->id();
$aliasExists = \Drupal::entityTypeManager()->getStorage('path_alias')->getQuery()
  ->condition('alias', '/privacy')->condition('path', $source)
  ->accessCheck(FALSE)->range(0, 1)->execute();
if ($aliasExists) {
  echo "• Alias /privacy already present.\n";
}
else {
  PathAlias::create(['path' => $source, 'alias' => '/privacy', 'langcode' => 'en'])->save();
  echo "• Created alias /privacy → {$source}.\n";
}
echo "Done.\n";
