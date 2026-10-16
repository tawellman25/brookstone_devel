<?php

/**
 * @file
 * Turn on the VBO confirmation step for every bulk action on a view.
 *
 * Found while tracing the 2026-10-15 over-invoicing: of the eight views that
 * expose the invoicing actions, `admin_work_order_administration` was the ONLY
 * one with the confirmation step switched off — and it was off for all SEVEN of
 * its actions, including Cancel and Warranty, which are as consequential as
 * invoicing. On that screen a bulk action applied immediately, with no
 * interstitial and no count.
 *
 * ⚠ The action plugins' own `confirm = TRUE` annotation DOES NOT DO THIS.
 * VBO ignores it; it reads `preconfiguration.add_confirmation` from the VIEW's
 * bulk-form field, or the plugin's `confirm_form_route_name`
 * (ViewsBulkOperationsBulkForm::buildForm, ~line 601). Reading the annotation
 * and concluding a confirmation step existed is exactly the mistake that was
 * made while diagnosing this.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1. Set BOS_VIEW to target another view.
 *
 * Run: drush php:script web/scripts/enable_vbo_confirmation.php
 */

$viewId = getenv('BOS_VIEW') ?: 'admin_work_order_administration';
$apply = getenv('BOS_APPLY') === '1';

$config = \Drupal::configFactory()->getEditable('views.view.' . $viewId);
if ($config->isNew()) {
  printf("ABORT: view %s does not exist here.\n", $viewId);
  return;
}

$changed = 0;
foreach ((array) $config->get('display') as $did => $display) {
  $fields = $display['display_options']['fields'] ?? NULL;
  if ($fields === NULL) {
    // Inherits the default display — do not write, or inheritance breaks.
    continue;
  }
  foreach ($fields as $fid => $field) {
    if (($field['plugin_id'] ?? '') !== 'views_bulk_operations_bulk_form') {
      continue;
    }
    $actions = $field['selected_actions'] ?? [];
    foreach ($actions as $i => $action) {
      $current = $action['preconfiguration']['add_confirmation'] ?? NULL;
      if ($current === TRUE) {
        printf("  %-14s %-46s already on\n", $did, $action['action_id'] ?? '?');
        continue;
      }
      $actions[$i]['preconfiguration']['add_confirmation'] = TRUE;
      $changed++;
      printf("  %-14s %-46s %s -> true\n", $did, $action['action_id'] ?? '?', var_export($current, TRUE));
    }
    $config->set("display.$did.display_options.fields.$fid.selected_actions", $actions);
  }
}

if (!$changed) {
  print "\nNothing to change.\n";
  return;
}
if (!$apply) {
  printf("\nDRY RUN — %d action(s) on %s would get a confirmation step.\n", $changed, $viewId);
  print "Re-run with BOS_APPLY=1 to write.\n";
  return;
}

$config->save();
printf("\nSaved. %d action(s) updated on %s.\n", $changed, $viewId);

// Verify from a fresh read.
$after = \Drupal::config('views.view.' . $viewId);
foreach ((array) $after->get('display') as $did => $display) {
  foreach ((array) ($display['display_options']['fields'] ?? []) as $field) {
    if (($field['plugin_id'] ?? '') !== 'views_bulk_operations_bulk_form') {
      continue;
    }
    $off = [];
    foreach ((array) ($field['selected_actions'] ?? []) as $a) {
      if (($a['preconfiguration']['add_confirmation'] ?? NULL) !== TRUE) {
        $off[] = $a['action_id'] ?? '?';
      }
    }
    printf("  %-14s actions still without confirmation: %s\n", $did, $off ? implode(', ', $off) : 'none');
  }
}
