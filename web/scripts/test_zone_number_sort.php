<?php

/**
 * @file
 * Prove the zone list sorts by zone number, not entry order.
 *
 * ⚠ WHY THIS CANNOT BE TESTED ON THE REAL DATA. All 8 zone records that exist
 * happen to have been ENTERED in zone order, so a correct sort and no sort at
 * all produce the identical result. Checking live rows would "pass" against a
 * view with its sorts deleted. So this creates zones deliberately OUT of order
 * — entered 5, 2, 10, 1 — and asserts they come back 1, 2, 5, 10.
 *
 * 10 is in there on purpose: it is the case a string sort gets wrong ("10"
 * before "2"), which is what sorting on the title would have done.
 *
 * Everything is created inside a transaction that is ALWAYS rolled back.
 * rollBack() is called explicitly — letting a Drupal Transaction fall out of
 * scope COMMITS it, which is how an earlier test in this project wrote its
 * throwaway data to a real record and then asserted it was absent.
 *
 * Run: drush php:script web/scripts/test_zone_number_sort.php
 */

$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

// --- the sorts are configured, and on the right keys -------------------------
$sorts = \Drupal::config('views.view.property_ss_zones')->get('display.default.display_options.sorts') ?: [];
$ok('the view has sorts at all', (bool) $sorts, implode(', ', array_keys($sorts)) ?: 'NONE');
$ok('  sorted on clock number first', array_key_first($sorts) === 'field_clock_number_value');
$ok('  then on zone number', isset($sorts['field_clock_zone_number_value']));
$ok('  both ascending',
  ($sorts['field_clock_number_value']['order'] ?? '') === 'ASC'
  && ($sorts['field_clock_zone_number_value']['order'] ?? '') === 'ASC');
// A sort keyed on the bare field name is accepted by Views and then ignored.
$ok('  keyed on the _value columns, which carry the sort handler',
  !isset($sorts['field_clock_number']) && !isset($sorts['field_clock_zone_number']));
$ok('  entity_view_1 inherits rather than overriding',
  \Drupal::config('views.view.property_ss_zones')->get('display.entity_view_1.display_options.sorts') === NULL);

// --- borrow a system to hang throwaway zones on ------------------------------
$sysId = $db->query('SELECT field_property_ss_system_target_id FROM {property_ss_zones__field_property_ss_system} WHERE deleted = 0 LIMIT 1')->fetchField();
$ok('found a sprinkler system to test against', (bool) $sysId, 'system ' . $sysId);
if (!$sysId) { printf("\n%d passed, %d failed\n", $pass, $fail); return; }

// BOS_TEST_READONLY=1 — the house pattern for running a write-y test on live.
// It creates nothing; instead it reads the ORDER BY out of the query Views
// actually builds, which proves the sort reached the SQL even though live's
// real rows happen to be in order already.
if (getenv('BOS_TEST_READONLY') === '1') {
  $view = \Drupal\views\Views::getView('property_ss_zones');
  $view->setDisplay('entity_view_1');
  $view->setArguments([$sysId]);
  $view->execute();
  $sql = preg_replace('/\s+/', ' ', (string) ($view->build_info['query'] ?? ''));
  $orderBy = stripos($sql, 'ORDER BY') !== FALSE ? substr($sql, stripos($sql, 'ORDER BY')) : '';
  $ok('READ-ONLY: the query has an ORDER BY', $orderBy !== '', mb_substr($orderBy, 0, 90));
  // ⚠ Match the ALIASES Views emits, which it TRUNCATES: the column comes out
  // as "..._field_clock_number_val", not "..._value". Asserting on _value
  // reported three failures against a perfectly correct ORDER BY.
  $clockAt = stripos($orderBy, 'field_clock_number_field_clock_number');
  // Truncated harder than the first one — Views cuts the alias mid-word:
  // "property_ss_zones__field_clock_zone_number_field_clock_zone_".
  $zoneAt = stripos($orderBy, 'field_clock_zone_number_field_clock_zone_');
  $ok('  it orders by clock number', $clockAt !== FALSE, $clockAt === FALSE ? 'not found' : "at $clockAt");
  $ok('  then by zone number', $zoneAt !== FALSE, $zoneAt === FALSE ? 'not found' : "at $zoneAt");
  $ok('  clock number comes first in the ORDER BY',
    $clockAt !== FALSE && $zoneAt !== FALSE && $clockAt < $zoneAt);
  $ok('  ascending', stripos($orderBy, 'DESC') === FALSE, mb_substr($orderBy, 0, 70));
  printf("\n%d passed, %d failed  (read-only: nothing was created)\n", $pass, $fail);
  return;
}

$before = count($db->query('SELECT id FROM {property_ss_zones_field_data}')->fetchAll());

$tx = $db->startTransaction();
$order = [];
$mine = [];
try {
  // Entered deliberately out of order. 10 catches a string sort.
  foreach ([5, 2, 10, 1] as $zoneNo) {
    $e = $etm->getStorage('property_ss_zones')->create([
      'type' => 'zone',
      'title' => sprintf('TEST 1.%d', $zoneNo),
      'field_property_ss_system' => $sysId,
      'field_clock_number' => 1,
      'field_clock_zone_number' => $zoneNo,
    ]);
    $e->save();
    // ⚠ Track the IDs, do NOT match on the title later: this bundle generates
    // its own label (real ones read "1.2 - East Front Yard"), so the 'TEST …'
    // title set above is overwritten on save and a prefix match finds nothing.
    $mine[(int) $e->id()] = $zoneNo;
  }

  $view = \Drupal\views\Views::getView('property_ss_zones');
  $view->setDisplay('entity_view_1');
  $view->setArguments([$sysId]);
  $view->execute();
  foreach ($view->result as $r) {
    $e = $r->_entity ?? NULL;
    if ($e && isset($mine[(int) $e->id()])) {
      $order[] = (int) $e->get('field_clock_zone_number')->value;
    }
  }
}
finally {
  $tx->rollBack();
  unset($tx);
}

$ok('the four test zones came back', count($order) === 4, implode(',', $order) ?: 'none');
$ok('ENTERED 5,2,10,1 -> RETURNED 1,2,5,10', $order === [1, 2, 5, 10], implode(',', $order));
$ok('  zone 10 sorts after zone 2, not before it (not a string sort)',
  count($order) === 4 && array_search(10, $order, TRUE) > array_search(2, $order, TRUE));
$ok('  and it is NOT entry order (which would have been 5,2,10,1)', $order !== [5, 2, 10, 1]);

$after = count($db->query('SELECT id FROM {property_ss_zones_field_data}')->fetchAll());
$ok('rolled back — no test zones left behind', $after === $before, "$before before, $after after");

printf("\n%d passed, %d failed\n", $pass, $fail);
