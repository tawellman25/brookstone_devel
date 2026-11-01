<?php

/**
 * @file
 * Put the teammate dashboard on the profile page.
 *
 * The block itself decides who sees it — self-view only, crew only — so this
 * placement is deliberately simple. The request_path condition is belt and
 * braces: it keeps the access check off every other page rather than relying on
 * it to say no thousands of times a day.
 *
 * `/user/{user}` is NOT an admin route, so it renders in the DEFAULT theme for
 * everyone including crew — one placement covers it. Checked, not assumed.
 *
 * Placed ABOVE the profile body (weight -50): a crew member opening this page
 * wants today's work, not their account details.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/place_teammate_dashboard_block.php
 */

const BLOCK_ID = 'teammate_dashboard_profile';
const PLUGIN = 'teammate_dashboard';
const REGION = 'content';

$apply = getenv('BOS_APPLY') === '1';
$theme = \Drupal::config('system.theme')->get('default');
printf("default theme: %s\n", $theme);

// The plugin must exist, or the block renders as broken.
$definition = \Drupal::service('plugin.manager.block')->getDefinition(PLUGIN, FALSE);
if (!$definition) {
  printf("ABORT: block plugin '%s' not found — run a cache rebuild first.\n", PLUGIN);
  return;
}
print "block plugin found\n";

// The region must exist in this theme, or the block is placed nowhere.
$regions = system_region_list($theme);
if (!isset($regions[REGION])) {
  printf("ABORT: region '%s' does not exist in %s. Available: %s\n",
    REGION, $theme, implode(', ', array_keys($regions)));
  return;
}
printf("region '%s' exists\n", REGION);

$storage = \Drupal::entityTypeManager()->getStorage('block');
$existing = $storage->load(BLOCK_ID);
if ($existing) {
  printf("\nBlock '%s' already placed: theme=%s region=%s status=%s weight=%s\n",
    BLOCK_ID, $existing->getTheme(), $existing->getRegion(),
    $existing->status() ? 'on' : 'off', $existing->getWeight());
  print "Left untouched — this script never overwrites an existing placement.\n";
  return;
}

if (!$apply) {
  printf("\nDRY RUN — would place '%s' in %s/%s, visible on /user/*, weight -50.\n", PLUGIN, $theme, REGION);
  print "Re-run with BOS_APPLY=1 to write.\n";
  return;
}

$block = $storage->create([
  'id' => BLOCK_ID,
  'plugin' => PLUGIN,
  'theme' => $theme,
  'region' => REGION,
  'weight' => -50,
  'settings' => [
    'id' => PLUGIN,
    'label' => 'My work',
    // The panels carry their own headings; a block title would just repeat.
    'label_display' => '0',
    'provider' => 'user_teammate_profile',
  ],
  'visibility' => [
    'request_path' => [
      'id' => 'request_path',
      'negate' => FALSE,
      // The user page and nothing else. /user/*/edit is excluded by not
      // matching — only the canonical profile carries the dashboard.
      'pages' => "/user/*",
    ],
  ],
]);
$block->save();

$fresh = $storage->load(BLOCK_ID);
printf("\nPlaced. id=%s theme=%s region=%s weight=%s status=%s\n",
  $fresh->id(), $fresh->getTheme(), $fresh->getRegion(), $fresh->getWeight(),
  $fresh->status() ? 'on' : 'off');
printf("visibility: %s\n", json_encode($fresh->getVisibility()));
