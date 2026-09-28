<?php

declare(strict_types=1);

/**
 * Order the testimonial form for the job it is actually used for.
 *
 * Office is entering a backlog of handwritten notes, so the fields they touch
 * on every single record come first — the words, who said them, the scan, then
 * the public image with its cropping warning right under it. Status and the
 * linking fields (customer, work order, submitter email) are occasional, so
 * they move below.
 *
 * Previously Testimony sat seventh, under Status, Work order, Submitter email
 * and both images.
 *
 * Form display only — no field, access or view-display change.
 * Idempotent; run per environment.
 *
 *   drush php:script web/scripts/order_testimonial_form.php
 */

$order = [
  'field_testimony'            => 0,
  'field_testimonial_by'       => 1,
  'field_testimonial_scan'     => 2,
  'field_testimonial_image'    => 3,
  'field_testimony_service'    => 4,
  'field_status'               => 5,
  'field_customer'             => 6,
  'field_work_order'           => 7,
  'field_submitter_email'      => 8,
];

$display = \Drupal::entityTypeManager()->getStorage('entity_form_display')
  ->load('testimonial.client.default');
if (!$display) {
  print "ABORT: no testimonial.client.default form display\n";
  return;
}

$changed = 0;
foreach ($order as $name => $weight) {
  $component = $display->getComponent($name);
  if (!$component) {
    print "  MISS   $name — not on the form\n";
    continue;
  }
  if ((int) ($component['weight'] ?? 0) === $weight) {
    print "  ok     $name (already $weight)\n";
    continue;
  }
  printf("  move   %-26s %s -> %d\n", $name, $component['weight'] ?? '?', $weight);
  $component['weight'] = $weight;
  $display->setComponent($name, $component);
  $changed++;
}

if ($changed) {
  $display->save();
}
printf("DONE (%d moved).\n", $changed);
