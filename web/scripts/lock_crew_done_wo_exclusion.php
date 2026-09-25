<?php

/**
 * Lock "done" work orders off the crew fertilizing map/list views.
 *
 * These views show WO pins on a Google map + a table. The "hide done work
 * orders" rule lived ONLY in the EXPOSED status filter's default value, so a
 * tech could clear it / pick "Any" / submit it empty and every done WO
 * (Complete/Invoiced/Paid/Canceled/Warrantied) flooded back onto the map and
 * would not drop off.
 *
 * This adds a second, NON-EXPOSED, locked status filter (taxonomy_index_tid,
 * operator "not" = is none of) on the default display that always excludes the
 * done statuses — regardless of what the exposed status filter is set to. The
 * exposed filter still lets staff narrow among the active statuses.
 *
 * Idempotent: skips a view that already has the locked filter. Edits ACTIVE
 * config directly (these views are drifted from config/sync — no cim).
 *
 * Run:  drush php:script web/scripts/lock_crew_done_wo_exclusion.php
 */

// WO statuses that mean "done" — never show these on a crew work list/map.
$DONE = [1097, 1281, 1504, 1098, 1283]; // Complete, Invoiced, Paid, Canceled, Warrantied.
$LOCK_KEY = 'field_status_exclude_done';

$views = [
  'teammate_lawn_fertilizer',
  'teammate_fertilize_trees_and_shrubs_wos',
];

foreach ($views as $viewId) {
  $cfg = \Drupal::configFactory()->getEditable('views.view.' . $viewId);
  if ($cfg->isNew()) {
    print "SKIP $viewId — view not found\n";
    continue;
  }
  $filters = $cfg->get('display.default.display_options.filters') ?? [];

  if (isset($filters[$LOCK_KEY])) {
    print "OK   $viewId — locked exclusion already present\n";
    continue;
  }
  if (!isset($filters['field_status_target_id'])) {
    print "SKIP $viewId — no field_status_target_id filter to model from\n";
    continue;
  }

  // Clone the existing status filter's structure so the plugin config is valid,
  // then lock it: not-exposed, operator "not", value = the done statuses.
  $locked = $filters['field_status_target_id'];
  $locked['id'] = $LOCK_KEY;
  $locked['admin_label'] = 'Exclude done work orders (locked)';
  $locked['operator'] = 'not';
  $locked['value'] = array_combine($DONE, $DONE);
  $locked['exposed'] = FALSE;
  $locked['is_grouped'] = FALSE;
  // Neutralise the exposed identifier so it can't collide with the exposed one.
  if (isset($locked['expose']['identifier'])) {
    $locked['expose']['identifier'] = $LOCK_KEY;
  }

  $filters[$LOCK_KEY] = $locked;
  $cfg->set('display.default.display_options.filters', $filters);
  $cfg->save();
  print "SET  $viewId — added locked '$LOCK_KEY' (not in " . implode(',', $DONE) . ")\n";
}

// Rebuild views data/cache so the change takes effect.
\Drupal::service('views.views_data')->clear();
\Drupal::service('cache.render')->deleteAll();
print "DONE.\n";
