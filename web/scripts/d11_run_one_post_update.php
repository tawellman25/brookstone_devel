<?php

/**
 * @file
 * Run ONE pending post-update to completion in THIS process — the CLI
 * equivalent of a single update.php batch step, bounded by its own process.
 *
 * Why (D11 cutover, live, 2026-11-07): the 1 GB LVE memory ceiling rules out
 * `drush updb` (one process for every update — 1.25 GB peak in rehearsal), and
 * LiteSpeed's ~120 s connection timeout killed update.php's web batch on a
 * post-update that resaves hundreds of config entities. One update per drush
 * process has neither problem: no accumulation across updates, no web server
 * in the path. The sandbox loop is exactly core's update_invoke_post_update()
 * driven to #finished >= 1, which also registers the update as invoked.
 *
 * Usage (live): $DRUSH scr web/scripts/d11_run_one_post_update.php -- <function>
 *   e.g.  -- views_post_update_table_css_class
 *
 * Exit 1 if the function is not a pending post-update, if it aborts, or if it
 * is still pending afterwards. No exit(0) on success (drush scr).
 */

$fn = NULL;
foreach (array_merge($extra ?? [], $_SERVER['argv'] ?? []) as $a) {
  if (preg_match('/^[a-z0-9_]+_post_update_[a-z0-9_]+$/', $a)) {
    $fn = $a;
    break;
  }
}
if (!$fn) {
  fwrite(STDERR, "usage: -- <module>_post_update_<name>\n");
  exit(1);
}

require_once DRUPAL_ROOT . '/core/includes/update.inc';

$registry = \Drupal::service('update.post_update_registry');
$pending = $registry->getPendingUpdateFunctions();
if (!in_array($fn, $pending, TRUE)) {
  printf("%s: NOT pending (%d pending in total) — nothing to do\n", $fn, count($pending));
  exit(1);
}

$t0 = microtime(TRUE);
$context = ['sandbox' => [], 'results' => []];
$passes = 0;
do {
  $passes++;
  update_invoke_post_update($fn, $context);
  if (!empty($context['results']['#abort'])) {
    break;
  }
} while (isset($context['finished']) && $context['finished'] < 1);

[$extension, $name] = explode('_post_update_', $fn, 2);
$res = $context['results'][$extension][$name] ?? [];
$msg = $res['results']['query'] ?? '';
$still = in_array($fn, $registry->getPendingUpdateFunctions(), TRUE);

printf("%s: %s — %d pass(es), %.1fs, peak %.0f MB%s\n",
  $fn,
  !empty($context['results']['#abort']) ? 'ABORTED' : ($still ? 'STILL PENDING' : 'done'),
  $passes,
  microtime(TRUE) - $t0,
  memory_get_peak_usage(TRUE) / 1048576,
  $msg !== '' ? ' — ' . trim(strip_tags((string) $msg)) : ''
);
if (!empty($context['results']['#abort'])) {
  print "   abort: " . trim(strip_tags((string) ($res['#abort']['query'] ?? ''))) . "\n";
  exit(1);
}
if ($still) {
  exit(1);
}
