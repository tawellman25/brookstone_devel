<?php

/**
 * @file
 * Move "Default Salt Rate (per lb)" (field_default_salt_per_lb) into the Snow
 * Removal field group (group_snow_removal), just above the per-customer Salt
 * Rate. Idempotent; run per env (keep git config/sync authoritative).
 *   drush php:script web/scripts/move_default_salt_rate_group.php
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;

$fd = EntityFormDisplay::load('config_pages.business_setting.default');
if (!$fd) { print "form display not found\n"; return; }
$FIELD = 'field_default_salt_per_lb';
$GROUP = 'group_snow_removal';

$groups = $fd->getThirdPartySettings('field_group');
if (empty($groups[$GROUP])) { print "$GROUP missing\n"; return; }

// Weight it just above field_salt_rate (127) so it reads Default → per-customer.
$c = $fd->getComponent($FIELD);
if ($c) {
  $c['weight'] = 126;
  $fd->setComponent($FIELD, $c);
}

// Remove from any other group's children, then add to Snow Removal.
foreach ($groups as $gid => $def) {
  if ($gid === $GROUP) { continue; }
  $kids = $def['children'] ?? [];
  if (($k = array_search($FIELD, $kids, TRUE)) !== FALSE) {
    array_splice($kids, $k, 1);
    $groups[$gid]['children'] = array_values($kids);
  }
}
$kids = $groups[$GROUP]['children'];
if (!in_array($FIELD, $kids, TRUE)) {
  $pos = array_search('field_salt_rate', $kids, TRUE);
  if ($pos === FALSE) { $kids[] = $FIELD; }
  else { array_splice($kids, $pos, 0, [$FIELD]); }
  $groups[$GROUP]['children'] = array_values($kids);
}

foreach ($groups as $gid => $def) { $fd->setThirdPartySetting('field_group', $gid, $def); }
$fd->save();

print "Moved $FIELD into $GROUP (weight 126, above field_salt_rate).\n";
print "Snow Removal children: " . implode(', ', $groups[$GROUP]['children']) . "\nDONE.\n";
