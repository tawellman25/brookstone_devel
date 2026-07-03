<?php

/**
 * @file
 * Read-only discovery for the Phase A clock-in redesign + GPS prerequisites.
 *
 * Reports on property location fields + coverage, the wo_time_clock:entry field
 * inventory, the current flag-based clock-in invocation points, existing
 * geolocation infrastructure + API keys, field-visibility patterns, and a
 * concrete test WO. NO writes, NO saves — inspection only.
 *
 * Run: ddev drush php:script web/scripts/discovery_phase_a_prerequisites.php
 */

$etm = \Drupal::entityTypeManager();
$efm = \Drupal::service('entity_field.manager');
$bundleInfo = \Drupal::service('entity_type.bundle.info');
$moduleHandler = \Drupal::moduleHandler();

$LOC_RE = '/lat|lng|lon|geo|location|address|coord/i';

$h = function (string $t): void {
  print "\n" . str_repeat('=', 78) . "\n" . $t . "\n" . str_repeat('=', 78) . "\n";
};
$sub = function (string $t): void {
  print "\n--- " . $t . " ---\n";
};
$mask = function ($v): string {
  $v = (string) $v;
  return $v === '' ? 'empty' : 'SET (masked, len=' . strlen($v) . ')';
};

/**
 * Minimal recursive grep over a directory tree.
 */
$grepTree = function (string $root, array $needles, array $exts, int $limit = 60): array {
  $out = [];
  if (!is_dir($root)) {
    return $out;
  }
  $it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
  );
  foreach ($it as $f) {
    if (!$f->isFile()) {
      continue;
    }
    if ($exts && !in_array(strtolower($f->getExtension()), $exts, TRUE)) {
      continue;
    }
    $lines = @file($f->getPathname());
    if (!$lines) {
      continue;
    }
    foreach ($lines as $i => $line) {
      foreach ($needles as $n) {
        if (stripos($line, $n) !== FALSE) {
          $out[] = [
            'file' => str_replace($root . '/', '', $f->getPathname()),
            'line' => $i + 1,
            'text' => trim($line),
          ];
          break;
        }
      }
      if (count($out) >= $limit) {
        return $out;
      }
    }
  }
  return $out;
};

print "Phase A + GPS prerequisites discovery — generated " . date('Y-m-d H:i:s T') . "\n";
print "READ ONLY. No data modified.\n";

// ===========================================================================
$h('SECTION 1 — Property location fields');
// ===========================================================================
$propBundles = array_keys($bundleInfo->getBundleInfo('properties'));
print "properties bundles: " . implode(', ', $propBundles) . "\n";

$nameMatched = [];
$typeMatched = [];
$LOC_TYPES = ['geofield', 'geolocation', 'geolocation_latlng', 'address', 'address_country', 'geofield_latlon'];
foreach ($propBundles as $b) {
  foreach ($efm->getFieldDefinitions('properties', $b) as $name => $def) {
    if (preg_match($LOC_RE, $name)) {
      $nameMatched[$name][$b] = $def;
    }
    if (in_array($def->getType(), $LOC_TYPES, TRUE)) {
      $typeMatched[$name][$b] = $def;
    }
  }
}

$sub('Name-matched fields (lat|lng|lon|geo|location|address|coord)');
if (!$nameMatched) {
  print "  (none)\n";
}
foreach ($nameMatched as $name => $byBundle) {
  $def = reset($byBundle);
  $card = $def->getFieldStorageDefinition()->getCardinality();
  printf("  %s | type=%s | label=\"%s\" | cardinality=%s | bundles=[%s]\n",
    $name, $def->getType(), (string) $def->getLabel(),
    $card == -1 ? 'unlimited' : $card, implode(',', array_keys($byBundle)));
  foreach (array_keys($byBundle) as $b) {
    try {
      $ids = $etm->getStorage('properties')->getQuery()->accessCheck(FALSE)
        ->condition('type', $b)->exists($name)->range(0, 5)->execute();
    }
    catch (\Throwable $e) {
      print "      [$b] (not query-sampleable — likely computed: " . $e->getMessage() . ")\n";
      continue;
    }
    if (!$ids) {
      print "      [$b] no non-empty stored values\n";
      continue;
    }
    foreach ($etm->getStorage('properties')->loadMultiple($ids) as $p) {
      if ($p->get($name)->isEmpty()) {
        continue;
      }
      $val = $p->get($name)->first()->getValue();
      print "      [$b] prop#{$p->id()}: " . substr(json_encode($val), 0, 180) . "\n";
    }
  }
}

