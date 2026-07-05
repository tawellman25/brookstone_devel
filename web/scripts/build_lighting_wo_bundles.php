<?php

/**
 * Build out the two lighting work_order bundles (landscape_lighting,
 * exterior_lighting) into full service WO bundles modeled on sprinkler_repair:
 * copy every configurable field instance it lacks (all storages are shared) +
 * the form/view display field widgets/formatters. Idempotent. Run: drush php:script.
 * (work_order is ECK — field config cim silent-skips, so this uses the entity API.)
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$src = 'sprinkler_repair';
$targets = ['landscape_lighting', 'exterior_lighting'];
$fm = \Drupal::service('entity_field.manager');
$edr = \Drupal::service('entity_display.repository');

$srcDefs = $fm->getFieldDefinitions('work_order', $src);
$srcForm = $edr->getFormDisplay('work_order', $src);
$srcView = $edr->getViewDisplay('work_order', $src);

foreach ($targets as $bundle) {
  $created = 0;
  $skipped = 0;
  foreach ($srcDefs as $name => $def) {
    if (strpos($name, 'field_') !== 0 || !$def instanceof FieldConfig) {
      continue;
    }
    if (FieldConfig::loadByName('work_order', $bundle, $name)) {
      $skipped++;
      continue;
    }
    $storage = FieldStorageConfig::loadByName('work_order', $name);
    if (!$storage) {
      print "  WARN: no shared storage for $name — skipped\n";
      continue;
    }
    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => $bundle,
      'label' => $def->getLabel(),
      'description' => $def->getDescription(),
      'required' => $def->isRequired(),
      'settings' => $def->getSettings(),
      'default_value' => $def->getDefaultValueLiteral() ?? [],
      'default_value_callback' => $def->getDefaultValueCallback() ?: '',
    ])->save();
    $created++;
  }
  // Copy the field widgets / formatters (not field-groups or bundle-specific EVA extras).
  $fd = $edr->getFormDisplay('work_order', $bundle);
  foreach ($srcForm->getComponents() as $name => $comp) {
    if (strpos($name, 'field_') === 0) {
      $fd->setComponent($name, $comp);
    }
  }
  $fd->save();
  $vd = $edr->getViewDisplay('work_order', $bundle);
  foreach ($srcView->getComponents() as $name => $comp) {
    if (strpos($name, 'field_') === 0) {
      $vd->setComponent($name, $comp);
    }
  }
  $vd->save();
  print "  $bundle: created $created field instances, $skipped already present\n";
}

// Verify.
$fm->clearCachedFieldDefinitions();
foreach ($targets as $bundle) {
  $defs = $fm->getFieldDefinitions('work_order', $bundle);
  $count = count(array_filter($defs, fn($f) => strpos($f->getName(), 'field_') === 0));
  $core = ['field_property', 'field_service', 'field_status', 'field_work_order_id'];
  $hasCore = count(array_intersect($core, array_keys($defs))) === count($core);
  print "  verify $bundle: $count field_ fields; core WO fields present: " . ($hasCore ? 'YES' : 'NO') . "\n";
}
