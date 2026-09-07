<?php

/**
 * @file
 * Winterizing pricing: bump base fee to $95 and add the 4 discount amounts to
 * Business Settings (config_pages: business_setting). Discounts are stored as
 * POSITIVE dollar amounts; billing subtracts the single LARGEST applicable one.
 *
 *   field_winterize_disc_contract  Signed contract / Automatic List   $5
 *   field_winterize_disc_services  Contract with more than 4 services  $10
 *   field_winterize_disc_new_cust  New customer, 4+ homes (one time)   $15
 *   field_winterize_disc_hoa       HOA contracted                      $35
 *
 * config_pages fields silent-skip on cim, so this script IS the deploy path.
 * Idempotent. Run: drush php:script web/scripts/setup_winterizing_discounts_business_setting.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

$out = [];

$ensureDecimal = function (string $name, string $label, string $desc) use (&$out) {
  $storage = FieldStorageConfig::loadByName('config_pages', $name);
  if (!$storage) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => 'config_pages', 'type' => 'decimal',
      'cardinality' => 1, 'settings' => ['precision' => 10, 'scale' => 2],
    ])->save();
    $out[] = "created storage $name";
    $storage = FieldStorageConfig::loadByName('config_pages', $name);
  }
  if (!FieldConfig::loadByName('config_pages', 'business_setting', $name)) {
    FieldConfig::create([
      'field_storage' => $storage, 'bundle' => 'business_setting',
      'label' => $label, 'description' => $desc, 'required' => FALSE,
    ])->save();
    $out[] = "created instance $name";
  }
};

$ensureDecimal('field_winterize_disc_contract', 'Discount — Signed Contract / Automatic List',
  'Dollar amount subtracted from a winterizing WO when the property has a current-year winterizing contract line OR is on the automatic (returning-customer) list.');
$ensureDecimal('field_winterize_disc_services', 'Discount — Contract with 4+ Services',
  'Dollar amount subtracted when the property\'s current-year residential contract has more than 4 selected services.');
$ensureDecimal('field_winterize_disc_new_cust', 'Discount — New Customer, 4+ Homes (one time)',
  'Dollar amount subtracted for a new customer signing up 4 or more homes (their first season only).');
$ensureDecimal('field_winterize_disc_hoa', 'Discount — HOA Contracted',
  'Dollar amount subtracted when the property is flagged HOA-contracted (e.g. Bear Creek). Base $95 − $35 = $60 net.');

// ── Form display: fields + a "Winterizing Discounts" group ───────────────────
$fd = EntityFormDisplay::load('config_pages.business_setting.default');
if ($fd) {
  $w = 0;
  foreach ([
    'field_winterize_disc_contract', 'field_winterize_disc_services',
    'field_winterize_disc_new_cust', 'field_winterize_disc_hoa',
  ] as $f) {
    $fd->setComponent($f, ['type' => 'number', 'weight' => $w++, 'region' => 'content', 'settings' => ['placeholder' => ''], 'third_party_settings' => []]);
  }
  $groups = $fd->getThirdPartySettings('field_group');
  $groups['group_winterizing_discounts'] = [
    'children' => [
      'field_winterize_disc_contract', 'field_winterize_disc_services',
      'field_winterize_disc_new_cust', 'field_winterize_disc_hoa',
    ],
    'label' => 'Winterizing Discounts',
    'region' => 'content',
    'parent_name' => '',
    'weight' => 5,
    'format_type' => 'details',
    'format_settings' => [
      'classes' => '', 'show_empty_fields' => FALSE, 'id' => '',
      'open' => FALSE, 'description' => 'Applied automatically at winterizing sign-off. Only the single LARGEST applicable discount is used (they do not stack).',
      'required_fields' => FALSE,
    ],
  ];
  foreach ($groups as $gid => $def) { $fd->setThirdPartySetting('field_group', $gid, $def); }
  $fd->save();
  $out[] = 'form display: added 4 discount fields + group_winterizing_discounts';
}

// ── Seed values on the business_setting entity (base fee → 95, discounts) ─────
$cp = \Drupal::service('config_pages.loader')->load('business_setting');
if ($cp) {
  $cp->set('field_winterizing_base_fee', '95.00');
  $seed = [
    'field_winterize_disc_contract' => '5.00',
    'field_winterize_disc_services' => '10.00',
    'field_winterize_disc_new_cust' => '15.00',
    'field_winterize_disc_hoa' => '35.00',
  ];
  foreach ($seed as $f => $v) {
    if ($cp->get($f)->isEmpty()) { $cp->set($f, $v); }
  }
  $cp->save();
  $out[] = 'base fee set to 95.00; discount amounts seeded (only where empty)';
}

print implode("\n", $out) . "\nDONE.\n";
