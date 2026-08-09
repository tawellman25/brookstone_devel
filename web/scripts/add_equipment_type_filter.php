<?php

/**
 * Add an exposed "Equipment Type" filter (field_equipment_type) to the
 * `equipment` view. page_1 inherits filters from the default display, so it's
 * added to default (→ shows on page_1). Cloned from the view's own working
 * exposed Status filter (same taxonomy_index_tid handler) so the dropdown is
 * configured correctly. Idempotent; run per env.
 *
 *   drush php:script web/scripts/add_equipment_type_filter.php
 */

use Drupal\views\Entity\View;

$v = View::load('equipment');
if (!$v) {
  print "ERROR: equipment view not found\n";
  return;
}
$display = $v->get('display');
$do = &$display['default']['display_options'];

if (!isset($do['filters']['field_status_target_id'])) {
  print "ERROR: expected the Status filter to clone from — abort.\n";
  return;
}

// Clone the working exposed Status filter, then retarget to Equipment Type.
// (Idempotent: re-running re-asserts the desired single-select dropdown config.)
$new = $do['filters']['field_status_target_id'];
$new['id'] = 'field_equipment_type_target_id';
$new['table'] = 'equipment__field_equipment_type';
$new['field'] = 'field_equipment_type_target_id';
$new['vid'] = 'equipment_types';
$new['expose']['operator_id'] = 'field_equipment_type_target_id_op';
$new['expose']['operator'] = 'field_equipment_type_target_id_op';
$new['expose']['label'] = 'Equipment Type';
$new['expose']['identifier'] = 'field_equipment_type_target_id';
// Single-select dropdown (one type at a time), not a multi-select listbox.
$new['type'] = 'select';
$new['expose']['multiple'] = FALSE;

$do['filters']['field_equipment_type_target_id'] = $new;

// Rename the bundle "Type" filter label to "Bundle".
if (isset($do['filters']['type'])) {
  $do['filters']['type']['expose']['label'] = 'Bundle';
}

// Reorder exposed filters: Bundle, Status, Equipment Type, Title, (then rest).
$order = ['type', 'field_status_target_id', 'field_equipment_type_target_id', 'title'];
$reordered = [];
foreach ($order as $k) {
  if (isset($do['filters'][$k])) {
    $reordered[$k] = $do['filters'][$k];
  }
}
foreach ($do['filters'] as $k => $f) {
  if (!isset($reordered[$k])) {
    $reordered[$k] = $f;
  }
}
$do['filters'] = $reordered;

$v->set('display', $display);
$v->save();

print "Equipment Type = single dropdown; 'Type' relabeled 'Bundle'; order = Bundle, Status, Equipment Type, Title.\n";
