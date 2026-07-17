<?php

/**
 * Add a VBO confirmation step to the contract-section "Mark Yes / Mark No /
 * Request Quote" bulk actions on the contract_sections_admin_table view.
 *
 * Why: on 2026-07-15 the office manager selected every row in the contract
 * sections table and clicked Mark "Yes" — it applied instantly to all 23
 * sections (19 actually changed; 4 were already Yes) on contract #4700, because
 * these actions carried `add_confirmation: false`. With confirmation on, VBO
 * shows "Are you sure you wish to perform 'Mark "Yes"' action on N entities?"
 * so a select-all mistake is visible BEFORE it is applied. This mirrors the
 * 2026-06-30 fix that added the same confirmation to the six billing views
 * after the accidental mass-invoice.
 *
 * Applied as an idempotent entity-API edit rather than a partial-cim: this
 * view is drifted from config/sync (live is missing two language cache-context
 * lines), so importing the whole file would push unrelated changes. Run once
 * per environment.
 *
 * Run: drush php:script web/scripts/contract_sections_add_confirmation.php
 */

const VIEW_ID = 'views.view.contract_sections_admin_table';

// The three contract-section intent actions that mutate field_do_you_want.
const GUARDED_ACTION_IDS = [
  'contract_section_set_do_you_want_yes',
  'contract_section_set_do_you_want_no',
  'contract_section_set_do_you_want_request_quote',
];

$config = \Drupal::configFactory()->getEditable(VIEW_ID);
if ($config->isNew()) {
  print "ABORT: " . VIEW_ID . " not found in this environment.\n";
  return;
}

$displays = $config->get('display') ?: [];
$changed = 0;
$already = 0;

foreach ($displays as $display_id => $display) {
  $path = "display.$display_id.display_options.fields.views_bulk_operations_bulk_form.selected_actions";
  $actions = $config->get($path);
  if (!is_array($actions)) {
    continue;
  }
  foreach ($actions as $delta => $action) {
    if (!in_array($action['action_id'] ?? '', GUARDED_ACTION_IDS, TRUE)) {
      continue;
    }
    if (!empty($action['preconfiguration']['add_confirmation'])) {
      $already++;
      printf("  already on : %s (%s)\n", $action['action_id'], $display_id);
      continue;
    }
    $actions[$delta]['preconfiguration']['add_confirmation'] = TRUE;
    $changed++;
    printf("  confirming : %s (%s)\n", $action['action_id'], $display_id);
  }
  if ($changed) {
    $config->set($path, $actions);
  }
}

if ($changed) {
  $config->save();
  printf("saved — %d action(s) now require confirmation (%d already had it)\n", $changed, $already);
}
else {
  printf("no change — %d action(s) already require confirmation\n", $already);
}
