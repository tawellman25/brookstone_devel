<?php

declare(strict_types=1);

/**
 * Add field_list_order to the credential entity.
 *
 * The public credentials page needs a deliberate order — licences, then insurance,
 * then fleet and manufacturer. Alphabetical puts ABPA above the applicator business
 * licence, which reads as arbitrary. Same field name/idea as
 * taxonomy_term.field_list_order on backflow_uses (a new storage, since storages
 * are per entity type).
 *
 * Takes credential from 17 to 18 fields — still under the twenty-field ceiling.
 * Idempotent.
 *   drush php:script web/scripts/setup_credential_list_order.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENTITY = 'credential';
$BUNDLE = 'credential';
$FIELD = 'field_list_order';

if (!FieldStorageConfig::loadByName($ENTITY, $FIELD)) {
  FieldStorageConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'type' => 'integer', 'cardinality' => 1,
  ])->save();
  print "created storage $ENTITY.$FIELD\n";
}
else {
  print "storage exists\n";
}
if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_name' => $FIELD, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE,
    'label' => 'List order',
    'description' => 'Display order on the public credentials page — lower first. Licences 10-30, insurance 40-60, fleet and manufacturer 70+.',
    'required' => FALSE,
  ])->save();
  print "created instance\n";
}
else {
  print "instance exists\n";
}

$repo = \Drupal::service('entity_display.repository');
$form = $repo->getFormDisplay($ENTITY, $BUNDLE, 'default');
if (!$form->getComponent($FIELD)) {
  $form->setComponent($FIELD, ['type' => 'number', 'weight' => 17, 'region' => 'content'])->save();
  print "added to the form\n";
}
$view = $repo->getViewDisplay($ENTITY, $BUNDLE, 'default');
if (!$view->getComponent($FIELD)) {
  $view->setComponent($FIELD, ['type' => 'number_integer', 'weight' => 17, 'label' => 'inline', 'region' => 'content'])->save();
  print "added to the default view display\n";
}
print "DONE.\n";
