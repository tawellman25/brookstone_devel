<?php

/**
 * @file
 * Attach the five Spruce Needle Drop photos to the plant_problems term.
 *
 * Sources must already be EXIF-stripped, orientation-baked and resized by
 * prepare_snd_photos.php. ⚠ The originals carried GPS COORDINATES; this script
 * re-checks and REFUSES any file that still has them, because "the images do
 * not identify the property" is only true once that tag is gone.
 *
 * ORDER follows the copy brief: whole tree, mid-canopy close-up, branch
 * detail, then detail. The brief describes four photos and Todd supplied five,
 * so the extra canopy-context frame sits fourth and the tightest detail last.
 *
 * ⚠ CAPTION PLACEMENT. The brief gives one caption, for "image 2, which is the
 * one doing the work". Per the house hero-caption standard the caption lives in
 * the image item's own `title` sub-field — NOT alt, and NOT shared across the
 * set. Every other photo gets an EMPTY title on purpose: empty means no line,
 * and falling back to alt would print technical description at the customer and
 * make a screen reader say it twice.
 *
 * ⚠ ALT TEXT. alt_field_required is TRUE on this field. Marketing supplied alt
 * for image 2 only, so the other four are described here by Code. They are
 * functional descriptions, not copy — flagged for marketing to revise.
 *
 * Idempotent: re-running replaces the field contents with the same five in the
 * same order rather than appending. DRY-RUN unless BOS_APPLY=1.
 *
 * Run: BOS_SND_DIR=/path/to/web-jpgs drush php:script web/scripts/attach_snd_photos.php
 */

const VID = 'plant_problems';
const TERM_NAME = 'Spruce Needle Drop';
const FIELD = 'field_banner_images';
const WANT_DIR = 'plant-problems/banner-images';

// filename => [alt, title]. Title non-empty on exactly one.
const PHOTOS = [
  '1-whole-tree.web.jpg' => [
    'A mature blue spruce in a gravel bed, green and full through the top with browning visible in the lower interior.',
    '',
  ],
  '2-mid-canopy-diagnosis.web.jpg' => [
    // Supplied by marketing.
    'Blue spruce branch with brown needles on inner old wood and healthy green growth at the branch tips.',
    'The diagnosis is in where the brown sits. Dead needles on the oldest wood closest to the trunk, healthy blue-green growth on every tip. A tree in trouble browns the other way round.',
  ],
  '3-branch-detail.web.jpg' => [
    'Spruce branches above gravel showing dead brown needles along the older wood beside new blue-green growth.',
    '',
  ],
  '4-canopy-context.web.jpg' => [
    'Spruce canopy against open sky, brown needles through the interior with green growth on the outer branches.',
    '',
  ],
  '5-needle-detail.web.jpg' => [
    'Close view of spruce needles, dry and brown on the inner wood with healthy green needles toward the tip.',
    '',
  ],
];

$apply = getenv('BOS_APPLY') === '1';
$dir = rtrim(getenv('BOS_SND_DIR') ?: '', '/');
if ($dir === '' || !is_dir($dir)) {
  printf("ABORT: set BOS_SND_DIR to the directory holding the .web.jpg files (got '%s').\n", $dir);
  return;
}

$etm = \Drupal::entityTypeManager();
$terms = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => VID, 'name' => TERM_NAME]);
$term = reset($terms);
if (!$term) {
  printf("ABORT: no '%s' term in the %s vocabulary.\n", TERM_NAME, VID);
  return;
}
printf("term: %s (tid %s), currently %d image(s)\n\n", $term->label(), $term->id(), $term->get(FIELD)->count());

