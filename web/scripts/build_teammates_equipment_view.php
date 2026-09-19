<?php

/**
 * /teammates/equipment — internal (staff-only) equipment landing.
 *
 * Header = the crew-facing "what this section is" copy; body = a View list of the
 * equipment_types terms (same list as the public /about-us/our-equipment page),
 * each linked with its description. Access restricted to internal roles.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_teammates_equipment_view.php
 */

use Drupal\views\Entity\View;

$VIEW_ID = 'teammates_equipment';
$PATH = 'teammates/equipment';
$VID = 'equipment_types';
$TITLE = 'Equipment';
$ROLES = ['teammates', 'supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator'];

$HEADER = <<<'HTML'
<p><strong>What this section is.</strong> Every machine we run has a page here. The public side explains to the customer what the machine does on their property. This side is for us.</p>

<p><strong>What is on each machine's page.</strong> What we use it for, what it is not for, and links to the SOPs for operating, inspecting and servicing it. If you are getting on a machine you have not run in a while, this is the fastest way to the SOP — faster than asking someone who is on a different property.</p>

<p><strong>If it is not on the page, it is not the procedure.</strong> When you find yourself doing something on a machine that the SOP does not cover, or doing it differently because the SOP is out of date, say so and it gets fixed here. A procedure that lives in one person's head is a procedure that leaves with them.</p>

<p><strong>The public side is readable by customers, and some of them read it.</strong> If somebody asks you about a machine while you are on their property, they may already know what it is and what it does. What the website tells them is plain and does not overstate anything, so there is nothing to walk back — answer them straight.</p>
HTML;

if (View::load($VIEW_ID)) {
  View::load($VIEW_ID)->delete();
}

$default_options = [
  'title' => $TITLE,
  'access' => ['type' => 'role', 'options' => ['role' => array_combine($ROLES, $ROLES)]],
  'cache' => ['type' => 'tag'],
  'query' => ['type' => 'views_query'],
  'exposed_form' => ['type' => 'basic'],
  'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
  'style' => ['type' => 'html_list'],
  'row' => ['type' => 'fields'],
  'fields' => [
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'name',
      'plugin_id' => 'taxonomy_term_name', 'label' => '', 'exclude' => FALSE,
      'element_label_colon' => FALSE, 'click_sort_column' => 'value', 'type' => 'string',
      'settings' => ['link_to_entity' => TRUE], 'group_column' => 'value', 'group_rows' => TRUE,
    ],
    'description__value' => [
      'id' => 'description__value', 'table' => 'taxonomy_term_field_data', 'field' => 'description__value',
      'relationship' => 'none', 'group_type' => 'group', 'entity_type' => 'taxonomy_term',
      'entity_field' => 'description', 'plugin_id' => 'field', 'label' => '', 'type' => 'text_default', 'settings' => [],
    ],
  ],
  'filters' => [
    'vid' => [
      'id' => 'vid', 'table' => 'taxonomy_term_field_data', 'field' => 'vid', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'vid', 'plugin_id' => 'bundle',
      'operator' => 'in', 'value' => [$VID => $VID], 'group' => 1,
    ],
    'status' => [
      'id' => 'status', 'table' => 'taxonomy_term_field_data', 'field' => 'status', 'relationship' => 'none',
      'group_type' => 'group', 'entity_type' => 'taxonomy_term', 'entity_field' => 'status', 'plugin_id' => 'boolean',
      'operator' => '=', 'value' => '1', 'group' => 1,
    ],
  ],
  'sorts' => [
    'name' => [
      'id' => 'name', 'table' => 'taxonomy_term_field_data', 'field' => 'name', 'plugin_id' => 'standard',
      'entity_type' => 'taxonomy_term', 'entity_field' => 'name', 'order' => 'ASC',
    ],
  ],
  'header' => [
    'area' => [
      'id' => 'area', 'table' => 'views', 'field' => 'area', 'plugin_id' => 'text',
      'content' => ['value' => $HEADER, 'format' => 'full_html'],
    ],
  ],
  'empty' => [], 'relationships' => [], 'arguments' => [], 'display_extenders' => [],
];

View::create([
  'id' => $VIEW_ID, 'label' => 'Teammates — Equipment', 'module' => 'views',
  'base_table' => 'taxonomy_term_field_data', 'base_field' => 'tid',
  'display' => [
    'default' => ['id' => 'default', 'display_title' => 'Default', 'display_plugin' => 'default', 'position' => 0, 'display_options' => $default_options],
    'page_1' => ['id' => 'page_1', 'display_title' => 'Page', 'display_plugin' => 'page', 'position' => 1, 'display_options' => ['path' => $PATH, 'display_extenders' => []]],
  ],
])->save();

$out = "view $VIEW_ID created at /$PATH (roles: " . implode(',', $ROLES) . ")\n";

// Menu link "Equipment" under the "Office" top link in the teammate menu.
$mlcStorage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$office = $mlcStorage->loadByProperties(['menu_name' => 'teammate-navigation', 'title' => 'Office']);
$parent = $office ? 'menu_link_content:' . reset($office)->uuid() : '';
$existing = $mlcStorage->loadByProperties(['menu_name' => 'teammate-navigation', 'title' => 'Equipment']);
if ($existing) {
  $link = reset($existing);
  $link->set('link', ['uri' => 'internal:/' . $PATH])->set('parent', $parent)->set('weight', 0)->save();
  $out .= "menu link 'Equipment' updated (parent Office)\n";
}
else {
  $mlcStorage->create([
    'title' => 'Equipment',
    'menu_name' => 'teammate-navigation',
    'link' => ['uri' => 'internal:/' . $PATH],
    'parent' => $parent,
    'weight' => 0,
    'expanded' => FALSE,
  ])->save();
  $out .= "menu link 'Equipment' created under Office\n";
}

print $out . "DONE.\n";