$sub('Type-matched fields (geofield / geolocation / address types)');
if (!$typeMatched) {
  print "  (none)\n";
}
foreach ($typeMatched as $name => $byBundle) {
  $def = reset($byBundle);
  printf("  %s | type=%s | label=\"%s\" | bundles=[%s]\n",
    $name, $def->getType(), (string) $def->getLabel(), implode(',', array_keys($byBundle)));
}

$sub('Geo/address modules enabled');
foreach (['geofield', 'geolocation', 'address'] as $m) {
  print "  $m: " . ($moduleHandler->moduleExists($m) ? 'ENABLED' : 'not enabled') . "\n";
}

// ===========================================================================
$h('SECTION 2 — Property geocoding population rate');
// ===========================================================================
$propDefs = $efm->getFieldDefinitions('properties', 'property');
$geoField = NULL;
$latField = NULL;
$lngField = NULL;
foreach ($propDefs as $name => $def) {
  if ($def->getType() === 'geofield' && $geoField === NULL) {
    $geoField = $name;
  }
  if (preg_match('/(^|_)lat/i', $name)) {
    $latField = $name;
  }
  if (preg_match('/(^|_)(lng|lon)/i', $name)) {
    $lngField = $name;
  }
}
$hasStatus = isset($propDefs['status']);
print "status field present on properties: " . ($hasStatus ? 'YES' : 'NO') . "\n";

$mkq = function () use ($etm, $hasStatus) {
  $q = $etm->getStorage('properties')->getQuery()->accessCheck(FALSE)->condition('type', 'property');
  if ($hasStatus) {
    $q->condition('status', 1);
  }
  return $q;
};
$total = (int) $mkq()->count()->execute();
print "total " . ($hasStatus ? 'active (status=1) ' : '') . "properties: $total\n";

$locUsed = $geoField ?: ($latField ?: NULL);
if ($geoField) {
  print "location field used for coverage: $geoField (geofield)\n";
  $pop = (int) $mkq()->exists($geoField)->count()->execute();
}
elseif ($latField && $lngField) {
  print "location fields used for coverage: $latField + $lngField\n";
  $pop = (int) $mkq()->exists($latField)->exists($lngField)->count()->execute();
}
else {
  print "!! no geofield or lat/lng fields detected — cannot compute coverage\n";
  $pop = 0;
}
if ($locUsed || ($latField && $lngField)) {
  printf("populated: %d (%.1f%%)\n", $pop, $total ? 100 * $pop / $total : 0);
  printf("empty:     %d (%.1f%%)\n", $total - $pop, $total ? 100 * ($total - $pop) / $total : 0);
}

// ===========================================================================
$h('SECTION 3 — wo_time_clock:entry field inventory');
// ===========================================================================
$tcDefs = $efm->getFieldDefinitions('wo_time_clock', 'entry');
print "field count: " . count($tcDefs) . "\n\n";
$tcLocHits = [];
foreach ($tcDefs as $name => $def) {
  printf("  %-34s type=%-22s label=\"%s\"\n", $name, $def->getType(), (string) $def->getLabel());
  if (preg_match('/location|gps|lat|lng|lon|geo/i', $name) || in_array($def->getType(), $LOC_TYPES, TRUE)) {
    $tcLocHits[$name] = $def->getType();
  }
}
$sub('Existing location/gps/geo fields on wo_time_clock:entry');
if ($tcLocHits) {
  foreach ($tcLocHits as $n => $t) {
    print "  !! $n (type=$t) — already present\n";
  }
}
else {
  print "  (none — no existing GPS/location fields to collide with)\n";
}

// ===========================================================================
$h('SECTION 4 — Current (flag-based) clock-in invocation points');
// ===========================================================================
$root = \Drupal::root();
$custom = $root . '/modules/custom';
$themesCustom = $root . '/themes/custom';
$codeExts = ['php', 'module', 'inc', 'install', 'theme', 'yml', 'twig', 'js'];

