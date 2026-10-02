<?php

/**
 * @file
 * A client-facing test-history list: the public columns plus the report PDF.
 *
 * The spec wants a customer to reach their own report, which the public view
 * deliberately omits. Cloning the PUBLIC view rather than the internal one means
 * the client tier can never inherit the work order or the repairs-recommended
 * text by accident — it starts from the safe list and gains exactly one column.
 *
 * Idempotent: deletes and rebuilds from the current public view, so a change
 * there carries across.
 */

use Drupal\views\Entity\View;

$source = View::load('backflow_test_history_public_eva');
if (!$source) {
  print "ABORT: backflow_test_history_public_eva not found — nothing to clone.\n";
  return;
}
$internal = View::load('backflow_test_history_eva');
if (!$internal) {
  print "ABORT: backflow_test_history_eva not found — no report PDF field to copy.\n";
  return;
}

$existing = View::load('backflow_test_history_client_eva');
if ($existing) {
  $existing->delete();
  print "removed the previous client view so this rebuilds cleanly\n";
}

$clone = $source->createDuplicate();
$clone->set('id', 'backflow_test_history_client_eva');
$clone->set('label', 'Backflow Test History (Client)');
$clone->set('description', 'Test history shown to the customer: the public columns plus their report PDF. No work order, no repair notes.');

// Copy the report-PDF field definition verbatim from the internal view, so its
// formatter and settings match what the office already sees.
$internalFields = $internal->get('display')['default']['display_options']['fields'] ?? [];
if (!isset($internalFields['field_report_pdf'])) {
  print "ABORT: the internal view has no field_report_pdf to copy.\n";
  return;
}
$display = $clone->get('display');
$fields = $display['default']['display_options']['fields'] ?? [];
$pdf = $internalFields['field_report_pdf'];
$pdf['weight'] = 100;
$fields['field_report_pdf'] = $pdf;
$display['default']['display_options']['fields'] = $fields;
$clone->set('display', $display);
$clone->save();

$saved = View::load('backflow_test_history_client_eva');
printf("built %s\n  fields: %s\n", $saved->id(),
  implode(', ', array_keys($saved->get('display')['default']['display_options']['fields'] ?? [])));
$evaDisplays = array_filter($saved->get('display'), fn($d) => ($d['display_plugin'] ?? '') === 'entity_view');
printf("  EVA displays: %s\n", implode(', ', array_keys($evaDisplays)) ?: 'NONE');
