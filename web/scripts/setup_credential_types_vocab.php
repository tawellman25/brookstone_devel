<?php

declare(strict_types=1);

/**
 * Create the credential_types vocabulary + its fields (Gate 1, stage 1).
 *
 * Type is a taxonomy, not free text, so "who holds a current backflow
 * certification?" is answerable by value rather than by spelling.
 *
 * NOTE (Gate 0 finding B2): the spec asked to reuse `field_type_code`, but
 * `taxonomy_term.field_type_code` already exists as a **list_string** whose
 * allowed_values are the 7 backflow device codes. A storage has one type per
 * entity type, so reusing it would make both vocabularies share one
 * allowed-values list (a backflow form would offer CDL). This uses a distinct
 * `field_credential_code` (string) instead. `field_public_description`
 * (text_long) IS genuinely reusable and is reused.
 *
 * Idempotent; ECK/field configs skip cim, so this script is the deploy path.
 *   drush php:script web/scripts/setup_credential_types_vocab.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;

$ENTITY = 'taxonomy_term';
$VID = 'credential_types';

if (!Vocabulary::load($VID)) {
  Vocabulary::create([
    'vid' => $VID,
    'name' => 'Credential Types',
    'description' => 'Kinds of company and personal credential — licenses, certifications, insurance policies. Drives scope defaults and whether a credential number may publish.',
  ])->save();
  print "created vocabulary: $VID\n";
}
else {
  print "vocabulary exists: $VID\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

$SCOPES = ['company' => 'Company-wide', 'teammate' => 'Individual teammate'];

$fields = [
  'field_credential_code' => [
    'type' => 'string', 'label' => 'Type code', 'weight' => 1,
    'desc' => 'Stable machine code. Logic keys off this, never the term ID.',
    'widget' => 'string_textfield', 'formatter' => 'string',
  ],
  'field_default_scope' => [
    'type' => 'list_string', 'label' => 'Default scope', 'weight' => 2,
    'desc' => 'Pre-selects scope on the credential form. A CDL is always personal; USDOT is always company.',
    'storage' => ['allowed_values' => $SCOPES],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_number_is_public' => [
    'type' => 'boolean', 'label' => 'Credential number may be published', 'weight' => 3,
    'desc' => 'License numbers are public record; insurance POLICY numbers are not. A credential number publishes only when this is checked AND the record is flagged publishable.',
    'widget' => 'boolean_checkbox', 'formatter' => 'boolean',
  ],
  'field_verification_url' => [
    'type' => 'link', 'label' => 'Verification URL', 'weight' => 4,
    'desc' => "The issuing agency's public lookup, so freshness is checked at the source.",
    'widget' => 'link_default', 'formatter' => 'link',
  ],
  'field_renewal_lead_days' => [
    'type' => 'integer', 'label' => 'Renewal lead days', 'weight' => 5,
    'desc' => 'How far ahead the expiring-soon view starts warning.',
    'widget' => 'number', 'formatter' => 'number_integer',
  ],
  'field_public_description' => [
    'type' => 'text_long', 'label' => 'Public Description', 'weight' => 6,
    'desc' => 'Default customer-facing explanation for this type; a record may override it.',
    'widget' => 'text_textarea', 'formatter' => 'text_default',
  ],
];

$repo = \Drupal::service('entity_display.repository');
$formDisplay = $repo->getFormDisplay($ENTITY, $VID, 'default');
$viewDisplay = $repo->getViewDisplay($ENTITY, $VID, 'default');

foreach ($fields as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    $sv = ['field_name' => $name, 'entity_type' => $ENTITY, 'type' => $def['type'], 'cardinality' => 1];
    if (!empty($def['storage'])) {
      $sv['settings'] = $def['storage'];
    }
    FieldStorageConfig::create($sv)->save();
    printf("  storage created: %-26s (%s)\n", $name, $def['type']);
  }
  else {
    printf("  storage reused:  %-26s\n", $name);
  }

  if (!FieldConfig::loadByName($ENTITY, $VID, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'bundle' => $VID,
      'label' => $def['label'],
      'description' => $def['desc'] ?? '',
      'required' => FALSE,
      'default_value' => $name === 'field_renewal_lead_days' ? [['value' => 60]] : [],
    ])->save();
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
print "DONE.\n";
