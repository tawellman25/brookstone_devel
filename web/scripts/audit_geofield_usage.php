<?php

/**
 * @file
 * Read-only audit of every geofield / geofield_map dependency in BOS.
 *
 * Written for the Drupal 11 upgrade (geofield 1.67 -> 10.3.5,
 * geofield_map 3.0.24 -> 11.1.9), which is the riskiest step in that project:
 * `field_geofield` is on every property, and the crew's route and work-order
 * maps are a geofield_map VIEWS STYLE plugin, not a field formatter. A silent
 * plugin rename would leave 28 view displays rendering nothing while every
 * page still returned HTTP 200.
 *
 * Run it BEFORE the upgrade to capture a baseline, and again AFTER to diff:
 *
 *   ddev drush scr web/scripts/audit_geofield_usage.php
 *   ddev drush scr web/scripts/audit_geofield_usage.php -- --json > after.json
 *
 * Exits NON-ZERO if any plugin id referenced by config no longer exists in the
 * plugin manager — which is the actual failure mode an upgrade produces, and
 * the one that does not show up as an error on any page.
 *
 * Read-only: loads config and queries plugin managers. Writes nothing.
 */

$as_json = in_array('--json', $extra ?? [], TRUE) || in_array('--json', $_SERVER['argv'] ?? [], TRUE);

$etm = \Drupal::entityTypeManager();
$cf = \Drupal::configFactory();

$widget_mgr = \Drupal::service('plugin.manager.field.widget');
$formatter_mgr = \Drupal::service('plugin.manager.field.formatter');
$style_mgr = \Drupal::service('plugin.manager.views.style');

$report = [
  'module_versions' => [],
  'fields' => [],
  'widgets' => [],
  'formatters' => [],
  'views_styles' => [],
  'settings_keys' => [],
  'missing_plugins' => [],
];

// ---------------------------------------------------------------------------
// Installed versions, so a baseline file records what it was taken against.
// ---------------------------------------------------------------------------
$ext = \Drupal::service('extension.list.module');
foreach (['geofield', 'geofield_map'] as $m) {
  try {
    $info = $ext->getExtensionInfo($m);
    $report['module_versions'][$m] = $info['version'] ?? '(no version key)';
  }
  catch (\Throwable $e) {
    $report['module_versions'][$m] = '(NOT INSTALLED)';
  }
}

// ---------------------------------------------------------------------------
// 1. geofield field storages and the bundles carrying them.
// ---------------------------------------------------------------------------
$geo_fields = [];
foreach ($etm->getStorage('field_storage_config')->loadMultiple() as $s) {
  if ($s->getType() === 'geofield') {
    $geo_fields[$s->getTargetEntityTypeId() . '.' . $s->getName()] = [];
  }
}
foreach ($etm->getStorage('field_config')->loadMultiple() as $f) {
  $k = $f->getTargetEntityTypeId() . '.' . $f->getName();
  if (isset($geo_fields[$k])) {
    $geo_fields[$k][] = $f->getTargetBundle();
  }
}
ksort($geo_fields);
$report['fields'] = $geo_fields;

// ---------------------------------------------------------------------------
// 2. Form widgets and 3. view formatters whose plugin id mentions geofield.
//    Also catches BOS's own hoa_boundary_map, which is a custom widget on a
//    geofield field and so is part of this blast radius even though its id
//    does not contain "geofield".
// ---------------------------------------------------------------------------
$geo_field_names = [];
foreach (array_keys($geo_fields) as $k) {
  [, $fname] = explode('.', $k, 2);
  $geo_field_names[$fname] = TRUE;
}

foreach ($cf->listAll('core.entity_form_display.') as $n) {
  foreach (($cf->get($n)->get('content') ?: []) as $field => $c) {
    $type = $c['type'] ?? '';
    if ($type === '') {
      continue;
    }
    if (str_contains($type, 'geofield') || isset($geo_field_names[$field])) {
      $id = str_replace('core.entity_form_display.', '', $n);
      $report['widgets'][] = ['display' => $id, 'field' => $field, 'plugin' => $type];
      if (!$widget_mgr->hasDefinition($type)) {
        $report['missing_plugins'][] = "WIDGET '$type' missing (used by $id :: $field)";
      }
    }
  }
}

