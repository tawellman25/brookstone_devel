<?php

/**
 * @file
 * Reversible test of the water purveyor entity and its resolver, built around
 * the real case: Orchard City serves three towns, and part of Cedaredge is on
 * Orchard City water. Everything created here is deleted before exit.
 */

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$etm = \Drupal::entityTypeManager();
$pass = 0; $fail = 0; $made = [];
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

// Real towns.
$cities = [];
foreach (['Austin', 'Eckert', 'Cory', 'Cedaredge'] as $name) {
  $found = $etm->getStorage('city')->loadByProperties(['field_city_name' => $name]);
  if ($found) { $cities[$name] = reset($found); }
}
printf("towns found: %s\n\n", implode(', ', array_keys($cities)));
$check('the towns exist to reference', count($cities) >= 2);

// A purveyor serving three towns — the Orchard City case.
// Orchard City Domestic Water serves the three towns whose names actually
// appear on addresses — Orchard City itself has no properties on it, because it
// is the municipality rather than the mailing town.
$serves = array_values(array_filter([
  $cities['Austin'] ?? NULL, $cities['Eckert'] ?? NULL, $cities['Cory'] ?? NULL,
]));
$oc = $etm->getStorage('water_purveyor')->create([
  'type' => 'purveyor',
  'title' => 'TEST Orchard City Domestic Water',
  'field_purveyor_cities' => array_map(fn($c) => ['target_id' => $c->id()], $serves),
  'field_purveyor_submit_method' => 'email',
  'field_purveyor_email' => 'reports@example-oc.test',
  'field_purveyor_contact' => 'Test Clerk',
]);
$oc->save();
$made[] = $oc;
$check('a purveyor saves', (bool) $oc->id(), 'id ' . $oc->id());
$check('it can serve several towns at once', count($oc->get('field_purveyor_cities')) === count($serves),
  count($oc->get('field_purveyor_cities')) . ' towns');
$check('it stores where reports go', (string) $oc->get('field_purveyor_email')->value === 'reports@example-oc.test');

// A property in one of those towns, with nothing set on it.
$pid = (int) \Drupal::database()->query("
  SELECT z.entity_id FROM {properties__field_zipcode_reference} z
  JOIN {zipcodes__field_city} zc ON zc.entity_id = z.field_zipcode_reference_target_id AND zc.deleted = 0
  WHERE z.deleted = 0 AND zc.field_city_target_id = :c LIMIT 1", [':c' => $serves[0]->id()])->fetchField();
if ($pid) {
  $prop = $etm->getStorage('properties')->load($pid);
  $had = $prop->get('field_water_purveyor')->target_id;
  $r = properties_water_purveyor_for($prop);
  $check('a property with none set falls back to its town',
    $r['purveyor'] && (int) $r['purveyor']->id() === (int) $oc->id() && $r['source'] === 'city',
    $r['purveyor'] ? $r['purveyor']->label() . ' (source: ' . $r['source'] . ')' : 'nothing');

  // Now the Cedaredge case: the property says otherwise, and must win.
  $other = $etm->getStorage('water_purveyor')->create([
    'type' => 'purveyor', 'title' => 'TEST Town of Cedaredge Water',
    'field_purveyor_cities' => $cities['Cedaredge'] ?? NULL ? [['target_id' => $cities['Cedaredge']->id()]] : [],
    'field_purveyor_submit_method' => 'mail',
  ]);
  $other->save();
  $made[] = $other;
  $prop->set('field_water_purveyor', $other->id());
  $prop->save();
  $r2 = properties_water_purveyor_for($prop);
  $check('the property\'s own purveyor beats the town', 
    $r2['purveyor'] && (int) $r2['purveyor']->id() === (int) $other->id() && $r2['source'] === 'property',
    $r2['purveyor'] ? $r2['purveyor']->label() . ' (source: ' . $r2['source'] . ')' : 'nothing');

  // Two purveyors serving one town: refuse to guess.
  $prop->set('field_water_purveyor', NULL);
  $prop->save();
  $oc->get('field_purveyor_cities')->appendItem(['target_id' => $cities['Cedaredge']->id() ?? $serves[0]->id()]);
  $oc->save();
  $second = $etm->getStorage('water_purveyor')->create([
    'type' => 'purveyor', 'title' => 'TEST Second Provider',
    'field_purveyor_cities' => [['target_id' => $serves[0]->id()]],
  ]);
  $second->save();
  $made[] = $second;
  $r3 = properties_water_purveyor_for($prop);
  $check('two purveyors in one town: it refuses to guess',
    $r3['purveyor'] === NULL && $r3['source'] === 'none',
    'got ' . ($r3['purveyor'] ? $r3['purveyor']->label() : 'nothing') . ' / ' . $r3['source']);

  // Restore the property.
  $prop->set('field_water_purveyor', $had);
  $prop->save();
  $check('the test property is back as it was',
    (string) $etm->getStorage('properties')->load($pid)->get('field_water_purveyor')->target_id === (string) $had);
}
else {
  print "NOTE no property found in those towns; the fallback was not exercised\n";
}

// administrator is flagged is_admin and therefore holds every permission by
// definition; checking it proves nothing. These are the roles that matter.
$check('no ordinary role can delete a purveyor', !array_filter(
  ['supervisor', 'administration', 'site_assistant', 'site_admin', 'teammates'],
  fn($r) => \Drupal\user\Entity\Role::load($r)?->hasPermission('delete any water_purveyor entities')));

foreach ($made as $e) { $e->delete(); }
$left = count(array_filter($made, fn($e) => (bool) $etm->getStorage('water_purveyor')->load($e->id())));
$check('all test purveyors removed', $left === 0, "remaining=$left");

printf("\n%d passed, %d failed\n", $pass, $fail);
