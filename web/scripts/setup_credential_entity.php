<?php

declare(strict_types=1);

/**
 * Create the `credential` ECK entity type + bundle + fields (Gate 1, stage 2).
 *
 * One entity covering company AND personal credentials, with scope STATED rather
 * than inferred from an empty teammate reference — an empty field cannot be told
 * apart from an unfinished record. Same correction as field_is_testable on the
 * backflow device types: explicit over implicit.
 *
 * field_teammate targets **user**, not teammate_profile (Gate 0 finding B1,
 * confirmed by Todd): both existing field_teammate instances target user, nothing
 * in BOS references teammate_profile as a target, and "my credentials" keys off
 * the current user directly.
 *
 * Credentials are never deleted — an expired one is history a filed report may
 * reference. Lifecycle runs through field_status; renewal creates a NEW record
 * and points the old one at it via field_superseded_by.
 *
 * Idempotent; ECK/field configs skip cim, so this script is the deploy path.
 *   drush php:script web/scripts/setup_credential_entity.php
 */

use Drupal\eck\Entity\EckEntityType;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\Entity\Role;

$ENTITY = 'credential';
$BUNDLE = 'credential';

if (!EckEntityType::load($ENTITY)) {
  EckEntityType::create([
    'id' => $ENTITY,
    'label' => 'Credential',
    'description' => 'A license, certification or insurance policy held by the company or by a teammate. Never deleted; renewal supersedes.',
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

$bundleStorage = \Drupal::entityTypeManager()->getStorage($ENTITY . '_type');
if (!$bundleStorage->load($BUNDLE)) {
  $bundleStorage->create([
    'type' => $BUNDLE,
    'name' => 'Credential',
    'description' => 'A single credential record.',
  ])->save();
  print "created bundle: $ENTITY.$BUNDLE\n";
}
else {
  print "bundle exists: $ENTITY.$BUNDLE\n";
}
\Drupal::entityTypeManager()->clearCachedDefinitions();
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();

$SCOPES = ['company' => 'Company-wide', 'teammate' => 'Individual teammate'];
$STATUSES = [
  'active' => 'Active',
  'pending_renewal' => 'Pending renewal',
  'expired' => 'Expired',
  'superseded' => 'Superseded',
  'void' => 'Void',
];

$fields = [
  'field_credential_type' => [
    'type' => 'entity_reference', 'label' => 'Credential type', 'weight' => 0, 'required' => TRUE,
    'storage' => ['target_type' => 'taxonomy_term'],
    'instance' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['credential_types' => 'credential_types']]],
    'widget' => 'options_select', 'formatter' => 'entity_reference_label',
  ],
  'field_scope' => [
    'type' => 'list_string', 'label' => 'Scope', 'weight' => 1, 'required' => TRUE,
    'desc' => 'Company-wide, or held by one teammate. Stated, never inferred — choose one.',
    'storage' => ['allowed_values' => $SCOPES],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_teammate' => [
    'type' => 'entity_reference', 'label' => 'Teammate', 'weight' => 2,
    'desc' => 'Required when scope is Individual teammate; must be empty when scope is Company-wide.',
    'storage' => ['target_type' => 'user'],
    'instance' => ['handler' => 'default:user', 'handler_settings' => ['include_anonymous' => FALSE, 'filter' => ['type' => 'role', 'role' => ['teammates' => 'teammates']]]],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_credential_number' => [
    'type' => 'string', 'label' => 'Credential number', 'weight' => 3,
    'desc' => 'License, certification or policy number.',
    'widget' => 'string_textfield', 'formatter' => 'string',
  ],
  'field_issuing_authority' => [
    'type' => 'string', 'label' => 'Issuing authority', 'weight' => 4,
    'desc' => 'CDA, ABPA, USDOT, or the insurance carrier. Defaults from the type; override per record because carriers change.',
    'widget' => 'string_textfield', 'formatter' => 'string',
  ],
  'field_coverage_limits' => [
    'type' => 'string', 'label' => 'Coverage limits', 'weight' => 5,
    'desc' => 'Insurance only — e.g. $1,000,000 per occurrence / $2,000,000 aggregate.',
    'widget' => 'string_textfield', 'formatter' => 'string',
  ],
  'field_issue_date' => [
    'type' => 'datetime', 'label' => 'Issue date', 'weight' => 6,
    'storage' => ['datetime_type' => 'date'],
    'widget' => 'datetime_default', 'formatter' => 'datetime_default',
  ],
  'field_expiration_date' => [
    'type' => 'datetime', 'label' => 'Expiration date', 'weight' => 7,
    'desc' => 'Leave empty for credentials that do not expire (USDOT, some registrations).',
    'storage' => ['datetime_type' => 'date'],
    'widget' => 'datetime_default', 'formatter' => 'datetime_default',
  ],
  'field_status' => [
    'type' => 'list_string', 'label' => 'Status', 'weight' => 8, 'default' => 'active',
    'storage' => ['allowed_values' => $STATUSES],
    'widget' => 'options_select', 'formatter' => 'list_default',
  ],
  'field_superseded_by' => [
    'type' => 'entity_reference', 'label' => 'Superseded by', 'weight' => 9,
    'desc' => 'The renewal record that replaced this one. Renewal creates a new record; it never overwrites this one.',
    'storage' => ['target_type' => 'credential'],
    'instance' => ['handler' => 'default:credential', 'handler_settings' => ['target_bundles' => ['credential' => 'credential']]],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_credential_images' => [
    'type' => 'image', 'label' => 'Credential images', 'weight' => 10, 'cardinality' => -1,
    'desc' => 'Photograph of a wallet card or a certificate — this is what is useful on a phone.',
    'widget' => 'image_image', 'formatter' => 'image',
  ],
  'field_credential_documents' => [
    'type' => 'file', 'label' => 'Credential documents', 'weight' => 11, 'cardinality' => -1,
    'desc' => 'The scanned certificate, policy, or current COI. Office/admin only.',
    'instance' => ['file_extensions' => 'pdf'],
    'widget' => 'file_generic', 'formatter' => 'file_default',
  ],
  'field_public_description' => [
    'type' => 'text_long', 'label' => 'Public description', 'weight' => 12,
    'desc' => 'Customer-facing explanation — what this credential is and why it matters.',
    'widget' => 'text_textarea', 'formatter' => 'text_default',
  ],
  'field_internal_notes' => [
    'type' => 'text_long', 'label' => 'Internal notes', 'weight' => 13,
    'desc' => 'How the renewal actually works, who handles it, what went wrong last time. Never public.',
    'widget' => 'text_textarea', 'formatter' => 'text_default',
  ],
  'field_renewal_contact' => [
    'type' => 'entity_reference', 'label' => 'Renewal contact', 'weight' => 14, 'cardinality' => -1,
    'desc' => 'Who to call to renew — the agent, plus the agency main line. Phone and address come from the contact record.',
    'storage' => ['target_type' => 'contacts'],
    'instance' => ['handler' => 'default:contacts', 'handler_settings' => ['target_bundles' => ['contact' => 'contact']]],
    'widget' => 'entity_reference_autocomplete', 'formatter' => 'entity_reference_label',
  ],
  'field_renewal_url' => [
    'type' => 'link', 'label' => 'Renewal URL', 'weight' => 15,
    'widget' => 'link_default', 'formatter' => 'link',
  ],
  'field_publish_publicly' => [
    'type' => 'boolean', 'label' => 'Publish on the public credentials page', 'weight' => 16, 'default' => 0,
    'desc' => 'Default off. The number publishes only if the TYPE also permits it.',
    'widget' => 'boolean_checkbox', 'formatter' => 'boolean',
  ],
];

$repo = \Drupal::service('entity_display.repository');
$formDisplay = $repo->getFormDisplay($ENTITY, $BUNDLE, 'default');
$viewDisplay = $repo->getViewDisplay($ENTITY, $BUNDLE, 'default');

foreach ($fields as $name => $def) {
  if (!FieldStorageConfig::loadByName($ENTITY, $name)) {
    $sv = [
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'type' => $def['type'],
      'cardinality' => $def['cardinality'] ?? 1,
    ];
    if (!empty($def['storage'])) {
      $sv['settings'] = $def['storage'];
    }
    FieldStorageConfig::create($sv)->save();
    printf("  storage created: %-28s (%s%s)\n", $name, $def['type'], ($def['cardinality'] ?? 1) === -1 ? ', multi' : '');
  }
  else {
    printf("  storage exists:  %-28s\n", $name);
  }

  if (!FieldConfig::loadByName($ENTITY, $BUNDLE, $name)) {
    $iv = [
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'bundle' => $BUNDLE,
      'label' => $def['label'],
      'description' => $def['desc'] ?? '',
      'required' => !empty($def['required']),
    ];
    if (!empty($def['instance'])) {
      $iv['settings'] = $def['instance'];
    }
    if (isset($def['default'])) {
      $iv['default_value'] = [['value' => $def['default']]];
    }
    FieldConfig::create($iv)->save();
    printf("    instance created: %-26s%s\n", $name, !empty($def['required']) ? ' REQUIRED' : '');
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

/* Permissions — discover the real ECK permission names, then grant.
   Todd 2026-09-27: ANY teammate may see credentials; office may manage. */
$all = array_keys(\Drupal::service('user.permissions')->getPermissions());
$credPerms = array_values(array_filter($all, fn($p) => str_contains($p, $ENTITY)));
print "  available permissions:\n";
foreach ($credPerms as $p) {
  print "    - $p\n";
}
$viewPerms = array_values(array_filter($credPerms, fn($p) => str_starts_with($p, 'view any')));
// Create + edit only. NO delete: credentials are permanent (an expired one is
// history a filed report may reference) — lifecycle runs through field_status and
// field_superseded_by. ECK's verb is "create", not "add".
$managePerms = array_values(array_filter($credPerms, fn($p) => preg_match('/^(create|edit any)/', $p)));
$deletePerms = array_values(array_filter($credPerms, fn($p) => str_starts_with($p, 'delete')));

$grant = function (string $rid, array $perms) {
  $role = Role::load($rid);
  if (!$role) { return; }
  $added = [];
  foreach ($perms as $p) {
    if (!$role->hasPermission($p)) {
      $role->grantPermission($p);
      $added[] = $p;
    }
  }
  if ($added) {
    $role->save();
    printf("  %-16s + %s\n", $rid, implode(', ', $added));
  }
};

$revoke = function (string $rid, array $perms) {
  $role = Role::load($rid);
  if (!$role) { return; }
  $removed = [];
  foreach ($perms as $p) {
    if ($role->hasPermission($p)) {
      $role->revokePermission($p);
      $removed[] = $p;
    }
  }
  if ($removed) {
    $role->save();
    printf("  %-16s - %s\n", $rid, implode(', ', $removed));
  }
};

$grant('teammates', $viewPerms);
foreach (['administration', 'supervisor', 'site_assistant', 'site_admin'] as $rid) {
  $grant($rid, array_merge($viewPerms, $managePerms));
  // Guard: never leave a delete grant in place on this entity type.
  $revoke($rid, $deletePerms);
}
$revoke('teammates', $deletePerms);
print "DONE.\n";
