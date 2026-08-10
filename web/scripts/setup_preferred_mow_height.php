<?php

/**
 * Add "Preferred Mow Height" (string) to the Mowing Information + mowing WO.
 * IDEMPOTENT, entity-API only (no cim). Source of truth =
 * property_lawn_maintenance.lawn_maintenance_info; a copy on
 * work_order.lawn_mowing is populated from the property on WO creation
 * (wo_lawn_mowing read-direction) so it shows on the mowing Work Order. The Mow
 * list views (property_lawn_maintenance-based) read the source field directly.
 *
 *   drush php:script web/scripts/setup_preferred_mow_height.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$FIELD = 'field_preferred_mow_height';

$ensure = function (string $entityType, string $bundle, string $label) use ($FIELD) {
  if (!FieldStorageConfig::loadByName($entityType, $FIELD)) {
    FieldStorageConfig::create([
      'field_name' => $FIELD,
      'entity_type' => $entityType,
      'type' => 'string',
      'cardinality' => 1,
    ])->save();
    print "  storage created: $entityType.$FIELD\n";
  }
  else {
    print "  storage exists: $entityType.$FIELD\n";
  }
  if (!FieldConfig::loadByName($entityType, $bundle, $FIELD)) {
    FieldConfig::create([
      'field_name' => $FIELD,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $label,
      'required' => FALSE,
      'description' => 'Free text, e.g. 3", or "2.5\" front / 3\" back".',
    ])->save();
    print "  instance created: $entityType.$bundle.$FIELD\n";
  }
  else {
    print "  instance exists: $entityType.$bundle.$FIELD\n";
  }
};

// Source (Mowing Information) + WO copy.
$ensure('property_lawn_maintenance', 'lawn_maintenance_info', 'Preferred Mow Height');
$ensure('work_order', 'lawn_mowing', 'Preferred Mow Height');

$repo = \Drupal::service('entity_display.repository');

// ── Mowing Information: form (after Special Mowing Instructions) + view ──
$pf = $repo->getFormDisplay('property_lawn_maintenance', 'lawn_maintenance_info');
$pf->setComponent($FIELD, ['type' => 'string_textfield', 'weight' => 6, 'region' => 'content', 'settings' => ['size' => 30, 'placeholder' => '']]);
// nudge the fields that were at weight >=6 down one so height sits after instructions
foreach (['field_mowing_dumping_location' => 7, 'field_mowing_last_mowed' => 8, 'field_mowing_last_mowed_by' => 9, 'field_property' => 10, 'field_mowing_contracted' => 11, 'field_aerating_lawns_contracted' => 12, 'field_dethatching_contracted' => 13] as $f => $w) {
  $c = $pf->getComponent($f);
  if ($c) {
    $c['weight'] = $w;
    $pf->setComponent($f, $c);
  }
}
$pf->save();
$pv = $repo->getViewDisplay('property_lawn_maintenance', 'lawn_maintenance_info');
if ($pv->getComponent('field_mowing_instructions') || TRUE) {
  $pv->setComponent($FIELD, ['type' => 'string', 'label' => 'inline', 'weight' => 6, 'region' => 'content', 'settings' => []]);
  $pv->save();
}

// ── Mowing WO: form + view display (prominent, near the top) ──
$wf = $repo->getFormDisplay('work_order', 'lawn_mowing');
$wf->setComponent($FIELD, ['type' => 'string_textfield', 'weight' => -20, 'region' => 'content', 'settings' => ['size' => 30, 'placeholder' => '']]);
$wf->save();
$wv = $repo->getViewDisplay('work_order', 'lawn_mowing');
$wv->setComponent($FIELD, ['type' => 'string', 'label' => 'inline', 'weight' => -20, 'region' => 'content', 'settings' => []]);
$wv->save();

print "DONE — Preferred Mow Height added (property source + WO copy + displays).\n";
