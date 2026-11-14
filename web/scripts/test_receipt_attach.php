<?php
// DEV ONLY — never on live (it writes rows, then rolls them back).
//   ddev exec php web/scripts/make_test_receipt.php && ddev drush php:script web/scripts/test_receipt_attach.php -- <wo_material_list id>
// Exercises MaterialListImportService::import() with a receipt photo: two rows share one File, the file is
// moved out of temporary:// and made permanent, usage is one per row, merge semantics, clean rollback.
use Drupal\file\Entity\File;
$listId = (int) ($extra[0] ?? 6137);
$etm = \Drupal::entityTypeManager(); $fs = \Drupal::service('file_system');
$pass = 0; $fail = 0; $t = function (string $name, bool $ok, string $note = '') use (&$pass, &$fail) { echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($note !== '' ? " — $note" : '') . "\n"; $ok ? $pass++ : $fail++; };
$importer = \Drupal::service('wo_material_list_management.import');
// A stocked material to merge against.
$mid = (int) reset(\Drupal::entityQuery('material')->accessCheck(FALSE)->range(0, 1)->execute());
echo "list $listId, material $mid\n";
$before = \Drupal::entityQuery('wo_material_list_item')->accessCheck(FALSE)->condition('field_list_id', $listId)->execute();
// Fresh temporary file like the form's upload handler makes.
$dir = 'temporary://wo_material_import'; $fs->prepareDirectory($dir, 1);
$uri = $fs->copy('/var/www/html/tmp_test/receipt.jpg', $dir . '/receipt_test.jpg', \Drupal\Core\File\FileExists::Rename);
$file = File::create(['uri' => $uri, 'filename' => basename($uri), 'filemime' => 'image/jpeg', 'status' => 0, 'uid' => 1]); $file->save();
$fid = (int) $file->id();
$rows = [
  ['include' => TRUE, 'material_id' => 0, 'quantity' => 1, 'unit_cost' => '1.59', 'supplier_item_number' => '3182557', 'description' => 'REDUCING WASHER STL 3/4" (test)', 'identifier' => '3182557'],
  ['include' => TRUE, 'material_id' => $mid, 'quantity' => 2, 'unit_cost' => '6.59', 'supplier_item_number' => 'TEST-3539541', 'description' => 'STRAIGHT CONNECT (test)', 'identifier' => 'TEST-3539541'],
  ['include' => FALSE, 'material_id' => 0, 'quantity' => 1, 'unit_cost' => '', 'supplier_item_number' => '', 'description' => 'not ticked', 'identifier' => 'x'],
];
$r1 = $importer->import($listId, $rows, 12, FALSE, $fid);
$t('import 1 created 2 / skipped 1', ($r1['created'] ?? 0) === 2 && ($r1['skipped'] ?? 0) === 1, json_encode($r1));
$t('receipt_attached = 2', ($r1['receipt_attached'] ?? 0) === 2);
$f = File::load($fid);
$t('file is permanent', $f && $f->isPermanent());
$t('file moved out of temporary:// into public:// (filefield_paths then applies its own per-item path)', $f && str_starts_with($f->getFileUri(), 'public://'), $f ? $f->getFileUri() : '');
$t('file exists on disk', $f && is_file($fs->realpath($f->getFileUri())));
$new = array_diff(\Drupal::entityQuery('wo_material_list_item')->accessCheck(FALSE)->condition('field_list_id', $listId)->execute(), $before);
$items = $etm->getStorage('wo_material_list_item')->loadMultiple($new);
$with = 0; foreach ($items as $it) { if ((int) ($it->get('field_reciept_pic')->target_id ?? 0) === $fid) { $with++; } }
$t('both new rows reference the same file', count($items) === 2 && $with === 2, count($items) . ' rows (' . implode(',', array_keys($items)) . '), ' . $with . ' with pic');
$usage = \Drupal::service('file.usage')->listUsage($f);
$cnt = (int) array_sum($usage['file']['wo_material_list_item'] ?? []);
$t('file usage = 2 (one per row)', $cnt === 2, "usage=$cnt");
// Merge: same stocked material again, same receipt → merged line already has a pic → unchanged; created purchased again +1.
$r2 = $importer->import($listId, [$rows[1]], 12, FALSE, $fid);
$t('import 2 merged 1 (same material)', ($r2['merged'] ?? 0) === 1, json_encode($r2));
$t('merge did not re-attach (already had it)', ($r2['receipt_attached'] ?? 0) === 0);
// Merge onto a line WITHOUT a pic: clear it first, import again → gets it.
$stocked = NULL; foreach ($items as $it) { if ($it->get('field_material_type')->value === 'stocked_item') { $stocked = $it; } }
$stocked->set('field_reciept_pic', NULL); $stocked->save();
$r3 = $importer->import($listId, [$rows[1]], 12, FALSE, $fid);
$stocked = $etm->getStorage('wo_material_list_item')->load($stocked->id());
$t('merge onto a line without a pic attaches it', ($r3['receipt_attached'] ?? 0) === 1 && (int) $stocked->get('field_reciept_pic')->target_id === $fid);
$t('quantity merged 2+2+2 = 6', (int) $stocked->get('field_quantity')->value === 6, 'qty=' . $stocked->get('field_quantity')->value);
$u = \Drupal::service('file.usage')->listUsage(File::load($fid)); $uc = (int) ($u['file']['wo_material_list_item'][$stocked->id()] ?? 0);
$t('usage for the re-attached row is exactly 1', $uc === 1, "usage=$uc");
// Rollback — delete freshly loaded entities (a stale object deletes by its stale field values).
$etm->getStorage('wo_material_list_item')->delete($etm->getStorage('wo_material_list_item')->loadMultiple(array_keys($items)));
$left = array_diff(\Drupal::entityQuery('wo_material_list_item')->accessCheck(FALSE)->condition('field_list_id', $listId)->execute(), $before);
$t('rollback: no test rows remain', !$left);
$usage = \Drupal::service('file.usage')->listUsage(File::load($fid));
$t('rollback: file usage back to 0', empty($usage), 'remaining usage: ' . json_encode($usage));
$f = File::load($fid); if ($f) { $f->delete(); }
$t('rollback: file entity deleted', !File::load($fid));
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
