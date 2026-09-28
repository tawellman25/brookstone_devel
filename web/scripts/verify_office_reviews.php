<?php

declare(strict_types=1);

/**
 * Render the three Office reviews surfaces with real rows as an office user, and
 * confirm anonymous can reach none of them.
 *
 *   drush php:script web/scripts/verify_office_reviews.php
 */

use Drupal\Core\Session\UserSession;
use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$s = $etm->getStorage('testimonial');
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-50s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

$made = [];
foreach ([['Approved Alice', 'approved'], ['Pending Pete', 'pending']] as [$by, $status]) {
  $t = $s->create(['type' => 'client', 'field_testimonial_by' => $by,
    'field_testimony' => ['value' => 'They showed up when they said they would.', 'format' => 'plain_text']]);
  $t->save();
  $t->set('field_status', $status)->save();
  $made[] = $t;
}

$ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', 'administration')->condition('status', 1)->range(0, 1)->execute();
$office = $etm->getStorage('user')->load(reset($ids));
$sw = \Drupal::service('account_switcher');
$anon = new UserSession(['uid' => 0, 'roles' => ['anonymous']]);

$render = function (string $disp, $account) use ($sw) {
  $sw->switchTo($account);
  $out = '';
  $err = '';
  try {
    $out = (string) \Drupal::service('renderer')->renderPlain(Views::getView('testimonials')->buildRenderable($disp));
  }
  catch (\Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
  }
  $sw->switchBack();
  return [$out, $err];
};

foreach (['page_all', 'page_pending', 'page_public'] as $disp) {
  [$out, $err] = $render($disp, $office);
  $rows = substr_count($out, '<tr') + substr_count($out, 'views-row');
  $check("office renders $disp", $err === '' && $rows > 0, $err ?: "$rows row(s)");
}

[$all] = $render('page_all', $office);
$check('office home shows BOTH statuses', str_contains($all, 'Approved Alice') && str_contains($all, 'Pending Pete'));

[$pend] = $render('page_pending', $office);
$check('pending tab shows only pending', str_contains($pend, 'Pending Pete') && !str_contains($pend, 'Approved Alice'));

[$appr] = $render('page_public', $office);
$check('approved tab shows only approved', str_contains($appr, 'Approved Alice') && !str_contains($appr, 'Pending Pete'));
$check('approved tab keeps the quote-card markup', str_contains($appr, 'review-card'));
$check('approved tab uses office-voice copy', str_contains($appr, 'for marketing copy'), 'no public CTA wording');

/* Anonymous must get nothing from any of them. */
foreach (['page_all', 'page_pending', 'page_public'] as $disp) {
  [$out] = $render($disp, $anon);
  $rows = substr_count($out, '<tr') + substr_count($out, 'views-row');
  $check("anon gets no rows from $disp", $rows === 0, "$rows row(s)");
}

foreach ($made as $t) { $t->delete(); }
print "  (test testimonials removed)\n";
printf("\n%d passed, %d failed.\n", $pass, $fail);
