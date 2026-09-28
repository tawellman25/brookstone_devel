<?php
// Verifies alias + image file path for the padded reference. Self-cleaning.
use Drupal\Core\File\FileSystemInterface;

$etm = \Drupal::entityTypeManager();
$fsv = \Drupal::service('file_system');

$mkfile = function () use ($fsv, $etm) {
  $im = imagecreatetruecolor(60, 40);
  imagefill($im, 0, 0, imagecolorallocate($im, 200, 200, 200));
  ob_start(); imagepng($im); $b = (string) ob_get_clean(); imagedestroy($im);
  $dir = 'public://ffp-test';
  $fsv->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
  $uri = $fsv->saveData($b, 'public://ffp-test/src-' . uniqid() . '.png', FileSystemInterface::EXISTS_REPLACE);
  $f = $etm->getStorage('file')->create(['uri' => $uri, 'status' => 1]);
  $f->save();
  return $f;
};

// A client user, if there is one, to prove the customer no longer reaches the path.
$customer = NULL;
foreach ($etm->getStorage('user')->loadByProperties(['status' => 1]) as $u) {
  if (in_array('client', $u->getRoles(), TRUE)) { $customer = $u; break; }
}
printf("customer used: %s\n\n", $customer ? $customer->label() : '(none available)');

$cases = ['plain' => [], 'with customer' => $customer ? ['field_customer' => $customer->id()] : []];
$made = [];

foreach ($cases as $label => $extra) {
  $file = $mkfile();
  $t = $etm->getStorage('testimonial')->create([
    'type' => 'client',
    'field_testimony' => ['value' => 'x', 'format' => 'basic_html'],
    'field_testimonial_image' => ['target_id' => $file->id()],
    'field_status' => 'pending',
  ] + $extra);
  $t->save();
  $t = $etm->getStorage('testimonial')->load($t->id());
  $img = $t->get('field_testimonial_image')->entity;
  $uri = $img ? $img->getFileUri() : '(none)';
  $ref = _bos_testimonial_ref((int) $t->id());

  printf("%-14s id=%s ref=%s\n", $label, $t->id(), $ref);
  printf("%-14s alias: %s\n", '', $t->toUrl()->toString());
  printf("%-14s file : %s\n", '', $uri);
  printf("%-14s alias matches /about-us/testimonial/%s : %s\n", '', $ref,
    $t->toUrl()->toString() === "/about-us/testimonial/$ref" ? 'yes' : 'NO');
  printf("%-14s file ends %s.png : %s\n", '', $ref, str_ends_with($uri, "/$ref.png") ? 'yes' : 'NO');
  if ($customer) {
    $needle = strtolower(str_replace(' ', '-', $customer->label()));
    printf("%-14s customer name in alias or file : %s\n", '',
      (str_contains(strtolower($t->toUrl()->toString()), $needle) || str_contains(strtolower($uri), $needle)) ? 'LEAK' : 'no');
  }
  print "\n";
  $made[] = [$t, $img];
}

foreach ($made as [$t, $img]) { $t->delete(); if ($img) { @unlink($img->getFileUri()); $img->delete(); } }
print "cleaned up\n";
