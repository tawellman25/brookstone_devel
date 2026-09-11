<?php

/**
 * @file
 * /contact rebuild — the `general_inquiry` bundle on the service_request entity
 * (the /winterize intake pattern). Reuses existing service_request field
 * storages; adds field_topic (the routing field) + field_property_freeform.
 *
 * Idempotent; run per env:
 *   ddev drush php:script web/scripts/setup_contact_general_inquiry.php   (dev)
 *   drush php:script web/scripts/setup_contact_general_inquiry.php        (live)
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

$BUNDLE = 'general_inquiry';

// 1. Bundle — written in the OLD eck.eck_type.{entity_type}.{bundle} form to
// match the sibling service_request bundles. (EckEntityBundle::create() emits
// the newer eck.eck_entity_bundle.* form, which BOS does not use and which
// breaks bundle resolution when mixed — see CLAUDE.md "ECK config conventions".)
$cfgName = "eck.eck_type.service_request.{$BUNDLE}";
// Remove any stray newer-form config first (idempotent cleanup).
\Drupal::configFactory()->getEditable("eck.eck_entity_bundle.{$BUNDLE}")->delete();
if (\Drupal::config($cfgName)->isNew()) {
  \Drupal::configFactory()->getEditable($cfgName)->setData([
    'langcode' => 'en',
    'status' => TRUE,
    'dependencies' => ['config' => ['eck.eck_entity_type.service_request']],
    'name' => 'General Inquiry',
    'type' => $BUNDLE,
    'description' => 'Public contact-form submissions (/contact).',
  ])->save();
  \Drupal::service('entity_type.bundle.info')->clearCachedBundles();
  echo "• created bundle {$cfgName}\n";
}
else {
  echo "• bundle {$cfgName} exists\n";
}

// 2. field_topic — the routing field (list_string).
if (!FieldStorageConfig::loadByName('service_request', 'field_topic')) {
  FieldStorageConfig::create([
    'field_name' => 'field_topic',
    'entity_type' => 'service_request',
    'type' => 'list_string',
    'cardinality' => 1,
    'settings' => [
      'allowed_values' => [
        'work_question' => 'A question about work you are doing for me',
        'problem' => 'A problem with work that was done',
        'billing' => 'Billing or invoice question',
        'scheduling' => 'Scheduling change',
        'quote' => 'I would like a quote on something',
        'commercial' => 'Commercial, HOA, or property management inquiry',
        'other' => 'Something else',
      ],
    ],
  ])->save();
  echo "• created storage field_topic\n";
}

// 3. Reuse existing service_request storages on the new bundle + field_topic.
$reuse = [
  'field_submitted_name' => ['label' => 'Name', 'required' => TRUE],
  'field_submitted_email' => ['label' => 'Email', 'required' => TRUE],
  'field_submitted_phone' => ['label' => 'Phone', 'required' => TRUE],
  'field_submitted_address' => ['label' => 'Property address', 'required' => FALSE],
  'field_topic' => ['label' => 'What is this about?', 'required' => TRUE],
  'field_request_status' => ['label' => 'Status', 'required' => FALSE],
  'field_source' => ['label' => 'Source', 'required' => FALSE],
  'field_campaign' => ['label' => 'Campaign', 'required' => FALSE],
  'field_customer_notes' => ['label' => 'Message', 'required' => FALSE],
  'field_office_notes' => ['label' => 'Office notes', 'required' => FALSE],
  'field_review_flags' => ['label' => 'Review flags', 'required' => FALSE],
  'field_public_ref' => ['label' => 'Reference', 'required' => FALSE],
  'field_property' => ['label' => 'Property', 'required' => FALSE],
  'field_existing_work_order' => ['label' => 'Related work order', 'required' => FALSE],
];
foreach ($reuse as $field => $meta) {
  if (!FieldStorageConfig::loadByName('service_request', $field)) {
    echo "  ! storage {$field} missing — skipped (unexpected)\n";
    continue;
  }
  if (!FieldConfig::loadByName('service_request', $BUNDLE, $field)) {
    FieldConfig::create([
      'field_name' => $field,
      'entity_type' => 'service_request',
      'bundle' => $BUNDLE,
      'label' => $meta['label'],
      'required' => $meta['required'],
    ])->save();
    echo "  + general_inquiry.{$field}\n";
  }
}

echo "Done.\n";
