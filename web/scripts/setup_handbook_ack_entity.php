<?php

/**
 * Create the handbook_acknowledgment ECK entity type, its `acknowledgment`
 * bundle, fields, and displays — idempotently, via the entity API.
 *
 * WHY a script and not cim: ECK field instances silently skip on cim. Entity API
 * is the reliable, idempotent landing path (BOS standard). Run per env.
 *
 * This is an APPEND-ONLY legal record — one entity per acknowledgment event.
 * Edits are blocked by bos_handbook_ack_entity_presave().
 *
 *   ddev drush php:script web/scripts/setup_handbook_ack_entity.php   (local)
 *   drush php:script web/scripts/setup_handbook_ack_entity.php        (live)
 */

use Drupal\eck\Entity\EckEntityType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\Role;

$ENTITY = 'handbook_acknowledgment';
$BUNDLE = 'acknowledgment';

// ── 1. ECK entity type ──────────────────────────────────────────────────────
if (!EckEntityType::load($ENTITY)) {
  EckEntityType::create([
    'id' => $ENTITY,
    'label' => 'Handbook Acknowledgment',
    'description' => 'Append-only record: a staff member acknowledged a specific handbook version. Legal record — never edited.',
    'uid' => TRUE,
    'created' => TRUE,
    'changed' => TRUE,
    'title' => TRUE,
    'standalone_url' => TRUE,
  ])->save();
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
    'name' => 'Acknowledgment',
    'description' => 'A single handbook acknowledgment event.',
  ])->save();
  print "created bundle: $ENTITY.$BUNDLE\n";
}
else {
  print "bundle exists: $ENTITY.$BUNDLE\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// ── 3. Fields ───────────────────────────────────────────────────────────────
$fields = [
  'field_user' => [
    'type' => 'entity_reference', 'label' => 'Staff member', 'weight' => 0,
    'storage' => ['target_type' => 'user'], 'instance' => ['handler' => 'default:user'],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_acknowledged_on' => ['type' => 'timestamp', 'label' => 'Acknowledged on', 'weight' => 1, 'widget' => 'datetime_timestamp', 'formatter' => 'timestamp'],
  'field_handbook_version' => ['type' => 'string', 'label' => 'Handbook version acknowledged', 'weight' => 2, 'storage' => ['max_length' => 64], 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_typed_name' => ['type' => 'string', 'label' => 'Typed name (e-signature)', 'weight' => 3, 'storage' => ['max_length' => 255], 'widget' => 'string_textfield', 'formatter' => 'string'],
  'field_ip' => ['type' => 'string', 'label' => 'IP address', 'weight' => 4, 'storage' => ['max_length' => 45], 'widget' => 'string_textfield', 'formatter' => 'string'],
];

$repo = \Drupal::service('entity_display.repository');
$formDisplay = $repo->getFormDisplay($ENTITY, $BUNDLE, 'default');
$viewDisplay = $repo->getViewDisplay($ENTITY, $BUNDLE, 'default');

foreach ($fields as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    $storageValues = ['field_name' => $name, 'entity_type' => $ENTITY, 'type' => $def['type'], 'cardinality' => 1];
    if (isset($def['storage']['target_type'])) {
      $storageValues['settings'] = ['target_type' => $def['storage']['target_type']];
    }
    if (isset($def['storage']['max_length'])) {
      $storageValues['settings']['max_length'] = $def['storage']['max_length'];
    }
    FieldStorageConfig::create($storageValues)->save();
    printf("  storage created: %s (%s)\n", $name, $def['type']);
  }
  else {
    printf("  storage exists:  %s\n", $name);
  }

  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    $instanceValues = [
      'field_name' => $name, 'entity_type' => $ENTITY, 'bundle' => $BUNDLE,
      'label' => $def['label'], 'required' => FALSE,
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

  $formDisplay->setComponent($name, ['type' => $def['widget'], 'weight' => $def['weight'], 'region' => 'content']);
  $viewDisplay->setComponent($name, ['type' => $def['formatter'], 'weight' => $def['weight'], 'label' => 'inline', 'region' => 'content']);
}
$formDisplay->save();
$viewDisplay->save();
print "  form + view displays saved\n";

// ── 4. Permissions — VIEW only to office/ops roles (append-only: no broad
//      edit/delete; edits blocked in code, delete stays superuser-only). ──
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
