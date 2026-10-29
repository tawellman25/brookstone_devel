<?php

/**
 * @file
 * Guards the views_aggregator "undefined function" fatal from coming back.
 *
 * Live threw, 2026-10-28, core 10.6.17:
 *
 *   Error: Call to undefined function template_preprocess_views_view_table()
 *   in template_preprocess_views_aggregator_results_table() (line 189 of
 *   modules/contrib/views_aggregator/views_aggregator.module)
 *
 * views_aggregator's preprocess calls core's table preprocess directly, but
 * that function lives in `core/modules/views/views.theme.inc`, which the theme
 * system loads only for hooks that DECLARE it. Core's `views_view_table`
 * declares it; `views_aggregator_results_table` declared NOTHING, so the call
 * worked only when something else in the request had already pulled the file
 * in — intermittent by nature, which is why the same page renders fine one
 * minute and dies the next.
 *
 * `bos_views_aggregator_fix` declares the include via hook_theme_registry_alter.
 *
 * ⚠ The load-bearing assertion is the REGISTRY one, not the render. A render
 * can pass for the wrong reason: whatever else the test itself touched may have
 * loaded views.theme.inc first, which is exactly the accident that masked this
 * bug in the first place. The registry entry is the thing that makes it
 * deterministic.
 *
 * Read-only — safe on live.
 *
 * Run: drush php:script web/scripts/test_views_aggregator_include.php
 */

const HOOK = 'views_aggregator_results_table';
const INCLUDE_PATH = 'core/modules/views/views.theme.inc';

$pass = 0; $fail = 0;
$ok = function (string $w, bool $g, string $x = '') use (&$pass, &$fail) {
  $g ? $pass++ : $fail++;
  printf("  %s  %s%s\n", $g ? 'PASS' : 'FAIL', $w, $x !== '' ? "  [$x]" : '');
};

$moduleHandler = \Drupal::moduleHandler();
$aggregatorOn = $moduleHandler->moduleExists('views_aggregator');
$ok('views_aggregator is installed (otherwise none of this applies)', $aggregatorOn);
$ok('the fix module is installed', $moduleHandler->moduleExists('bos_views_aggregator_fix'));
if (!$aggregatorOn) {
  printf("\n%d passed, %d failed\n", $pass, $fail);
  return;
}

$registry = \Drupal::service('theme.registry')->get();
$ok('the aggregator theme hook is in the registry', isset($registry[HOOK]));
$includes = $registry[HOOK]['includes'] ?? [];
$ok('  it DECLARES views.theme.inc — the actual fix',
  in_array(INCLUDE_PATH, $includes, TRUE), json_encode($includes));
$ok('  the declared file actually exists on disk',
  file_exists(DRUPAL_ROOT . '/' . INCLUDE_PATH));

// Core's own table hook is the reference implementation; if core ever stops
// declaring it there, this whole approach needs revisiting.
$coreIncludes = $registry['views_view_table']['includes'] ?? [];
$ok('core still declares the same include on views_view_table',
  in_array(INCLUDE_PATH, $coreIncludes, TRUE), json_encode(array_unique($coreIncludes)));

// The function must exist once the include is loaded, or the declaration is
// pointing at the wrong file.
$moduleHandler->loadInclude('views', 'inc', 'views.theme');
$ok('template_preprocess_views_view_table() exists once that file is loaded',
  function_exists('template_preprocess_views_view_table'));

// And the call site still looks the way the diagnosis assumed. If upstream
// fixes it themselves this will flip, and the fix module becomes a no-op --
// worth knowing rather than discovering by accident.
$modulePath = \Drupal::service('extension.list.module')->getPath('views_aggregator');
$src = @file_get_contents($modulePath . '/views_aggregator.module');
$ok('views_aggregator still calls the core preprocess directly',
  is_string($src) && str_contains($src, 'template_preprocess_views_view_table($vars)'),
  is_string($src) ? 'yes — fix still needed' : 'could not read module');
if (is_string($src)) {
  $ok('  and still does NOT load the include itself (upstream unchanged)',
    !preg_match("/loadInclude\s*\(\s*['\"]views['\"]\s*,\s*['\"]inc['\"]\s*,\s*['\"]views\.theme['\"]/", $src),
    'if this fails, upstream fixed it and this module is now redundant');
}

// Finally, render one aggregator-styled view end to end.
$rendered = NULL;
foreach (['income_weed_spraying', 'admin_property_estimating'] as $id) {
  $view = \Drupal\views\Views::getView($id);
  if (!$view) {
    continue;
  }
  $view->setDisplay('default');
  $view->execute();
  if (!$view->result) {
    continue;
  }
  try {
    $html = (string) \Drupal::service('renderer')->renderRoot($view->render());
    $rendered = [$id, strlen($html), count($view->result)];
  }
  catch (\Throwable $e) {
    $ok("rendering $id did not fatal", FALSE, $e->getMessage());
  }
  break;
}
$ok('an aggregator-styled view renders', $rendered !== NULL,
  $rendered ? sprintf('%s: %d rows, %d bytes', $rendered[0], $rendered[2], $rendered[1]) : 'none had rows to render');

printf("\n%d passed, %d failed\n", $pass, $fail);
