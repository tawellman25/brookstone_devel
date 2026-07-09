<?php

/**
 * Convert the wo_time_clock_entries view (the per-teammate time-entry breakdown
 * embedded on the WO page's Hours group) from the views_aggregator table style
 * to an Unformatted list, so each entry renders as a card via the row template
 * views-view-fields--wo-time-clock-entries.html.twig (wo_clock module). The
 * per-teammate subtotal — previously the aggregator SUM row — is re-added by
 * wo_clock_preprocess_views_view() as a footer.
 *
 * Idempotent. Run: drush php:script web/scripts/wo_time_clock_entries_to_cards.php
 */

use Drupal\views\Entity\View;

$view = View::load('wo_time_clock_entries');
if (!$view) {
  print "wo_time_clock_entries view not found\n";
  return;
}

$display = $view->get('display');
$display['default']['display_options']['style'] = [
  'type' => 'default',
  'options' => [
    'grouping' => [],
    'row_class' => '',
    'default_row_class' => TRUE,
    'uses_fields' => FALSE,
  ],
];
$display['default']['display_options']['row'] = [
  'type' => 'fields',
  'options' => [
    'default_field_elements' => TRUE,
    'inline' => [],
    'separator' => '',
    'hide_empty' => FALSE,
  ],
];

$view->set('display', $display);
$view->save();

print "wo_time_clock_entries converted: style=unformatted (card rows)\n";
