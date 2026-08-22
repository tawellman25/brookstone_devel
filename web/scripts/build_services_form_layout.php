<?php

/**
 * Idempotent: tidy the services term EDIT form after the description split.
 *
 *  - Removes the core taxonomy `description` base field from the form (its
 *    public content has been consolidated into field_public_description).
 *  - Puts field_public_description in the "Public View" tab (drops core
 *    `description` from that tab's children).
 *  - Adds a "Teammate View" tab holding the Crew / Training Description
 *    (field_description), as a sibling tab under the Office Tabs group.
 *
 *   drush php:script web/scripts/build_services_form_layout.php
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;

$fd = EntityFormDisplay::load('taxonomy_term.services.default');
if (!$fd) {
  echo "no services term form display\n";
  return;
}
$out = [];

// 1. Retire the core base `description` field from the form.
if ($fd->getComponent('description')) {
  $fd->removeComponent('description');
  $out[] = 'removed core `description` from the form';
}

$groups = $fd->getThirdPartySettings('field_group');

// 2. Public View tab: drop `description`, ensure field_public_description present.
if (isset($groups['group_public_view'])) {
  $children = array_values(array_diff($groups['group_public_view']['children'] ?? [], ['description', 'field_description']));
  if (!in_array('field_public_description', $children, TRUE)) {
    // Put the public description near the top of the tab (after subtitle).
    $children = array_merge(['field_subtitle', 'field_public_description'], array_values(array_diff($children, ['field_subtitle'])));
  }
  $groups['group_public_view']['children'] = array_values(array_unique($children));
  $out[] = 'public tab children: ' . implode(', ', $groups['group_public_view']['children']);
}

// 3. Teammate View tab holding the training body — sibling of the public tab.
$groups['group_teammate_view'] = [
  'children' => ['field_description'],
  'label' => 'Teammate View',
  'parent_name' => 'group_office_tabs',
  'region' => 'content',
  'weight' => 6,
  'format_type' => 'tab',
  'format_settings' => [
    'classes' => 'group-teammate-view field-group-fieldset',
    'show_empty_fields' => TRUE,
    'id' => '',
    'label_as_html' => FALSE,
    'formatter' => 'closed',
    'description' => 'How we do it — crew training content. Shown to teammates/office on the service page.',
    'required_fields' => TRUE,
  ],
];
// Register the new tab under the Office Tabs container.
if (isset($groups['group_office_tabs'])) {
  $tabs = $groups['group_office_tabs']['children'] ?? [];
  if (!in_array('group_teammate_view', $tabs, TRUE)) {
    $tabs[] = 'group_teammate_view';
    $groups['group_office_tabs']['children'] = array_values($tabs);
  }
}
$out[] = 'added "Teammate View" tab (field_description)';

$fd->setThirdPartySetting('field_group', 'group_public_view', $groups['group_public_view']);
$fd->setThirdPartySetting('field_group', 'group_teammate_view', $groups['group_teammate_view']);
if (isset($groups['group_office_tabs'])) {
  $fd->setThirdPartySetting('field_group', 'group_office_tabs', $groups['group_office_tabs']);
}

// 4. Make sure both bodies have a widget + sensible weights.
// field_public_description storage is text_long (shared) → plain textarea widget.
$fd->setComponent('field_public_description', [
  'type' => 'text_textarea',
  'weight' => 2,
  'region' => 'content',
  'settings' => ['rows' => 6, 'placeholder' => ''],
  'third_party_settings' => [],
]);
$fd->setComponent('field_description', [
  'type' => 'text_textarea_with_summary',
  'weight' => 1,
  'region' => 'content',
  'settings' => ['rows' => 8, 'summary_rows' => 3, 'placeholder' => '', 'show_summary' => TRUE],
  'third_party_settings' => [],
]);

$fd->save();

echo "== build_services_form_layout ==\n";
foreach ($out as $line) {
  echo "  - $line\n";
}
