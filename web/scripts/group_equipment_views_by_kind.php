<?php

/**
 * Group the equipment landing views (teammates_equipment + land_our_equipment)
 * by KIND — field_equipment_bundle — so they render tidy sections (Heavy
 * Equipment, Small Engine, Vehicles, …) instead of one long alphabetical list.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/group_equipment_views_by_kind.php
 */

use Drupal\views\Entity\View;

$views = ['teammates_equipment', 'land_our_equipment'];
$out = [];

foreach ($views as $vid) {
  $view = View::load($vid);
  if (!$view) {
    $out[] = "SKIP $vid — not found";
    continue;
  }
  $display = $view->get('display');
  $opts = &$display['default']['display_options'];

  // 1) Add the bundle field (excluded from rows; used for the group header, label shown).
  $opts['fields']['field_equipment_bundle'] = [
    'id' => 'field_equipment_bundle', 'table' => 'taxonomy_term__field_equipment_bundle',
    'field' => 'field_equipment_bundle', 'relationship' => 'none', 'group_type' => 'group',
    'entity_type' => 'taxonomy_term', 'entity_field' => 'field_equipment_bundle', 'plugin_id' => 'field',
    'label' => '', 'exclude' => TRUE, 'type' => 'list_default', 'settings' => [],
  ];

  // 2) Sort by bundle first (so groups are contiguous), then name.
  $nameSort = $opts['sorts']['name'] ?? null;
  $opts['sorts'] = [
    'field_equipment_bundle_value' => [
      'id' => 'field_equipment_bundle_value', 'table' => 'taxonomy_term__field_equipment_bundle',
      'field' => 'field_equipment_bundle_value', 'relationship' => 'none', 'group_type' => 'group',
      'plugin_id' => 'standard', 'order' => 'ASC',
    ],
  ];
  if ($nameSort) {
    $opts['sorts']['name'] = $nameSort;
  }

  // 3) Group the style on the bundle field (rendered = shows the label).
  if (!isset($opts['style']['options'])) {
    $opts['style']['options'] = [];
  }
  $opts['style']['options']['grouping'] = [
    ['field' => 'field_equipment_bundle', 'rendered' => TRUE, 'rendered_strip' => FALSE],
  ];

  $view->set('display', $display)->save();
  $out[] = "$vid grouped by field_equipment_bundle";
}

print implode("\n", $out) . "\nDONE.\n";
