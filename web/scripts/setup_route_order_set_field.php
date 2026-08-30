<?php

/**
 * @file
 * Create field_route_order_set (boolean) on scheduling.work_order.
 *
 * Set automatically when the office arranges a route in the Route Editor
 * (drag-reorder or Optimize). The winterize carry-forward reuses a
 * route-order-set route's stop order for next year instead of falling back to
 * the actual driven order. Distinct from field_scheduled_firm (customer
 * commitment — "we told them a day"). Idempotent; ECK/field configs skip cim,
 * so this script is the deploy path.
 *
 * Run: ddev drush php:script web/scripts/setup_route_order_set_field.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

if (!FieldStorageConfig::loadByName('scheduling', 'field_route_order_set')) {
  FieldStorageConfig::create([
    'field_name' => 'field_route_order_set',
    'entity_type' => 'scheduling',
    'type' => 'boolean',
    'cardinality' => 1,
  ])->save();
  print "Created field storage scheduling.field_route_order_set\n";
}
else {
  print "Field storage already exists\n";
}

if (!FieldConfig::loadByName('scheduling', 'work_order', 'field_route_order_set')) {
  FieldConfig::create([
    'field_name' => 'field_route_order_set',
    'entity_type' => 'scheduling',
    'bundle' => 'work_order',
    'label' => 'Route order set',
    'description' => 'Set automatically when the office arranges this route in the Route Editor (reorder or Optimize). The winterize carry-forward reuses a route-order-set route\'s stop order next year. Distinct from Firm/Tentative (customer commitment).',
    'settings' => ['on_label' => 'Order set', 'off_label' => 'Not set'],
  ])->save();
  print "Created field instance scheduling.work_order.field_route_order_set\n";
}
else {
  print "Field instance already exists\n";
}

$e = \Drupal::entityTypeManager()->getStorage('field_config')->load('scheduling.work_order.field_route_order_set');
print 'instance uuid: ' . ($e ? $e->uuid() : 'MISSING') . "\n";
$s = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load('scheduling.field_route_order_set');
print 'storage uuid: ' . ($s ? $s->uuid() : 'MISSING') . "\n";
print "DONE\n";