$sub('"work_order_timer" across modules/custom');
$hits = $grepTree($custom, ['work_order_timer'], $codeExts);
print $hits ? '' : "  (none)\n";
foreach ($hits as $m) {
  print "  {$m['file']}:{$m['line']}  " . substr($m['text'], 0, 120) . "\n";
}

$sub('flagging_insert / flagging_delete hooks across modules/custom');
$hits = $grepTree($custom, ['flagging_insert', 'flagging_delete'], $codeExts);
print $hits ? '' : "  (none)\n";
foreach ($hits as $m) {
  print "  {$m['file']}:{$m['line']}  " . substr($m['text'], 0, 120) . "\n";
}

$sub('flag service / flag()/unflag()/getFlagById across modules/custom');
$hits = $grepTree($custom, ['->flag(', '->unflag(', 'getFlagById', 'flag.linkbuilder', 'flagService', "flag('work_order_timer'"], $codeExts);
print $hits ? '' : "  (none)\n";
foreach ($hits as $m) {
  print "  {$m['file']}:{$m['line']}  " . substr($m['text'], 0, 120) . "\n";
}

$sub('theme layer references to the timer flag (themes/custom)');
$hits = $grepTree($themesCustom, ['work_order_timer', 'timer_flag'], ['twig', 'theme', 'js', 'yml']);
print $hits ? '' : "  (none)\n";
foreach ($hits as $m) {
  print "  {$m['file']}:{$m['line']}  " . substr($m['text'], 0, 120) . "\n";
}

// ===========================================================================
$h('SECTION 5 — Existing geolocation infrastructure');
// ===========================================================================
$sub('candidate modules (installed in codebase vs enabled)');
$extList = \Drupal::service('extension.list.module');
foreach (['geofield', 'geolocation', 'geolocation_field', 'address', 'geocoder', 'google_analytics', 'geofield_map', 'key'] as $m) {
  printf("  %-18s installed=%-4s enabled=%s\n",
    $m, $extList->exists($m) ? 'YES' : 'no', $moduleHandler->moduleExists($m) ? 'YES' : 'no');
}

$sub('key module — stored key entities (names only, no secrets)');
if ($moduleHandler->moduleExists('key')) {
  $keys = $etm->getStorage('key')->loadMultiple();
  if (!$keys) {
    print "  (no key entities)\n";
  }
  foreach ($keys as $k) {
    print "  {$k->id()} — \"{$k->label()}\"\n";
  }
}
else {
  print "  key module not enabled\n";
}

$sub('business_setting fields matching api/google/map/key');
try {
  $bs = \Drupal::service('config_pages.loader')->load('business_setting');
  if ($bs) {
    $any = FALSE;
    foreach ($bs->getFieldDefinitions() as $fn => $fd) {
      if (preg_match('/api|google|map|key/i', $fn . ' ' . (string) $fd->getLabel())) {
        $any = TRUE;
        printf("  %s (\"%s\") — %s\n", $fn, (string) $fd->getLabel(),
          $bs->get($fn)->isEmpty() ? 'empty' : $mask($bs->get($fn)->value ?? '1'));
      }
    }
    if (!$any) {
      print "  (no api/google/map/key-named fields on business_setting)\n";
    }
  }
  else {
    print "  business_setting config page not found\n";
  }
}
catch (\Throwable $e) {
  print "  (config_pages unavailable: " . $e->getMessage() . ")\n";
}

$sub('common geolocation config api-key entries');
foreach (['geolocation.settings' => 'google_map_api_key', 'geofield_map.settings' => 'gmap_api_key', 'geocoder.settings' => 'plugins'] as $cfg => $key) {
  $v = \Drupal::config($cfg)->get($key);
  print "  $cfg:$key — " . ($v ? (is_scalar($v) ? $mask($v) : 'SET (structured)') : 'empty/unset') . "\n";
}

// ===========================================================================
$h('SECTION 6 — Field visibility pattern on wo_time_clock:entry');
// ===========================================================================
print "field_permissions module enabled: " . ($moduleHandler->moduleExists('field_permissions') ? 'YES' : 'NO') . "\n";
print "permissions_by_term enabled: " . ($moduleHandler->moduleExists('permissions_by_term') ? 'YES' : 'NO') . "\n";
if (!$moduleHandler->moduleExists('field_permissions')) {
  print "(No per-field role access module. Field visibility here is governed by FORM/VIEW display\n";
  print " placement — 'hidden by default, admin-only' = leave the field off the crew form/view display.)\n";
}