foreach ($cf->listAll('core.entity_view_display.') as $n) {
  foreach (($cf->get($n)->get('content') ?: []) as $field => $c) {
    $type = $c['type'] ?? '';
    if ($type === '') {
      continue;
    }
    if (str_contains($type, 'geofield') || isset($geo_field_names[$field])) {
      $id = str_replace('core.entity_view_display.', '', $n);
      $report['formatters'][] = ['display' => $id, 'field' => $field, 'plugin' => $type];
      if (!$formatter_mgr->hasDefinition($type)) {
        $report['missing_plugins'][] = "FORMATTER '$type' missing (used by $id :: $field)";
      }
    }
  }
}

// ---------------------------------------------------------------------------
// 4. Views STYLE plugins — the big one. 28 displays on BOS as of 2026-11-06.
// ---------------------------------------------------------------------------
foreach ($cf->listAll('views.view.') as $n) {
  $raw = $cf->get($n)->getRawData();
  foreach (($raw['display'] ?? []) as $did => $disp) {
    $type = $disp['display_options']['style']['type'] ?? '';
    if ($type !== '' && str_contains($type, 'geofield')) {
      $view = str_replace('views.view.', '', $n);
      $report['views_styles'][] = ['view' => $view, 'display' => $did, 'plugin' => $type];
      if (!$style_mgr->hasDefinition($type)) {
        $report['missing_plugins'][] = "VIEWS STYLE '$type' missing (used by $view :: $did)";
      }
    }
  }
}

// ---------------------------------------------------------------------------
// 5. geofield_map.settings keys. Four custom code paths read gmap_api_key
//    directly (bos_hoa HoaBoundaryMapWidget, bos_scheduling
//    RouteEditorController, bos_service_request CreateCustomerForm, and
//    estimate.module relies on the widget's geocode box). If the upgrade
//    renames or relocates that key those read an empty string and every map
//    silently fails to load, with no error anywhere.
// ---------------------------------------------------------------------------
$settings = $cf->get('geofield_map.settings')->getRawData() ?: [];
foreach ($settings as $k => $v) {
  $report['settings_keys'][$k] = is_scalar($v) ? gettype($v) : gettype($v);
}
$report['gmap_api_key_present'] = !empty($settings['gmap_api_key']);

// ---------------------------------------------------------------------------
// Output.
// ---------------------------------------------------------------------------
if ($as_json) {
  print json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
else {
  print "=== INSTALLED VERSIONS ===\n";
  foreach ($report['module_versions'] as $m => $v) {
    printf("  %-14s %s\n", $m, $v);
  }

  printf("\n=== GEOFIELD FIELDS (%d) ===\n", count($report['fields']));
  foreach ($report['fields'] as $k => $bundles) {
    printf("  %-50s %s\n", $k, implode(', ', $bundles) ?: '(no instances)');
  }

  printf("\n=== FORM WIDGETS (%d) ===\n", count($report['widgets']));
  foreach ($report['widgets'] as $r) {
    printf("  %-46s %-34s %s\n", $r['display'], $r['field'], $r['plugin']);
  }

  printf("\n=== VIEW FORMATTERS (%d) ===\n", count($report['formatters']));
  foreach ($report['formatters'] as $r) {
    printf("  %-46s %-34s %s\n", $r['display'], $r['field'], $r['plugin']);
  }

  printf("\n=== VIEWS STYLES (%d) ===\n", count($report['views_styles']));
  foreach ($report['views_styles'] as $r) {
    printf("  %-52s %-14s %s\n", $r['view'], $r['display'], $r['plugin']);
  }

  printf("\n=== geofield_map.settings keys (%d) ===\n", count($report['settings_keys']));
  foreach ($report['settings_keys'] as $k => $t) {
    printf("  %-26s (%s)\n", $k, $t);
  }
  printf("  gmap_api_key present: %s\n", $report['gmap_api_key_present'] ? 'YES' : '*** NO ***');

  print "\n=== RESULT ===\n";
  if ($report['missing_plugins']) {
    printf("  *** %d REFERENCED PLUGIN(S) MISSING ***\n", count($report['missing_plugins']));
    foreach ($report['missing_plugins'] as $m) {
      print "    - $m\n";
    }
  }
  else {
    printf(
      "  OK — every referenced plugin resolves (%d widgets, %d formatters, %d views styles)\n",
      count($report['widgets']),
      count($report['formatters']),
      count($report['views_styles'])
    );
  }
}

if ($report['missing_plugins']) {
  // Non-zero so this can gate the upgrade.
  exit(1);
}
