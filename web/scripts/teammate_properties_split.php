<?php

/**
 * Split the crew /teammates/properties view:
 *   1. page_1 (list) -> Unformatted card list (slim crew card via the template).
 *   2. New page_map display at /teammates/properties/map showing the
 *      geofield_google_map (copied from block_1), added as a CHILD menu item
 *      under the "Properties" menu link (teammate-navigation).
 *   3. Disable the map block placements — the map now has its own page, so it
 *      no longer loads (all ~2,500 pins) on the fast list page.
 *
 * Idempotent. Run: drush php:script web/scripts/teammate_properties_split.php
 */

use Drupal\block\Entity\Block;
use Drupal\views\Entity\View;

$view = View::load('teammate_properties');
if (!$view) {
  print "teammate_properties view not found\n";
  return;
}

$display = $view->get('display');

// A child display only USES its display_options overrides when the matching
// 'defaults' flag is FALSE (otherwise it inherits the default display). Flip
// every option page_1 overrides.
foreach (['style', 'row', 'fields', 'pager', 'filters', 'filter_groups', 'exposed_form'] as $opt) {
  $display['page_1']['display_options']['defaults'][$opt] = FALSE;
}

// 1) List page -> card layout (override style + fields on page_1 only, so the
//    map display's own fields/style are untouched).
$display['page_1']['display_options']['style'] = [
  'type' => 'default',
  'options' => [
    'grouping' => [],
    'row_class' => '',
    'default_row_class' => TRUE,
    'uses_fields' => FALSE,
  ],
];
$display['page_1']['display_options']['row'] = [
  'type' => 'fields',
  'options' => [
    'default_field_elements' => TRUE,
    'inline' => [],
    'separator' => '',
    'hide_empty' => FALSE,
  ],
];
$display['page_1']['display_options']['fields'] = [
  'field_nickname' => $display['default']['display_options']['fields']['field_nickname'],
];

// Pager (50/page) so the crew list loads fast instead of all ~2,500 at once.
$display['page_1']['display_options']['pager'] = [
  'type' => 'full',
  'options' => [
    'offset' => 0,
    'items_per_page' => 50,
    'total_pages' => NULL,
    'id' => 0,
    'tags' => [
      'next' => 'Next ›',
      'previous' => '‹ Previous',
      'first' => '« First',
      'last' => 'Last »',
    ],
    'expose' => [
      'items_per_page' => FALSE,
      'items_per_page_label' => 'Items per page',
      'items_per_page_options' => '25, 50, 100',
      'items_per_page_options_all' => FALSE,
      'items_per_page_options_all_label' => '- All -',
      'offset' => FALSE,
      'offset_label' => 'Offset',
    ],
    'quantity' => 9,
  ],
];

// Exposed "Search properties" filter on the nickname.
$display['page_1']['display_options']['exposed_form'] = [
  'type' => 'basic',
  'options' => [
    'submit_button' => 'Search',
    'reset_button' => TRUE,
    'reset_button_label' => 'Clear',
    'exposed_sorts_label' => 'Sort by',
    'expose_sort_order' => TRUE,
    'sort_asc_label' => 'Asc',
    'sort_desc_label' => 'Desc',
  ],
];
$display['page_1']['display_options']['filters'] = [
  'field_nickname' => [
    'id' => 'field_nickname',
    'table' => 'properties__field_nickname',
    'field' => 'field_nickname_value',
    'relationship' => 'none',
    'group_type' => 'group',
    'admin_label' => '',
    'operator' => 'contains',
    'value' => '',
    'group' => 1,
    'exposed' => TRUE,
    'expose' => [
      'operator_id' => 'field_nickname_op',
      'label' => 'Search properties',
      'description' => '',
      'use_operator' => FALSE,
      'operator' => 'field_nickname_op',
      'operator_limit_selection' => FALSE,
      'operator_list' => [],
      'identifier' => 'search',
      'required' => FALSE,
      'remember' => FALSE,
      'multiple' => FALSE,
      'remember_roles' => ['authenticated' => 'authenticated'],
      'placeholder' => 'Property name…',
    ],
    'is_grouped' => FALSE,
    'group_info' => [
      'label' => '',
      'description' => '',
      'identifier' => '',
      'optional' => TRUE,
      'widget' => 'select',
      'multiple' => FALSE,
      'remember' => FALSE,
      'default_group' => 'All',
      'default_group_multiple' => [],
      'group_items' => [],
    ],
    'plugin_id' => 'string',
    'entity_type' => 'properties',
    'entity_field' => 'field_nickname',
  ],
];

// 2) Map page from block_1.
if (!isset($display['page_map'])) {
  $map = $display['block_1'];
  $map['id'] = 'page_map';
  $map['display_title'] = 'Property Map';
  $map['display_plugin'] = 'page';
  $map['position'] = 3;
  $do = &$map['display_options'];
  // Drop block-only keys.
  unset($do['block_description'], $do['block_category'], $do['block_hide_empty'], $do['allow']);
  $do['path'] = 'teammates/properties/map';
  $do['menu'] = [
    'type' => 'normal',
    'title' => 'Property Map',
    'description' => '',
    'expanded' => FALSE,
    'parent' => 'views_view:views.teammate_properties.page_1',
    'weight' => -46,
    'context' => '0',
    'menu_name' => 'teammate-navigation',
  ];
  unset($do);
  $display['page_map'] = $map;
  print "added page_map display at /teammates/properties/map (menu child of Properties)\n";
}
else {
  print "page_map display already present\n";
}

$view->set('display', $display);
$view->save();
print "page_1 -> card list; map moved to its own page\n";

// 3) Disable the now-redundant map block placements.
$block_ids = [
  'brookstone_admin_views_block__teammate_properties_block_1',
  'brookstone_olivero_views_block__teammate_properties_block_1',
  'olivero_sewards_views_block__teammate_properties_block_1',
  'olivero_views_block__teammate_properties_block_1',
];
foreach ($block_ids as $bid) {
  $b = Block::load($bid);
  if ($b && $b->status()) {
    $b->disable()->save();
    print "disabled map block: $bid\n";
  }
}
