<?php

/**
 * Place the portal-waitlist block on the login page (brookstone_olivero,
 * content region, /user/login only). Idempotent. Per-env (block placement is
 * per-environment content, like the winterize/homepage blocks — not cim).
 *   ddev drush php:script web/scripts/setup_portal_waitlist_block.php
 */

use Drupal\block\Entity\Block;

$THEME = 'brookstone_olivero';
$ID = 'portal_waitlist_login';

if (!Block::load($ID)) {
  Block::create([
    'id' => $ID,
    'plugin' => 'bos_portal_waitlist',
    'region' => 'content',
    'theme' => $THEME,
    'weight' => 10,
    'settings' => [
      'id' => 'bos_portal_waitlist',
      'label' => 'Customer portal waitlist',
      'label_display' => '0',
    ],
    'visibility' => [
      'request_path' => [
        'id' => 'request_path',
        'pages' => '/user/login',
        'negate' => FALSE,
      ],
    ],
  ])->save();
  print "created block $ID on $THEME\n";
}
else {
  print "block $ID exists\n";
}
print "DONE\n";
