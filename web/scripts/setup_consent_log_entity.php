<?php

/**
 * Create the consent_log ECK entity type + `log` bundle + fields, idempotently.
 *
 * Append-only audit trail of Contact opt-in changes — one row per flag change,
 * capturing channel, type, old->new state, source, actor, time, IP, note. This
 * is the provenance the boolean flags on Contact can't give (when/who/why, and
 * customer opt-out vs bad import). Written automatically by bos_consent_log when
 * a Contact opt-in flag changes; edits blocked in code.
 *
 * WHY a script not cim: ECK/field configs skip cim (BOS standard). Run per env.
 *   ddev drush php:script web/scripts/setup_consent_log_entity.php
 */

use Drupal\eck\Entity\EckEntityType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\Role;

$ENTITY = 'consent_log';
$BUNDLE = 'log';

// 1. ECK entity type.
if (!EckEntityType::load($ENTITY)) {
  EckEntityType::create([
    'id' => $ENTITY,
    'label' => 'Consent Log',
    'description' => 'Append-only audit trail of contact opt-in/consent changes. System-written; never edited.',
    'uid' => TRUE,
    'created' => TRUE,
    'changed' => FALSE,
    'title' => TRUE,
    'standalone_url' => FALSE,
  ])->save();
  print "created ECK entity type: $ENTITY\n";
}
else {
  print "ECK entity type exists: $ENTITY\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// 2. Bundle.
$bundleStorage = \Drupal::entityTypeManager()->getStorage($ENTITY . '_type');
if (!$bundleStorage->load($BUNDLE)) {
  $bundleStorage->create([
    'type' => $BUNDLE,
    'name' => 'Log',
    'description' => 'A single consent-change event.',
  ])->save();
  print "created bundle: $ENTITY.$BUNDLE\n";
}
else {
  print "bundle exists: $ENTITY.$BUNDLE\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// 3. Fields.
$STATES = ['unknown' => 'Unknown', 'opted_in' => 'Opted in', 'opted_out' => 'Opted out'];
$fields = [
  'field_contact' => [
    'type' => 'entity_reference', 'label' => 'Contact', 'weight' => 0,
    'storage' => ['target_type' => 'contacts'],
    'instance' => ['handler' => 'default:contacts', 'handler_settings' => ['target_bundles' => ['contact' => 'contact']]],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_channel' => [
    'type' => 'list_string', 'label' => 'Channel', 'weight' => 1,
    'storage' => ['allowed_values' => ['email' => 'Email', 'sms' => 'Text / SMS']],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_consent_type' => [
    'type' => 'list_string', 'label' => 'Consent type', 'weight' => 2,
    'storage' => ['allowed_values' => ['marketing' => 'Marketing', 'service' => 'Service / transactional']],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_old_state' => [
    'type' => 'list_string', 'label' => 'Previous state', 'weight' => 3,
    'storage' => ['allowed_values' => $STATES],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_new_state' => [
    'type' => 'list_string', 'label' => 'New state', 'weight' => 4,
    'storage' => ['allowed_values' => $STATES],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_event_source' => [
    'type' => 'list_string', 'label' => 'Source', 'weight' => 5,
    'storage' => ['allowed_values' => [
      'web_form' => 'Web form', 'phone' => 'Phone', 'paper' => 'Paper / in person',
      'import' => 'Data import', 'staff' => 'Entered by staff', 'system' => 'System',
    ]],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_actor' => [
    'type' => 'entity_reference', 'label' => 'Changed by', 'weight' => 6,
    'storage' => ['target_type' => 'user'], 'instance' => ['handler' => 'default:user'],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_ip' => [
    'type' => 'string', 'label' => 'IP address', 'weight' => 7,
    'storage' => ['max_length' => 45], 'widget' => 'string_textfield', 'formatter' => 'string',
  ],
  'field_note' => [
    'type' => 'string', 'label' => 'Note', 'weight' => 8,
    'storage' => ['max_length' => 255], 'widget' => 'string_textfield', 'formatter' => 'string',
  ],
];

$repo = \Drupal::service('entity_display.repository');
$formDisplay = $repo->getFormDisplay($ENTITY, $BUNDLE, 'default');
$viewDisplay = $repo->getViewDisplay($ENTITY, $BUNDLE, 'default');

foreach ($fields as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    $storageValues = ['field_name' => $name, 'entity_type' => $ENTITY, 'type' => $def['type'], 'cardinality' => 1];
    if (!empty($def['storage'])) {
      $storageValues['settings'] = $def['storage'];
    }
    FieldStorageConfig::create($storageValues)->save();
    printf("  storage created: %s (%s)\n", $name, $def['type']);
  }
  else {
    printf("  storage exists:  %s\n", $name);
  }

  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    $instanceValues = ['field_name' => $name, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE, 'label' => $def['label'], 'required' => FALSE];
    if (!empty($def['instance'])) {
      $instanceValues['settings'] = $def['instance'];
    }
    FieldConfig::create($instanceValues)->save();
    printf("    instance created: %s\n", $name);
  }
  else {
    printf("    instance exists:  %s\n", $name);
  }

  $formDisplay->setComponent($name, ['type' => $def['widget'], 'weight' => $def['weight'], 'region' => 'content']);
  $viewDisplay->setComponent($name, ['type' => $def['formatter'], 'weight' => $def['weight'], 'label' => 'inline', 'region' => 'content']);
}
$formDisplay->save();
$viewDisplay->save();
print "  form + view displays saved\n";

// 4. View permission to office/ops roles (append-only: no edit/delete grants).
$viewPerm = 'view any ' . $ENTITY . ' entities';
foreach (['administration', 'supervisor', 'site_assistant', 'site_admin'] as $rid) {
  $role = Role::load($rid);
  if ($role && !$role->hasPermission($viewPerm)) {
    $role->grantPermission($viewPerm);
    $role->save();
    printf("  granted '%s' to %s\n", $viewPerm, $rid);
  }
}
print "Done.\n";
