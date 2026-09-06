<?php

/**
 * Add campaign code(s) to the public service-request allowlist so `?c=<code>`
 * is stored on the booking (non-allowlisted codes fall back to "unknown").
 * Idempotent. Active-config edit (not cim). Run per env.
 *
 * Codes to add can be passed via env: BOS_CAMPAIGN_CODES="fb26,goog26"
 * Default: fb26.
 *   ddev drush php:script web/scripts/add_campaign_codes.php
 */

$codes = array_filter(array_map('trim', explode(',', getenv('BOS_CAMPAIGN_CODES') ?: 'fb26')));
$config = \Drupal::configFactory()->getEditable('bos_service_request.settings');
$list = $config->get('campaigns') ?: [];
$added = [];
foreach ($codes as $c) {
  $c = preg_replace('/[^A-Za-z0-9_\-]/', '', $c);
  if ($c !== '' && !in_array($c, $list, TRUE)) {
    $list[] = $c;
    $added[] = $c;
  }
}
if ($added) {
  $config->set('campaigns', $list)->save();
}
print 'added: ' . (implode(',', $added) ?: '(none)') . "\n";
print 'allowlist now: ' . implode(', ', $list) . "\n";
