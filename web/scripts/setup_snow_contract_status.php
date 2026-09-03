<?php

/**
 * @file
 * Reuse the existing contract status system on the snow contract:
 *  - add the existing field_contract_status (contract_status vocab) to
 *    contracts.snow_removal (it was residential-only), placed on the forms/view;
 *  - add an "Executed / Active" term to contract_status (find-or-create by name).
 *
 * No new status field — one status system across contract bundles. Idempotent.
 * Run: ddev drush php:script web/scripts/setup_snow_contract_status.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\taxonomy\Entity\Term;

// 1. field_contract_status instance on snow_removal (copy residential settings).
if (!FieldConfig::loadByName('contracts', 'snow_removal', 'field_contract_status')) {
  $res = FieldConfig::loadByName('contracts', 'residential', 'field_contract_status');
  FieldConfig::create([
    'field_name' => 'field_contract_status',
    'entity_type' => 'contracts',
    'bundle' => 'snow_removal',
    'label' => 'Contract Status',
    'description' => 'Snow agreement lifecycle. Shares the contract_status vocab with residential.',
    'settings' => $res ? $res->getSettings() : [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => ['target_bundles' => ['contract_status' => 'contract_status'], 'sort' => ['field' => 'name', 'direction' => 'asc'], 'auto_create' => FALSE, 'auto_create_bundle' => ''],
    ],
  ])->save();
  print "instance field_contract_status on snow_removal created\n";
}
else {
  print "instance exists\n";
}
foreach (['default', 'admin'] as $fd_id) {
  $fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("contracts.snow_removal.$fd_id");
  if ($fd && !$fd->getComponent('field_contract_status')) {
    $fd->setComponent('field_contract_status', ['type' => 'options_select', 'weight' => 5])->save();
    print "placed on $fd_id form\n";
  }
}
$vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('contracts.snow_removal.default');
if ($vd && !$vd->getComponent('field_contract_status')) {
  $vd->setComponent('field_contract_status', ['type' => 'entity_reference_label', 'weight' => 5, 'label' => 'inline'])->save();
  print "placed on default view\n";
}

// 2. "Executed / Active" status term (find-or-create).
$existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')
  ->loadByProperties(['vid' => 'contract_status', 'name' => 'Executed / Active']);
if ($existing) {
  $t = reset($existing);
  print 'term exists: ' . $t->id() . "\n";
}
else {
  $t = Term::create(['vid' => 'contract_status', 'name' => 'Executed / Active']);
  $t->save();
  print 'term created: ' . $t->id() . "\n";
}
print "DONE\n";
