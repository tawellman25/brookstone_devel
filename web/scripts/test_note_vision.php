<?php
// Dev-only smoke test: build a note image, read it, redact it, save both for eyeballing.
$vision = \Drupal::service('bos_testimonial.note_vision');
$redactor = \Drupal::service('bos_testimonial.note_redactor');

print "vision available: " . ($vision->isAvailable() ? "yes" : "NO") . "\n";
if (!$vision->isAvailable()) { print "ABORT — no provider/key on this env\n"; return; }

// A stand-in note: message, then a signature-ish line and a phone number.
$w = 1000; $h = 700;
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 252, 250, 243));
$ink = imagecolorallocate($im, 30, 30, 40);
$lines = [
  'Dear Brookstone,',
  '',
  'Thank you so much for the work you did on our',
  'sprinklers this spring. Gerald was on time, explained',
  'what was wrong, and cleaned up after himself.',
  'The lawn has never looked better.',
  '',
  'We will be calling you again next season.',
];
$y = 60;
foreach ($lines as $line) { imagestring($im, 5, 70, $y, $line, $ink); $y += 40; }
// Signature block + phone — the bits that must get blurred.
imagestring($im, 5, 70, 520, 'Sincerely, Margaret Whitfield', $ink);
imagestring($im, 5, 70, 570, '1842 Oak Hollow Rd, Delta CO', $ink);
imagestring($im, 5, 70, 610, '(970) 555-0148', $ink);
ob_start(); imagepng($im); $binary = (string) ob_get_clean(); imagedestroy($im);
file_put_contents('/tmp/note-source.png', $binary);
printf("source note written: %d bytes\n\n", strlen($binary));

$t0 = microtime(TRUE);
$r = $vision->read($binary, 'image/png');
printf("read in %.1fs\n\n", microtime(TRUE) - $t0);

print "--- transcript ---\n" . $r['transcript'] . "\n\n";
print "signed_by: " . ($r['signed_by'] ?: '(none)') . "\n";
print "personal_info: " . count($r['personal_info']) . "\n";
foreach ($r['personal_info'] as $p) {
  printf("   %-12s %-28s box=[%.2f %.2f %.2f %.2f]\n", $p['type'], substr($p['where'],0,28), ...$p['box']);
}
print "warnings:\n";
foreach ($r['warnings'] as $wn) { print "   - $wn\n"; }

// Does the transcript leak the personal lines it was told to leave out?
print "\n--- transcript hygiene ---\n";
foreach (['Margaret Whitfield' => 'signature name', '555-0148' => 'phone', 'Oak Hollow' => 'address'] as $needle => $what) {
  printf("  %-16s in transcript: %s\n", $what, str_contains($r['transcript'], $needle) ? 'YES (leaked)' : 'no');
}

if ($r['personal_info'] && $redactor->supports('image/png')) {
  $prev = $redactor->redact($binary, $r['personal_info'], TRUE);
  $clean = $redactor->redact($binary, $r['personal_info'], FALSE);
  file_put_contents('/tmp/note-preview.png', $prev);
  file_put_contents('/tmp/note-redacted.png', $clean);
  printf("\nredacted: preview %d bytes, clean %d bytes -> /tmp/note-{preview,redacted}.png\n", strlen($prev), strlen($clean));
}
