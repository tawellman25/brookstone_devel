<?php

/**
 * Create the water_purveyor ECK entity + `purveyor` bundle + fields, and the
 * reference to it from a property.
 *
 * A water purveyor is who a backflow test report goes to. BOS had nowhere to
 * record one — nothing matching purveyor/provider/authority/district existed on
 * any entity.
 *
 * WHY the reference lives on the PROPERTY, not the city: the service area does
 * not follow the town line. Part of Cedaredge is on Orchard City domestic water,
 * so a city-level answer would be confidently wrong for those addresses, and
 * wrong in the direction that matters — a report sent to the wrong authority.
 *
 * The purveyor still records which towns it serves (Orchard City covers three),
 * and that list is used to SUGGEST a default when a property has none set. The
 * property's own value always wins. Same shape as the sprinkler system type:
 * the specific record beats the general one.
 *
 * Residential backflows are coming, so this is built to scale from 23 devices to
 * thousands: one purveyor record, referenced, rather than a name retyped per
 * property.
 *
 * WHY a script not cim: ECK/field configs skip cim (BOS standard). Run per env.
 *   ddev drush php:script web/scripts/setup_water_purveyor_entity.php
 */

use Drupal\eck\Entity\EckEntityType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

const ENTITY = 'water_purveyor';
const BUNDLE = 'purveyor';

$etm = \Drupal::entityTypeManager();

// --- 1. Entity type ----------------------------------------------------------
if (!EckEntityType::load(ENTITY)) {
  EckEntityType::create([
    'id' => ENTITY,
    'label' => 'Water Purveyor',
    'description' => 'A water provider or district that backflow test reports are submitted to.',
    'uid' => TRUE,
    'created' => TRUE,
    'changed' => TRUE,
    'title' => TRUE,
    'standalone_url' => TRUE,
  ])->save();
  print "created ECK entity type: " . ENTITY . "\n";
}
else {
  print "ECK entity type exists: " . ENTITY . "\n";
}
$etm->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// --- 2. Bundle ---------------------------------------------------------------
$bundleStorage = $etm->getStorage(ENTITY . '_type');
if (!$bundleStorage->load(BUNDLE)) {
  $bundleStorage->create([
    'type' => BUNDLE,
    'name' => 'Purveyor',
    'description' => 'One water provider or district.',
  ])->save();
  print "created bundle: " . ENTITY . '.' . BUNDLE . "\n";
}
else {
  print "bundle exists: " . ENTITY . '.' . BUNDLE . "\n";
}
$etm->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

// --- 3. Fields ---------------------------------------------------------------
$fields = [
  'field_purveyor_cities' => [
    'type' => 'entity_reference',
    'cardinality' => -1,
    'label' => 'Towns served',
    'description' => 'Which towns this purveyor serves. Used to suggest a default on a new property — a property\'s own purveyor always wins, because service areas cross town lines.',
    'storage_settings' => ['target_type' => 'city'],
    'settings' => ['handler' => 'default:city', 'handler_settings' => []],
    'widget' => ['type' => 'entity_reference_autocomplete_tags', 'settings' => []],
  ],
  'field_purveyor_submit_method' => [
    'type' => 'list_string',
    'cardinality' => 1,
    'label' => 'How reports are submitted',
    'storage_settings' => ['allowed_values' => [
      'email' => 'Email',
      'portal' => 'Online portal',
      'mail' => 'Mail',
      'in_person' => 'In person',
      'none' => 'Does not require reports',
    ]],
    'widget' => ['type' => 'options_select', 'settings' => []],
  ],
  'field_purveyor_email' => [
    'type' => 'email',
    'cardinality' => 1,
    'label' => 'Report submission email',
    'description' => 'Where test reports are sent.',
    'widget' => ['type' => 'email_default', 'settings' => []],
  ],
  'field_purveyor_url' => [
    'type' => 'link',
    'cardinality' => 1,
    'label' => 'Submission portal',
    'storage_settings' => [],
    'settings' => ['link_type' => 17, 'title' => 1],
    'widget' => ['type' => 'link_default', 'settings' => []],
  ],
  'field_purveyor_phone' => [
    'type' => 'telephone',
    'cardinality' => 1,
    'label' => 'Phone',
    'widget' => ['type' => 'telephone_default', 'settings' => []],
  ],
  'field_purveyor_contact' => [
    'type' => 'string',
    'cardinality' => 1,
    'label' => 'Contact name',
    'settings' => ['max_length' => 255],
    'widget' => ['type' => 'string_textfield', 'settings' => ['size' => 60]],
  ],
  'field_purveyor_notes' => [
    'type' => 'string_long',
    'cardinality' => 1,
    'label' => 'Notes',
    'description' => 'Anything a tester needs to know — deadlines, forms, quirks.',
    'widget' => ['type' => 'string_textarea', 'settings' => ['rows' => 4]],
  ],
];

