<?php

/**
 * Build the services admin_view display to the BOS audience view-mode pattern
 * (see __BOS_AI/Governance/ui_patterns.md): identity on top, then collapsible
 * field_group "Details" sections mirroring the audiences — Public View,
 * Crew View, Office Admin.
 *
 * A STARTER layout — office can refine field membership in Manage Display.
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_services_admin_view.php
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;

$ID = 'taxonomy_term.services.admin_view';

// Shown fields: field => [type, label, weight, settings].
$show = [
  'name'                      => ['string', 'hidden', -5, []],
  'field_iconic_image'        => ['image', 'hidden', 0, ['image_style' => 'medium', 'image_link' => '']],
  'field_subtitle'            => ['string', 'hidden', 1, []],
  // Public View group.
  'field_service_public_desc' => ['text_default', 'hidden', 3, []],
  // Crew View group.
  'field_service_crew_desc'   => ['text_default', 'hidden', 5, []],
  'field_sop_code'            => ['string', 'inline', 6, []],
  // Office Admin group.
  'field_department'          => ['entity_reference_label', 'inline', 8, ['link' => FALSE]],
  'field_service_bundle'      => ['string', 'inline', 9, []],
  'field_work_order_service'  => ['boolean', 'inline', 10, []],
  'field_list_order'          => ['number_integer', 'inline', 11, []],
  'field_on_demand'           => ['boolean', 'inline', 12, []],
];

// Audience-mirroring Details groups.
$fs = ['classes' => '', 'show_empty_fields' => FALSE, 'id' => '', 'label_as_html' => FALSE, 'open' => TRUE, 'description' => ''];
$groups = [
  'group_public_view'  => ['label' => 'Public View',  'weight' => 2, 'children' => ['field_service_public_desc']],
  'group_crew_view'    => ['label' => 'Crew View',    'weight' => 4, 'children' => ['field_service_crew_desc', 'field_sop_code']],
  'group_office_admin' => ['label' => 'Office Admin', 'weight' => 7, 'children' => ['field_department', 'field_service_bundle', 'field_work_order_service', 'field_list_order', 'field_on_demand']],
];

$display = EntityViewDisplay::load($ID) ?: EntityViewDisplay::create([
  'targetEntityType' => 'taxonomy_term',
  'bundle' => 'services',
  'mode' => 'admin_view',
  'status' => TRUE,
]);

// Set shown components; hide everything else.
$efm = \Drupal::service('entity_field.manager');
$all = array_keys($efm->getFieldDefinitions('taxonomy_term', 'services'));
foreach ($show as $field => [$type, $label, $weight, $settings]) {
  $display->setComponent($field, ['type' => $type, 'label' => $label, 'weight' => $weight, 'region' => 'content', 'settings' => $settings]);
}
foreach (array_merge($all, array_keys($display->getComponents())) as $field) {
  if (!isset($show[$field])) {
    $display->removeComponent($field);
  }
}

// Field-group sections.
foreach ($groups as $gid => $g) {
  $display->setThirdPartySetting('field_group', $gid, [
    'children' => $g['children'],
    'label' => $g['label'],
    'parent_name' => '',
    'region' => 'content',
    'weight' => $g['weight'],
    'format_type' => 'details',
    'format_settings' => $fs,
  ]);
}

$display->save();
print "built $ID (groups: " . implode(', ', array_keys($groups)) . ")\nDONE.\n";
