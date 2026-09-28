<?php
// Band redaction: the path the form actually uses. No API key needed.
$redactor = \Drupal::service('bos_testimonial.note_redactor');

$w = 1000; $h = 700;
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 252, 250, 243));
$ink = imagecolorallocate($im, 20, 20, 30);
$y = 60;
foreach (['Dear Brookstone,', '', 'Thank you so much for the work you did on our',
          'sprinklers this spring. Gerald was on time, explained',
          'what was wrong, and cleaned up after himself.',
          'The lawn has never looked better.', '',
          'We will be calling you again next season.'] as $l) {
  imagestring($im, 5, 70, $y, $l, $ink); $y += 40;
}
imagestring($im, 5, 70, 520, 'Sincerely, Margaret Whitfield', $ink);
imagestring($im, 5, 70, 570, '1842 Oak Hollow Rd, Delta CO', $ink);
imagestring($im, 5, 70, 610, '(970) 555-0148', $ink);
ob_start(); imagepng($im); $src = (string) ob_get_clean(); imagedestroy($im);

$a = imagecreatefromstring($src);
$contrast = function ($img, int $y0, int $y1) use ($w): float {
  $min = 255; $max = 0;
  for ($y = $y0; $y < $y1; $y += 2) for ($x = 60; $x < 600; $x += 2) {
    $c = imagecolorat($img, $x, $y);
    $lum = (($c>>16&255)*0.3)+(($c>>8&255)*0.59)+(($c&255)*0.11);
    $min = min($min,$lum); $max = max($max,$lum);
  }
  return round($max - $min, 1);
};

printf("%-6s | %-22s %-22s %-22s %s\n", 'line', 'message(y100-140)', 'signature(y515-540)', 'address(y565-590)', 'phone(y605-630)');
foreach ([0.60, 0.70, 0.80] as $from) {
  $out = $redactor->redactBand($src, $from, FALSE);
  $b = imagecreatefromstring($out);
  printf("%-6s | %-22s %-22s %-22s %s\n",
    (int) round($from*100) . '%',
    $contrast($b, 100, 140), $contrast($b, 515, 540), $contrast($b, 565, 590), $contrast($b, 605, 630));
  imagedestroy($b);
}
printf("%-6s | %-22s %-22s %-22s %s   <- untouched original\n", 'none',
  $contrast($a,100,140), $contrast($a,515,540), $contrast($a,565,590), $contrast($a,605,630));

file_put_contents('/tmp/band-70.png', $redactor->redactBand($src, 0.70, TRUE));
print "\npreview at 70% -> /tmp/band-70.png\n";
