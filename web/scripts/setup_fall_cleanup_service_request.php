<?php

/**
 * Create the `fall_cleanup` bundle on the existing service_request ECK entity,
 * its field instances (reusing shared storages), the fall-specific fields, and
 * the form/view displays. Idempotent, entity-API (ECK configs skip cim). Run per
 * env. Mirrors setup_service_request_entity.php (the sprinkler_winterizing bundle)
 * — nothing here touches that bundle.
 *
 *   ddev drush php:script web/scripts/setup_fall_cleanup_service_request.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\Role;

$ENTITY = 'service_request';
$BUNDLE = 'fall_cleanup';

// ── Bundle ───────────────────────────────────────────────────────────────────
$bundleStorage = \Drupal::entityTypeManager()->getStorage($ENTITY . '_type');
if (!$bundleStorage->load($BUNDLE)) {
  $bundleStorage->create([
    'type' => $BUNDLE,
    'name' => 'Fall Cleanup',
    'description' => 'Public fall-cleanup service requests (services landing page + Google Ads).',
  ])->save();
  print "created bundle: $ENTITY.$BUNDLE\n";
}
else {
  print "bundle exists: $ENTITY.$BUNDLE\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// ── Shared fields (storages already exist on service_request; instance only) ──
// weight → display order on the admin form/view.
$shared = [
  'field_property' => ['label' => 'Matched property', 'weight' => 0, 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label'],
  'field_service' => ['label' => 'Service', 'weight' => 1, 'widget' => 'options_select', 'formatter' => 'entity_reference_label'],
  'field_service_year' => ['label' => 'Service year', 'weight' => 2, 'widget' => 'number', 'formatter' => 'number_integer'],
  'field_request_status' => ['label' => 'Request status', 'weight' => 3, 'widget' => 'options_select', 'formatter' => 'entity_reference_label'],
  'field_source' => ['label' => 'Source', 'weight' => 4, 'widget' => 'options_select', 'formatter' => 'list_default'],
  'field_campaign' => ['label' => 'Campaign', 'weight' => 5, 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_public_ref' => ['label' => 'Public reference', 'weight' => 6, 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_name' => ['label' => 'Submitted name', 'weight' => 10, 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_address' => ['label' => 'Submitted address', 'weight' => 11, 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_zip' => ['label' => 'Submitted ZIP', 'weight' => 12, 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_phone' => ['label' => 'Submitted phone', 'weight' => 13, 'widget' => 'telephone_default', 'formatter' => 'basic_string'],
  'field_submitted_email' => ['label' => 'Submitted email', 'weight' => 14, 'widget' => 'email_default', 'formatter' => 'basic_string'],
  'field_access_notes' => ['label' => 'Gate & access notes', 'weight' => 20, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_customer_notes' => ['label' => 'Customer notes', 'weight' => 21, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_office_notes' => ['label' => 'Office notes', 'weight' => 22, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_review_flags' => ['label' => 'Review flags', 'weight' => 31, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_match_candidates' => ['label' => 'Match candidates (JSON)', 'weight' => 32, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_existing_work_order' => ['label' => 'Existing work order', 'weight' => 40, 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label'],
  'field_existing_contract' => ['label' => 'Existing contract', 'weight' => 41, 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label'],
  'field_work_order' => ['label' => 'Created work order', 'weight' => 42, 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label'],
  'field_converted_by' => ['label' => 'Converted by', 'weight' => 43, 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label'],
  'field_converted_on' => ['label' => 'Converted on', 'weight' => 44, 'widget' => 'datetime_timestamp', 'formatter' => 'timestamp'],
  'field_notice_version' => ['label' => 'Notice version', 'weight' => 45, 'widget' => 'string_textfield', 'formatter' => 'string'],
];

// ── Fall-specific fields (new storage + instance) ────────────────────────────
$new = [
  'field_fc_needs' => [
    'type' => 'list_string', 'label' => 'What do you need?', 'weight' => 15, 'cardinality' => -1,
    'allowed_values' => [
      'leaf_removal' => 'Leaf removal',
      'bed_cleanup' => 'Bed cleanup and weeding',
      'perennial_cutback' => 'Perennial cutback',
      'final_mow' => 'Final mow',
      'core_aeration' => 'Core aeration',
      'fallen_fruit' => 'Fallen fruit pickup',
      'debris_haul' => 'Debris haul-off',
      'not_sure' => 'Not sure — take a look and tell me',
    ],
    'widget' => 'options_buttons', 'formatter' => 'list_default',
  ],
  'field_fc_tree_count' => [
    'type' => 'list_string', 'label' => 'Mature trees dropping on property', 'weight' => 16, 'cardinality' => 1,
    'allowed_values' => ['none' => 'None', '1_3' => '1–3', '4_8' => '4–8', '9_plus' => '9+', 'not_sure' => 'Not sure'],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_fc_wants_winterize' => ['type' => 'boolean', 'label' => 'Winterize my sprinklers while you are here', 'weight' => 23, 'widget' => 'boolean_checkbox', 'formatter' => 'boolean'],
  'field_fc_wants_snow' => ['type' => 'boolean', 'label' => 'Contact me about a snow removal contract', 'weight' => 24, 'widget' => 'boolean_checkbox', 'formatter' => 'boolean'],
  'field_fc_wants_landscape' => ['type' => 'boolean', 'label' => 'Contact me in the spring about a landscape project', 'weight' => 25, 'widget' => 'boolean_checkbox', 'formatter' => 'boolean'],
  'field_fc_linked_winterize' => [
    'type' => 'entity_reference', 'label' => 'Linked winterize request', 'weight' => 46, 'cardinality' => 1,
    'target_type' => 'service_request', 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_fc_linked_estimate' => [
    'type' => 'entity_reference', 'label' => 'Linked design-build lead', 'weight' => 47, 'cardinality' => 1,
    'target_type' => 'estimate_request', 'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
];

$repo = \Drupal::service('entity_display.repository');
$formDisplay = $repo->getFormDisplay($ENTITY, $BUNDLE, 'default');
$viewDisplay = $repo->getViewDisplay($ENTITY, $BUNDLE, 'default');

// Shared instances.
foreach ($shared as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    print "  !! shared storage missing (unexpected): $name — skipping\n";
    continue;
  }
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    FieldConfig::create([
      'field_name' => $name, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE,
      'label' => $def['label'], 'required' => FALSE,
    ])->save();
    printf("    instance created: %s.%s\n", $BUNDLE, $name);
  }
  $formDisplay->setComponent($name, ['type' => $def['widget'], 'weight' => $def['weight'], 'region' => 'content']);
  $viewDisplay->setComponent($name, ['type' => $def['formatter'], 'weight' => $def['weight'], 'label' => 'inline', 'region' => 'content']);
}

// New storages + instances.
foreach ($new as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    $sv = [
      'field_name' => $name, 'entity_type' => $ENTITY, 'type' => $def['type'],
      'cardinality' => $def['cardinality'] ?? 1,
    ];
    if (!empty($def['allowed_values'])) {
      $sv['settings']['allowed_values'] = $def['allowed_values'];
    }
    if (!empty($def['target_type'])) {
      $sv['settings']['target_type'] = $def['target_type'];
    }
    FieldStorageConfig::create($sv)->save();
    printf("  storage created: %s (%s)\n", $name, $def['type']);
  }
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    $iv = ['field_name' => $name, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE, 'label' => $def['label'], 'required' => FALSE];
    if (!empty($def['target_type']) && $def['target_type'] === 'estimate_request') {
      $iv['settings'] = ['handler' => 'default:estimate_request'];
    }
    elseif (!empty($def['target_type']) && $def['target_type'] === 'service_request') {
      $iv['settings'] = ['handler' => 'default:service_request'];
    }
    FieldConfig::create($iv)->save();
    printf("    instance created: %s.%s\n", $BUNDLE, $name);
  }
  $formDisplay->setComponent($name, ['type' => $def['widget'], 'weight' => $def['weight'], 'region' => 'content']);
  $viewDisplay->setComponent($name, ['type' => $def['formatter'], 'weight' => $def['weight'], 'label' => 'inline', 'region' => 'content']);
}

$formDisplay->save();
$viewDisplay->save();
print "  form + view displays saved\n";

// ── ECK entity perms already granted to office roles by the winterize setup ──
// (create/view/edit/delete any service_request entities) — they cover all
// bundles, so nothing to add here. Re-grant defensively if missing.
$eckPerms = ['create service_request entities', 'view any service_request entities', 'edit any service_request entities', 'delete any service_request entities'];
foreach (['administration', 'supervisor', 'site_admin'] as $rid) {
  $role = Role::load($rid);
  if (!$role) {
    continue;
  }
  $changed = FALSE;
  foreach ($eckPerms as $perm) {
    if (!$role->hasPermission($perm)) {
      $role->grantPermission($perm);
      $changed = TRUE;
    }
  }
  if ($changed) {
    $role->save();
    printf("  granted service_request ECK perms to %s\n", $rid);
  }
}

print "DONE — fall_cleanup bundle + fields + displays ready.\n";
