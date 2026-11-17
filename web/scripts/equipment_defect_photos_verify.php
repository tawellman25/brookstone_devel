<?php

/**
 * @file
 * equipment_defect.standard.field_defect_photos — verification.
 *
 *   drush php:script web/scripts/equipment_defect_photos_verify.php              # dev: round-trips a defect with a generated photo (then deletes both)
 *   drush php:script web/scripts/equipment_defect_photos_verify.php -- --no-write  # live: read-only
 *
 * Read-only part: field definition + table, image styles, both displays (the
 * photos sit directly below the notes; every weight on each display unique),
 * which roles may edit defects (an image widget uploads under the entity's edit
 * access — there is no separate upload permission), the add form (routed GET as
 * uid 1) showing the file input with label + help text, an existing defect page
 * rendering, and the Open Defects board still answering 200 with its columns.
 * Write part (dev only): writes a 400×300 PNG to public://equipment/defects,
 * saves a defect carrying it, reloads, renders the defect page as uid 1 and
 * checks the medium thumbnail + the colorbox link to the max_1300x1300
 * derivative, then deletes the defect and the file.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$write = !in_array('--no-write', $extra ?? [], TRUE);
$pass = 0;
$fail = 0;
$ok = static function (bool $b) use (&$pass, &$fail): string {
  $b ? $pass++ : $fail++;
  return $b ? 'ok' : '** FAIL **';
};
$etm = \Drupal::entityTypeManager();
$mh = \Drupal::moduleHandler();

echo "== definition ==\n";
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('equipment_defect', 'standard');
$d = $defs['field_defect_photos'] ?? NULL;
echo '  field_defect_photos present: ' . $ok((bool) $d) . "\n";
if ($d) {
  printf("  type=%s (%s)  cardinality=%d (%s unlimited)  required=%s (%s)  label=\"%s\" (%s)\n", $d->getType(), $ok($d->getType() === 'image'), $d->getFieldStorageDefinition()->getCardinality(), $ok($d->getFieldStorageDefinition()->getCardinality() === -1), $d->isRequired() ? 'yes' : 'no', $ok(!$d->isRequired()), $d->getLabel(), $ok((string) $d->getLabel() === 'Defect Photos'));
  printf("  dir=%s (%s)  scheme=%s  ext=\"%s\" (%s)  alt field on=%s / required=%s (%s optional)  title field=%s (%s off)\n", $d->getSetting('file_directory'), $ok($d->getSetting('file_directory') === 'equipment/defects'), $d->getFieldStorageDefinition()->getSetting('uri_scheme'), $d->getSetting('file_extensions'), $ok(str_contains($d->getSetting('file_extensions'), 'jpg')), $d->getSetting('alt_field') ? 'yes' : 'no', $d->getSetting('alt_field_required') ? 'yes' : 'no', $ok($d->getSetting('alt_field') && !$d->getSetting('alt_field_required')), $d->getSetting('title_field') ? 'on' : 'off', $ok(!$d->getSetting('title_field')));
  echo '  help text: ' . $d->getDescription() . "\n";
}
$cols = \Drupal::database()->schema()->tableExists('equipment_defect__field_defect_photos') ? \Drupal::database()->query('SHOW COLUMNS FROM {equipment_defect__field_defect_photos}')->fetchAllKeyed(0, 1) : [];
echo '  table equipment_defect__field_defect_photos: ' . ($cols ? 'target_id ' . ($cols['field_defect_photos_target_id'] ?? 'MISSING') . ', alt ' . ($cols['field_defect_photos_alt'] ?? 'MISSING') : 'MISSING') . ' ' . $ok(isset($cols['field_defect_photos_target_id'], $cols['field_defect_photos_alt'])) . "\n";
$styles = $etm->getStorage('image_style');
echo '  modules image+file+colorbox: ' . $ok($mh->moduleExists('image') && $mh->moduleExists('file') && $mh->moduleExists('colorbox')) . '  image styles thumbnail/medium/max_1300x1300: ' . $ok($styles->load('thumbnail') && $styles->load('medium') && $styles->load('max_1300x1300')) . "\n";

echo "== displays ==\n";
foreach (['form' => [EntityFormDisplay::class, 'image_image'], 'view' => [EntityViewDisplay::class, 'colorbox']] as $kind => [$class, $plugin]) {
  $disp = $class::load('equipment_defect.standard.default');
  $c = $disp?->getComponent('field_defect_photos');
  $a = $disp?->getComponent('field_defect_notes');
  // Saved config only: the loaded display also carries runtime defaults for
  // base fields with display options (langcode @2 here) that toArray() strips
  // on save — they are not in the stored config and predate this field.
  $weights = array_map(static fn(array $x): int => (int) ($x['weight'] ?? 0), \Drupal::config('core.entity_' . $kind . '_display.equipment_defect.standard.default')->get('content') ?? []);
  $unique = $weights && count($weights) === count(array_unique($weights));
  printf("  %s: %s %s  (notes @%s, photos @%s — directly below: %s)  %d stored components, weights unique: %s\n", $kind, $c['type'] ?? 'not placed', $ok(($c['type'] ?? '') === $plugin), $a['weight'] ?? '?', $c['weight'] ?? '?', $ok(isset($a, $c) && (int) $c['weight'] === (int) $a['weight'] + 1), count($weights), $ok($unique));
  if ($kind === 'view' && $c) {
    $s = $c['settings'];
    printf("        page style=%s (%s)  lightbox style=%s (%s)  gallery=%s  label=%s\n", $s['colorbox_node_style'] ?? '', $ok(($s['colorbox_node_style'] ?? '') === 'medium'), $s['colorbox_image_style'] ?? '', $ok(($s['colorbox_image_style'] ?? '') === 'max_1300x1300'), $s['colorbox_gallery'] ?? '', $c['label'] ?? '');
  }
  if ($kind === 'form' && $c) {
    printf("        preview style=%s (%s)\n", $c['settings']['preview_image_style'] ?? '', $ok(($c['settings']['preview_image_style'] ?? '') === 'thumbnail'));
  }
}

echo "== who can edit defects (= who can upload: an image widget has no permission of its own) ==\n";
$editors = [];
foreach ($etm->getStorage('user_role')->loadMultiple() as $r) {
  if (in_array($r->id(), ['anonymous', 'authenticated'], TRUE)) {
    continue;
  }
  $p = $r->getPermissions();
  $edit = $r->isAdmin() || in_array('bypass eck entity access', $p, TRUE) || in_array('edit any equipment_defect entities', $p, TRUE) || in_array('edit any equipment_defect entities of bundle standard', $p, TRUE);
  if ($edit) {
    $editors[] = $r->id();
  }
}
echo '  roles: ' . implode(', ', $editors) . ' (' . count($editors) . ")\n";

$kernel = \Drupal::service('http_kernel');
$switch = \Drupal::service('account_switcher');
$get = static function (string $path) use ($kernel): array {
  \Drupal::theme()->resetActiveTheme();
  $req = Request::create($path, 'GET');
  $req->setSession(new Session(new MockArraySessionStorage()));
  $res = $kernel->handle($req, HttpKernelInterface::SUB_REQUEST);
  return [$res->getStatusCode(), (string) $res->getContent()];
};

echo "== routed GETs as uid 1 ==\n";
$switch->switchTo($etm->getStorage('user')->load(1));
try {
  [$code, $html] = $get('/admin/content/equipment_defect/add/standard');
  $has_input = (bool) preg_match('/<input[^>]*type="file"[^>]*name="files\[field_defect_photos_0\]\[\]"/', $html) || (bool) preg_match('/name="files\[field_defect_photos_0\]\[\]"[^>]*type="file"/', $html);
  printf("  add form: %d %s  file input for field_defect_photos: %s  label: %s  help text: %s  below the notes textarea: %s\n", $code, $ok($code === 200), $ok($has_input), $ok(str_contains($html, 'Defect Photos')), $ok(str_contains($html, 'General pictures of the machine belong on the equipment record')), $ok(($n = strpos($html, 'field_defect_notes[0][value]')) !== FALSE && ($p = strpos($html, 'field_defect_photos_0')) !== FALSE && $p > $n && ($s = strpos($html, 'edit-field-defect-status')) !== FALSE && $p < $s));

  $ids = $etm->getStorage('equipment_defect')->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
  if ($ids) {
    $e = $etm->getStorage('equipment_defect')->load(reset($ids));
    [$code] = $get($e->toUrl()->toString());
    printf("  existing defect #%d page (%s): %d %s (empty photo field renders nothing)\n", $e->id(), $e->toUrl()->toString(), $code, $ok($code === 200));
  }
  [$code, $html] = $get('/admin/operations/equipment/open-defects');
  printf("  Open Defects board: %d %s  columns Equipment/Severity/Summary/Status still there: %s  no PHP notice in the page: %s\n", $code, $ok($code === 200), $ok(str_contains($html, 'Equipment') && str_contains($html, 'Severity') && str_contains($html, 'Summary') && str_contains($html, 'Status')), $ok(!str_contains($html, 'Warning: ') && !str_contains($html, 'Deprecated: ')));
}
finally {
  $switch->switchBack();
}

if (!$write) {
  echo "== round-trip skipped (--no-write) ==\n";
  echo "\n$pass ok, $fail failed\n";
  // exit(0) makes drush report "terminated abnormally" on a clean pass; only
  // a failure needs the non-zero exit.
  if ($fail) {
    exit(1);
  }
  return;
}

echo "== round-trip (dev): PNG → file entity → defect → page render → delete ==\n";
$switch->switchTo($etm->getStorage('user')->load(1));
$fs = \Drupal::service('file_system');
$dir = 'public://equipment/defects';
$fs->prepareDirectory($dir, $fs::CREATE_DIRECTORY | $fs::MODIFY_PERMISSIONS);
$im = imagecreatetruecolor(400, 300);
imagefilledrectangle($im, 0, 0, 399, 299, imagecolorallocate($im, 200, 40, 40));
imagestring($im, 5, 120, 140, 'VERIFY defect photo', imagecolorallocate($im, 255, 255, 255));
ob_start();
imagepng($im);
$png = ob_get_clean();
imagedestroy($im);
$uri = $fs->saveData($png, "$dir/verify-defect-photo.png", $fs::EXISTS_RENAME);
$file = File::create(['uri' => $uri, 'uid' => 1, 'status' => 1, 'filename' => basename($uri)]);
$file->save();
printf("  file #%d %s (%d B) saved: %s\n", $file->id(), $uri, strlen($png), $ok(file_exists($uri)));

$storage = $etm->getStorage('equipment_defect');
$eq = $etm->getStorage('equipment')->getQuery()->accessCheck(FALSE)->range(0, 1)->execute();
$first = static fn(string $f) => array_key_first($defs[$f]->getSetting('allowed_values') ?? []);
$e = $storage->create([
  'type' => 'standard',
  'title' => 'VERIFY field_defect_photos (temporary)',
  'uid' => 1,
  'field_equipment' => $eq ? reset($eq) : NULL,
  'field_defect_summary' => 'verify: evidence photo',
  'field_defect_category' => $first('field_defect_category'),
  'field_defect_severity' => $first('field_defect_severity'),
  'field_defect_date_reported' => date('Y-m-d'),
  'field_defect_photos' => [['target_id' => $file->id(), 'alt' => '']],
]);
$viol = $e->validate();
echo '  validation (alt left empty): ' . $ok(count($viol) === 0) . (count($viol) ? ' — ' . implode(' | ', array_map(static fn($v) => $v->getPropertyPath() . ': ' . strip_tags((string) $v->getMessage()), iterator_to_array($viol))) : '') . "\n";
$e->save();
$id = $e->id();
$storage->resetCache([$id]);
$r = $storage->load($id);
$items = $r->get('field_defect_photos');
printf("  saved defect #%d: %d photo(s) %s  target_id=%s %s  width×height stored=%sx%s %s\n", $id, $items->count(), $ok($items->count() === 1), $items->target_id, $ok((int) $items->target_id === (int) $file->id()), $items->width, $items->height, $ok((int) $items->width === 400 && (int) $items->height === 300));
$usage = \Drupal::service('file.usage')->listUsage($file);
echo '  file usage recorded for equipment_defect: ' . $ok(isset($usage['file']['equipment_defect'][$id])) . "\n";
try {
  [$code, $html] = $get($r->toUrl()->toString());
  $thumb = (bool) preg_match('#<img[^>]+src="[^"]*/styles/medium/public/equipment/defects/verify-defect-photo[^"]*"#', $html);
  $box = (bool) preg_match('#<a[^>]+href="[^"]*/styles/max_1300x1300/public/equipment/defects/verify-defect-photo[^"]*"[^>]*class="[^"]*colorbox#', $html) || (bool) preg_match('#<a[^>]+class="[^"]*colorbox[^"]*"[^>]*href="[^"]*/styles/max_1300x1300/public/equipment/defects/verify-defect-photo#', $html);
  printf("  defect page: %d %s  medium thumbnail <img>: %s  colorbox link to max_1300x1300: %s  'Defect Photos' label: %s\n", $code, $ok($code === 200), $ok($thumb), $ok($box), $ok(str_contains($html, 'Defect Photos')));
  $derivative = \Drupal::service('image.factory')->get($uri)->isValid() && $styles->load('medium')->createDerivative($uri, $styles->load('medium')->buildUri($uri));
  echo '  medium derivative generates from the stored file: ' . $ok((bool) $derivative) . "\n";
}
finally {
  $r->delete();
  echo '  test defect deleted: ' . $ok(!$storage->load($id)) . "\n";
  $file->delete();
  foreach (['medium', 'max_1300x1300', 'thumbnail'] as $s) {
    $du = $styles->load($s)->buildUri($uri);
    if (file_exists($du)) {
      $fs->delete($du);
    }
  }
  echo '  test file + derivatives deleted: ' . $ok(!file_exists($uri) && !File::load($file->id())) . "\n";
  $switch->switchBack();
}
echo "\n$pass ok, $fail failed\n";
if ($fail) {
  exit(1);
}
