<?php

declare(strict_types=1);

/**
 * RENDER every credential display. Executing a view does not exercise
 * FieldPluginBase::advancedRender(), which is where a sparse field definition
 * blows up ("array + null") — so each display must be rendered with real rows, as
 * a user who can actually see them (a drush render is anonymous by default and
 * silently returns an empty view).
 *
 *   drush php:script web/scripts/verify_credential_render.php
 */

use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-46s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

$term = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']) as $t) {
  if ((string) $t->get('field_credential_code')->value === 'CDA_PRIV_APP') { $term = $t; }
}
$made = [];
foreach (['+20 days' => 'RENDER-PENDING', '-5 days' => 'RENDER-EXPIRED'] as $when => $num) {
  $c = $etm->getStorage('credential')->create([
    'type' => 'credential', 'field_credential_type' => $term->id(), 'field_scope' => 'teammate',
    'field_teammate' => 1, 'field_credential_number' => $num,
    'field_expiration_date' => date('Y-m-d', strtotime($when)), 'field_status' => 'active',
  ]);
  $c->save();
  $made[] = $c;
}
// A company record so page_company renders rows too.
$gl = NULL;
foreach ($etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'credential_types']) as $t) {
  if ((string) $t->get('field_credential_code')->value === 'GL') { $gl = $t; }
}
$co = $etm->getStorage('credential')->create([
  'type' => 'credential', 'field_credential_type' => $gl->id(), 'field_scope' => 'company',
  'field_credential_number' => 'RENDER-GL', 'field_issuing_authority' => 'Render Carrier',
  'field_coverage_limits' => '$1,000,000 / $2,000,000', 'field_publish_publicly' => TRUE,
  'field_status' => 'active',
]);
$co->save();
$made[] = $co;
bos_credential_cron();

$ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', 'administration')->condition('status', 1)->range(0, 1)->execute();
$office = $etm->getStorage('user')->load(reset($ids));
$sw = \Drupal::service('account_switcher');

foreach (['page_company', 'page_expiring', 'page_mine', 'block_profile', 'block_public'] as $disp) {
  $args = in_array($disp, ['page_mine', 'block_profile'], TRUE) ? [1] : [];
  $sw->switchTo($office);
  $out = '';
  $err = '';
  try {
    $v = Views::getView('credentials');
    $build = $v->buildRenderable($disp, $args);
    $out = (string) \Drupal::service('renderer')->renderPlain($build);
  }
  catch (\Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
  }
  $sw->switchBack();
  // page_company uses the TABLE style, which emits <tr>, not .views-row — count
  // both so a table display is not a false negative.
  $rows = substr_count($out, 'views-row') + substr_count($out, '<tr');
  $check("render $disp", $err === '' && $rows > 0, $err !== '' ? $err : "$rows row(s), " . strlen($out) . ' bytes');
}

/* Card classes + no leaked raw key. */
$sw->switchTo($office);
$out = (string) \Drupal::service('renderer')->renderPlain(Views::getView('credentials')->buildRenderable('page_expiring'));
$sw->switchBack();
$check('card accent class pending-renewal', str_contains($out, 'credential-card--pending-renewal'));
$check('card accent class expired', str_contains($out, 'credential-card--expired'));
$check('status badge markup present', str_contains($out, 'views-field-field-status'));
$check('raw status key not leaked as text', !preg_match('/>\s*(pending_renewal|expired)\s*</', $out));
// renderPlain() does not emit <link> tags — attachments are processed at page
// level. Inspect the render array's #attached, not the HTML string.
$sw->switchTo($office);
$build = Views::getView('credentials')->buildRenderable('page_expiring');
\Drupal::service('renderer')->renderPlain($build);
$libs = $build['#attached']['library'] ?? [];
$sw->switchBack();
$check('card CSS library attached', in_array('bos_credential/credential_cards', $libs, TRUE), implode(', ', $libs) ?: '(none)');

foreach ($made as $c) { $c->delete(); }
print "  (temp records removed)\n";
printf("\n%d passed, %d failed.\n", $pass, $fail);
