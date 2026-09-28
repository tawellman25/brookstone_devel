<?php

declare(strict_types=1);

/**
 * Verify /reviews shows APPROVED testimonials and never pending ones.
 *
 * This is the filter where a wrong operator is a content-safety bug: `in` on a
 * list_string filter emits no SQL, which would publish unreviewed text from
 * strangers. Tested with one approved and one pending record, rendered as ANON.
 *
 *   drush php:script web/scripts/verify_reviews_page.php
 */

use Drupal\Core\Session\UserSession;
use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$s = $etm->getStorage('testimonial');
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-52s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

$made = [];
foreach ([['Approved Alice', 'approved', 'The crew showed up when they said they would.'],
          ['Pending Pete', 'pending', 'SHOULD NOT BE PUBLIC — still awaiting review.']] as [$by, $status, $text]) {
  $t = $s->create(['type' => 'client', 'field_testimonial_by' => $by,
    'field_testimony' => ['value' => $text, 'format' => 'plain_text']]);
  $t->save();
  $t->set('field_status', $status)->save();
  $made[] = $t;
}

$sw = \Drupal::service('account_switcher');
$sw->switchTo(new UserSession(['uid' => 0, 'roles' => ['anonymous']]));
$err = '';
$out = '';
try {
  $out = (string) \Drupal::service('renderer')->renderPlain(Views::getView('testimonials')->buildRenderable('page_public'));
}
catch (\Throwable $e) {
  $err = get_class($e) . ': ' . $e->getMessage();
}
$sw->switchBack();

$check('page_public renders for anon', $err === '', $err ?: strlen($out) . ' bytes');
$check('APPROVED testimonial appears', str_contains($out, 'Approved Alice'));
$check('approved quote text appears', str_contains($out, 'showed up when they said'));
$check('PENDING testimonial is NOT public', !str_contains($out, 'Pending Pete'), 'the filter operator is doing its job');
$check('pending text is NOT public', !str_contains($out, 'SHOULD NOT BE PUBLIC'));
$check('card markup present', str_contains($out, 'review-card'));
$check('leave-a-review CTA present', str_contains($out, '/review'));

foreach ($made as $t) { $t->delete(); }
print "  (test testimonials removed)\n";
printf("\n%d passed, %d failed.\n", $pass, $fail);
