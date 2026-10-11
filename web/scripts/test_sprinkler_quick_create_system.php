<?php
/**
 * The quick-entry form must create the property's first sprinkler system.
 *
 * Before 2026-10-09 it refused, told the crew to go and use "Add Sprinkler
 * System", and — because every save branch was gated on a system existing —
 * silently dropped the shut-off, hookup and clock values they had already
 * typed, under a message saying nothing had been entered. 43 of this season's
 * 579 winterizing work orders are on a property with no system by either
 * lookup path — and of those, 42 have no system type on the work order either,
 * which is why the type has to be asked for rather than inferred.
 *
 * Note for anyone re-measuring: counting "has no system" with a join on
 * property_sprinkler_system.field_property alone over-counts badly (it gives
 * 107). Only 1,147 of 1,253 systems carry that field; the complete test is the
 * one findSystem() makes, which also follows property_sprinkler_info.
 *
 * The part worth testing is not that a record appears. It is that BILLING can
 * see it: _wo_sw_property_systems() reaches a system only through
 * property_sprinkler_info.field_systems, and _wo_sw_resolve_system_type_total()
 * returns 0 when it resolves no type — so a system linked the obvious way
 * (field_property alone) would leave the winterizing billing nothing.
 *
 * Reversible: every record it creates is deleted, and the info record it
 * borrows is put back as it was. Safe to re-run.
 */
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$pass = 0; $fail = 0;
$ok = function (string $what, bool $good, string $got = '') use (&$pass, &$fail) {
  $good ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $good ? 'PASS' : 'FAIL', $what, $got !== '' ? " [$got]" : '');
};

