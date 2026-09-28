<?php

declare(strict_types=1);

/**
 * Prove that making the ITEMLESS field-access check neutral did not open a hole.
 *
 * Temporarily publishes the General Liability record with a fake policy number —
 * the worst case, a published record whose TYPE forbids showing the number — then
 * checks the public page and the entity API. Reverts in a finally block.
 *
 *   drush php:script web/scripts/verify_credential_number_access.php
 */

use Drupal\Core\Session\UserSession;
use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-50s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

$gl = NULL;
foreach ($etm->getStorage('credential')->loadMultiple(\Drupal::entityQuery('credential')->accessCheck(FALSE)->execute()) as $c) {
  $t = $c->get('field_credential_type')->entity;
  if ($t && (string) $t->get('field_credential_code')->value === 'GL') { $gl = $c; }
}
if (!$gl) { print "No GL record found.\n"; return; }

$origNum = (string) ($gl->get('field_credential_number')->value ?? '');
$origPub = (bool) ($gl->get('field_publish_publicly')->value ?? FALSE);

try {
  $gl->set('field_credential_number', 'POLICY-SECRET-999')
     ->set('field_publish_publicly', TRUE)
     ->set('field_coverage_limits', '$1,000,000 / $2,000,000')
     ->set('field_issuing_authority', 'Test Carrier')
     ->save();
  drupal_flush_all_caches();

  $anon = new UserSession(['uid' => 0, 'roles' => ['anonymous']]);

  /* 1. Entity-API field access WITH items — the real decision. */
  $fresh = $etm->getStorage('credential')->loadUnchanged($gl->id());
  $check('entity API: anon denied the GL policy number',
    !$fresh->get('field_credential_number')->access('view', $anon));

  /* 2. The ABPA record's number stays visible (type permits it). */
  $abpa = $etm->getStorage('credential')->loadByProperties(['field_credential_number' => '06-2512234']);
  $abpa = reset($abpa);
  $check('entity API: anon ALLOWED the ABPA licence number',
    $abpa->get('field_credential_number')->access('view', $anon));

  /* 3. The public page: GL row appears, its number does not. */
  $sw = \Drupal::service('account_switcher');
  $sw->switchTo($anon);
  $html = (string) \Drupal::service('renderer')->renderPlain(Views::getView('credentials')->buildRenderable('page_public'));
  $sw->switchBack();
  $check('public page shows the GL row', str_contains($html, 'General Liability'), substr_count($html, 'credential-public__item') . ' rows');
  $check('public page does NOT show the policy number', !str_contains($html, 'POLICY-SECRET-999'));
  $check('public page still shows the ABPA number', str_contains($html, '06-2512234'));
  $check('public page shows GL coverage limits', str_contains($html, '1,000,000'));

  /* 4. Can anon reach credentials through JSON:API at all? */
  $anonRole = $etm->getStorage('user_role')->load('anonymous');
  $canView = $anonRole && ($anonRole->hasPermission('view any credential entities') || $anonRole->hasPermission('view any credential entities of bundle credential'));
  $check('anon has NO credential entity-view permission (JSON:API collection closed)', !$canView,
    $canView ? 'anon CAN read credentials via the API' : 'entity access closed to anon');
}
finally {
  $gl = $etm->getStorage('credential')->loadUnchanged($gl->id());
  $gl->set('field_credential_number', $origNum)
     ->set('field_publish_publicly', $origPub)
     ->set('field_coverage_limits', NULL)
     ->set('field_issuing_authority', NULL)
     ->save();
  drupal_flush_all_caches();
  printf("  (GL record reverted: number=%s publish=%s)\n", $origNum, $origPub ? 'TRUE' : 'false');
}

printf("\n%d passed, %d failed.\n", $pass, $fail);
