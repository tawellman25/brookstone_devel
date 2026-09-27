<?php

declare(strict_types=1);

/**
 * Execute every credential display and assert what it returns. Hand-built Views
 * handlers fail silently if a plugin id is wrong, so each one is RUN, not trusted.
 *
 *   drush php:script web/scripts/verify_credential_views.php
 */

use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-52s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

/* A temporary credential inside the lead window, to prove the expiring filter. */
$abpa = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']) as $t) {
  if ((string) $t->get('field_credential_code')->value === 'CDA_PRIV_APP') { $abpa = $t; }
}
$tmp = $etm->getStorage('credential')->create([
  'type' => 'credential', 'field_credential_type' => $abpa->id(), 'field_scope' => 'teammate',
  'field_teammate' => 1, 'field_credential_number' => 'EXPIRING-TEST',
  'field_expiration_date' => date('Y-m-d', strtotime('+20 days')), 'field_status' => 'active',
]);
$tmp->save();
bos_credential_cron();
$tmp = $etm->getStorage('credential')->loadUnchanged($tmp->id());
$check('cron flagged the +20d credential pending_renewal', (string) $tmp->get('field_status')->value === 'pending_renewal');

$run = function (string $display, array $args = []) {
  $v = Views::getView('credentials');
  if (!$v) { return NULL; }
  $v->setDisplay($display);
  $v->setArguments($args);
  $v->execute();
  return $v;
};

// a. Company list → the GL record only.
$v = $run('page_company');
$titles = array_map(fn($r) => (string) $r->_entity->label(), $v->result);
$check('page_company returns company-scope only', count($v->result) === 1 && str_contains($titles[0] ?? '', 'General Liability'), implode(' | ', $titles));

// b. Expiring soon → the temp record, sorted by expiry.
$v = $run('page_expiring');
$nums = array_map(fn($r) => (string) $r->_entity->get('field_credential_number')->value, $v->result);
$check('page_expiring returns pending_renewal/expired only', in_array('EXPIRING-TEST', $nums, TRUE) && !in_array('GL-TEST-0001', $nums, TRUE), implode(' | ', $nums));
$check('page_expiring uses the card row class', ($v->style_plugin->options['row_class'] ?? '') === 'credential-card');

// c. My credentials → uid 1's two teammate credentials.
$v = $run('page_mine', [1]);
$check('page_mine (uid 1) returns only that user\'s credentials', count($v->result) === 2, count($v->result) . ' rows');
$v0 = $run('page_mine', [2]);
$check('page_mine for another uid returns none', count($v0->result) === 0, count($v0->result) . ' rows');

// d. Profile block → same shape, route-user argument.
$v = $run('block_profile', [1]);
$check('block_profile (uid 1) scoped to that teammate', count($v->result) === 2, count($v->result) . ' rows');

// e. Public block → publishable only, and no forbidden field in the field list.
$v = $run('block_public');
$check('block_public returns publishable records only', count($v->result) === 1, count($v->result) . ' rows');
$fields = array_keys($v->display_handler->getOption('fields') ?? []);
$forbidden = array_intersect($fields, ['field_expiration_date', 'field_credential_documents', 'field_internal_notes', 'field_renewal_contact', 'field_renewal_url']);
$check('block_public exposes no forbidden field', empty($forbidden), $forbidden ? 'LEAK: ' . implode(',', $forbidden) : implode(', ', $fields));

/* Routes registered for the page displays. */
$rp = \Drupal::service('router.route_provider');
foreach (['view.credentials.page_company', 'view.credentials.page_expiring', 'view.credentials.page_mine'] as $rn) {
  try {
    $r = $rp->getRouteByName($rn);
    $check("route $rn", TRUE, $r->getPath());
  }
  catch (\Throwable $e) {
    $check("route $rn", FALSE, 'NOT REGISTERED');
  }
}

$tmp->delete();
print "  (temporary expiring credential removed)\n";
printf("\n%d passed, %d failed.\n", $pass, $fail);
