<?php
// Drives ReadNoteForm's review step + save path without needing a vision key —
// the half that was never exercised. Creates and deletes its own test record.
use Drupal\bos_testimonial\Form\ReadNoteForm;
use Drupal\Core\Form\FormState;
use Drupal\Core\File\FileSystemInterface;

$fs_service = \Drupal::service('file_system');
$etm = \Drupal::entityTypeManager();

// A stand-in scan.
$w = 1000; $h = 700;
$im = imagecreatetruecolor($w, $h);
imagefill($im, 0, 0, imagecolorallocate($im, 252, 250, 243));
$ink = imagecolorallocate($im, 20, 20, 30);
imagestring($im, 5, 70, 120, 'Thank you for the work on our sprinklers.', $ink);
imagestring($im, 5, 70, 520, 'Sincerely, Margaret Whitfield', $ink);
imagestring($im, 5, 70, 610, '(970) 555-0148', $ink);
ob_start(); imagepng($im); $png = (string) ob_get_clean(); imagedestroy($im);

$dir = 'public://testimonials/scans/test';
$fs_service->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY);
$uri = $fs_service->saveData($png, $dir . '/note-test.png', FileSystemInterface::EXISTS_REPLACE);
$file = $etm->getStorage('file')->create(['uri' => $uri, 'status' => 1]);
$file->save();

$office = NULL;
foreach ($etm->getStorage('user')->loadByProperties(['status' => 1]) as $u) {
  if (in_array('administration', $u->getRoles(), TRUE)) { $office = $u; break; }
}
\Drupal::service('account_switcher')->switchTo($office);

$t = $etm->getStorage('testimonial')->create([
  'type' => 'client',
  'field_testimony' => ['value' => 'placeholder', 'format' => 'basic_html'],
  'field_testimonial_scan' => ['target_id' => $file->id()],
  'field_status' => 'pending',
]);
$t->save();
printf("test testimonial %d with scan %d\n\n", $t->id(), $file->id());

// --- step 1 renders, and offers the read button -------------------------
$fo = ReadNoteForm::create(\Drupal::getContainer());
$fs = new FormState();
$fs->set('testimonial', $t);
$built = $fo->buildForm([], $fs, $t);
print "STEP 1\n";
printf("  read button present    : %s\n", isset($built['actions']['read']) ? 'yes' : 'NO');
printf("  scan listed            : %s\n", isset($built['delta']) ? 'yes' : 'NO');

// --- step 2: seed a model result, build the preview, render -------------
$fs->set('result', [
  'transcript' => "Thank you for the work on our sprinklers.",
  'signed_by' => 'Margaret Whitfield',
  'personal_info' => [['type' => 'signature', 'where' => 'bottom', 'box' => [0.07, 0.74, 0.4, 0.05]]],
  'warnings' => ['Check this one'],
]);
$fs->set('delta', 0);
$fs->setValue('blur_from', 70);
$form = [];
$fo->previewSubmit($form, $fs);
printf("\n  preview built          : %s\n", $fs->get('preview_url') ? 'yes' : 'NO (' . $fs->get('preview_error') . ')');
printf("  clean copy built       : %s\n", $fs->get('clean_uri') ? 'yes' : 'NO');

$built2 = $fo->buildForm([], $fs, $t);
$html = (string) \Drupal::service('renderer')->renderInIsolation($built2);
print "\nSTEP 2\n";
foreach ([
  'transcript field'   => isset($built2['transcript']),
  'signed_by field'    => isset($built2['signed_by']),
  'findings checklist' => isset($built2['found']),
  'warnings shown'     => isset($built2['warnings']),
  'blur line control'  => isset($built2['redact']['blur_from']),
  'update preview btn' => isset($built2['redact']['repreview']),
  'preview image'      => isset($built2['redact']['preview']),
  'use_image checkbox' => isset($built2['redact']['use_image']),
  'save_text checkbox' => isset($built2['save_text']),
] as $what => $ok) { printf("  %-20s : %s\n", $what, $ok ? 'yes' : 'NO'); }
printf("  renders (bytes)      : %d\n", strlen($html));

// --- save path ----------------------------------------------------------
$fs->setValue('save_text', 1);
$fs->setValue('use_image', 1);
$fs->setValue('transcript', 'Thank you for the work on our sprinklers.');
$fs->setValue('signed_by', 'Margaret W.');
$fo->submitForm($form, $fs);

$t = $etm->getStorage('testimonial')->load($t->id());
print "\nAFTER SAVE\n";
printf("  testimony            : %s\n", substr(strip_tags((string) $t->get('field_testimony')->value), 0, 46));
printf("  testimonial_by       : %s\n", $t->get('field_testimonial_by')->value);
$img = $t->get('field_testimonial_image')->entity;
printf("  public image set     : %s\n", $img ? $img->getFileUri() : 'NO');
printf("  status still pending : %s\n", $t->get('field_status')->value === 'pending' ? 'yes (nothing auto-published)' : 'NO — ' . $t->get('field_status')->value);

if ($img) {
  $bin = file_get_contents($img->getFileUri());
  $b = imagecreatefromstring($bin);
  $contrast = function ($i, $y0, $y1) { $min=255;$max=0; for($y=$y0;$y<$y1;$y+=2) for($x=60;$x<600;$x+=2){ $c=imagecolorat($i,$x,$y); $l=(($c>>16&255)*.3)+(($c>>8&255)*.59)+(($c&255)*.11); $min=min($min,$l);$max=max($max,$l);} return round($max-$min,1); };
  printf("  saved img: signature contrast %.1f (orig 228.7)\n", $contrast($b, 515, 540));
  printf("  saved img: message   contrast %.1f (should stay high)\n", $contrast($b, 115, 140));
  printf("  saved img has outline: %s\n", str_contains($bin, 'x') && $contrast($b, 515, 540) < 60 ? 'blurred ok' : 'check');
}

// cleanup
foreach ($t->get('field_testimonial_image') as $i) { if ($i->entity) $i->entity->delete(); }
$t->delete();
$file->delete();
\Drupal::service('account_switcher')->switchBack();
print "\ncleaned up\n";
