<?php

/**
 * B. Link a backflow test to the gauge used: field_test_gauge on
 * wo_tasks_list:backflow_testing -> equipment (test_gauges). Placed in the Test
 * group. The default (gauge assigned to the tester) + the expired-calibration
 * warning are handled in wo_backflow_testing.module.
 *
 * Idempotent; entity-API (no cim). Run per env.
 *   drush php:script web/scripts/setup_backflow_test_gauge_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$BUNDLE = 'backflow_testing';

if (!FieldStorageConfig::loadByName('wo_tasks_list', 'field_test_gauge')) {
  FieldStorageConfig::create([
    'field_name' => 'field_test_gauge', 'entity_type' => 'wo_tasks_list',
    'type' => 'entity_reference', 'cardinality' => 1,
    'settings' => ['target_type' => 'equipment'],
  ])->save();
  print "storage field_test_gauge\n";
}
if (!FieldConfig::loadByName('wo_tasks_list', $BUNDLE, 'field_test_gauge')) {
  FieldConfig::create([
    'field_name' => 'field_test_gauge', 'entity_type' => 'wo_tasks_list', 'bundle' => $BUNDLE,
    'label' => 'Test Gauge',
    'description' => 'The calibrated gauge used for this test. Defaults to the gauge assigned to the tester.',
    'settings' => [
      'handler' => 'default:equipment',
      'handler_settings' => ['target_bundles' => ['test_gauges' => 'test_gauges'], 'sort' => ['field' => '_none'], 'auto_create' => FALSE],
    ],
  ])->save();
  print "instance field_test_gauge\n";
}

// Form display: add to the Test group, after the cert number.
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('wo_tasks_list', $BUNDLE);
if (!$fd->getComponent('field_test_gauge')) {
  $fd->setComponent('field_test_gauge', ['type' => 'entity_reference_autocomplete', 'weight' => 6, 'region' => 'content']);
}
$groups = $fd->getThirdPartySettings('field_group');
if (!empty($groups['group_test_details']) && !in_array('field_test_gauge', $groups['group_test_details']['children'], TRUE)) {
  // Insert after field_certification_number if present, else append.
  $children = $groups['group_test_details']['children'];
  $pos = array_search('field_certification_number', $children, TRUE);
  if ($pos === FALSE) { $children[] = 'field_test_gauge'; }
  else { array_splice($children, $pos + 1, 0, 'field_test_gauge'); }
  $groups['group_test_details']['children'] = $children;
  $fd->setThirdPartySetting('field_group', 'group_test_details', $groups['group_test_details']);
  print "added field_test_gauge to Test group\n";
}
$fd->save();
print "DONE.\n";
