<?php

/**
 * Place the acknowledgment form block on the ack page and add the report menu
 * link under the admin "Handbook" item. Idempotent; run per env after the module
 * is enabled. (Block placement + menu link are content/config with env-local IDs.)
 *
 *   ddev drush php:script web/scripts/setup_handbook_ack_ui.php   (local)
 *   drush php:script web/scripts/setup_handbook_ack_ui.php        (live)
 */

use Drupal\block\Entity\Block;
use Drupal\menu_link_content\Entity\MenuLinkContent;

// ── 1. Acknowledgment form block on the ack page (crew theme). ──
$bid = 'brookstone_olivero_handbook_ack';
if (!Block::load($bid)) {
  Block::create([
    'id' => $bid,
    'theme' => 'brookstone_olivero',
    'region' => 'content_below',
    'plugin' => 'handbook_acknowledgment_block',
    'weight' => 0,
    'settings' => [
      'id' => 'handbook_acknowledgment_block',
      'label' => 'Acknowledge the Team Handbook',
      'label_display' => 'visible',
      'provider' => 'bos_handbook_ack',
    ],
    'visibility' => [
      'request_path' => [
        'id' => 'request_path',
        'pages' => '/teammates/training/handbook/acknowledgment',
        'negate' => FALSE,
      ],
    ],
  ])->save();
  print "placed block: $bid (content_below on the ack page)\n";
}
else {
  print "block exists: $bid\n";
}

// ── 2. Report menu link under the admin "Handbook" item. ──
$mlc = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$uri = 'internal:/admin/operations/training/handbook/acknowledgments';

$parent = '';
$ph = $mlc->getQuery()->accessCheck(FALSE)
  ->condition('menu_name', 'admin')->condition('title', 'Handbook')->execute();
if ($ph) {
  $parent = 'menu_link_content:' . $mlc->load(reset($ph))->uuid();
}

$ex = $mlc->getQuery()->accessCheck(FALSE)
  ->condition('menu_name', 'admin')->condition('link.uri', $uri)->execute();
if ($ex) {
  $link = $mlc->load(reset($ex));
  print "menu link exists (id " . $link->id() . ")\n";
}
else {
  $link = MenuLinkContent::create([
    'title' => 'Acknowledgments',
    'link' => ['uri' => $uri],
    'menu_name' => 'admin',
    'weight' => 2,
    'expanded' => FALSE,
  ]);
  print "created menu link 'Acknowledgments'\n";
}
$link->set('weight', 2);
if ($parent !== '') {
  $link->set('parent', $parent);
  print "  nested under admin 'Handbook'\n";
}
else {
  print "  WARNING: admin 'Handbook' link not found — left at top level of admin menu\n";
}
$link->save();

\Drupal::service('plugin.manager.menu.link')->rebuild();
print "Done.\n";