// A winterizing work order whose property has no sprinkler system — the exact
// shape of WO#53692 in the report. Found, not hardcoded, so this keeps working.
$woId = (int) getenv('BOS_TEST_WO') ?: 0;
if (!$woId) {
  $candidates = $db->query("SELECT w.id FROM {work_order_field_data} w
    JOIN {work_order__field_property} p ON p.entity_id = w.id
    LEFT JOIN {property_sprinkler_system__field_property} s ON s.field_property_target_id = p.field_property_target_id
    WHERE w.type = 'sprinkler_winterizing' AND s.entity_id IS NULL
    ORDER BY w.id DESC LIMIT 50")->fetchCol();
  foreach ($candidates as $cid) {
    $c = $etm->getStorage('work_order')->load($cid);
    $pid = $c ? (int) $c->get('field_property')->target_id : 0;
    if (!$pid) { continue; }
    // Must have no system by EITHER lookup path.
    $infoIds = $etm->getStorage('property_sprinkler_info')->getQuery()->accessCheck(FALSE)
      ->condition('field_property', $pid)->execute();
    $listed = 0;
    foreach ($infoIds as $iid) {
      $listed += count($etm->getStorage('property_sprinkler_info')->load($iid)->get('field_systems')->getValue());
    }
    if ($listed === 0) { $woId = (int) $cid; break; }
  }
}
if (!$woId) { print "no suitable work order found\n"; return; }

$wo = $etm->getStorage('work_order')->load($woId);
$propertyId = (int) $wo->get('field_property')->target_id;
printf("work order %d, property %d (%s)\n\n", $woId, $propertyId, $wo->get('field_property')->entity->label());

$findSystems = function () use ($etm, $propertyId) {
  $ids = $etm->getStorage('property_sprinkler_system')->getQuery()->accessCheck(FALSE)
    ->condition('field_property', $propertyId)->execute();
  return array_values($ids);
};

print "0. baseline\n";
$ok('property has no sprinkler system', !$findSystems());
$ok('billing sees no system', !_wo_sw_property_systems($propertyId));

// Snapshot the info record so it can be restored exactly.
$infoIds = $etm->getStorage('property_sprinkler_info')->getQuery()->accessCheck(FALSE)
  ->condition('field_property', $propertyId)->sort('id')->range(0, 1)->execute();
$infoBefore = $infoIds ? $etm->getStorage('property_sprinkler_info')->load(reset($infoIds)) : NULL;
$infoSystemsBefore = $infoBefore ? $infoBefore->get('field_systems')->getValue() : NULL;
$infoExistedBefore = (bool) $infoBefore;

$submit = function (array $values) use ($wo) {
  $fs = new \Drupal\Core\Form\FormState();
  $fs->setValues($values);
  \Drupal::formBuilder()->submitForm(\Drupal\properties\Form\SprinklerQuickCaptureForm::class, $fs, $wo);
  return $fs;
};

print "\n1. an empty save creates nothing\n";
$fs = $submit(['zones' => '', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => '', 'bfp_location' => '',
  'clock_location' => '', 'system_type' => '']);
$ok('no system created', !$findSystems());
$ok('no validation error raised', !$fs->getErrors());

print "\n2. values entered with no type chosen is refused, not half-saved\n";
$fs = $submit(['zones' => '6', 'shut_off_location' => '', 'shut_off_notes' => 'TEST shutoff', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => '', 'bfp_location' => '',
  'clock_location' => '', 'system_type' => '']);
$ok('validation error on system_type', (bool) $fs->getErrors(), implode(' | ', array_keys($fs->getErrors())));
$ok('still no system created', !$findSystems());

print "\n2b. a BACKFLOW is recorded without forcing a sprinkler system\n";
// A backflow device belongs to the PROPERTY, the same record the testing side
// uses, so a crew reading a serial off a tag must not be made to answer what
// the water supply is first. Everything else on this screen does need a system.
$findDevices = function () use ($etm, $propertyId) {
  $ids = $etm->getStorage('property_backflow_device')->getQuery()->accessCheck(FALSE)
    ->condition('field_property', $propertyId)->sort('id')->execute();
  return $ids ? $etm->getStorage('property_backflow_device')->loadMultiple($ids) : [];
};
$ok('no backflow device to start', !$findDevices());
$bfTypes = $etm->getStorage('taxonomy_term')->loadByProperties(['vid' => 'backflow_device_types', 'field_type_code' => 'PVB']);
$PVB = $bfTypes ? (int) reset($bfTypes)->id() : 0;
$ok('found the PVB type term', (bool) $PVB, 'tid=' . $PVB);

$fs = $submit(['zones' => '', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => 'backflow', 'bfp_location' => 'TEST - north wall',
  'clock_location' => '', 'system_type' => '', 'bf_type' => $PVB, 'bf_serial' => 'TEST-SN-001']);
$ok('no validation error (no system type demanded)', !$fs->getErrors(), implode(' | ', array_keys($fs->getErrors())));
$ok('still NO sprinkler system created', !$findSystems());
$devices = $findDevices();
$ok('exactly one backflow device created', count($devices) === 1, 'count=' . count($devices));
$device = $devices ? reset($devices) : NULL;
if ($device) {
  $ok('serial number stored', (string) $device->get('field_serial_number')->value === 'TEST-SN-001');
  $ok('device type stored', (int) $device->get('field_device_type')->target_id === $PVB);
  $ok('location stored on the DEVICE', str_contains((string) $device->get('field_physical_location')->value, 'north wall'));
  $ok('titled with its BF code', str_starts_with((string) $device->label(), 'BF-'), (string) $device->label());
  $ok('QR generated', !$device->get('field_qr_code')->isEmpty());
  $ok('used for irrigation', (string) ($device->get('field_used_for')->entity?->get('field_use_code')->value ?? '') === 'IRRIGATION');
  $ok('status active', (string) $device->get('field_current_status')->value === 'active');
}

print "\n2c. a second visit updates that device, never makes a second\n";
$submit(['zones' => '', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => 'backflow', 'bfp_location' => '',
  'clock_location' => '', 'system_type' => '', 'bf_type' => '', 'bf_serial' => 'TEST-SN-002']);
$devices = $findDevices();
$ok('still exactly one device', count($devices) === 1, 'count=' . count($devices));
$d2 = $devices ? $etm->getStorage('property_backflow_device')->loadUnchanged(reset($devices)->id()) : NULL;
$ok('serial updated', $d2 && (string) $d2->get('field_serial_number')->value === 'TEST-SN-002');
$ok('type NOT blanked by an empty box', $d2 && (int) $d2->get('field_device_type')->target_id === $PVB);
$ok('location NOT blanked by an empty box', $d2 && str_contains((string) $d2->get('field_physical_location')->value, 'north wall'));

print "\n3. values + a pump type (Well) creates the system\n";
$WELL = 30742;
$fs = $submit(['zones' => '6', 'shut_off_location' => '', 'shut_off_notes' => 'TEST shutoff - delete me',
  'key_needed' => '', 'hookup_type' => '', 'hookup_location' => 'TEST hookup - delete me',
  'which' => '', 'bfp_location' => '', 'clock_location' => 'TEST clock - delete me',
  'system_type' => $WELL]);
$ok('no validation error', !$fs->getErrors(), implode(' | ', array_keys($fs->getErrors())));
$systems = $findSystems();
$ok('exactly one system created', count($systems) === 1, 'count=' . count($systems));
$system = $systems ? $etm->getStorage('property_sprinkler_system')->load(reset($systems)) : NULL;
if (!$system) { print "\n  cannot continue without the system\n"; return; }

$ok('title is not a label placeholder', !str_contains((string) $system->label(), '%AutoEntityLabel'), (string) $system->label());
$ok('named by the house convention', (string) $system->get('field_system_name')->value === 'Primary', (string) $system->label());
$ok('type is the one chosen', (int) $system->get('field_system_type')->target_id === $WELL);
$ok('operation set to Automatic', (int) $system->get('field_operation')->target_id === 1279);

print "\n4. all three link paths, because billing uses the third\n";
$ok('link 1: field_property -> property', (int) $system->get('field_property')->target_id === $propertyId);
$ok('link 2: field_property_system_info set', !$system->get('field_property_system_info')->isEmpty());
$infoId = (int) $system->get('field_property_system_info')->target_id;
$info = $etm->getStorage('property_sprinkler_info')->load($infoId);
$listedIds = array_column($info->get('field_systems')->getValue(), 'target_id');
$ok('link 3: listed in info.field_systems', in_array((string) $system->id(), array_map('strval', $listedIds), TRUE));

print "\n5. billing can actually see and price it\n";
$billingSystems = _wo_sw_property_systems($propertyId);
$ok('billing finds exactly one system', count($billingSystems) === 1, 'count=' . count($billingSystems));
$probe = $etm->getStorage('work_order')->load($woId);
if ($probe->hasField('field_system_type')) { $probe->set('field_system_type', NULL); }
$total = _wo_sw_resolve_system_type_total($probe, $propertyId, 95.0, 25.0);
$ok('a Well system prices base + pump, not 0', abs($total - 120.0) < 0.01, 'total=' . $total);

print "\n6. the values the crew typed were kept, not dropped\n";
$ok('zones saved on the system', (int) $system->get('field_total_zones')->value === 6, 'zones=' . $system->get('field_total_zones')->value);
$sourceIds = $etm->getStorage('property_ss_sources')->getQuery()->accessCheck(FALSE)
  ->condition('field_property_ss_system', $system->id())->execute();
$source = $sourceIds ? $etm->getStorage('property_ss_sources')->load(reset($sourceIds)) : NULL;
$ok('a water source record was written', (bool) $source);
$ok('shut-off note kept', $source && str_contains((string) $source->get('field_shut_off_location_descript')->value, 'TEST shutoff'));
$ok('hookup location kept', $source && str_contains((string) $source->get('field_ss_hookup_location')->value, 'TEST hookup'));
$ok('source bundle matches a well system', $source && $source->bundle() === 'well_water_source', $source ? $source->bundle() : '-');
$ctrlIds = $etm->getStorage('property_system_controller')->getQuery()->accessCheck(FALSE)
  ->condition('field_property_ss_system', $system->id())->execute();
$ctrl = $ctrlIds ? $etm->getStorage('property_system_controller')->load(reset($ctrlIds)) : NULL;
$ok('a controller record was written', (bool) $ctrl);
$ok('clock location kept', $ctrl && str_contains((string) $ctrl->get('field_controller_location')->value, 'TEST clock'));

print "\n7. a second save reuses the system, never makes a second one\n";
$fs = $submit(['zones' => '8', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => '', 'bfp_location' => '',
  'clock_location' => '', 'system_type' => '']);
$ok('still exactly one system', count($findSystems()) === 1, 'count=' . count($findSystems()));
$reloaded = $etm->getStorage('property_sprinkler_system')->loadUnchanged($system->id());
$ok('zones updated on the same record', (int) $reloaded->get('field_total_zones')->value === 8);

print "\n7b. a PUMP is recorded as a real pump record\n";
$findPumpsFor = function () use ($etm, $system) {
  $src = $etm->getStorage('property_ss_sources')->getQuery()->accessCheck(FALSE)
    ->condition('field_property_ss_system', $system->id())->sort('id')->range(0,1)->execute();
  if (!$src) { return []; }
  $ids = $etm->getStorage('property_sprinkler_pumps')->getQuery()->accessCheck(FALSE)
    ->condition('field_property_ss_source', reset($src))->execute();
  return $ids ? $etm->getStorage('property_sprinkler_pumps')->loadMultiple($ids) : [];
};
$ok('no pump to start', !$findPumpsFor());
$submit(['zones' => '', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => 'pump', 'bfp_location' => 'TEST - pump house',
  'clock_location' => '', 'system_type' => '', 'pump_hp' => '01.50']);
$pumps = $findPumpsFor();
$ok('one pump created', count($pumps) === 1, 'count=' . count($pumps));
$pump = $pumps ? reset($pumps) : NULL;
if ($pump) {
  $ok('horse power stored', (string) $pump->get('field_pump_size')->value === '01.50', (string) $pump->get('field_pump_size')->value);
  $ok('location stored on the PUMP, not a backflow field',
    str_contains((string) $pump->get('field_pump_location')->value, 'pump house'));
}
$submit(['zones' => '', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => 'pump', 'bfp_location' => '',
  'clock_location' => '', 'system_type' => '', 'pump_hp' => '03.00']);
$ok('second visit updates, no duplicate pump', count($findPumpsFor()) === 1);
$p2 = $findPumpsFor();
$ok('horse power updated', $p2 && (string) reset($p2)->get('field_pump_size')->value === '03.00');

print "\n8. the clock takes more than one photo\n";
// The crew takes three: where the clock is, the face with the door open, and
// the wiring (which is how the real zone count gets settled). The field has
// always been unlimited and the submit handler always appended every fid --
// the element was simply accepting one at a time until 2026-10-09.
$built = \Drupal::formBuilder()->getForm(\Drupal\properties\Form\SprinklerQuickCaptureForm::class, $wo);
$ok('the clock photo element accepts multiple', !empty($built['clock']['clock_photo']['#multiple']));

$dir = 'public://sprinkler-quick';
\Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
// 1x1 PNG.
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$testFiles = [];
foreach (['location', 'door-open', 'wiring', 'extra'] as $n) {
  $file = \Drupal::service('file.repository')->writeData($png, $dir . '/TEST-delete-me-' . $n . '-' . uniqid() . '.png');
  // ManagedFile::valueCallback refuses a temporary file owned by someone else,
  // and drush is nobody, so these have to be permanent to get through.
  $file->setPermanent();
  $file->save();
  $testFiles[] = $file;
}
// The first three are this visit; the fourth stands in for a later one.
$extra = array_pop($testFiles);
$fids = array_map(fn($f) => (int) $f->id(), $testFiles);
$testFiles[] = $extra;
$fs = $submit(['zones' => '', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => '', 'bfp_location' => '',
  // valueCallback reads $input['fids'] as the space-separated string the
  // hidden form field carries, not a plain array of ids.
  'clock_location' => '', 'system_type' => '', 'clock_photo' => ['fids' => implode(' ', $fids)]]);
$ctrlAfter = $etm->getStorage('property_system_controller')->loadUnchanged($ctrl->id());
$count = $ctrlAfter ? count($ctrlAfter->get('field_controller_photos')->getValue()) : 0;
$ok('all three photos landed on the clock', $count === 3, 'photos=' . $count);

// A later visit adds to them rather than replacing them.
$submit(['zones' => '', 'shut_off_location' => '', 'shut_off_notes' => '', 'key_needed' => '',
  'hookup_type' => '', 'hookup_location' => '', 'which' => '', 'bfp_location' => '',
  'clock_location' => '', 'system_type' => '', 'clock_photo' => ['fids' => (string) $extra->id()]]);
$ctrlAfter = $etm->getStorage('property_system_controller')->loadUnchanged($ctrl->id());
$count2 = $ctrlAfter ? count($ctrlAfter->get('field_controller_photos')->getValue()) : 0;
$ok('a second visit appends, never replaces', $count2 === 4, 'photos=' . $count2);

// ---- cleanup -------------------------------------------------------------
print "\ncleanup\n";
foreach ($findDevices() as $d) { $d->delete(); }
foreach ($findPumpsFor() as $pm) { $pm->delete(); }
foreach ($testFiles ?? [] as $f) { $f->delete(); }
if ($ctrl) { $ctrl->delete(); }
if ($source) { $source->delete(); }
$system->delete();
if ($infoExistedBefore) {
  $info = $etm->getStorage('property_sprinkler_info')->loadUnchanged($infoId);
  $info->set('field_systems', $infoSystemsBefore);
  $info->save();
  print "  info $infoId restored to " . count($infoSystemsBefore) . " system(s)\n";
}
else {
  $etm->getStorage('property_sprinkler_info')->loadUnchanged($infoId)?->delete();
  print "  info $infoId deleted (it did not exist before)\n";
}
$ok('property is back to no system', !$findSystems());
$ok('no backflow device left behind', !$findDevices());
$ok('billing is back to no system', !_wo_sw_property_systems($propertyId));

printf("\n%d passed, %d failed\n", $pass, $fail);
