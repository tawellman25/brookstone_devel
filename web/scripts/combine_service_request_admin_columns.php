<?php

/**
 * Narrow the service_request_admin queue by combining columns (15 → 10):
 *   - Requester  = name + address + ZIP  (rewrite on the ZIP field)
 *   - Source     = source + campaign     (rewrite on the campaign field)
 *   - Opt-ins    = the 3 opt-in booleans (rewrite on the specific-date field;
 *                  each boolean shows a short label when set, blank when not)
 * Uses Views "Rewrite results" with tokens; the merged-away fields are kept as
 * excluded (still available as tokens). Idempotent; run per environment.
 *
 *   drush php:script web/scripts/combine_service_request_admin_columns.php
 */

$view = \Drupal::entityTypeManager()->getStorage('view')->load('service_request_admin');
if (!$view) {
  echo "service_request_admin view not found.\n";
  return;
}
$display = $view->get('display');
$fields = $display['default']['display_options']['fields'] ?? [];

$setRewrite = function (array &$f, string $text) {
  $f['exclude'] = FALSE;
  $f['alter'] = ($f['alter'] ?? []) + [];
  $f['alter']['alter_text'] = TRUE;
  $f['alter']['text'] = $text;
};

// Requester (name + address, zip) — rewrite on ZIP (last of the three so all
// three tokens are available above it); hide name + address.
if (isset($fields['field_submitted_zip'], $fields['field_submitted_name'], $fields['field_submitted_address'])) {
  $fields['field_submitted_zip']['label'] = 'Requester';
  $setRewrite($fields['field_submitted_zip'], '<strong>{{ field_submitted_name }}</strong><br>{{ field_submitted_address }}, {{ field_submitted_zip }}');
  $fields['field_submitted_name']['exclude'] = TRUE;
  $fields['field_submitted_address']['exclude'] = TRUE;
}

// Source (+ campaign) — rewrite on campaign (below source); hide source.
if (isset($fields['field_campaign'], $fields['field_source'])) {
  $fields['field_campaign']['label'] = 'Source';
  $setRewrite($fields['field_campaign'], '{{ field_source }}<br>{{ field_campaign }}');
  $fields['field_source']['exclude'] = TRUE;
}

// Opt-ins — custom boolean text (blank when unchecked), merged on the last one.
$boolCustom = function (array &$f, string $trueText) {
  $f['type'] = 'boolean';
  $f['settings'] = ['format' => 'custom', 'format_custom_true' => $trueText, 'format_custom_false' => ''];
};
if (isset($fields['field_wants_recurring'], $fields['field_wants_startup'], $fields['field_wants_specific_date'])) {
  $boolCustom($fields['field_wants_recurring'], 'Auto');
  $boolCustom($fields['field_wants_startup'], 'Spring start-up');
  $boolCustom($fields['field_wants_specific_date'], '⚠ Specific date');
  $fields['field_wants_recurring']['exclude'] = TRUE;
  $fields['field_wants_startup']['exclude'] = TRUE;
  $fields['field_wants_specific_date']['label'] = 'Opt-ins';
  $setRewrite($fields['field_wants_specific_date'], '{{ field_wants_recurring }} {{ field_wants_startup }} {{ field_wants_specific_date }}');
}

$display['default']['display_options']['fields'] = $fields;
$view->set('display', $display);
$view->save();

echo "Combined columns on service_request_admin: Requester, Source, Opt-ins.\n";
