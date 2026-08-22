<?php

/**
 * Idempotent: expose the /winterize signup open/close gate on the Business
 * Settings page (config_pages: business_setting) so the office can edit it in
 * the UI instead of via drush/config.
 *
 * Adds three fields in a "Public Service Requests" group and seeds them from the
 * current bos_service_request.settings gate so behaviour is preserved:
 *   field_winterize_signup_open (boolean)  ← bundles.sprinkler_winterizing.signup_open
 *   field_winterize_open_from   (date)     ← ...open_from
 *   field_winterize_open_until  (date)     ← ...open_until
 *
 * WinterizeForm reads these (falling back to the module config when absent).
 *
 *   drush php:script web/scripts/setup_winterize_gate_business_setting.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

$out = [];

$ensure = function (string $name, string $type, array $storageSettings, string $label, string $desc) use (&$out) {
  $storage = FieldStorageConfig::loadByName('config_pages', $name);
  if (!$storage) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => 'config_pages', 'type' => $type,
      'cardinality' => 1, 'settings' => $storageSettings,
    ])->save();
    $out[] = "created storage $name ($type)";
    $storage = FieldStorageConfig::loadByName('config_pages', $name);
  }
  if (!FieldConfig::loadByName('config_pages', 'business_setting', $name)) {
    FieldConfig::create([
      'field_storage' => $storage, 'bundle' => 'business_setting',
      'label' => $label, 'description' => $desc, 'required' => FALSE,
    ])->save();
    $out[] = "created instance $name on business_setting";
  }
};

$ensure('field_winterize_signup_open', 'boolean', [], 'Winterize Signup — Open',
  'When checked, the public /winterize form accepts submissions (within the optional open/close dates below). Uncheck to show the "signup is closed, call the office" message.');
$ensure('field_winterize_open_from', 'datetime', ['datetime_type' => 'date'], 'Winterize Signup — Opens On',
  'Optional. Before this date the form is closed even if "Open" is checked. Leave empty for no start bound.');
$ensure('field_winterize_open_until', 'datetime', ['datetime_type' => 'date'], 'Winterize Signup — Closes On',
  'Optional. After this date the form auto-closes. Leave empty for no end bound.');

// ── Form display: fields + a "Public Service Requests" details group ─────────
$fd = EntityFormDisplay::load('config_pages.business_setting.default');
if ($fd) {
  $fd->setComponent('field_winterize_signup_open', ['type' => 'boolean_checkbox', 'weight' => 0, 'region' => 'content', 'settings' => ['display_label' => TRUE], 'third_party_settings' => []]);
  $fd->setComponent('field_winterize_open_from', ['type' => 'datetime_default', 'weight' => 1, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  $fd->setComponent('field_winterize_open_until', ['type' => 'datetime_default', 'weight' => 2, 'region' => 'content', 'settings' => [], 'third_party_settings' => []]);
  $groups = $fd->getThirdPartySettings('field_group');
  $groups['group_public_service_requests'] = [
    'children' => ['field_winterize_signup_open', 'field_winterize_open_from', 'field_winterize_open_until'],
    'label' => 'Public Service Requests',
    'parent_name' => '',
    'region' => 'content',
    'weight' => 40,
    'format_type' => 'details',
    'format_settings' => [
      'classes' => '', 'show_empty_fields' => FALSE, 'id' => '',
      'description' => 'Controls the public /winterize signup form (open/closed + dates).',
      'open' => TRUE, 'required_fields' => TRUE,
    ],
  ];
  $fd->setThirdPartySetting('field_group', 'group_public_service_requests', $groups['group_public_service_requests']);
  $fd->save();
  $out[] = 'form: added Public Service Requests group with 3 fields';
}

// ── Seed values from the current module-config gate (only if unset) ──────────
$gate = \Drupal::config('bos_service_request.settings')->get('bundles.sprinkler_winterizing') ?? [];
$bs = \Drupal::service('config_pages.loader')->load('business_setting');
if ($bs) {
  $changed = FALSE;
  if ($bs->hasField('field_winterize_signup_open') && $bs->get('field_winterize_signup_open')->isEmpty()) {
    $bs->set('field_winterize_signup_open', !empty($gate['signup_open']) ? 1 : 0);
    $changed = TRUE;
  }
  if ($bs->hasField('field_winterize_open_from') && $bs->get('field_winterize_open_from')->isEmpty() && !empty($gate['open_from'])) {
    $bs->set('field_winterize_open_from', $gate['open_from']);
    $changed = TRUE;
  }
  if ($bs->hasField('field_winterize_open_until') && $bs->get('field_winterize_open_until')->isEmpty() && !empty($gate['open_until'])) {
    $bs->set('field_winterize_open_until', $gate['open_until']);
    $changed = TRUE;
  }
  if ($changed) {
    $bs->save();
    $out[] = sprintf('seeded gate: open=%s from=%s until=%s',
      !empty($gate['signup_open']) ? 'yes' : 'no', $gate['open_from'] ?? '(none)', $gate['open_until'] ?? '(none)');
  }
  else {
    $out[] = 'gate values already set — left as-is';
  }
}

echo "== setup_winterize_gate_business_setting ==\n";
foreach ($out as $l) {
  echo "  - $l\n";
}
