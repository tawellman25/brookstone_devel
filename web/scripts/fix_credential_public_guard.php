<?php

declare(strict_types=1);

/**
 * Stop the public credentials page advertising a credential that is not active.
 *
 * Found live on 2026-09-28: /about-us/credentials was showing
 * "CDA Qualified Supervisor — Gerald Reeves, Licence number: 0000000" — a
 * placeholder number on a credential that EXPIRED 2025-02-25. That is the page
 * a property manager might copy into a bid packet, so it is the worst possible
 * place for both of those faults.
 *
 * Two fixes, because the data fix alone would leave the hole open:
 *
 *  1. STRUCTURAL — the public display filtered on field_publish_publicly only.
 *     Now it also requires field_status = active, so ticking the publish box on
 *     an expired or superseded record cannot put it on the website. Operator is
 *     'or', never 'in': field_status is a list_string, so its handler is
 *     ManyToOne, which has no 'in' operator and would emit no SQL at all —
 *     silently matching everything, which here means publishing everything.
 *
 *  2. DATA — unpublish that record. It is expired AND still carries the 0000000
 *     placeholder, so it fails the office's own rule that a record publishes
 *     once its number stops being zeros.
 *
 * Reversible: tick the box again once the credential is renewed and its real
 * number entered, and the status filter will let it through on its own.
 *
 * Idempotent; run per environment.
 *
 *   drush php:script web/scripts/fix_credential_public_guard.php
 */

// --- 1. the view guard -----------------------------------------------------
$view = \Drupal::entityTypeManager()->getStorage('view')->load('credentials');
if (!$view) {
  print "ABORT: credentials view not found\n";
  return;
}
$display = $view->get('display');
$filters = $display['page_public']['display_options']['filters'] ?? [];

if (isset($filters['field_status_value'])) {
  print "view: status filter already present\n";
}
else {
  $filters['field_status_value'] = [
    'id' => 'field_status_value',
    'table' => 'credential__field_status',
    'field' => 'field_status_value',
    'plugin_id' => 'list_field',
    // 'or', NOT 'in' — see the note at the top of this file.
    'operator' => 'or',
    'value' => ['active' => 'active'],
    'group' => 1,
    'exposed' => FALSE,
  ];
  $display['page_public']['display_options']['filters'] = $filters;
  $view->set('display', $display);
  $view->save();
  print "view: added field_status = active to the public display\n";
}

// --- 2. the record ---------------------------------------------------------
$storage = \Drupal::entityTypeManager()->getStorage('credential');
$fixed = 0;
foreach ($storage->loadMultiple() as $c) {
  if (!$c->get('field_publish_publicly')->value) {
    continue;
  }
  $status = (string) $c->get('field_status')->value;
  $number = (string) $c->get('field_credential_number')->value;
  $placeholder = $number === '' || preg_match('/^0+$/', $number) === 1;

  if ($status === 'active' && !$placeholder) {
    printf("  keep      %-42s (active, real number)\n", $c->label());
    continue;
  }
  printf("  UNPUBLISH %-42s status=%s number=%s\n", $c->label(), $status, $number ?: '(none)');
  $c->set('field_publish_publicly', FALSE);
  $c->save();
  $fixed++;
}

printf("\n%d record(s) unpublished.\n", $fixed);
