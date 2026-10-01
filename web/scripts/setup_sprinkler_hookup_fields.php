<?php

/**
 * @file
 * Add "Hookup Location" and "Hookup Type" to the water-source record.
 *
 * Where the compressor connects to blow the system out is the single most
 * winterizing-relevant fact about a property, and BOS had nowhere to put it —
 * checked across all six sprinkler entity types, nothing matching
 * hookup/blow/air/compressor/quick-connect existed.
 *
 * Location is free text because it is genuinely variable ("NE corner, behind the
 * hose bibb"). Type is a short select so it is one tap on a phone.
 *
 * ⚠ The type list is a first guess and is meant to be corrected — adjust it at
 * /admin/reports/fields or tell me the real list. Changing allowed_values later
 * does not disturb stored values that still appear in the list.
 *
 * Idempotent, all three source bundles. Per environment: field configs skip cim.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

const ENTITY = 'property_ss_sources';
const BUNDLES = ['domestic_source', 'dirty_water_source', 'well_water_source'];

$fields = [
  'field_ss_hookup_location' => [
    'type' => 'string',
    'label' => 'Hookup Location',
    'description' => 'Where the compressor connects to blow the system out.',
    'settings' => ['max_length' => 255],
    'widget' => ['type' => 'string_textfield', 'settings' => ['size' => 60, 'placeholder' => 'e.g. NE corner, behind the hose bibb']],
  ],
  'field_ss_hookup_type' => [
    'type' => 'list_string',
    'label' => 'Hookup Type',
    'description' => 'What you connect to.',
    'storage_settings' => ['allowed_values' => [
      'blow_out_port' => 'Blow-Out Port',
      'hose_bibb' => 'Hose Bibb',
      'quick_coupler' => 'Quick Coupler',
      'union' => 'Union',
      'petcock' => 'Petcock',
      'boiler_drain' => 'Boiler Drain',
      'other' => 'Other',
      'none' => 'None',
    ]],
    'widget' => ['type' => 'options_select', 'settings' => []],
  ],
];

foreach ($fields as $name => $spec) {
  $storage = FieldStorageConfig::loadByName(ENTITY, $name);
  if (!$storage) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => ENTITY,
      'type' => $spec['type'],
      'cardinality' => 1,
      'settings' => $spec['storage_settings'] ?? [],
    ])->save();
    print "created storage: $name\n";
  }
  else {
    print "storage ok: $name\n";
  }

  foreach (BUNDLES as $bundle) {
    if (FieldConfig::loadByName(ENTITY, $bundle, $name)) {
      printf("  instance ok  %s.%s\n", $bundle, $name);
      continue;
    }
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => ENTITY,
      'bundle' => $bundle,
      'label' => $spec['label'],
      'description' => $spec['description'],
      'required' => FALSE,
      'settings' => $spec['settings'] ?? [],
    ])->save();
    printf("  instance ADDED %s.%s\n", $bundle, $name);
  }
}

// Put them on the form and the view display, next to the shut-off fields.
$etm = \Drupal::entityTypeManager();
foreach (BUNDLES as $bundle) {
  $form = $etm->getStorage('entity_form_display')->load(ENTITY . '.' . $bundle . '.default');
  if ($form) {
    $w = 7;
    foreach ($fields as $name => $spec) {
      if (!$form->getComponent($name)) {
        $form->setComponent($name, ['type' => $spec['widget']['type'], 'weight' => $w, 'region' => 'content', 'settings' => $spec['widget']['settings']]);
      }
      $w++;
    }
    $form->save();
  }
  $view = $etm->getStorage('entity_view_display')->load(ENTITY . '.' . $bundle . '.default');
  if ($view) {
    $w = 7;
    foreach ($fields as $name => $spec) {
      if (!$view->getComponent($name)) {
        $view->setComponent($name, [
          'type' => $spec['type'] === 'list_string' ? 'list_default' : 'string',
          'weight' => $w, 'label' => 'inline', 'region' => 'content',
        ]);
      }
      $w++;
    }
    $view->save();
  }
}
print "\nform + view displays updated for all three source bundles.\n";
