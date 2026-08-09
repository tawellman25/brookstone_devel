<?php

/**
 * Build the `equipment_similar` view — "Similar Equipment" strip embedded at the
 * bottom of each equipment page (same field_equipment_type, excluding the one
 * you're on), showing first picture + title. The equipment_similar module
 * embeds it via hook_ENTITY_TYPE_view, passing [type_tid, current_equipment_id].
 *
 * Cloned from equipment_type_current_list (correct base_table + the
 * field_equipment_type contextual filter + linked title field). Idempotent.
 * Run: drush php:script web/scripts/build_equipment_similar_view.php
 */

use Drupal\views\Entity\View;

$SRC = 'equipment_type_current_list';
$src = View::load($SRC);
if (!$src) {
  print "ERROR: source view $SRC not found\n";
  return;
}
$orig = $src->toArray();
$srcDo = $orig['display']['default']['display_options'];

// Reuse the source's linked title field + the type contextual filter verbatim.
$titleField = $srcDo['fields']['title'];
$typeArg = $srcDo['arguments']['field_equipment_type_target_id'];
// Make sure the type arg shows nothing (not "all") when no value is provided.
$typeArg['default_action'] = 'not found';
$typeArg['title_enable'] = FALSE;

// First picture, thumbnail, linked to the equipment.
$pictureField = [
  'id' => 'field_pictures',
  'table' => 'equipment__field_pictures',
  'field' => 'field_pictures',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'equipment',
  'entity_field' => 'field_pictures',
  'plugin_id' => 'field',
  'label' => '',
  'exclude' => FALSE,
  'type' => 'image',
  'settings' => ['image_style' => 'thumbnail', 'image_link' => 'content'],
  'group_rows' => TRUE,
  'delta_limit' => 1,
  'delta_offset' => 0,
  'delta_reversed' => FALSE,
  'delta_first_last' => FALSE,
  'multi_type' => 'separator',
  'separator' => '',
];

// Exclude the equipment we're currently viewing (2nd argument, numeric + Exclude).
$idArg = [
  'id' => 'id',
  'table' => 'equipment_field_data',
  'field' => 'id',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'equipment',
  'entity_field' => 'id',
  'plugin_id' => 'numeric',
  'default_action' => 'ignore',
  'exception' => ['value' => 'all', 'title_enable' => FALSE, 'title' => 'All'],
  'title_enable' => FALSE,
  'default_argument_type' => 'fixed',
  'default_argument_options' => ['argument' => ''],
  'summary_options' => ['base_path' => '', 'count' => TRUE, 'items_per_page' => 25, 'override' => FALSE],
  'summary' => ['sort_order' => 'asc', 'number_of_records' => 0, 'format' => 'default_summary'],
  'specify_validation' => FALSE,
  'validate' => ['type' => 'none', 'fail' => 'not found'],
  'break_phrase' => FALSE,
  // The "Exclude" option — drop rows matching this id (the current equipment).
  'not' => TRUE,
];

if (View::load('equipment_similar')) {
  View::load('equipment_similar')->delete();
  print "removed existing equipment_similar (rebuild)\n";
}

$a = $orig;
unset($a['uuid']);
$a['id'] = 'equipment_similar';
$a['label'] = 'Similar Equipment (by type)';

// Keep only the default display; embed that.
$a['display'] = ['default' => $a['display']['default']];
$do = &$a['display']['default']['display_options'];
$do['title'] = '';
$do['fields'] = ['field_pictures' => $pictureField, 'title' => $titleField];
$do['arguments'] = ['field_equipment_type_target_id' => $typeArg, 'id' => $idArg];
$do['style'] = ['type' => 'default', 'options' => ['grouping' => [], 'row_class' => '', 'default_row_class' => TRUE]];
$do['row'] = ['type' => 'fields', 'options' => ['default_field_elements' => TRUE, 'inline' => [], 'separator' => '', 'hide_empty' => FALSE]];
$do['pager'] = ['type' => 'none', 'options' => ['offset' => 0]];
// No area handlers — the "Similar Equipment" heading is added by the module
// hook (only when there are siblings), which sidesteps the text-area plugin.
$do['header'] = [];
$do['footer'] = [];
$do['empty'] = [];
unset($do['menu'], $do['tab_options']);

View::create($a)->save();
print "created view equipment_similar (Similar Equipment strip)\n";
print "DONE.\n";
