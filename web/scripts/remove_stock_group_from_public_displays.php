<?php

/**
 * @file
 * Take "Stock & Lead Time" off the material PUBLIC displays.
 *
 * The group is `group_stock_lead_time`, holding field_last_restocked_date,
 * field_lead_time and field_quantity_in_stock. It sits on 7 public displays
 * and the three fields are components on 8.
 *
 * ⚠ THIS IS NOT CLOSING A LEAK, and saying so matters so nobody later assumes
 * the display was the protection. All three fields are already in
 * MATERIAL_FIELD_OFFICE_CREW, and material_entity_field_access() denies them to
 * anonymous users — verified across plants, annuals, shrubs, trees, mulch, sod
 * and decorative_rock: an anonymous account can view NONE of them, and the live
 * Dahlia page renders no "Stock & Lead Time" heading at all.
 *
 * It is worth doing anyway, for two reasons:
 *   - a display listing fields it can never render is config that lies about
 *     what the page shows;
 *   - it is the second line of defence. If field access is ever relaxed — a
 *     constant edited, a role granted — these would appear on public pages
 *     immediately. Removed from the display, they would not.
 *
 * Only *.public displays are touched. admin / teammate / client / default keep
 * the group: those audiences are exactly who the stock figures are for.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/remove_stock_group_from_public_displays.php
 */

const GROUP = 'group_stock_lead_time';
const FIELDS = ['field_last_restocked_date', 'field_lead_time', 'field_quantity_in_stock'];

$apply = getenv('BOS_APPLY') === '1';
$storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
$ids = \Drupal::entityQuery('entity_view_display')->accessCheck(FALSE)
  ->condition('id', 'material.', 'STARTS_WITH')->execute();

$changed = 0;
$touched = [];
foreach ($storage->loadMultiple($ids) as $id => $display) {
  if (!str_ends_with($id, '.public')) {
    continue;
  }
  $removedFields = [];
  $removedGroup = FALSE;

  foreach (FIELDS as $field) {
    if ($display->getComponent($field)) {
      $removedFields[] = $field;
    }
  }
  $group = $display->getThirdPartySetting('field_group', GROUP);
  if ($group) {
    $removedGroup = TRUE;
    // ⚠ A group can hold children this script is not removing. Refuse rather
    // than silently orphan them — an orphaned child renders outside any group,
    // which looks like a layout bug long after anyone remembers why.
    $unexpected = array_diff($group['children'] ?? [], FIELDS);
    if ($unexpected) {
      printf("  %-34s SKIPPED — group holds unexpected children: %s\n", $id, implode(', ', $unexpected));
      continue;
    }
  }

  if (!$removedFields && !$removedGroup) {
    continue;
  }
  $touched[] = sprintf('%-34s fields: %-2d  group: %s', $id, count($removedFields), $removedGroup ? 'yes' : 'no');
  if ($apply) {
    foreach ($removedFields as $field) {
      $display->removeComponent($field);
    }
    if ($removedGroup) {
      $display->unsetThirdPartySetting('field_group', GROUP);
    }
    $display->save();
  }
  $changed++;
}

printf("%s %d public display(s):\n", $apply ? 'UPDATED' : 'WOULD UPDATE', $changed);
foreach ($touched as $t) {
  print "  $t\n";
}
if (!$changed) {
  print "Nothing to do — already removed.\n";
  return;
}
if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1 to write.\n";
  return;
}

// Prove it from fresh reads, and confirm the other audiences still have it.
$stillPublic = [];
$keptElsewhere = 0;
foreach ($storage->loadMultiple($ids) as $id => $display) {
  $fresh = $storage->loadUnchanged($id);
  $has = (bool) $fresh->getThirdPartySetting('field_group', GROUP);
  foreach (FIELDS as $field) {
    $has = $has || (bool) $fresh->getComponent($field);
  }
  if (str_ends_with($id, '.public')) {
    if ($has) {
      $stillPublic[] = $id;
    }
  }
  elseif ($has) {
    $keptElsewhere++;
  }
}
printf("\npublic displays still showing it: %s\n", $stillPublic ? implode(', ', $stillPublic) : 'none');
printf("non-public displays that KEEP it (admin/teammate/client/default): %d\n", $keptElsewhere);
