<?php

/**
 * Add field_work_todo_description ("Work To Be Done") to the two lighting
 * work_order bundles that lack it, so all 36 bundles have the description field
 * the voice-intake appends to. Idempotent. Run: drush php:script <this>.
 * (work_order is ECK — field config cim silent-skips, so this uses the entity API.)
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$storage = FieldStorageConfig::loadByName('work_order', 'field_work_todo_description');
if (!$storage) {
  print "ERROR: field storage field_work_todo_description missing on work_order.\n";
  return;
}
$edr = \Drupal::service('entity_display.repository');
$src = 'misc_services';
$formComp = $edr->getFormDisplay('work_order', $src)->getComponent('field_work_todo_description');
$viewComp = $edr->getViewDisplay('work_order', $src)->getComponent('field_work_todo_description');

foreach (['exterior_lighting', 'landscape_lighting'] as $bundle) {
  if (!FieldConfig::loadByName('work_order', $bundle, 'field_work_todo_description')) {
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => 'Work To Be Done',
      'required' => FALSE,
      'settings' => ['allowed_formats' => ['full_html']],
    ])->save();
    print "created field instance on $bundle\n";
  }
  else {
    print "field instance already on $bundle\n";
  }
  $edr->getFormDisplay('work_order', $bundle)->setComponent('field_work_todo_description', $formComp)->save();
  $edr->getViewDisplay('work_order', $bundle)->setComponent('field_work_todo_description', $viewComp)->save();
  print "  form + view display components set on $bundle\n";
}

// Verify.
$fm = \Drupal::service('entity_field.manager');
$fm->clearCachedFieldDefinitions();
foreach (['exterior_lighting', 'landscape_lighting'] as $bundle) {
  $has = isset($fm->getFieldDefinitions('work_order', $bundle)['field_work_todo_description']);
  print "  verify $bundle has field: " . ($has ? 'YES' : 'NO') . "\n";
}
