<?php
// DEV ONLY. Usage: ddev exec php web/scripts/make_test_receipt.php [out.jpg]  (default tmp_test/receipt.jpg)
// Synthetic copy of the Delta Ace Hardware receipt (same lines) for testing the
// photo → vision → preview path on dev. Monospace TTF if present, else GD font 5 upscaled.
$lines = [
  "    THANK YOU FOR SHOPPING AT",
  "      DELTA ACE HARDWARE",
  " 121 GUNNISON RIVER DR. UNIT 121",
  "       DELTA, CO 81416",
  "   www.deltaacehardware.com",
  "       (970) 874-9515",
  "",
  "11/13/26  9:15AM CA        562   SALE",
  "---------------------------------------",
  "3539541           2 EA     6.59 EA",
  "STRAIGHT CONNECT 1/2\"PLS          13.18",
  "3182557           1 EA     1.59 EA",
  "REDUCING WASHER STL 3/4\"           1.59",
  "",
  "SUB-TOTAL:\$   14.77   TAX: \$    1.40",
  "                      TOTAL: \$  16.17",
  "CHARGE AMT:   16.17",
  "",
  "==>> JRNL#     F91915   INV#631277",
  "     CUST NO:           1710",
  "           Customer Copy",
];
$ttf = null;
foreach (['/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf', '/usr/share/fonts/truetype/liberation/LiberationMono-Regular.ttf'] as $f) { if (is_file($f)) { $ttf = $f; break; } }
$w = 1000; $h = 60 + count($lines) * 44;
$im = imagecreatetruecolor($w, $h);
$white = imagecolorallocate($im, 250, 250, 248); $black = imagecolorallocate($im, 25, 25, 25);
imagefilledrectangle($im, 0, 0, $w, $h, $white);
$y = 50;
foreach ($lines as $l) {
  if ($ttf) { imagettftext($im, 22, 0, 40, $y, $black, $ttf, $l); }
  else { imagestring($im, 5, 40, $y - 20, $l, $black); }
  $y += 44;
}
if (!$ttf) { $im = imagescale($im, $w * 2); }
// Slight rotation like a hand-held photo.
$im = imagerotate($im, 3, $white);
$out = $argv[1] ?? '/var/www/html/tmp_test/receipt.jpg';
@mkdir(dirname($out), 0777, TRUE);
imagejpeg($im, $out, 88);
echo ($ttf ? "ttf: $ttf" : "gd font 5 upscaled") . " → " . filesize($out) . " bytes\n";
