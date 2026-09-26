<?php

declare(strict_types=1);

/**
 * Wire the backflow_uses landing view (land_backflow_uses,
 * /services/backflow-prevention/uses) to use the content fields the "ALL
 * FIELDS" spec maps to it:
 *   - card teaser  -> field_short_description  (was the core description field,
 *                     which is empty on every uses term)
 *   - card order   -> field_list_order ASC, then name ASC as a tiebreak
 *
 * Only the field + sort handlers are touched — the view's header/footer/title
 * chrome is the separate "Backflow Uses - Landing View Copy" deliverable and is
 * left alone. Edits via the View entity API (so routes/caches rebuild); the view
 * is drifted from sync, so this is NOT a cim. Idempotent; run per env.
 *
 *   drush php:script web/scripts/build_backflow_uses_landing.php
 */

$view = \Drupal::entityTypeManager()->getStorage('view')->load('land_backflow_uses');
if (!$view) {
  print "view land_backflow_uses not found.\n";
  return;
}

$display = $view->get('display');
$opts = &$display['default']['display_options'];

// Fields: term name (linked) + the short-description teaser. Drop the empty
// core description column.
$opts['fields'] = [
  'name' => [
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
    'element_label_colon' => FALSE,
    'click_sort_column' => 'value',
    'type' => 'string',
    'settings' => ['link_to_entity' => TRUE],
    'group_column' => 'value',
    'group_rows' => TRUE,
  ],
  'field_short_description' => [
    'id' => 'field_short_description',
    'table' => 'taxonomy_term__field_short_description',
    'field' => 'field_short_description',
    'relationship' => 'none',
    'group_type' => 'group',
    'entity_type' => 'taxonomy_term',
    'entity_field' => 'field_short_description',
    'plugin_id' => 'field',
    'label' => '',
    'type' => 'text_default',
    'settings' => [],
  ],
];

// Sort by list order, then name.
$opts['sorts'] = [
  'field_list_order_value' => [
    'id' => 'field_list_order_value',
    'table' => 'taxonomy_term__field_list_order',
    'field' => 'field_list_order_value',
    'relationship' => 'none',
    'group_type' => 'group',
    'entity_type' => 'taxonomy_term',
    'entity_field' => 'field_list_order',
    'plugin_id' => 'standard',
    'order' => 'ASC',
  ],
  'name' => [
    'id' => 'name',
    'table' => 'taxonomy_term_field_data',
    'field' => 'name',
    'relationship' => 'none',
    'group_type' => 'group',
    'entity_type' => 'taxonomy_term',
    'entity_field' => 'name',
    'plugin_id' => 'standard',
    'order' => 'ASC',
  ],
];

$view->set('display', $display);
$view->save();
print "land_backflow_uses: card = name + field_short_description, sorted by field_list_order then name.\n";
print "DONE.\n";
