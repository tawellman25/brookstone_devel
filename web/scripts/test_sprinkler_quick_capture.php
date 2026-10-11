<?php

/**
 * @file
 * Reversible test of the sprinkler quick-entry screen. Everything it creates or
 * changes is restored before exit.
 */

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

// A sprinkler WO on a property that HAS a system, so find-or-create is exercised.
$row = $db->query("
  SELECT w.id wo, wp.field_property_target_id pid
  FROM {work_order_field_data} w
  JOIN {work_order__field_property} wp ON wp.entity_id = w.id AND wp.deleted = 0
  JOIN {property_sprinkler_system__field_property} sp ON sp.field_property_target_id = wp.field_property_target_id AND sp.deleted = 0
  WHERE w.type = 'sprinkler_winterizing' LIMIT 1")->fetchObject();
if (!$row) { print "no suitable work order found\n"; return; }
$woId = (int) $row->wo; $pid = (int) $row->pid;
$wo = $etm->getStorage('work_order')->load($woId);
$prop = $etm->getStorage('properties')->load($pid);
printf("WO %d on property %d (%s)\n\n", $woId, $pid, $prop->label());

$fo = \Drupal::classResolver('Drupal\properties\Form\SprinklerQuickCaptureForm');

// --- The form builds and shows every item the crew asked for ---------------
$fs = new \Drupal\Core\Form\FormState();
$fs->addBuildInfo('args', [$wo]);
$form = \Drupal::formBuilder()->buildForm($fo, $fs);
$wanted = [
  'zones' => $form['zones'] ?? NULL,
  'shut off location' => $form['shut_off']['shut_off_location'] ?? NULL,
  'key type' => $form['shut_off']['key_needed'] ?? NULL,
  'shut off photo' => $form['shut_off']['shut_off_photo'] ?? NULL,
  'hookup type' => $form['hookup']['hookup_type'] ?? NULL,
  'hookup location' => $form['hookup']['hookup_location'] ?? NULL,
  'backflow or pump' => $form['bfp']['which'] ?? NULL,
  'clock location' => $form['clock']['clock_location'] ?? NULL,
];
foreach ($wanted as $label => $el) { $check("form shows: $label", $el !== NULL); }
$check('key type offers the real key list',
  isset($form['shut_off']['key_needed']['#options']['curb_box_key']) || count($form['shut_off']['key_needed']['#options'] ?? []) >= 5,
  implode(' / ', array_slice(array_values($form['shut_off']['key_needed']['#options'] ?? []), 0, 4)));
$check('hookup type offers the new list', count($form['hookup']['hookup_type']['#options'] ?? []) >= 6,
  implode(' / ', array_slice(array_values($form['hookup']['hookup_type']['#options'] ?? []), 0, 4)));

// --- Snapshot what we are about to change ---------------------------------
$sysIds = $etm->getStorage('property_sprinkler_system')->getQuery()->accessCheck(FALSE)
  ->condition('field_property', $pid)->sort('id')->range(0, 1)->execute();
$system = $etm->getStorage('property_sprinkler_system')->load(reset($sysIds));
$beforeZones = $system->get('field_total_zones')->value;
$srcIdsBefore = $etm->getStorage('property_ss_sources')->getQuery()->accessCheck(FALSE)
  ->condition('field_property_ss_system', $system->id())->execute();
// Snapshot every value this test overwrites on an existing record, so the
// restore puts the real data back rather than just clearing TEST strings.
$snapshot = [];
if ($srcIdsBefore) {
  $s0 = $etm->getStorage('property_ss_sources')->load(reset($srcIdsBefore));
  foreach (['field_ss_shut_off_location','field_shut_off_location_descript','field_ss_key_needed',
            'field_ss_hookup_type','field_ss_hookup_location','field_ss_backflow_location','field_ss_shut_off_notes'] as $f) {
    $snapshot[$f] = $s0->get($f)->isEmpty() ? NULL : $s0->get($f)->value;
  }
}
$ctlSnapshot = NULL;
$ctlIdsBefore = $etm->getStorage('property_system_controller')->getQuery()->accessCheck(FALSE)
  ->condition('field_property_ss_system', $system->id())->execute();
if ($ctlIdsBefore) {
  $c0 = $etm->getStorage('property_system_controller')->load(reset($ctlIdsBefore));
  $ctlSnapshot = $c0->get('field_controller_location')->isEmpty() ? NULL : $c0->get('field_controller_location')->value;
}

// Backflow devices already on this property, so the run only ever deletes its
// own and never an office record.
$devIdsBefore = [];
$devSnapshot = [];
if (!$system->get('field_property')->isEmpty()) {
  $devIdsBefore = $etm->getStorage('property_backflow_device')->getQuery()->accessCheck(FALSE)
    ->condition('field_property', (int) $system->get('field_property')->target_id)->execute();
  // The form UPDATES an existing device rather than making a second one, which
  // is right -- and means this test would otherwise leave a real device
  // carrying "TEST in the basement" as its location. Snapshot the fields it
  // writes so they can be put back exactly.
  foreach ($etm->getStorage('property_backflow_device')->loadMultiple($devIdsBefore) as $d0) {
    $devSnapshot[$d0->id()] = [
      'field_physical_location' => $d0->get('field_physical_location')->getValue(),
      'field_serial_number' => $d0->get('field_serial_number')->getValue(),
      'field_device_type' => $d0->get('field_device_type')->getValue(),
    ];
  }
}

// --- Submit the five things -----------------------------------------------
$fs2 = new \Drupal\Core\Form\FormState();
$fs2->addBuildInfo('args', [$wo]);
$fs2->setValues([
  'zones' => 7,
  'shut_off_location' => '3',      // in the Crawlspace
  'shut_off_notes' => 'TEST left of the furnace',
  'key_needed' => '3',             // Long Key
  'hookup_type' => 'blow_out_port',
  'hookup_location' => 'TEST NE corner behind the hose bibb',
  'which' => 'backflow',
  'bfp_location' => 'TEST in the basement',
  'clock_location' => 'TEST garage north wall',
  'shut_off_photo' => [],
  'clock_photo' => [],
]);
$fs2->setUserInput(['op' => 'Save']);
\Drupal::formBuilder()->submitForm($fo, $fs2);
$errs = implode(' ', array_map('strval', $fs2->getErrors()));
$check('submitted without errors', $errs === '', $errs ?: 'none');

// --- Did it land on the right records? ------------------------------------
$etm->getStorage('property_sprinkler_system')->resetCache([$system->id()]);
$system = $etm->getStorage('property_sprinkler_system')->load($system->id());
$check('zones written to the sprinkler system', (int) $system->get('field_total_zones')->value === 7,
  'now ' . $system->get('field_total_zones')->value);

$srcIds = $etm->getStorage('property_ss_sources')->getQuery()->accessCheck(FALSE)
  ->condition('field_property_ss_system', $system->id())->sort('id')->execute();
$source = $srcIds ? $etm->getStorage('property_ss_sources')->load(reset($srcIds)) : NULL;
$check('a water-source record exists for the system', (bool) $source,
  $srcIdsBefore ? 'reused the existing one' : 'created one');
if ($source) {
  foreach ([
    'field_ss_shut_off_location' => '3',
    'field_shut_off_location_descript' => 'TEST left of the furnace',
    'field_ss_key_needed' => '3',
    'field_ss_hookup_type' => 'blow_out_port',
    'field_ss_hookup_location' => 'TEST NE corner behind the hose bibb',
    'field_ss_backflow_location' => 'TEST in the basement',
  ] as $f => $want) {
    $check(sprintf('  %-34s saved', $f), (string) $source->get($f)->value === $want,
      (string) $source->get($f)->value);
  }
  // Until 2026-10-10 this appended "Has a backflow." to the shut-off NOTES and
  // wrote nothing else -- unqueryable, in a field about something else, and it
  // did not survive to the next visit. It is now a real property_backflow_device
  // on the property, the same record the testing side works from.
  $pid = (int) $system->get('field_property')->target_id;
  $devIds = $pid ? $etm->getStorage('property_backflow_device')->getQuery()->accessCheck(FALSE)
    ->condition('field_property', $pid)->sort('id')->execute() : [];
  $device = $devIds ? $etm->getStorage('property_backflow_device')->load(end($devIds)) : NULL;
  $check('  backflow recorded as a real device', (bool) $device, $device ? $device->label() : 'none');
  $check('  device location taken from the form', $device
    && str_contains((string) $device->get('field_physical_location')->value, 'TEST in the basement'),
    $device ? (string) $device->get('field_physical_location')->value : '-');
    $check('  source bundle matches the system type', in_array($source->bundle(), ['domestic_source','dirty_water_source','well_water_source'], TRUE),
    $source->bundle() . ' for system type "' . ($system->get('field_system_type')->entity?->label() ?? '?') . '"');
}

$ctlIds = $etm->getStorage('property_system_controller')->getQuery()->accessCheck(FALSE)
  ->condition('field_property_ss_system', $system->id())->sort('id')->execute();
$controller = $ctlIds ? $etm->getStorage('property_system_controller')->load(reset($ctlIds)) : NULL;
$check('clock location saved on a controller record', $controller && (string) $controller->get('field_controller_location')->value === 'TEST garage north wall',
  $controller ? (string) $controller->get('field_controller_location')->value : 'no controller');
if ($controller && !$ctlIdsBefore) {
  $check('  a new controller got the required defaults',
    (int) $controller->get('field_controller_number')->value === 1 && $controller->get('field_controller_type')->value !== NULL);
}

// --- Restore ---------------------------------------------------------------
$system->set('field_total_zones', $beforeZones);
$system->save();
foreach (array_diff($srcIds, $srcIdsBefore) as $id) { $etm->getStorage('property_ss_sources')->load($id)?->delete(); }
foreach (array_diff($ctlIds, $ctlIdsBefore) as $id) { $etm->getStorage('property_system_controller')->load($id)?->delete(); }
foreach (array_diff($devIds ?? [], $devIdsBefore ?? []) as $id) { $etm->getStorage('property_backflow_device')->load($id)?->delete(); }
// Put every pre-existing device back exactly as it was found.
foreach ($devSnapshot as $did => $fields) {
  $d = $etm->getStorage('property_backflow_device')->load($did);
  if (!$d) { continue; }
  foreach ($fields as $f => $val) { $d->set($f, $val); }
  $d->save();
}
// If we reused existing records, clear only the TEST values we wrote.
if ($source && in_array($source->id(), $srcIdsBefore)) {
  foreach ($snapshot as $f => $was) { $source->set($f, $was); }
  $source->save();
}
if ($controller && in_array($controller->id(), $ctlIdsBefore)) {
  $controller->set('field_controller_location', $ctlSnapshot);
  $controller->save();
}
$etm->getStorage('property_sprinkler_system')->resetCache([$system->id()]);
$check('zones restored', (string) $etm->getStorage('property_sprinkler_system')->load($system->id())->get('field_total_zones')->value === (string) $beforeZones,
  'back to ' . var_export($beforeZones, TRUE));
$leftSrc = count(array_diff($etm->getStorage('property_ss_sources')->getQuery()->accessCheck(FALSE)->condition('field_property_ss_system', $system->id())->execute(), $srcIdsBefore));
$leftCtl = count(array_diff($etm->getStorage('property_system_controller')->getQuery()->accessCheck(FALSE)->condition('field_property_ss_system', $system->id())->execute(), $ctlIdsBefore));
if ($source && in_array($source->id(), $srcIdsBefore)) {
  $etm->getStorage('property_ss_sources')->resetCache([$source->id()]);
  $after = $etm->getStorage('property_ss_sources')->load($source->id());
  $restored = TRUE;
  foreach ($snapshot as $f => $was) {
    $now = $after->get($f)->isEmpty() ? NULL : $after->get($f)->value;
    if ((string) $now !== (string) $was) { $restored = FALSE; }
  }
  $check('the existing source record is byte-for-byte as it was', $restored);
}
$check('no test records left behind', $leftSrc === 0 && $leftCtl === 0, "sources=$leftSrc controllers=$leftCtl");
$restored = TRUE; $detail = '';
foreach ($devSnapshot as $did => $fields) {
  $d = $etm->getStorage('property_backflow_device')->loadUnchanged($did);
  foreach ($fields as $f => $val) {
    if ($d->get($f)->getValue() !== $val) { $restored = FALSE; $detail = "device $did .$f"; }
  }
}
$check('pre-existing backflow devices restored byte-for-byte', $restored,
  $devSnapshot ? (count($devSnapshot) . ' device(s)' . ($detail ? " — DRIFTED: $detail" : '')) : 'none on this property');

printf("\n%d passed, %d failed\n", $pass, $fail);
