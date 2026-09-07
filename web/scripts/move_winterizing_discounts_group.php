<?php

/**
 * @file
 * Nest the "Winterizing Discounts" field group INSIDE "Irrigation Fees" on the
 * Business Settings form, positioned above the Backflow Testing Rate.
 *
 * Idempotent. Run on each env (form display is config, but we keep git's
 * config/sync YAML authoritative rather than relying on a DB sync).
 *   drush php:script web/scripts/move_winterizing_discounts_group.php
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;

$fd = EntityFormDisplay::load('config_pages.business_setting.default');
if (!$fd) { print "form display not found\n"; return; }

$groups = $fd->getThirdPartySettings('field_group');
if (empty($groups['group_winterizing_discounts']) || empty($groups['group_irrigation_fees'])) {
  print "expected groups missing\n"; return;
}

// 1. Backflow rate → weight 113 (so the discounts group at 112 sits above it,
//    just under the pump fee at 111).
$bf = $fd->getComponent('field_backflow_testing_rate');
if ($bf) {
  $bf['weight'] = 113;
  $fd->setComponent('field_backflow_testing_rate', $bf);
}

// 2. Nest the discounts group under Irrigation Fees at weight 112.
$groups['group_winterizing_discounts']['parent_name'] = 'group_irrigation_fees';
$groups['group_winterizing_discounts']['weight'] = 112;

// 3. Add it to Irrigation Fees' children (before backflow) if not already there.
$children = $groups['group_irrigation_fees']['children'];
if (!in_array('group_winterizing_discounts', $children, TRUE)) {
  $pos = array_search('field_backflow_testing_rate', $children, TRUE);
  if ($pos === FALSE) { $children[] = 'group_winterizing_discounts'; }
  else { array_splice($children, $pos, 0, ['group_winterizing_discounts']); }
  $groups['group_irrigation_fees']['children'] = array_values($children);
}

foreach ($groups as $gid => $def) { $fd->setThirdPartySetting('field_group', $gid, $def); }
$fd->save();

print "Nested group_winterizing_discounts under group_irrigation_fees (weight 112, backflow→113).\n";
print "Irrigation children: " . implode(', ', $groups['group_irrigation_fees']['children']) . "\nDONE.\n";
