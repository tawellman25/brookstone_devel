<?php

/**
 * @file
 * Open the "Notes" group by default on Pre-emergent work orders.
 *
 * Todd, 2026-11-03. The crew had to expand Notes on every pre-emergent WO to
 * read it; a collapsed group on a page the crew reads first is a note nobody
 * reads.
 *
 * ⚠ SURGICAL BY DESIGN. Live view displays are Manage-Display-curated and
 * differ from dev, so this touches exactly ONE key —
 * third_party_settings.field_group.group_notes.format_settings.open — on ONE
 * display, and leaves every other field, group, weight and setting alone. Do
 * not grow this into a display builder.
 *
 * Scope is pre_emergent only, as asked. Notes is collapsed on most other
 * bundles too; that is not this change's business.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/open_pre_emergent_notes_group.php
 */

const DISPLAY = 'work_order.pre_emergent.default';
const GROUP = 'group_notes';

$apply = getenv('BOS_APPLY') === '1';
$display = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load(DISPLAY);

if (!$display) {
  printf("ABORT: display %s not found.\n", DISPLAY);
  return;
}

$group = $display->getThirdPartySetting('field_group', GROUP);
if (!is_array($group)) {
  printf("ABORT: %s has no '%s' field group. Groups present: %s\n", DISPLAY, GROUP,
    implode(', ', array_keys($display->getThirdPartySettings('field_group') ?: [])));
  return;
}

printf("%s / %s (\"%s\")\n", DISPLAY, GROUP, $group['label'] ?? '?');
printf("  format_type : %s\n", $group['format_type'] ?? '?');
printf("  region      : %s\n", $group['region'] ?? '?');
printf("  open        : %s\n", !empty($group['format_settings']['open']) ? 'true' : 'false');

// `open` only means anything to a collapsible format.
if (($group['format_type'] ?? '') !== 'details') {
  printf("\nABORT: format_type is '%s', not 'details' — 'open' would do nothing.\n", $group['format_type'] ?? '?');
  return;
}
if (!empty($group['format_settings']['open'])) {
  print "\nAlready open. Nothing to do.\n";
  return;
}
if (!$apply) {
  print "\nWould set open: true.\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

$group['format_settings']['open'] = TRUE;
$display->setThirdPartySetting('field_group', GROUP, $group)->save();

// Verify from a fresh load, not the object we just saved.
$fresh = \Drupal::entityTypeManager()->getStorage('entity_view_display')
  ->loadUnchanged(DISPLAY)->getThirdPartySetting('field_group', GROUP);
printf("\nopen is now: %s  %s\n",
  !empty($fresh['format_settings']['open']) ? 'true' : 'false',
  !empty($fresh['format_settings']['open']) ? 'OK' : '** FAILED **');

// Prove nothing else moved.
printf("children unchanged: %s\n", implode(', ', $fresh['children'] ?? []));
printf("weight still %s, region still %s\n", $fresh['weight'] ?? '?', $fresh['region'] ?? '?');
