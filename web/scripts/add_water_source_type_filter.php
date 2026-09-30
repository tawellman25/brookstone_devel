<?php

/**
 * @file
 * Add a working "Water Source Type" exposed filter to the sprinkler WO
 * management view, and remove the one that could not work.
 *
 * WHY the hand-added filter did not work: it filtered the BUNDLE of
 * property_ss_sources (domestic_source / dirty_water_source / well_water_source)
 * through property_sprinkler_system.field_water_sources. Mechanically correct,
 * but that entity holds only 41 records sitewide, attached to ~35 of 1,253
 * sprinkler systems — so picking a water source collapsed the list from 916 rows
 * to at most 55. It was hiding ~94% of the work, not filtering it.
 *
 * The office's "water source type" is field_system_type on
 * property_sprinkler_system — Domestic / Dirty / Duel / Well Water System —
 * which is populated on 1,253 of 1,253 systems and is the pump-fee charge driver
 * that winterizing and start-up bill from. The view ALREADY displays it as a
 * column on the relationship this filter uses, so the path is proven.
 *
 * The dropdown depends on properties_views_data_alter() swapping that column's
 * filter to the custom `properties_sprinkler_system_type` plugin; a plugin_id
 * written here would be ignored, because the handler comes from views DATA.
 * (Core's `entity_reference` filter was tried first and does not filter in this
 * install — see the plugin's docblock.)
 *
 * Idempotent. Run per environment: this view is drifted from config/sync, so it
 * is edited through the entity API, never imported.
 */

use Drupal\views\Views;

const VIEW_ID = 'admin_sprinkler_start_up_management';
const REL = 'reverse__property_sprinkler_system__field_property_system_info';
const FILTER_ID = 'field_system_type_target_id';
const DEAD_FILTER = 'type_1';

$view = \Drupal::entityTypeManager()->getStorage('view')->load(VIEW_ID);
if (!$view) {
  print "View " . VIEW_ID . " not found.\n";
  return;
}

$display = $view->get('display');
$options = &$display['default']['display_options'];
$filters = $options['filters'] ?? [];
$changed = FALSE;

// The relationship must already exist — it does, the System Type column uses it.
if (!isset($options['relationships'][REL])) {
  print "ABORT: relationship " . REL . " is missing; not guessing at a new one.\n";
  return;
}

// 1. Drop the water-source-bundle filter, if present.
if (isset($filters[DEAD_FILTER])) {
  $t = $filters[DEAD_FILTER]['table'] ?? '';
  if ($t !== 'property_ss_sources_field_data') {
    printf("ABORT: '%s' is a filter on %s, not the water-source bundle — leaving it alone.\n", DEAD_FILTER, $t);
    return;
  }
  unset($filters[DEAD_FILTER]);
  $changed = TRUE;
  print "removed the property_ss_sources bundle filter ('" . DEAD_FILTER . "')\n";
}

// 2. Add the System Type filter as a select of the four types.
$wanted = [
  'id' => FILTER_ID,
  'table' => 'property_sprinkler_system__field_system_type',
  'field' => FILTER_ID,
  'relationship' => REL,
  'plugin_id' => 'properties_sprinkler_system_type',
  'operator' => 'in',
  'value' => [],
  'exposed' => TRUE,
  'expose' => [
    'operator_id' => FILTER_ID . '_op',
    'label' => 'Water Source Type',
    'description' => 'Domestic, Dirty, Duel or Well — the system type the job bills from.',
    'use_operator' => FALSE,
    'operator' => FILTER_ID . '_op',
    'operator_limit_selection' => FALSE,
    'operator_list' => [],
    'identifier' => 'water_source_type',
    'required' => FALSE,
    'remember' => FALSE,
    'multiple' => TRUE,
    'remember_roles' => ['authenticated' => 'authenticated'],
    'reduce' => FALSE,
  ],
  'is_grouped' => FALSE,
  'group_info' => [],
];

if (($filters[FILTER_ID] ?? NULL) != $wanted) {
  $filters[FILTER_ID] = $wanted;
  $changed = TRUE;
  print "added the Water Source Type filter (field_system_type, select list)\n";
}
else {
  print "Water Source Type filter already correct\n";
}

// 3. Order the exposed form the way the columns read: after Complexity.
$order = [];
foreach ($filters as $id => $f) {
  if ($id === FILTER_ID) { continue; }
  $order[$id] = $f;
  if ($id === 'field_complexity_level_target_id') { $order[FILTER_ID] = $filters[FILTER_ID]; }
}
if (!isset($order[FILTER_ID])) { $order[FILTER_ID] = $filters[FILTER_ID]; }
if (array_keys($order) !== array_keys($filters)) { $changed = TRUE; }
$filters = $order;

if (!$changed) {
  print "Nothing to change.\n";
  return;
}

$options['filters'] = $filters;
$view->set('display', $display);
// Save through the entity API so routes + caches rebuild (a raw config write
// leaves a view's routes stale — see Governance/drupal_bos_gotchas.md).
$view->save();
print "saved " . VIEW_ID . "\n";