// --- preflight: the files, and the GPS re-check -----------------------------
$ok = TRUE;
foreach (PHOTOS as $file => [$alt, $title]) {
  $path = $dir . '/' . $file;
  if (!is_file($path)) {
    printf("  MISSING  %s\n", $file);
    $ok = FALSE;
    continue;
  }
  $exif = @exif_read_data($path);
  $gps = $exif && (isset($exif['GPSLatitude']) || isset($exif['GPSLongitude']));
  if ($gps) {
    printf("  ** GPS STILL PRESENT %s — refusing. Re-run prepare_snd_photos.php.\n", $file);
    $ok = FALSE;
    continue;
  }
  [$w, $h] = getimagesize($path);
  printf("  %-32s %5dx%-5d %5.1f MB  alt=%d chars  caption=%s\n", $file, $w, $h,
    filesize($path) / 1048576, mb_strlen($alt), $title === '' ? 'none' : mb_strlen($title) . ' chars');
}
if (!$ok) {
  print "\nABORT: preflight failed.\n";
  return;
}

// The cloned field instance inherited a file_directory from the source
// vocabulary's own ancestry ('backflow/type/banner_images'), which would file
// these under an unrelated service. Correct it before writing any file.
$fc = \Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', VID, FIELD);
$currentDir = $fc->getSetting('file_directory');
printf("\nfile_directory: %s%s\n", $currentDir, $currentDir === WANT_DIR ? '' : '  -> will become ' . WANT_DIR);

// The public view mode does not render this field (season draws its hero from
// module code instead), so attaching photos without this leaves them invisible.
$displaysToFix = [];
foreach (['full', 'admin_view'] as $mode) {
  $cfg = \Drupal::config('core.entity_view_display.taxonomy_term.' . VID . '.' . $mode);
  if ($cfg->isNew()) {
    continue;
  }
  if (!array_key_exists(FIELD, $cfg->get('content') ?? [])) {
    $displaysToFix[] = $mode;
  }
}
printf("view modes needing %s added: %s\n", FIELD, $displaysToFix ? implode(', ', $displaysToFix) : 'none');

if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

// --- apply -------------------------------------------------------------------
if ($currentDir !== WANT_DIR) {
  $fc->setSetting('file_directory', WANT_DIR);
  $fc->save();
  printf("\nfile_directory set to %s\n", WANT_DIR);
}

$fs = \Drupal::service('file_system');
$repo = \Drupal::service('file.repository');
$destDir = 'public://' . WANT_DIR;
$fs->prepareDirectory($destDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);

$values = [];
foreach (PHOTOS as $file => [$alt, $title]) {
  $data = file_get_contents($dir . '/' . $file);
  $target = $destDir . '/' . $file;
  $existing = $etm->getStorage('file')->loadByProperties(['uri' => $target]);
  if ($existing) {
    $f = reset($existing);
  }
  else {
    $f = $repo->writeData($data, $target, \Drupal\Core\File\FileExists::Replace);
  }
  $f->setPermanent();
  $f->save();
  $values[] = ['target_id' => $f->id(), 'alt' => $alt, 'title' => $title];
  printf("  %-32s fid=%-6s %s\n", $file, $f->id(), $f->getFileUri());
}

$term->set(FIELD, $values);
$term->save();

foreach ($displaysToFix as $mode) {
  $d = \Drupal\Core\Entity\Entity\EntityViewDisplay::load('taxonomy_term.' . VID . '.' . $mode);
  $d->setComponent(FIELD, [
    'type' => 'image',
    'label' => 'hidden',
    'settings' => ['image_style' => 'large', 'image_link' => ''],
    'weight' => -10,
  ])->save();
  printf("  added %s to the %s view mode\n", FIELD, $mode);
}

// --- verify from a fresh load ------------------------------------------------
$fresh = $etm->getStorage('taxonomy_term')->loadUnchanged($term->id());
printf("\nverify — %d image(s) on the term:\n", $fresh->get(FIELD)->count());
foreach ($fresh->get(FIELD) as $i => $item) {
  $file = $item->entity;
  printf("  %d. %-34s alt=%-3d caption=%s\n", $i + 1,
    $file ? basename($file->getFileUri()) : '(missing file)',
    mb_strlen((string) $item->alt),
    $item->title === '' || $item->title === NULL ? 'none' : mb_strlen((string) $item->title) . ' chars');
}
$captions = 0;
foreach ($fresh->get(FIELD) as $item) {
  if ((string) $item->title !== '') {
    $captions++;
  }
}
printf("\ncaptions set: %d (expected exactly 1 — the diagnosis frame)\n", $captions);
