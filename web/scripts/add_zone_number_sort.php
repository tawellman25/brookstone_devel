<?php

/**
 * @file
 * Sort the work-order zone list by zone number instead of entry order.
 *
 * `views.view.property_ss_zones` has NO sorts at all, so rows come back in
 * whatever order storage hands them over — effectively entity id, i.e. the
 * order someone typed them in. Todd reported it as "sorting by date entered",
 * which is exactly what no sort looks like.
 *
 * Sorted by CLOCK NUMBER then CLOCK ZONE NUMBER, in that order, because the
 * zone titles already use that convention ("1.2 - East Front Yard" = clock 1,
 * zone 2) and a property can have more than one controller — zone 1 on clock 2
 * is a different valve from zone 1 on clock 1. Sorting on the zone number alone
 * would interleave two controllers' zones.
 *
 * ⚠ The sort keys are `field_clock_number_VALUE`, not `field_clock_number`.
 * Views data exposes a sort handler only on the _value key; the bare field name
 * is present but carries NO sort handler, and Views accepts such a sort into
 * config and then silently ignores it — the house trap, verified against
 * views.views_data before writing rather than assumed.
 *
 * Sorts go on the DEFAULT display; entity_view_1 inherits them (it has no
 * sorts of its own, and writing to it directly would break that inheritance).
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/add_zone_number_sort.php
 */

const VIEW_ID = 'property_ss_zones';
const SORTS = [
  'field_clock_number_value' => 'field_clock_number',
  'field_clock_zone_number_value' => 'field_clock_zone_number',
];

$apply = getenv('BOS_APPLY') === '1';
$viewsData = \Drupal::service('views.views_data');

// Guard: a sort Views cannot handle is accepted and then ignored.
foreach (SORTS as $key => $field) {
  $table = 'property_ss_zones__' . $field;
  $d = $viewsData->get($table);
  if (!$d || !isset($d[$key]['sort'])) {
    printf("ABORT: %s.%s has no sort handler in views data — Views would ignore it.\n", $table, $key);
    return;
  }
  printf("views data OK: %-46s sort=%s\n", $table . '.' . $key, $d[$key]['sort']['id'] ?? 'yes');
}

$config = \Drupal::configFactory()->getEditable('views.view.' . VIEW_ID);
if ($config->isNew()) {
  printf("ABORT: view %s does not exist here.\n", VIEW_ID);
  return;
}

$existing = $config->get('display.default.display_options.sorts') ?: [];
if ($existing) {
  printf("\nThe default display already has sorts: %s\n", implode(', ', array_keys($existing)));
  print "Left untouched — this script does not reorder someone else's sorting.\n";
  return;
}

$sorts = [];
$weight = 0;
foreach (SORTS as $key => $field) {
  $sorts[$key] = [
    'id' => $key,
    'table' => 'property_ss_zones__' . $field,
    'field' => $key,
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'plugin_id' => 'standard',
    'order' => 'ASC',
    'expose' => ['label' => ''],
    'exposed' => FALSE,
    'entity_type' => 'property_ss_zones',
    'entity_field' => $field,
  ];
  $weight++;
}

printf("\nWould add %d sort(s) to %s:default — %s (both ASC)\n",
  count($sorts), VIEW_ID, implode(' then ', array_keys($sorts)));

if (!$apply) {
  print "DRY RUN — re-run with BOS_APPLY=1 to write.\n";
  return;
}

$config->set('display.default.display_options.sorts', $sorts)->save();

// Prove it from a fresh read, and from an actual query result.
$after = array_keys(\Drupal::config('views.view.' . VIEW_ID)->get('display.default.display_options.sorts') ?: []);
printf("Saved. Sorts now: %s\n", implode(', ', $after));

$view = \Drupal\views\Views::getView(VIEW_ID);
if ($view) {
  $view->setDisplay('entity_view_1');
  $view->execute();
  print "\nentity_view_1 now returns (clock.zone — title):\n";
  foreach (array_slice($view->result, 0, 12) as $row) {
    $e = $row->_entity ?? NULL;
    if (!$e) {
      continue;
    }
    printf("   %s.%s  %s\n",
      $e->get('field_clock_number')->value ?? '-',
      $e->get('field_clock_zone_number')->value ?? '-',
      mb_substr((string) $e->label(), 0, 40));
  }
}
