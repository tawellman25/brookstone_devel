<?php

/**
 * Create the service_request ECK entity type, its sprinkler_winterizing bundle,
 * all fields, and form/view displays — idempotently, via the entity API.
 *
 * WHY a script and not cim: ECK field instances silently skip on cim, and ECK
 * bundle export writes a stray empty string into the bundles array. The entity
 * API is the reliable, idempotent landing path (BOS standard). Run per env.
 *
 * Prereq: run seed_service_request_status.php first (the field_request_status
 * reference targets that vocabulary).
 *
 *   ddev drush php:script web/scripts/setup_service_request_entity.php   (local)
 *   drush php:script web/scripts/setup_service_request_entity.php        (live)
 */

use Drupal\eck\Entity\EckEntityType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\Role;

$ENTITY = 'service_request';
$BUNDLE = 'sprinkler_winterizing';

// ── 1. ECK entity type ──────────────────────────────────────────────────────
if (!EckEntityType::load($ENTITY)) {
  $type = EckEntityType::create([
    'id' => $ENTITY,
    'label' => 'Service Request',
    'description' => 'Public service-request intake record. Intake only — never execution.',
    'uid' => TRUE,
    'created' => TRUE,
    'changed' => TRUE,
    'title' => TRUE,
    // No public URL — canonical /service_request/{id} lives behind permissions.
    'standalone_url' => TRUE,
  ]);
  $type->save(); // postSave() installs storage via eck.entity.entity_update_service.
  print "created ECK entity type: $ENTITY\n";
}
else {
  print "ECK entity type exists: $ENTITY\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// ── 2. Bundle ───────────────────────────────────────────────────────────────
$bundleStorage = \Drupal::entityTypeManager()->getStorage($ENTITY . '_type');
if (!$bundleStorage->load($BUNDLE)) {
  $bundleStorage->create([
    'type' => $BUNDLE,
    'name' => 'Sprinkler Winterizing',
    'description' => 'Public sprinkler-winterizing service requests (postcard QR + website).',
  ])->save();
  print "created bundle: $ENTITY.$BUNDLE\n";
}
else {
  print "bundle exists: $ENTITY.$BUNDLE\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// ── 3. Fields ───────────────────────────────────────────────────────────────
// Each: type, label, optional storage[] (target_type / max_length / allowed_values),
// optional instance[] (handler_settings), widget, formatter, weight.
$fields = [
  'field_property' => [
    'type' => 'entity_reference', 'label' => 'Matched property', 'weight' => 0,
    'storage' => ['target_type' => 'properties'],
    'instance' => ['handler' => 'default:properties', 'handler_settings' => ['target_bundles' => ['property' => 'property']]],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_service' => [
    'type' => 'entity_reference', 'label' => 'Service', 'weight' => 1,
    'storage' => ['target_type' => 'taxonomy_term'],
    'instance' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['services' => 'services']]],
    'widget' => 'options_select', 'formatter' => 'entity_reference_label',
  ],
  'field_service_year' => ['type' => 'integer', 'label' => 'Service year', 'weight' => 2, 'widget' => 'number', 'formatter' => 'number_integer'],
  'field_request_status' => [
    'type' => 'entity_reference', 'label' => 'Request status', 'weight' => 3,
    'storage' => ['target_type' => 'taxonomy_term'],
    'instance' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['service_request_status' => 'service_request_status']]],
    'widget' => 'options_select', 'formatter' => 'entity_reference_label',
  ],
  'field_source' => [
    'type' => 'list_string', 'label' => 'Source', 'weight' => 4,
    'storage' => ['allowed_values' => ['website' => 'Website', 'postcard_qr' => 'Postcard QR', 'phone' => 'Phone', 'office' => 'Office', 'email' => 'Email', 'other' => 'Other']],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_campaign' => ['type' => 'string', 'label' => 'Campaign', 'weight' => 5, 'storage' => ['max_length' => 64], 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_public_ref' => ['type' => 'string', 'label' => 'Public reference', 'weight' => 6, 'storage' => ['max_length' => 12], 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_name' => ['type' => 'string', 'label' => 'Submitted name', 'weight' => 10, 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_address' => ['type' => 'string', 'label' => 'Submitted address', 'weight' => 11, 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_zip' => ['type' => 'string', 'label' => 'Submitted ZIP', 'weight' => 12, 'storage' => ['max_length' => 10], 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_submitted_phone' => ['type' => 'telephone', 'label' => 'Submitted phone', 'weight' => 13, 'widget' => 'telephone_default', 'formatter' => 'basic_string'],
  'field_submitted_email' => ['type' => 'email', 'label' => 'Submitted email', 'weight' => 14, 'widget' => 'email_default', 'formatter' => 'basic_string'],
  'field_access_notes' => ['type' => 'string_long', 'label' => 'Access notes', 'weight' => 20, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_customer_notes' => ['type' => 'string_long', 'label' => 'Customer notes', 'weight' => 21, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_office_notes' => ['type' => 'string_long', 'label' => 'Office notes', 'weight' => 22, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_wants_recurring' => ['type' => 'boolean', 'label' => 'Wants automatic winterizing each fall', 'weight' => 23, 'widget' => 'boolean_checkbox', 'formatter' => 'boolean'],
  'field_match_candidates' => ['type' => 'string_long', 'label' => 'Match candidates (JSON)', 'weight' => 30, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_review_flags' => ['type' => 'string_long', 'label' => 'Review flags', 'weight' => 31, 'widget' => 'string_textarea', 'formatter' => 'basic_string'],
  'field_existing_work_order' => [
    'type' => 'entity_reference', 'label' => 'Existing work order', 'weight' => 40,
    'storage' => ['target_type' => 'work_order'], 'instance' => ['handler' => 'default:work_order'],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_existing_contract' => [
    'type' => 'entity_reference', 'label' => 'Existing contract', 'weight' => 41,
    'storage' => ['target_type' => 'contracts'], 'instance' => ['handler' => 'default:contracts'],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_work_order' => [
    'type' => 'entity_reference', 'label' => 'Created work order', 'weight' => 42,
    'storage' => ['target_type' => 'work_order'], 'instance' => ['handler' => 'default:work_order'],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_converted_by' => [
    'type' => 'entity_reference', 'label' => 'Converted by', 'weight' => 43,
    'storage' => ['target_type' => 'user'], 'instance' => ['handler' => 'default:user'],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_converted_on' => ['type' => 'timestamp', 'label' => 'Converted on', 'weight' => 44, 'widget' => 'datetime_timestamp', 'formatter' => 'timestamp'],
  // Phase 2 additions (all nullable).
  'field_wants_startup' => ['type' => 'boolean', 'label' => 'Wants spring start-up contact', 'weight' => 24, 'widget' => 'boolean_checkbox', 'formatter' => 'boolean'],
  'field_water_supply' => [
    'type' => 'list_string', 'label' => 'Water supply (as submitted)', 'weight' => 25,
    // module: options — a core storage silently degrades the Views filter to string (see field_source).
    'storage' => ['allowed_values' => ['city' => 'City / domestic', 'ditch' => 'Ditch / irrigation company', 'well' => 'Well', 'unsure' => 'Not sure']],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_notice_version' => ['type' => 'string', 'label' => 'Notice version (hash of disclaimer shown)', 'weight' => 45, 'storage' => ['max_length' => 64], 'widget' => 'string_textfield', 'formatter' => 'string'],
];

$repo = \Drupal::service('entity_display.repository');
$formDisplay = $repo->getFormDisplay($ENTITY, $BUNDLE, 'default');
$viewDisplay = $repo->getViewDisplay($ENTITY, $BUNDLE, 'default');

foreach ($fields as $name => $def) {
  // Field storage.
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    $storageValues = [
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'type' => $def['type'],
      'cardinality' => 1,
    ];
    if (!empty($def['storage'])) {
      $storageValues['settings'] = array_diff_key($def['storage'], ['allowed_values' => NULL]);
      if (isset($def['storage']['target_type'])) {
        $storageValues['settings'] = ['target_type' => $def['storage']['target_type']];
      }
      if (isset($def['storage']['max_length'])) {
        $storageValues['settings']['max_length'] = $def['storage']['max_length'];
      }
      if (isset($def['storage']['allowed_values'])) {
        $storageValues['settings']['allowed_values'] = $def['storage']['allowed_values'];
      }
    }
    FieldStorageConfig::create($storageValues)->save();
    printf("  storage created: %s (%s)\n", $name, $def['type']);
  }
  else {
    printf("  storage exists:  %s\n", $name);
  }

  // Field instance.
  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    $instanceValues = [
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => $def['label'],
      'required' => FALSE,
    ];
    if (!empty($def['instance'])) {
      $instanceValues['settings'] = $def['instance'];
    }
    FieldConfig::create($instanceValues)->save();
    printf("    instance created: %s.%s.%s\n", $ENTITY, $BUNDLE, $name);
  }
  else {
    printf("    instance exists:  %s\n", $name);
  }

  // Displays.
  $formDisplay->setComponent($name, ['type' => $def['widget'], 'weight' => $def['weight'], 'region' => 'content']);
  $viewDisplay->setComponent($name, ['type' => $def['formatter'], 'weight' => $def['weight'], 'label' => 'inline', 'region' => 'content']);
}
$formDisplay->save();
$viewDisplay->save();
print "  form + view displays saved\n";

// ── 4. Confirm field_source landed as module: options (Views-filter gotcha) ──
$srcStorage = FieldStorageConfig::loadByName($ENTITY, 'field_source');
printf("  field_source provider module = %s (must be 'options')\n", $srcStorage ? $srcStorage->getTypeProvider() : '?');

// ── 5. Grant ECK entity permissions to office roles (created after entity) ──
// Office roles can view/edit/delete, and create (legitimate office/phone intake
// — field_source includes 'phone'/'office'). Anonymous is granted NOTHING; the
// public path creates programmatically as uid 0 without a permission.
$eckPerms = [
  'create ' . $ENTITY . ' entities',
  'view any ' . $ENTITY . ' entities',
  'edit any ' . $ENTITY . ' entities',
  'delete any ' . $ENTITY . ' entities',
];
foreach (['administration', 'supervisor', 'site_admin'] as $rid) {
  $role = Role::load($rid);
  if (!$role) {
    printf("  role %s not found — skipped\n", $rid);
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
    printf("  granted ECK service_request perms to %s\n", $rid);
  }
  else {
    printf("  %s already has ECK service_request perms\n", $rid);
  }
}

print "DONE — service_request entity + bundle + fields + displays ready.\n";
