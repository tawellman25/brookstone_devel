<?php

declare(strict_types=1);

/**
 * Treat system.logging as environment-specific config.
 *
 * error_level is not a shared setting: production hides PHP warnings from
 * visitors, development shows them. Adding it to config_ignore lets the two
 * environments differ permanently without a partial-cim silently reverting either
 * — the same treatment s3fs.settings, stage_file_proxy.settings and the key
 * entities already get.
 *
 * Without this, config/sync carries `error_level: all`, so any future partial
 * import of system.logging would put customer-visible stack traces back on live.
 *
 * Idempotent. Run per env.
 *   drush php:script web/scripts/ignore_system_logging.php
 */

$config = \Drupal::configFactory()->getEditable('config_ignore.settings');
$list = $config->get('ignored_config_entities') ?: [];

if (in_array('system.logging', $list, TRUE)) {
  print "system.logging already ignored\n";
}
else {
  $list[] = 'system.logging';
  sort($list);
  $config->set('ignored_config_entities', $list)->save();
  print "added system.logging to config_ignore\n";
}

print "ignored now:\n";
foreach (\Drupal::config('config_ignore.settings')->get('ignored_config_entities') as $i) {
  print "  - $i\n";
}
