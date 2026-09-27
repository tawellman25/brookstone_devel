<?php

declare(strict_types=1);

/**
 * READ-ONLY live verification of the credential build. Creates and changes
 * NOTHING — the dev verifiers make throwaway records, which must not land on
 * production.
 *
 *   drush php:script web/scripts/verify_credential_live.php
 */

use Drupal\Core\Session\UserSession;
use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-52s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

$check('bos_credential module enabled', \Drupal::moduleHandler()->moduleExists('bos_credential'));
$check('credential entity type registered', $etm->hasDefinition('credential'));

$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']);
$check('credential_types seeded', count($terms) === 10, count($terms) . ' terms');
$codes = [];
foreach ($terms as $t) { $codes[(string) $t->get('field_credential_code')->value] = $t; }
$check('number_is_public: license YES / insurance NO',
  (bool) $codes['ABPA_TESTER']->get('field_number_is_public')->value === TRUE
  && (bool) $codes['GL']->get('field_number_is_public')->value === FALSE
  && (bool) $codes['CDL']->get('field_number_is_public')->value === FALSE);

$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('credential', 'credential');
$want = ['field_credential_type','field_scope','field_teammate','field_credential_number','field_issuing_authority','field_coverage_limits','field_issue_date','field_expiration_date','field_status','field_superseded_by','field_credential_images','field_credential_documents','field_public_description','field_internal_notes','field_renewal_contact','field_renewal_url','field_publish_publicly'];
$missing = array_diff($want, array_keys($defs));
$check('all 17 fields present', empty($missing), $missing ? 'MISSING: ' . implode(',', $missing) : '17/17');
$check('field_teammate targets user', ($defs['field_teammate']->getSetting('target_type') ?? '') === 'user');

/* Nobody may delete a credential. */
$deleters = [];
foreach ($etm->getStorage('user_role')->loadMultiple() as $rid => $role) {
  foreach ($role->getPermissions() as $p) {
    if (str_starts_with($p, 'delete') && str_contains($p, 'credential')) { $deleters[] = "$rid:$p"; }
  }
}
$check('no role holds a credential delete permission', empty($deleters), $deleters ? implode(' | ', $deleters) : 'none');

/* The real records. */
$ids = \Drupal::entityQuery('credential')->accessCheck(FALSE)->execute();
printf("  credential records on live: %d\n", count($ids));
foreach ($etm->getStorage('credential')->loadMultiple($ids) as $c) {
  printf("    id=%-3d %-44s number=%-14s scope=%-9s expires=%-12s status=%s\n", $c->id(), $c->label(),
    $c->get('field_credential_number')->value ?? '', $c->get('field_scope')->value ?? '',
    substr((string) ($c->get('field_expiration_date')->value ?? ''), 0, 10) ?: '(none)',
    $c->get('field_status')->value ?? '');
}

/* Views execute (read-only). */
foreach ([['page_company', []], ['page_expiring', []], ['block_public', []]] as [$disp, $args]) {
  $v = Views::getView('credentials');
  $v->setDisplay($disp);
  $v->setArguments($args);
  $v->execute();
  // ManyToOne (list_string) filters emit an INNER JOIN; field filters on other
  // column types emit LEFT JOIN. Match either — asserting LEFT JOIN alone is a
  // false alarm (it was, on the first live run).
  $sql = (string) $v->build_info['query'];
  $joined = str_contains($sql, 'JOIN {credential__');
  $check("view $disp executes with its filter joined", $joined, count($v->result) . ' rows');
}
$rp = \Drupal::service('router.route_provider');
foreach (['view.credentials.page_company', 'view.credentials.page_expiring', 'view.credentials.page_mine'] as $rn) {
  try { $rp->getRouteByName($rn); $check("route $rn", TRUE); }
  catch (\Throwable $e) { $check("route $rn", FALSE, 'NOT REGISTERED'); }
}

/* Field access on the REAL record, no writes. */
$abpa = $etm->getStorage('credential')->loadByProperties(['field_credential_number' => '06-2512234']);
if ($abpa) {
  $cred = reset($abpa);
  $sw = \Drupal::service('account_switcher');
  $pick = function (string $role) use ($etm) {
    $ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', $role)->condition('status', 1)->execute();
    foreach ($etm->getStorage('user')->loadMultiple($ids) as $u) {
      if ($u->id() == 1) { continue; }
      if ($role === 'teammates' && array_intersect(['administration','supervisor','site_assistant','site_admin','administrator'], $u->getRoles())) { continue; }
      return $u;
    }
    return NULL;
  };
  foreach (['anonymous' => new UserSession(['uid' => 0, 'roles' => ['anonymous']]), 'teammate' => $pick('teammates'), 'office' => $pick('administration')] as $label => $acct) {
    if (!$acct) { continue; }
    $sw->switchTo($acct);
    $num = $cred->get('field_credential_number')->access('view', $acct);
    $notes = $cred->get('field_internal_notes')->access('view', $acct);
    $sw->switchBack();
    $expectNum = $label !== 'anonymous';   // license number: crew + office yes, anon no (record not publishable)
    $expectNotes = $label === 'office';
    $check("field access ($label): number=" . ($num ? 'Y' : 'N') . " notes=" . ($notes ? 'Y' : 'N'),
      $num === $expectNum && $notes === $expectNotes);
  }
  /* The backflow resolver against real data. */
  $check('backflow resolver finds the cert today', bos_credential_is_backflow_certified(1));
  $check('backflow resolver: not certified after 2028-12-31', !bos_credential_is_backflow_certified(1, '2029-06-01'));
}
else {
  $check('ABPA credential present on live', FALSE, 'run the migration');
}

printf("\n%d passed, %d failed.\n", $pass, $fail);
