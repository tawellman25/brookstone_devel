<?php

/**
 * @file
 * Targeted cache refresh for a crew-hours deploy — everything `drush cr`
 * would do that a code deploy actually needs, without the full flush.
 *
 *   drush php:script web/scripts/targeted_refresh.php
 *
 * Why not `cr` during the day: a full flush empties the render, page, entity
 * and discovery caches at once, so every crew request for the next minutes
 * rebuilds them inside the request (1 GB LVE per process, 120 s kill — see
 * reference_live_server). This keeps the warm render/entity caches and refreshes
 * only what new or changed code invalidates:
 *   - container (new services, event subscribers, hook implementations —
 *     procedural .module hooks are collected into the container on D11)
 *   - router + menu links (new routes, local tasks, menu links)
 *   - theme registry + Twig (new hook_theme entries / templates)
 *   - library discovery + asset query string + CSS/JS aggregates (new CSS/JS)
 *   - plugin definitions (views handlers, field widgets, blocks, queue workers)
 *   - the 'rendered' cache tag, so pages that embed changed output re-render
 *     lazily on their next hit instead of all at once.
 * Entity/field definition caches are left alone — a setup script that adds a
 * field clears those itself.
 *
 * ⚠ LIVE LESSON 2026-11-14: container rebuild + router rebuild in ONE drush
 * process was killed silently at the router step by the 1 GB LVE limit (no
 * PHP error, no watchdog — SIGKILL, so the dumper's transaction rolled back
 * and the router stayed intact; dev peak for the same run was 378 MB). On
 * live run it as TWO processes:
 *   drush php:script web/scripts/targeted_refresh.php -- --skip-router
 *   drush php:script web/scripts/targeted_refresh.php -- --skip-container
 * (or let a setup script that rebuilds the router be the second process).
 * The 2026-11-11 ad-hoc version without the router step: 5.2 s, 107 MB.
 */

$args = $extra ?? [];
$skip_container = in_array('--skip-container', $args, TRUE);
$skip_router = in_array('--skip-router', $args, TRUE);
$t0 = microtime(TRUE);
$step = static function (string $label, callable $fn): void {
  $s = microtime(TRUE);
  $fn();
  printf("  %-44s %5.2fs\n", $label, microtime(TRUE) - $s);
};

if (!$skip_container) {
  $step('container rebuild', static fn() => \Drupal::service('kernel')->rebuildContainer());
  $step('plugin definitions', static fn() => \Drupal::service('plugin.cache_clearer')->clearCachedDefinitions());
}
if (!$skip_router) {
  $step('router rebuild', static fn() => \Drupal::service('router.builder')->rebuild());
  $step('menu links rebuild', static fn() => \Drupal::service('plugin.manager.menu.link')->rebuild());
}
$step('theme registry reset', static fn() => \Drupal::service('theme.registry')->reset());
$step('twig invalidate', static fn() => \Drupal::service('twig')->invalidate());
$step('library_info + rendered tags', static fn() => \Drupal::service('cache_tags.invalidator')->invalidateTags(['library_info', 'rendered', 'local_task', 'config:views.view.list']));
$step('asset query string reset', static fn() => \Drupal::service('asset.query_string')->reset());
$step('css/js aggregates deleted', static function (): void {
  \Drupal::service('asset.css.collection_optimizer')->deleteAll();
  \Drupal::service('asset.js.collection_optimizer')->deleteAll();
});
printf("done in %.2fs, peak %d MB\n", microtime(TRUE) - $t0, memory_get_peak_usage(TRUE) / 1048576);
