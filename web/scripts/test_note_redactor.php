<?php
// Pure-GD test: no API key needed. Proves the blur actually destroys pixels.
$redactor = \Drupal::service('bos_testimonial.note_redactor');

$w = 1000; $h = 700;
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 252, 250, 243));
$ink = imagecolorallocate($im, 20, 20, 30);
imagestring($im, 5, 70, 100, 'Thank you for the work on our sprinklers.', $ink);
imagestring($im, 5, 70, 520, 'Sincerely, Margaret Whitfield', $ink);
imagestring($im, 5, 70, 610, '(970) 555-0148', $ink);
ob_start(); imagepng($im); $src = (string) ob_get_clean(); imagedestroy($im);

print "supports image/png : " . ($redactor->supports('image/png') ? 'yes' : 'no') . "\n";
print "supports appl/pdf  : " . ($redactor->supports('application/pdf') ? 'yes' : 'no') . "\n\n";

// Boxes over the signature line and the phone line (normalised, deliberately tight
// so the padding is what has to save it).
$regions = [
  ['type' => 'signature', 'where' => 'bottom', 'box' => [0.07, 0.735, 0.42, 0.045]],
  ['type' => 'phone',     'where' => 'bottom', 'box' => [0.07, 0.865, 0.22, 0.045]],
];

$clean = $redactor->redact($src, $regions, FALSE);
$prev  = $redactor->redact($src, $regions, TRUE);
printf("redacted ok: clean %d bytes, preview %d bytes\n", strlen($clean), strlen($prev));

// Did the pixels actually change where we said, and NOT change elsewhere?
$a = imagecreatefromstring($src);
$b = imagecreatefromstring($clean);
$sample = function ($img, int $x, int $y): int { return imagecolorat($img, $x, $y); };
$differs = function (int $x, int $y) use ($a, $b, $sample): bool { return $sample($a,$x,$y) !== $sample($b,$x,$y); };

// Count changed pixels across the signature row band and across the message row.
$band = function (int $y0, int $y1) use ($a, $b, $w): float {
  $changed = 0; $total = 0;
  for ($y = $y0; $y < $y1; $y += 3) {
    for ($x = 0; $x < $w; $x += 3) { $total++; if (imagecolorat($a,$x,$y) !== imagecolorat($b,$x,$y)) $changed++; }
  }
  return $total ? round(100 * $changed / $total, 1) : 0.0;
};
printf("\nmessage band  (y 90-130)  changed: %.1f%%  <- should be 0\n", $band(90, 130));
printf("signature band(y 510-545) changed: %.1f%%  <- should be high\n", $band(510, 545));
printf("phone band    (y 600-635) changed: %.1f%%  <- should be high\n", $band(600, 635));

// Is the signature text actually gone (not just softened)? Compare contrast.
$contrast = function ($img, int $y0, int $y1) use ($w): float {
  $min = 255; $max = 0;
  for ($y = $y0; $y < $y1; $y += 2) {
    for ($x = 60; $x < 520; $x += 2) {
      $c = imagecolorat($img, $x, $y); $lum = (($c >> 16 & 255) * 0.3) + (($c >> 8 & 255) * 0.59) + (($c & 255) * 0.11);
      $min = min($min, $lum); $max = max($max, $lum);
    }
  }
  return round($max - $min, 1);
};
printf("\nsignature contrast before: %.1f\n", $contrast($a, 515, 540));
printf("signature contrast after : %.1f  <- near zero means the ink is gone\n", $contrast($b, 515, 540));

file_put_contents('/tmp/redact-preview.png', $prev);
print "\npreview written to /tmp/redact-preview.png\n";

try { $redactor->redact($src, [], FALSE); print "EMPTY REGIONS: should have thrown\n"; }
catch (\Throwable $e) { print "empty regions -> throws: " . $e->getMessage() . "\n"; }
