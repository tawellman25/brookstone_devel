<?php

declare(strict_types=1);

/**
 * Verify the §6 backflow integration + §4 supersede immutability.
 * Creates throwaway records and removes them.
 *
 *   drush php:script web/scripts/verify_credential_backflow.php
 */

$etm = \Drupal::entityTypeManager();
$cs = $etm->getStorage('credential');
$ts = $etm->getStorage('wo_tasks_list');
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-56s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};
$abpa = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']) as $t) {
  if ((string) $t->get('field_credential_code')->value === 'ABPA_TESTER') { $abpa = $t; }
}

$cleanup = [];

/* 1. Snapshot reads from the credential. */
$child = $ts->create([
  'type' => 'backflow_testing',
  'field_tester' => 1,
  'field_test_date' => date('Y-m-d') . 'T12:00:00',
]);
$child->save();
$cleanup[] = $child;
$snap = (string) ($child->get('field_certification_number')->value ?? '');
$check('test snapshots the number from the credential', $snap === '06-2512234', "got '$snap'");
$assoc = $child->hasField('field_certification_association') ? (string) ($child->get('field_certification_association')->value ?? '') : '(no field)';
$check('issuing authority snapshotted as the association', $assoc === 'ABPA', "got '$assoc'");

/* 2. Supersede the credential — the filed snapshot must NOT move. */
$old = $cs->loadByProperties(['field_credential_number' => '06-2512234']);
$old = reset($old);
$new = $cs->create([
  'type' => 'credential', 'field_credential_type' => $abpa->id(), 'field_scope' => 'teammate',
  'field_teammate' => 1, 'field_credential_number' => '06-SUPERSEDE-TEST',
  'field_issuing_authority' => 'ABPA', 'field_status' => 'active',
  'field_issue_date' => date('Y-m-d'),
]);
$new->save();
$cleanup[] = $new;
$old->set('field_status', 'superseded')->set('field_superseded_by', $new->id())->save();

// A plain re-save re-runs presave; the snapshot must still not move.
$child->save();
$child = $ts->loadUnchanged($child->id());
$after = (string) ($child->get('field_certification_number')->value ?? '');
$check('snapshot unchanged after the credential is superseded', $after === '06-2512234', "got '$after'");
$check('old record intact + points at its replacement',
  (string) $old->get('field_status')->value === 'superseded' && (int) $old->get('field_superseded_by')->target_id === (int) $new->id());

// Restore the ABPA record to active so dev data stays sane.
$old->set('field_status', 'active')->set('field_superseded_by', NULL)->save();

/* 3. Expiry-aware resolution — the warning's trigger. */
$crew = NULL;
$ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', 'teammates')->condition('status', 1)->execute();
foreach ($etm->getStorage('user')->loadMultiple($ids) as $u) {
  if ($u->id() != 1 && !array_intersect(['administration','supervisor','site_assistant','site_admin','administrator'], $u->getRoles())) { $crew = $u; break; }
}
if ($crew) {
  $lapsed = $cs->create([
    'type' => 'credential', 'field_credential_type' => $abpa->id(), 'field_scope' => 'teammate',
    'field_teammate' => $crew->id(), 'field_credential_number' => 'LAPSED-TEST',
    'field_issue_date' => '2023-01-01', 'field_expiration_date' => '2024-01-01', 'field_status' => 'active',
  ]);
  $lapsed->save();
  $cleanup[] = $lapsed;
  $check('lapsed credential: NOT certified today', !bos_credential_is_backflow_certified((int) $crew->id()));
  $check('lapsed credential: WAS certified on a date inside its term', bos_credential_is_backflow_certified((int) $crew->id(), '2023-06-01'));
  $check('lapsed credential: NOT certified before it was issued', !bos_credential_is_backflow_certified((int) $crew->id(), '2022-01-01'));
}
else {
  print "  (no crew-only user available for the expiry tests)\n";
}

foreach ($cleanup as $e) { $e->delete(); }
print "  (throwaway records removed)\n";
printf("\n%d passed, %d failed.\n", $pass, $fail);
