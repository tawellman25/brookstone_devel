<?php

/**
 * @file
 * Reversible test: alt text is no longer required, and gets generated.
 * Everything created here is deleted before exit.
 */

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0; $made = [];
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

// Alt must be optional on every one of them.
$req = [];
foreach ([
  'property_sprinkler_system.system' => ['field_zone_map'],
  'property_ss_sources.domestic_source' => ['field_ss_backflow_photos', 'field_ss_shut_off_location_pic'],
  'property_ss_zones.zone' => ['field_ss_zone_photos'],
  'property_system_controller.controller' => ['field_controller_photos'],
  'property_sprinkler_pumps.pump' => ['field_pump_configuration_photos', 'field_pump_serial_number_pic'],
  'property_sprinkler_design.design' => ['field_sprinkler_design'],
] as $key => $fields) {
  [$t, $b] = explode('.', $key);
  foreach ($fields as $f) {
    $c = \Drupal\field\Entity\FieldConfig::loadByName($t, $b, $f);
    if ($c && $c->getSetting('alt_field_required')) { $req[] = "$key.$f"; }
  }
}
$check('no sprinkler photo field still requires alt text', !$req, $req ? implode(', ', $req) : 'all optional');

// A real property with a nickname, to name the photo after.
$pid = (int) \Drupal::database()->query("
  SELECT n.entity_id FROM {properties__field_nickname} n
  WHERE n.deleted = 0 AND n.field_nickname_value <> '' LIMIT 1")->fetchField();
$prop = $etm->getStorage('properties')->load($pid);
$nick = trim((string) $prop->get('field_nickname')->value);
printf("\nusing property %d \"%s\"\n\n", $pid, $nick);

// A file to attach. Reuse an existing managed file rather than writing one.
$fid = (int) \Drupal::database()->query("SELECT fid FROM {file_managed} WHERE filemime LIKE 'image/%' AND status = 1 LIMIT 1")->fetchField();
$check('found an existing image file to attach', $fid > 0, "fid=$fid");
if (!$fid) { return; }

// A sprinkler system linked to the property, with two zone-map photos and no alt.
$sys = $etm->getStorage('property_sprinkler_system')->create([
  'type' => 'system',
  'field_property' => $pid,
  'field_zone_map' => [
    ['target_id' => $fid, 'alt' => ''],
    ['target_id' => $fid, 'alt' => ''],
  ],
]);
$sys->save();
$made[] = $sys;
$etm->getStorage('property_sprinkler_system')->resetCache([$sys->id()]);
$sys = $etm->getStorage('property_sprinkler_system')->load($sys->id());
$alts = [];
foreach ($sys->get('field_zone_map') as $i) { $alts[] = (string) $i->alt; }
$check('saved with NO alt typed (would have been blocked before)', (bool) $sys->id());
$check('alt generated for photo 1', $alts[0] === "$nick - zone map 01", $alts[0] ?: '(empty)');
$check('alt generated for photo 2 and numbered', $alts[1] === "$nick - zone map 02", $alts[1] ?: '(empty)');

// Typed alt must be left alone.
$sys->set('field_zone_map', [
  ['target_id' => $fid, 'alt' => 'Main valve box behind the shed'],
  ['target_id' => $fid, 'alt' => ''],
]);
$sys->save();
$etm->getStorage('property_sprinkler_system')->resetCache([$sys->id()]);
$sys = $etm->getStorage('property_sprinkler_system')->load($sys->id());
$alts = [];
foreach ($sys->get('field_zone_map') as $i) { $alts[] = (string) $i->alt; }
$check('typed alt text is never overwritten', $alts[0] === 'Main valve box behind the shed', $alts[0]);
$check('the empty one beside it is still filled', $alts[1] === "$nick - zone map 02", $alts[1] ?: '(empty)');

// The deepest chain: pump -> source -> system -> property.
$src = $etm->getStorage('property_ss_sources')->create([
  'type' => 'domestic_source',
  'field_property_ss_system' => $sys->id(),
  'field_ss_shut_off_location_pic' => [['target_id' => $fid, 'alt' => '']],
]);
$src->save();
$made[] = $src;
$pump = $etm->getStorage('property_sprinkler_pumps')->create([
  'type' => 'pump',
  'field_property_ss_source' => $src->id(),
  'field_pump_configuration_photos' => [['target_id' => $fid, 'alt' => '']],
]);
$pump->save();
$made[] = $pump;
foreach ([[$src, 'field_ss_shut_off_location_pic', 'shut off'], [$pump, 'field_pump_configuration_photos', 'pump configuration']] as [$e, $f, $label]) {
  $st = $etm->getStorage($e->getEntityTypeId());
  $st->resetCache([$e->id()]);
  $fresh = $st->load($e->id());
  $alt = (string) $fresh->get($f)->first()->alt;
  $check(sprintf('%-26s resolves the property through its chain', $e->getEntityTypeId()),
    $alt === "$nick - $label 01", $alt ?: '(empty)');
}

// --- clean up -------------------------------------------------------------
foreach (array_reverse($made) as $e) { $e->delete(); }
$left = 0;
foreach ($made as $e) {
  if ($etm->getStorage($e->getEntityTypeId())->load($e->id())) { $left++; }
}
$check('all test records removed', $left === 0, "remaining=$left");

printf("\n%d passed, %d failed\n", $pass, $fail);
