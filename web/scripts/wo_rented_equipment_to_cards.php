<?php

/**
 * Switch the wo_rented_equipment view (WO Equipment section) from a table to an
 * unformatted list of card rows, so the wo_rental_equipment_ui row template
 * (views-view-fields--wo-rented-equipment) renders each entry as a card.
 * Idempotent; run per env.
 *
 *   drush php:script web/scripts/wo_rented_equipment_to_cards.php
 */

use Drupal\views\Entity\View;

$v = View::load('wo_rented_equipment');
if (!$v) {
  print "ERROR: wo_rented_equipment view not found\n";
  return;
}
$display = $v->get('display');
$changed = [];
foreach ($display as $id => &$disp) {
  $do = &$disp['display_options'];
  // Only touch displays that carry their own style (default always does).
  if ($id !== 'default' && !isset($do['style'])) {
    continue;
  }
  $do['style'] = ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE]];
  $do['row'] = ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]];
  $changed[] = $id;
}
unset($disp);

$v->set('display', $display);
$v->save();
print "wo_rented_equipment -> unformatted card rows on: " . implode(', ', $changed) . "\n";
