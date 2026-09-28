<?php

declare(strict_types=1);

/**
 * RENDER the testimonial admin displays with real rows, as an office user, plus
 * the QR page and image. Execute-only checks miss advancedRender() crashes and a
 * drush render is anonymous by default.
 *
 *   drush php:script web/scripts/verify_testimonial_render.php
 */

use Drupal\views\Views;

$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("  [%s] %-46s %s\n", $ok ? 'PASS' : 'FAIL', $l, $d);
  $ok ? $pass++ : $fail++;
};

$s = $etm->getStorage('testimonial');
$made = [];
foreach ([['Pending Person', 'pending'], ['Approved Person', 'approved']] as [$name, $status]) {
  $t = $s->create(['type' => 'client', 'field_testimonial_by' => $name,
    'field_testimony' => ['value' => 'They showed up when they said they would, which is most of it.', 'format' => 'plain_text']]);
  $t->save();
  // Office context, so the presave backstop allows the approved one.
  $t->set('field_status', $status)->save();
  $made[] = $t;
}

$ids = \Drupal::entityQuery('user')->accessCheck(FALSE)->condition('roles', 'administration')->condition('status', 1)->range(0, 1)->execute();
$office = $etm->getStorage('user')->load(reset($ids));
$sw = \Drupal::service('account_switcher');

foreach (['page_all', 'page_pending'] as $disp) {
  $sw->switchTo($office);
  $err = '';
  $out = '';
  try {
    $out = (string) \Drupal::service('renderer')->renderPlain(Views::getView('testimonials')->buildRenderable($disp));
  }
  catch (\Throwable $e) {
    $err = get_class($e) . ': ' . $e->getMessage();
  }
  $sw->switchBack();
  $rows = substr_count($out, '<tr');
  $check("render $disp", $err === '' && $rows > 0, $err ?: "$rows row(s)");
  if ($disp === 'page_pending' && $err === '') {
    $check('pending tab excludes the approved one',
      str_contains($out, 'Pending Person') && !str_contains($out, 'Approved Person'));
  }
}

/* The QR page + image. */
$sw->switchTo($office);
$req = \Symfony\Component\HttpFoundation\Request::create('/admin/operations/system_content/testimonials/qr');
$ctl = \Drupal::classResolver('Drupal\bos_testimonial\Controller\TestimonialQrController');
try {
  $build = $ctl->page($req);
  $html = (string) \Drupal::service('renderer')->renderPlain($build);
  $check('QR page renders', str_contains($html, '/review'), 'destination shown');
  $check('QR page shows the suggested wording', str_contains($html, 'would love your feedback'));
  $resp = $ctl->image(\Symfony\Component\HttpFoundation\Request::create('/x'));
  $png = $resp->getContent();
  $check('QR image is a real PNG', substr($png, 1, 3) === 'PNG', strlen($png) . ' bytes, ' . $resp->headers->get('Content-Type'));
  $resp2 = $ctl->image(\Symfony\Component\HttpFoundation\Request::create('/x', 'GET', ['wo' => 9882]));
  $check('per-job QR generates', substr($resp2->getContent(), 1, 3) === 'PNG', strlen($resp2->getContent()) . ' bytes');
}
catch (\Throwable $e) {
  $check('QR controller', FALSE, get_class($e) . ': ' . $e->getMessage());
}
$sw->switchBack();

foreach ($made as $t) { $t->delete(); }
print "  (test testimonials removed)\n";
printf("\n%d passed, %d failed.\n", $pass, $fail);
