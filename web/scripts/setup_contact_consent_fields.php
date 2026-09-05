<?php

/**
 * @file
 * Stage 1 of the Contact opt-in/consent model. Adds consent fields to
 * contacts.contact. Contact holds the PERSON + opt-ins ONLY (nothing secure);
 * all secure/business data stays on User + customer_profile. Split opt-ins:
 * marketing ("book now") vs service/transactional ("we're coming"), per channel
 * (email / SMS). A NULL field_consent_updated = "never asked / unknown" — which
 * marketing sends MUST treat as NOT opted in; service messages are unaffected.
 *
 * Idempotent; ECK/field configs skip cim, so this script is the deploy path.
 * Run: ddev drush php:script web/scripts/setup_contact_consent_fields.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$ET = 'contacts';
$BUNDLE = 'contact';

// Field specs: name => [type, label, description, storage settings, instance
// settings, form widget, view formatter, weight].
$fields = [
  'field_opt_in_marketing_email' => [
    'type' => 'boolean', 'label' => 'Marketing email OK',
    'desc' => 'Person has opted in to promotional/marketing email (campaigns, offers, win-back). Not required for service messages.',
    'store' => [], 'inst' => ['on_label' => 'Opted in', 'off_label' => 'No'],
    'widget' => 'boolean_checkbox', 'formatter' => 'boolean', 'weight' => 30,
  ],
  'field_opt_in_marketing_sms' => [
    'type' => 'boolean', 'label' => 'Marketing text/SMS OK',
    'desc' => 'Person has opted in to promotional/marketing text messages.',
    'store' => [], 'inst' => ['on_label' => 'Opted in', 'off_label' => 'No'],
    'widget' => 'boolean_checkbox', 'formatter' => 'boolean', 'weight' => 31,
  ],
  'field_opt_in_service_email' => [
    'type' => 'boolean', 'label' => 'Service email OK',
    'desc' => 'Person has opted in to transactional/service email (appointment reminders, "we\'re coming", service updates).',
    'store' => [], 'inst' => ['on_label' => 'Opted in', 'off_label' => 'No'],
    'widget' => 'boolean_checkbox', 'formatter' => 'boolean', 'weight' => 32,
  ],
  'field_opt_in_service_sms' => [
    'type' => 'boolean', 'label' => 'Service text/SMS OK',
    'desc' => 'Person has opted in to transactional/service text messages (appointment reminders, "we\'re coming").',
    'store' => [], 'inst' => ['on_label' => 'Opted in', 'off_label' => 'No'],
    'widget' => 'boolean_checkbox', 'formatter' => 'boolean', 'weight' => 33,
  ],
  'field_consent_updated' => [
    'type' => 'datetime', 'label' => 'Consent last updated',
    'desc' => 'When any opt-in above was last captured or changed. Empty = never asked / unknown (treat as NOT opted in for marketing).',
    'store' => ['datetime_type' => 'datetime'], 'inst' => [],
    'widget' => 'datetime_default', 'formatter' => 'datetime_default', 'weight' => 34,
  ],
  'field_consent_source' => [
    'type' => 'list_string', 'label' => 'Consent source',
    'desc' => 'How the opt-in was captured.',
    'store' => ['allowed_values' => [
      'web_form' => 'Web form',
      'phone' => 'Phone',
      'paper' => 'Paper / in person',
      'import' => 'Data import',
      'staff' => 'Entered by staff',
    ]],
    'inst' => [],
    'widget' => 'options_select', 'formatter' => 'list_default', 'weight' => 35,
  ],
];

foreach ($fields as $name => $spec) {
  if (!FieldStorageConfig::loadByName($ET, $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $ET,
      'type' => $spec['type'],
      'cardinality' => 1,
      'settings' => $spec['store'],
    ])->save();
    print "Created storage $ET.$name\n";
  }
  else {
    print "storage $name exists\n";
  }

  if (!FieldConfig::loadByName($ET, $BUNDLE, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $ET,
      'bundle' => $BUNDLE,
      'label' => $spec['label'],
      'description' => $spec['desc'],
      'settings' => $spec['inst'],
    ])->save();
    print "Created instance $ET.$BUNDLE.$name\n";
  }
  else {
    print "instance $name exists\n";
  }
}

// Place on the Contact default form + view displays.
$fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("$ET.$BUNDLE.default");
$vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load("$ET.$BUNDLE.default");
foreach ($fields as $name => $spec) {
  if ($fd && !$fd->getComponent($name)) {
    $fd->setComponent($name, [
      'type' => $spec['widget'],
      'weight' => $spec['weight'],
      'settings' => $spec['type'] === 'boolean' ? ['display_label' => TRUE] : [],
    ]);
    print "form: placed $name\n";
  }
  if ($vd && !$vd->getComponent($name)) {
    $vd->setComponent($name, [
      'type' => $spec['formatter'],
      'weight' => $spec['weight'],
      'label' => 'inline',
    ]);
    print "view: placed $name\n";
  }
}
if ($fd) { $fd->save(); }
if ($vd) { $vd->save(); }

print "--- uuids (patch into sync YAML if committing configs) ---\n";
foreach (array_keys($fields) as $name) {
  $s = \Drupal::entityTypeManager()->getStorage('field_storage_config')->load("$ET.$name");
  $i = \Drupal::entityTypeManager()->getStorage('field_config')->load("$ET.$BUNDLE.$name");
  printf("%-32s storage=%s instance=%s\n", $name, $s ? $s->uuid() : 'MISSING', $i ? $i->uuid() : 'MISSING');
}
print "DONE\n";
