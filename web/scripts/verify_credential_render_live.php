<?php

declare(strict_types=1);

/**
 * READ-ONLY live render check. Renders every credential display as an office user
 * and reports any throwable. Creates NOTHING.
 *
 * Note on coverage: FieldPluginBase::advancedRender() is only exercised by a
 * display that actually returns a ROW. Live holds one credential (teammate scope,
 * active, expiring 2028), so page_mine / block_profile render a row here and prove
 * the field definitions are sound. page_company, page_expiring and block_public
 * legitimately return zero rows until the office enters a company credential — so
 * those are proven with rows on DDEV, and only proven crash-free-when-empty here.
 *
 *   drush php:script web/scripts/verify_credential_render_live.php
 */

use Drupal\Core\Session\UserSession;
use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-44s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

$ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', 'administration')->condition('status', 1)->range(0, 1)->execute();
$office = $ids ? $etm->getStorage('user')->load(reset($ids)) : NULL;
if (!$office) {
  print "No active administration user — cannot render as office.\n";
  return;
}
$sw = \Drupal::service('account_switcher');

$render = function (string $disp, array $args, $account) use ($sw) {
  $sw->switchTo($account);
  $out = '';
  $err = '';
  $libs = [];
  try {
    $build = Views::getView('credentials')->buildRenderable($disp, $args);
    $out = (string) \Drupal::service('renderer')->renderPlain($build);
    $libs = $build['#attached']['library'] ?? [];
  }
  catch (\Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
  }
  $sw->switchBack();
  return [$out, $err, $libs];
};

foreach (['page_company' => [], 'page_expiring' => [], 'page_mine' => [1], 'block_profile' => [1], 'block_public' => []] as $disp => $args) {
  [$out, $err, $libs] = $render($disp, $args, $office);
  $rows = substr_count($out, 'views-row') + substr_count($out, '<tr');
  $check("render $disp", $err === '', $err !== '' ? $err : "$rows row(s), " . strlen($out) . ' bytes');
}

/* The display that actually has a row on live must show the card chrome. */
[$out, $err, $libs] = $render('page_mine', [1], $office);
$check('page_mine renders a real row', substr_count($out, 'views-row') >= 1, substr_count($out, 'views-row') . ' row(s)');
$check('card wrapper + accent class present', str_contains($out, 'credential-card'), 'credential-card' );
$check('status accent keyed active', str_contains($out, 'credential-card--active'));
$check('card CSS library attached', in_array('bos_credential/credential_cards', $libs, TRUE), implode(', ', array_unique($libs)) ?: '(none)');
$check('raw status key not leaked', !preg_match('/>\s*(active|pending_renewal|expired)\s*</', $out));

/* Anonymous must not be able to render the internal display at all. */
[$out2, $err2] = $render('page_mine', [1], new UserSession(['uid' => 0, 'roles' => ['anonymous']]));
$check('anonymous gets no rows from page_mine', substr_count($out2, 'views-row') === 0, $err2 ?: 'empty');

printf("\n%d passed, %d failed.\n", $pass, $fail);