$sub('wo_time_clock.entry.default FORM display components (visible on the entry form)');
$formDisplay = $etm->getStorage('entity_form_display')->load('wo_time_clock.entry.default');
$onForm = [];
if ($formDisplay) {
  foreach ($formDisplay->getComponents() as $f => $c) {
    $onForm[$f] = TRUE;
    printf("  %-34s widget=%s\n", $f, $c['type'] ?? '?');
  }
}
else {
  print "  (no default form display)\n";
}
$hiddenOnForm = array_diff(array_keys($tcDefs), array_keys($onForm));
print "  hidden-from-form fields (in schema but not on the form): " . (implode(', ', $hiddenOnForm) ?: '(none)') . "\n";

$sub('wo_time_clock.entry.default VIEW display components');
$viewDisplay = $etm->getStorage('entity_view_display')->load('wo_time_clock.entry.default');
if ($viewDisplay) {
  foreach ($viewDisplay->getComponents() as $f => $c) {
    printf("  %-34s formatter=%s\n", $f, $c['type'] ?? '(region/hidden)');
  }
}
else {
  print "  (no default view display)\n";
}

// ===========================================================================
$h('SECTION 7 — Sample WO for testing (in-progress, geolocated property)');
// ===========================================================================
$since = strtotime('-30 days');
$crewFieldCandidates = ['field_assigned_to', 'field_supervisor', 'field_crew', 'field_assigned_crew', 'field_teammate'];
$found = NULL;
try {
  $ids = $etm->getStorage('work_order')->getQuery()->accessCheck(FALSE)
    ->condition('field_status', 1092)
    ->condition('changed', $since, '>=')
    ->exists('field_property')
    ->sort('changed', 'DESC')
    ->range(0, 300)
    ->execute();
  foreach ($etm->getStorage('work_order')->loadMultiple($ids) as $wo) {
    $pid = $wo->get('field_property')->target_id ?? NULL;
    if (!$pid) {
      continue;
    }
    $p = $etm->getStorage('properties')->load($pid);
    if (!$p || !$geoField || !$p->hasField($geoField) || $p->get($geoField)->isEmpty()) {
      continue;
    }
    $found = [$wo, $p];
    break;
  }
}
catch (\Throwable $e) {
  print "  (query error: " . $e->getMessage() . ")\n";
}
if ($found) {
  [$wo, $p] = $found;
  print "  WO ID:      " . $wo->id() . "\n";
  print "  WO title:   " . $wo->label() . "\n";
  print "  WO#:        " . ($wo->hasField('field_work_order_id') ? ($wo->get('field_work_order_id')->value ?? '?') : '?') . "\n";
  print "  Property:   #" . $p->id() . " — " . $p->label() . "\n";
  if ($p->hasField('field_nickname') && !$p->get('field_nickname')->isEmpty()) {
    print "  Nickname:   " . $p->get('field_nickname')->value . "\n";
  }
  if ($p->hasField('field_full_address') && !$p->get('field_full_address')->isEmpty()) {
    print "  Address:    " . trim(strip_tags((string) $p->get('field_full_address')->value)) . "\n";
  }
  $gv = $p->get($geoField)->first()->getValue();
  print "  geofield:   lat=" . ($gv['lat'] ?? '?') . " lon=" . ($gv['lon'] ?? '?') . " | value=" . ($gv['value'] ?? '?') . "\n";
  $crewShown = FALSE;
  foreach ($crewFieldCandidates as $cf) {
    if ($wo->hasField($cf) && !$wo->get($cf)->isEmpty()) {
      $names = [];
      foreach ($wo->get($cf) as $it) {
        if ($it->target_id && ($u = \Drupal\user\Entity\User::load($it->target_id))) {
          $names[] = $u->getAccountName();
        }
      }
      print "  Crew ($cf): " . (implode(', ', $names) ?: '(refs present)') . "\n";
      $crewShown = TRUE;
    }
  }
  if (!$crewShown) {
    print "  Crew:       (no crew field populated on the WO — assignment is via the scheduling entity)\n";
  }
}
else {
  print "  (no in-progress (1092) WO in the last 30 days with a geolocated property found)\n";
}

print "\n" . str_repeat('=', 78) . "\nEND OF DISCOVERY\n" . str_repeat('=', 78) . "\n";
