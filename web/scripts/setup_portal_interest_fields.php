<?php

/**
 * @file
 * Portal-waitlist fields on contacts.contact. These are a PRODUCT SIGNAL
 * ("this person wants the future customer portal"), NOT consent — so they are
 * plain fields, deliberately separate from the opt-in flags, and they never
 * touch the consent_log (bos_consent_log only watches the 4 opt-in flags).
 *
 * Idempotent; ECK/field configs skip cim (BOS standard). Run per env.
 *   ddev drush php:script web/scripts/setup_portal_interest_fields.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$ET = 'contacts';
$BUNDLE = 'contact';

$fields = [
  'field_portal_interest' => [
    'type' => 'boolean', 'label' => 'Portal waitlist interest',
    'desc' => 'Person asked to be told when the customer portal opens (a product signal, not consent).',
    'store' => [], 'inst' => ['on_label' => 'Interested', 'off_label' => 'No'],
    'widget' => 'boolean_checkbox', 'formatter' => 'boolean', 'weight' => 40,
  ],
  'field_portal_interest_date' => [
    'type' => 'datetime', 'label' => 'Portal interest recorded',
    'desc' => 'When the person joined the portal waitlist.',
    'store' => ['datetime_type' => 'datetime'], 'inst' => [],
    'widget' => 'datetime_default', 'formatter' => 'datetime_default', 'weight' => 41,
  ],
  'field_portal_interest_note' => [
    'type' => 'string_long', 'label' => 'Portal waitlist details',
    'desc' => 'Lead details captured at signup: mobile, service address, already-a-customer answer, campaign code.',
    'store' => [], 'inst' => [],
    'widget' => 'string_textarea', 'formatter' => 'basic_string', 'weight' => 42,
  ],
];

$repo = \Drupal::service('entity_display.repository');
$fd = $repo->getFormDisplay($ET, $BUNDLE, 'default');
$vd = $repo->getViewDisplay($ET, $BUNDLE, 'default');

foreach ($fields as $name => $spec) {
  if (!FieldStorageConfig::loadByName($ET, $name)) {
    $sv = ['field_name' => $name, 'entity_type' => $ET, 'type' => $spec['type'], 'cardinality' => 1];
    if (!empty($spec['store'])) { $sv['settings'] = $spec['store']; }
    FieldStorageConfig::create($sv)->save();
    print "storage created: $name\n";
  }
  else { print "storage exists: $name\n"; }

  if (!FieldConfig::loadByName($ET, $BUNDLE, $name)) {
    $iv = ['field_name' => $name, 'entity_type' => $ET, 'bundle' => $BUNDLE, 'label' => $spec['label'], 'description' => $spec['desc'], 'required' => FALSE];
    if (!empty($spec['inst'])) { $iv['settings'] = $spec['inst']; }
    FieldConfig::create($iv)->save();
    print "  instance created: $name\n";
  }
  else { print "  instance exists: $name\n"; }

  $fd->setComponent($name, ['type' => $spec['widget'], 'weight' => $spec['weight'], 'region' => 'content']);
  $vd->setComponent($name, ['type' => $spec['formatter'], 'weight' => $spec['weight'], 'label' => 'inline', 'region' => 'content']);
}
$fd->save();
$vd->save();
print "displays saved. DONE\n";
