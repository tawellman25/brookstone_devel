<?php

/**
 * Create the equipment:it_equipment bundle (IT / computer equipment) + its
 * reused and net-new fields + IT device-type taxonomy terms + form/view
 * displays.
 *
 * IT gear reuses the existing `equipment` ECK entity so it inherits the defect /
 * maintenance-event / inspection machinery. Reused fields are instanced from
 * existing shared storages; net-new IT fields (prefixed field_it_) get new
 * storages instanced ONLY on it_equipment, so no other equipment bundle is
 * touched.
 *
 * Idempotent — safe to re-run; run once per environment (ECK/field configs
 * silent-skip on cim). See __BOS_AI/Entities/equipment_it_equipment.md.
 *
 * Run: drush php:script web/scripts/setup_it_equipment_bundle.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Term;

$etm = \Drupal::entityTypeManager();
$ENTITY = 'equipment';
$BUNDLE = 'it_equipment';

// ── 1. Bundle ────────────────────────────────────────────────────────────
$bundleEntityTypeId = $etm->getDefinition($ENTITY)->getBundleEntityType();
$bundleStorage = $etm->getStorage($bundleEntityTypeId);
if (!$bundleStorage->load($BUNDLE)) {
  $bundleStorage->create([
    'type' => $BUNDLE,
    'name' => 'IT Equipment',
    'description' => 'Computers, NAS, network switches, router/gateway, and printers — IT assets tracked on the shared equipment entity.',
  ])->save();
  print "created bundle equipment.$BUNDLE\n";
}
else {
  print "bundle equipment.$BUNDLE already exists\n";
}

// ── 2. IT device-type taxonomy terms (equipment_types) ───────────────────
$IT_TYPES = [
  'Desktop PC / Workstation',
  'NAS (Network Attached Storage)',
  'Network Switch',
  'Router / Gateway',
  'Printer',
  'Unidentified Network Device',
];
// NOTE: equipment_types uses auto_entitylabel with pattern
// [term:field_common_name] — the term LABEL is copied from field_common_name on
// save. Setting `name` alone is overwritten to empty. So set field_common_name
// (and name as a harmless fallback), and dedupe on field_common_name.
$termStorage = $etm->getStorage('taxonomy_term');
foreach ($IT_TYPES as $name) {
  $existing = $termStorage->loadByProperties(['vid' => 'equipment_types', 'field_common_name' => $name]);
  if (!$existing) {
    Term::create([
      'vid' => 'equipment_types',
      'name' => $name,
      'field_common_name' => $name,
    ])->save();
    print "  added equipment_types term: $name\n";
  }
}

// ── 3. Net-new IT field storages + instances (instanced only on it_equipment)
// [machine_name => [type, label, extra_storage_settings]]
$NEW_FIELDS = [
  'field_it_hostname'         => ['string',    'Hostname / Name'],
  'field_it_user'            => ['string',    'Current User'],
  'field_it_ipv4'            => ['string',    'IPv4 Address'],
  'field_it_mac'             => ['string',    'MAC Address'],
  'field_it_os'             => ['string',    'Operating System'],
  'field_it_os_build'        => ['string',    'OS Build'],
  'field_it_cpu'            => ['string',    'Processor (CPU)'],
  'field_it_ram_gb'          => ['decimal',   'RAM (GB)', ['precision' => 6, 'scale' => 2]],
  'field_it_location'        => ['string',    'Location'],
  'field_it_notes'          => ['text_long', 'Role / Notes (internal)'],
  'field_it_disk_encryption' => ['string',    'OS Disk Encryption'],
  'field_it_firewall'        => ['string',    'Active Firewall'],
  'field_it_antivirus'       => ['string',    'Antivirus Products'],
  'field_it_time_sync'       => ['string',    'Time Sync'],
  'field_it_network_profile' => ['string',    'Network Profile'],
  'field_it_workgroup'       => ['string',    'Workgroup'],
  'field_it_dhcp'           => ['boolean',   'DHCP Enabled'],
  'field_it_gateway'         => ['string',    'Gateway'],
  'field_it_dns'            => ['string',    'DNS Servers'],
  'field_it_link_speed'      => ['string',    'Link Speed'],
];

foreach ($NEW_FIELDS as $name => $spec) {
  [$type, $label] = $spec;
  $settings = $spec[2] ?? [];
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'type' => $type,
      'cardinality' => 1,
      'settings' => $settings,
    ])->save();
    print "  created storage $ENTITY.$name ($type)\n";
  }
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => $label,
    ])->save();
    print "  instanced $name on $BUNDLE\n";
  }
}

// ── 4. Reused field instances (existing shared storages) ─────────────────
// [machine_name => [label, handler_settings|null]]
$REUSED = [
  'field_equipment_number'   => ['Asset ID', NULL],
  'field_equipment_make'     => ['Manufacturer', NULL],
  'field_model'             => ['Model', NULL],
  'field_serial_code_number' => ['Serial Number', NULL],
  'field_date_purchased'     => ['Date Purchased', NULL],
  'field_purchase_price'     => ['Purchase Price', NULL],
  'field_equipment_type'     => ['Device Type', ['target_bundles' => ['equipment_types' => 'equipment_types']]],
  'field_status'            => ['Status', ['target_bundles' => ['equipment_status' => 'equipment_status']]],
];
foreach ($REUSED as $name => [$label, $handlerBundles]) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    print "  WARN: expected shared storage $ENTITY.$name missing — skipped\n";
    continue;
  }
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    $values = [
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => $label,
    ];
    if ($handlerBundles) {
      $values['settings'] = ['handler' => 'default:taxonomy_term', 'handler_settings' => $handlerBundles];
    }
    FieldConfig::create($values)->save();
    print "  instanced (reused) $name on $BUNDLE\n";
  }
}

// ── 5. Form + view displays ──────────────────────────────────────────────
$displayRepo = \Drupal::service('entity_display.repository');
$formDisplay = $displayRepo->getFormDisplay($ENTITY, $BUNDLE);
$viewDisplay = $displayRepo->getViewDisplay($ENTITY, $BUNDLE);

// Ordered so the record reads top-to-bottom sensibly.
$ORDER = [
  'field_equipment_number', 'field_equipment_type', 'field_status', 'field_it_hostname',
  'field_it_user', 'field_it_location', 'field_equipment_make', 'field_model',
  'field_serial_code_number', 'field_it_os', 'field_it_os_build', 'field_it_cpu',
  'field_it_ram_gb', 'field_it_ipv4', 'field_it_mac', 'field_it_gateway', 'field_it_dns',
  'field_it_dhcp', 'field_it_network_profile', 'field_it_link_speed', 'field_it_workgroup',
  'field_it_disk_encryption', 'field_it_firewall', 'field_it_antivirus', 'field_it_time_sync',
  'field_date_purchased', 'field_purchase_price', 'field_it_notes',
];
$weight = 0;
foreach ($ORDER as $name) {
  $type = FieldStorageConfig::loadByName($ENTITY, $name)?->getType();
  if (!$type) {
    continue;
  }
  // Widget + formatter picked by type.
  $widget = match ($type) {
    'boolean' => 'boolean_checkbox',
    'text_long' => 'text_textarea',
    'decimal' => 'number',
    'datetime' => 'datetime_default',
    'entity_reference' => 'options_select',
    default => 'string_textfield',
  };
  $formatter = match ($type) {
    'boolean' => 'boolean',
    'text_long' => 'text_default',
    'decimal' => 'number_decimal',
    'datetime' => 'datetime_default',
    'entity_reference' => 'entity_reference_label',
    default => 'string',
  };
  $formDisplay->setComponent($name, ['type' => $widget, 'weight' => $weight]);
  $viewDisplay->setComponent($name, ['type' => $formatter, 'weight' => $weight, 'label' => 'inline']);
  $weight += 2;
}
$formDisplay->save();
$viewDisplay->save();
print "configured form + view displays for $BUNDLE\n";

print "DONE — equipment.$BUNDLE ready.\n";
