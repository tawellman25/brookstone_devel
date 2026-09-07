<?php

/**
 * @file
 * Consolidate snow settings on the Business Settings form:
 *  - move "Snow Removal Labor" (field_snow_removal_labor) and "Snow Removal
 *    Labor Cost" (field_labor_cost_snow_removal) from Labor Rates into the Snow
 *    Removal group (group_snow_removal), at the top.
 *  - delete the empty duplicate "Snow Removal" group (group_snow_removal_labor).
 *
 * Idempotent; run per env (keep git config/sync authoritative).
 *   drush php:script web/scripts/consolidate_snow_removal_group.php
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;

$fd = EntityFormDisplay::load('config_pages.business_setting.default');
if (!$fd) { print "form display not found\n"; return; }
$MOVE = ['field_snow_removal_labor', 'field_labor_cost_snow_removal'];
$DEST = 'group_snow_removal';
$DROP = 'group_snow_removal_labor';

$groups = $fd->getThirdPartySettings('field_group');
if (empty($groups[$DEST])) { print "$DEST missing\n"; return; }

// Remove the fields from every group's children.
foreach ($groups as $gid => $def) {
  $kids = $def['children'] ?? [];
  $kids = array_values(array_filter($kids, fn($c) => !in_array($c, $MOVE, TRUE)));
  $groups[$gid]['children'] = $kids;
}

// Weight the two fields to sort first in the destination group.
$minW = 999;
foreach ($groups[$DEST]['children'] as $c) {
  $comp = $fd->getComponent($c);
  if ($comp && isset($comp['weight'])) { $minW = min($minW, (int) $comp['weight']); }
}
$w = $minW - count($MOVE);
foreach ($MOVE as $f) {
  $comp = $fd->getComponent($f) ?: ['region' => 'content', 'settings' => [], 'third_party_settings' => []];
  $comp['weight'] = $w++;
  $fd->setComponent($f, $comp);
}

// Add them to the destination group (labor first, then cost).
$groups[$DEST]['children'] = array_merge($MOVE, $groups[$DEST]['children']);

// Delete the empty duplicate group.
if (isset($groups[$DROP])) {
  $fd->unsetThirdPartySetting('field_group', $DROP);
  unset($groups[$DROP]);
}

foreach ($groups as $gid => $def) { $fd->setThirdPartySetting('field_group', $gid, $def); }
$fd->save();

print "Moved " . implode(' + ', $MOVE) . " into $DEST; deleted $DROP.\n";
print "Snow Removal children: " . implode(', ', $groups[$DEST]['children']) . "\nDONE.\n";
