<?php

/**
 * @file
 * Test the "Water Source Type" exposed filter on the sprinkler WO management
 * view. Read-only: runs the view, writes nothing.
 *
 * Counts are checked, never config. A Views filter can look perfect in config and
 * add no WHERE clause at all — core's entity_reference filter was tried here
 * first and did exactly that, rendering a correct dropdown while every selection
 * returned the unfiltered result.
 */

use Drupal\views\Views;

\Drupal::service('account_switcher')->switchTo(\Drupal\user\Entity\User::load(1));
$pass = 0; $fail = 0;
$check = function (string $l, bool $ok, string $d = '') use (&$pass, &$fail) {
  printf("%s %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  — $d" : '');
  $ok ? $pass++ : $fail++;
};

$types = [];
foreach (\Drupal::entityTypeManager()->getStorage('sprinkler_system_types')->loadMultiple() as $t) {
  $types[(int) $t->id()] = $t->label();
}
if (!$types) { print "No sprinkler_system_types records — cannot test.\n"; return; }

$run = function (array $ids) {
  $v = Views::getView('admin_sprinkler_start_up_management');
  $v->setDisplay('page_1');
  if ($ids) { $v->setExposedInput(['water_source_type' => array_combine($ids, $ids)]); }
  $v->setItemsPerPage(0);
  $v->preExecute();
  $v->execute();
  $n = count($v->result);
  $sql = (string) $v->build_info['query'];
  $v->destroy();
  return [$n, $sql];
};

[$base, $baseSql] = $run([]);
printf("\nbaseline (no selection): %d rows\n", $base);
$check('no selection leaves the query unconstrained',
  !str_contains($baseSql, 'field_system_type_target_id IN'));

$each = [];
foreach ($types as $id => $label) {
  [$n, $sql] = $run([$id]);
  $each[$id] = $n;
  $check(sprintf('%-22s filters', $label),
    str_contains($sql, 'field_system_type_target_id') && $n > 0 && $n < $base,
    "rows=$n of $base");
}

// The decisive one: a two-value selection must be the exact sum of its parts.
// An inert filter returns the baseline for every combination instead.
$ids = array_keys($types);
$small = array_slice($ids, -2);
[$pair] = $run($small);
$expect = $each[$small[0]] + $each[$small[1]];
$check('a two-type selection is the exact sum of both (real OR, not a no-op)',
  $pair === $expect, "pair=$pair expected=$expect");

[$all] = $run($ids);
$check('all types together < baseline but > any one type',
  $all < $base && $all > max($each), "all=$all baseline=$base largest single=" . max($each));
$check('every type is a strict subset', max($each) < $base, 'largest single=' . max($each) . " baseline=$base");

// The filter the office added, which could not work, must be gone.
$f = \Drupal::entityTypeManager()->getStorage('view')
  ->load('admin_sprinkler_start_up_management')
  ->get('display')['default']['display_options']['filters'];
$check('the property_ss_sources bundle filter is gone', !isset($f['type_1']));
$check('the filter is exposed as a multi-select',
  !empty($f['field_system_type_target_id']['exposed'])
  && !empty($f['field_system_type_target_id']['expose']['multiple']));
$check('it uses the custom InOperator plugin, not numeric',
  ($f['field_system_type_target_id']['plugin_id'] ?? '') === 'properties_sprinkler_system_type');

// Every type must be offered in the dropdown, sourced from the entity.
$v = Views::getView('admin_sprinkler_start_up_management');
$v->setDisplay('page_1');
$v->initHandlers();
$opts = $v->filter['field_system_type_target_id']->getValueOptions();
$v->destroy();
$check('the dropdown offers every system type',
  count($opts) === count($types) && !array_diff(array_map('strval', $types), array_map('strval', $opts)),
  implode(' / ', $opts));

printf("\n%d passed, %d failed\n", $pass, $fail);
