<?php

/**
 * Make "Rock Type" visible and easy to pick on the Rock material form.
 *
 * The field (field_rock_type → rock_types vocab, 8 terms) existed on the
 * decorative_rock ("Rock") form but was (a) an autocomplete text box and
 * (b) buried inside a COLLAPSED "Bulk Specifications" group (a leftover group
 * name from the shared/cloned material layout), so it read as "no rock-type
 * field." This:
 *   - switches the widget to a select dropdown (8 fixed terms → a real picker),
 *   - opens that group by default and renames it "Rock Specifications",
 *   - puts Rock Type first in the group.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/fix_decorative_rock_rock_type_form.php
 */

$fd = \Drupal::service('entity_display.repository')
  ->getFormDisplay('material', 'decorative_rock', 'default');
$out = [];

// 1) Rock Type -> select dropdown, first in its group.
$c = $fd->getComponent('field_rock_type');
if ($c) {
  $c['type'] = 'options_select';
  $c['settings'] = [];
  $c['weight'] = -1;
  $fd->setComponent('field_rock_type', $c);
  $out[] = 'field_rock_type -> options_select (weight -1)';
}
else {
  $out[] = 'WARN: field_rock_type not on the form';
}

// 2) Open + rename the containing group.
$groups = $fd->getThirdPartySettings('field_group');
if (isset($groups['group_bulk_specifications'])) {
  $g = $groups['group_bulk_specifications'];
  $g['label'] = 'Rock Specifications';
  $g['format_settings']['open'] = TRUE;
  $fd->setThirdPartySetting('field_group', 'group_bulk_specifications', $g);
  $out[] = 'group_bulk_specifications -> "Rock Specifications", open by default';
}
else {
  $out[] = 'NOTE: group_bulk_specifications not found (field may be ungrouped)';
}

$fd->save();
print implode("\n", $out) . "\nDONE.\n";
