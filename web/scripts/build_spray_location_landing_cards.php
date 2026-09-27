<?php

declare(strict_types=1);

/**
 * Render the Spraying Locations landing view as a card grid.
 *
 * /services/landscape-lawn-care/spraying/location (view land_spray_location)
 * listed its 20 children as a bulleted <ul> (Views style `html_list`) with the
 * core `description` field as the teaser — and `description` is now empty on
 * every term, so the listing was reduced to bare names.
 *
 * This switches it to the BOS child-listing card grid (same shape as
 * service_children / material_children) and feeds the cards
 * field_short_description, which is exactly what that field was written for: the
 * public one-line teaser. The three location text fields do NOT share a display —
 * field_public_description is the term page, field_teammate_description is the
 * crew tier, field_short_description is this landing teaser.
 *
 * Field/style definitions are copied from the land_backflow_uses landing (the
 * established precedent) so the structure is Views-canonical:
 *   - style `default` (= "Unformatted list") + row_class
 *   - display css_class for CSS scoping
 *   - name linked, wrapped in a title div
 *   - field_short_description as the teaser, rendered whole (the copy is authored
 *     as a one-liner, so trimming it mid-sentence would read worse)
 *
 * Only the `default` display is edited — page_1 overrides header/footer only and
 * inherits fields/style. Saved through the View ENTITY so postSave rebuilds
 * routes + caches. Idempotent.
 *
 *   drush php:script web/scripts/build_spray_location_landing_cards.php
 */

use Drupal\views\Entity\View;

$view = View::load('land_spray_location');
if (!$view) {
  print "ERROR: view land_spray_location not found.\n";
  return;
}

$display = $view->get('display');
$opts = &$display['default']['display_options'];

/* 1. Wrapper class for CSS scoping. */
$opts['css_class'] = 'spray-locations-landing';

/* 2. Unformatted list (plugin id is 'default') + per-row card class. */
$opts['style'] = [
  'type' => 'default',
  'options' => [
    'grouping' => [],
    'row_class' => 'spray-location-card',
    'default_row_class' => TRUE,
    'uses_fields' => FALSE,
  ],
];

/* 3. Fields: linked name in a title div + the short-description teaser.
      The legacy core-description field is dropped (empty on all 20 terms). */
$fields = $opts['fields'] ?? [];
unset($fields['description__value'], $fields['description']);

$fields['name'] = [
  'id' => 'name',
  'table' => 'taxonomy_term_field_data',
  'field' => 'name',
  'relationship' => 'none',
  'group_type' => 'group',
  'entity_type' => 'taxonomy_term',
  'entity_field' => 'name',
  'plugin_id' => 'taxonomy_term_name',
  'label' => '',
  'exclude' => FALSE,
  'element_type' => 'div',
  'element_class' => 'spray-location-card__title',
  'element_label_colon' => FALSE,
  'element_default_classes' => TRUE,
  'click_sort_column' => 'value',
  'type' => 'string',
  'settings' => ['link_to_entity' => TRUE],
  'group_column' => 'value',
  'group_rows' => TRUE,
];

$fields['field_short_description'] = [
  'id' => 'field_short_description',
  'table' => 'taxonomy_term__field_short_description',
  'field' => 'field_short_description',
  'relationship' => 'none',
  'group_type' => 'group',
  'entity_type' => 'taxonomy_term',
  'entity_field' => 'field_short_description',
  'plugin_id' => 'field',
  'label' => '',
  'exclude' => FALSE,
  'element_type' => 'div',
  'element_class' => 'spray-location-card__blurb',
  'element_label_colon' => FALSE,
  'element_default_classes' => TRUE,
  'type' => 'text_default',
  'settings' => [],
];

// Name first, then the teaser.
$opts['fields'] = ['name' => $fields['name'], 'field_short_description' => $fields['field_short_description']];

$view->set('display', $display);
$view->save();

print "  css_class : {$opts['css_class']}\n";
print "  style     : {$opts['style']['type']} (row_class={$opts['style']['options']['row_class']})\n";
print '  fields    : ' . implode(', ', array_keys($opts['fields'])) . "\n";
print "DONE.\n";
