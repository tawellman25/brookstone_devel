<?php
/**
 * field_internal_only — "this page is for staff, not the public".
 *
 * A field rather than a list of node ids in a module, so the office can mark
 * the next one without a deploy and so the reason travels with the content.
 * bos_content_access denies the view at hook_node_access when it is set.
 *
 * Idempotent. Field configs silently skip on cim, so this script is the deploy
 * path, per the BOS convention.
 */
$efm = \Drupal::service('entity_field.manager');
$etm = \Drupal::entityTypeManager();
$BUNDLES = ['page', 'help_manual'];

$storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('node', 'field_internal_only');
if (!$storage) {
  \Drupal\field\Entity\FieldStorageConfig::create([
    'field_name' => 'field_internal_only',
    'entity_type' => 'node',
    'type' => 'boolean',
    'cardinality' => 1,
  ])->save();
  print "created field storage node.field_internal_only\n";
}
else { print "field storage already exists\n"; }

foreach ($BUNDLES as $bundle) {
  $field = \Drupal\field\Entity\FieldConfig::loadByName('node', $bundle, 'field_internal_only');
  if (!$field) {
    \Drupal\field\Entity\FieldConfig::create([
      'field_name' => 'field_internal_only',
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => 'Internal only (hide from the public)',
      'description' => 'Tick this for crew or office material. The page stays available to staff and is refused to everyone else — including search engines.',
      'required' => FALSE,
      'settings' => ['on_label' => 'Staff only', 'off_label' => 'Public'],
    ])->save();
    printf("  added to node.%s\n", $bundle);
  }
  else { printf("  already on node.%s\n", $bundle); }

  // On the edit form so it can actually be ticked.
  $form = $etm->getStorage('entity_form_display')->load("node.$bundle.default");
  if ($form && !$form->getComponent('field_internal_only')) {
    $form->setComponent('field_internal_only', [
      'type' => 'boolean_checkbox',
      'weight' => 50,
      'settings' => ['display_label' => TRUE],
      'region' => 'content',
    ])->save();
    printf("  widget added to the %s edit form\n", $bundle);
  }
  // Deliberately NOT on any view display: it is a control, not content.
  foreach ($etm->getStorage('entity_view_display')->loadByProperties(['targetEntityType' => 'node', 'bundle' => $bundle]) as $vd) {
    if ($vd->getComponent('field_internal_only')) {
      $vd->removeComponent('field_internal_only')->save();
      printf("  removed from the %s %s display (it is a control, not content)\n", $bundle, $vd->getMode());
    }
  }
}
print "\ndone\n";