foreach ($fields as $name => $spec) {
  if (!FieldStorageConfig::loadByName(ENTITY, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => ENTITY,
      'type' => $spec['type'],
      'cardinality' => $spec['cardinality'],
      'settings' => $spec['storage_settings'] ?? [],
    ])->save();
    print "  created storage: $name\n";
  }
  if (!FieldConfig::loadByName(ENTITY, BUNDLE, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => ENTITY,
      'bundle' => BUNDLE,
      'label' => $spec['label'],
      'description' => $spec['description'] ?? '',
      'required' => FALSE,
      'settings' => $spec['settings'] ?? [],
    ])->save();
    printf("  added field:     %s\n", $name);
  }
}

// --- 4. The reference FROM a property ----------------------------------------
// This is the authoritative answer for a given address.
if (!FieldStorageConfig::loadByName('properties', 'field_water_purveyor')) {
  FieldStorageConfig::create([
    'field_name' => 'field_water_purveyor',
    'entity_type' => 'properties',
    'type' => 'entity_reference',
    'cardinality' => 1,
    'settings' => ['target_type' => ENTITY],
  ])->save();
  print "  created storage: properties.field_water_purveyor\n";
}
foreach (['property', 'hoa'] as $propBundle) {
  if (!FieldConfig::loadByName('properties', $propBundle, 'field_water_purveyor')) {
    FieldConfig::create([
      'field_name' => 'field_water_purveyor',
      'entity_type' => 'properties',
      'bundle' => $propBundle,
      'label' => 'Water Purveyor',
      'description' => 'Who backflow test reports for this property go to. Leave empty to use whoever serves this town.',
      'required' => FALSE,
      'settings' => ['handler' => 'default:' . ENTITY, 'handler_settings' => ['target_bundles' => [BUNDLE => BUNDLE]]],
    ])->save();
    printf("  added field:     properties.%s.field_water_purveyor\n", $propBundle);
  }
}

// --- 5. Displays -------------------------------------------------------------
$formDisplay = $etm->getStorage('entity_form_display')->load(ENTITY . '.' . BUNDLE . '.default')
  ?: $etm->getStorage('entity_form_display')->create([
    'targetEntityType' => ENTITY, 'bundle' => BUNDLE, 'mode' => 'default', 'status' => TRUE,
  ]);
$viewDisplay = $etm->getStorage('entity_view_display')->load(ENTITY . '.' . BUNDLE . '.default')
  ?: $etm->getStorage('entity_view_display')->create([
    'targetEntityType' => ENTITY, 'bundle' => BUNDLE, 'mode' => 'default', 'status' => TRUE,
  ]);
$w = 0;
foreach ($fields as $name => $spec) {
  if (!$formDisplay->getComponent($name)) {
    $formDisplay->setComponent($name, ['type' => $spec['widget']['type'], 'weight' => $w, 'region' => 'content', 'settings' => $spec['widget']['settings']]);
  }
  if (!$viewDisplay->getComponent($name)) {
    $viewDisplay->setComponent($name, ['label' => 'inline', 'weight' => $w, 'region' => 'content', 'settings' => []]);
  }
  $w++;
}
$formDisplay->save();
$viewDisplay->save();
print "  form + view displays set\n";

// Put the purveyor on the property form, near the zipcode.
$propForm = $etm->getStorage('entity_form_display')->load('properties.property.default');
if ($propForm && !$propForm->getComponent('field_water_purveyor')) {
  $propForm->setComponent('field_water_purveyor', [
    'type' => 'entity_reference_autocomplete', 'weight' => 12, 'region' => 'content',
    'settings' => ['match_operator' => 'CONTAINS', 'size' => 60, 'placeholder' => ''],
  ])->save();
  print "  added to the property form\n";
}

// --- 6. Permissions ----------------------------------------------------------
// Office maintains them; crew need to read one (a tester needs the submission
// details). Nobody gets delete: a purveyor referenced by a filed report should
// not vanish.
$officeRoles = ['supervisor', 'administration', 'site_assistant', 'site_admin', 'administrator'];
foreach ($officeRoles as $rid) {
  if ($role = Role::load($rid)) {
    foreach ([
      'view any ' . ENTITY . ' entities',
      'create ' . ENTITY . ' entities',
      'edit any ' . ENTITY . ' entities',
      'access ' . ENTITY . ' entity listing',
    ] as $perm) {
      $role->grantPermission($perm);
    }
    $role->save();
  }
}
if ($crew = Role::load('teammates')) {
  $crew->grantPermission('view any ' . ENTITY . ' entities');
  $crew->save();
}
print "  permissions granted (office: full; crew: view; nobody: delete)\n";

printf("\nDone. %s.%s is ready.\n", ENTITY, BUNDLE);
