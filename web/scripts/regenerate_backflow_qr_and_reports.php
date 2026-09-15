<?php

/**
 * Regenerate backflow device QR codes and the frozen report PDFs.
 *
 * Devices created in a non-web context (Drush/import) had their permanent QR
 * generated with no request host, so it encoded "http://default/..." — an
 * unscannable URL. The QR generator is now hardened (backflow_device.module),
 * but the already-stored QR PNGs and the frozen report PDFs that embed them
 * must be rebuilt once.
 *
 *   - Device QR: rewritten in place (same file URI) via the fixed generator.
 *   - Report PDF: field_report_pdf cleared in memory, then regenerated (it
 *     re-reads the now-correct device QR). Same file URI, overwritten.
 *   - Service tags render on demand and self-heal (no action needed).
 *
 * Safe to re-run. Entity-API (no cim). Run per env:
 *   drush php:script web/scripts/regenerate_backflow_qr_and_reports.php
 */

use Drupal\Core\File\FileExists;

$out = [];
$etm = \Drupal::entityTypeManager();

// 1) Device QRs.
$device_ids = \Drupal::entityQuery('property_backflow_device')->accessCheck(FALSE)->execute();
$qr_done = 0;
foreach ($etm->getStorage('property_backflow_device')->loadMultiple($device_ids) as $device) {
  $code = function_exists('_backflow_device_code')
    ? _backflow_device_code($device)
    : (string) $device->label();
  $file = _backflow_device_generate_qr($device, $code);
  if ($file) {
    // Same URI is reused, but re-point the field defensively.
    $device->set('field_qr_code', [
      'target_id' => $file->id(),
      'alt' => 'QR code for ' . $code,
    ]);
    $device->save();
    $qr_done++;
  }
  else {
    $out[] = "WARN: QR regen failed for device {$device->id()} ($code)";
  }
}
$out[] = "device QRs regenerated: $qr_done / " . count($device_ids);

// 2) Frozen report PDFs — every backflow test child that already has one.
$child_ids = \Drupal::entityQuery('wo_tasks_list')
  ->accessCheck(FALSE)
  ->condition('type', 'backflow_testing')
  ->exists('field_report_pdf')
  ->execute();
$pdf_done = 0;
foreach ($etm->getStorage('wo_tasks_list')->loadMultiple($child_ids) as $child) {
  // Clear in memory so the (freeze-guarded) generator rebuilds it. The file URI
  // is deterministic and written with FileExists::Replace, so no orphan.
  $child->set('field_report_pdf', NULL);
  _wo_backflow_testing_generate_report_pdf($child);
  if (!$child->get('field_report_pdf')->isEmpty()) {
    $pdf_done++;
  }
  else {
    $out[] = "WARN: PDF regen failed for test child {$child->id()}";
  }
}
$out[] = "report PDFs regenerated: $pdf_done / " . count($child_ids);

print implode("\n", $out) . "\nDONE.\n";
