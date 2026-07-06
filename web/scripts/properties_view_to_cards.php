<?php

/**
 * Convert the `properties` admin view (/admin/properties) to the card layout:
 *   - style: HTML table -> Unformatted list (so each row is a card div)
 *   - fields: strip to just field_nickname (the row template renders the card
 *     from the property entity via properties_preprocess_views_view_fields();
 *     the removed Property ID / Aerial view / Map Point / Operations / VBO
 *     bulk form / Full Address columns are no longer shown).
 *
 * Idempotent. Run: drush php:script web/scripts/properties_view_to_cards.php
 */

use Drupal\views\Entity\View;

$view = View::load('properties');
if (!$view) {
  print "properties view not found\n";
  return;
}

$display = $view->get('display');
$do = &$display['default']['display_options'];

// Unformatted list style.
$do['style'] = [
  'type' => 'default',
  'options' => [
    'grouping' => [],
    'row_class' => '',
    'default_row_class' => TRUE,
    'uses_fields' => FALSE,
  ],
];

// Keep only field_nickname as the row anchor; the card is rendered from the
// entity in preprocess. (Removes VBO, Property ID, Full Address, Aerial view,
// Map Point, Operations, entity-link, title, Contract-id.)
if (isset($do['fields']['field_nickname'])) {
  $do['fields'] = ['field_nickname' => $do['fields']['field_nickname']];
}
else {
  print "WARNING: field_nickname not present — leaving fields untouched\n";
}

$view->set('display', $display);
$view->save();

print "properties view converted: style=unformatted, fields=" . implode(',', array_keys($do['fields'])) . "\n";
