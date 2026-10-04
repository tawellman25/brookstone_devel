<?php

declare(strict_types=1);

/**
 * Does anything use the `entity_field` block plugins? (read-only)
 *
 * `entity_field:<entity_type>:<field>` definitions come from ctools and exist so
 * page_manager / panels variants can place a single entity field into a layout.
 * On BOS there are ~3,031 of them. If no variant references one, they are as
 * unreachable as the layout_builder field_blocks pruned by
 * bos_block_plugin_perf, and cost memory on every block-definition cache build.
 *
 * Decisive test: scan EVERY config object for a plugin id beginning
 * `entity_field:`. Any page_manager variant, panels display or placed block
 * that uses one stores the id verbatim, so a clean scan means nothing can be
 * referencing them.
 *
 *   drush php:script web/scripts/audit_entity_field_blocks.php
 */

$defs = \Drupal::service('plugin.manager.block')->getDefinitions();
$ef = array_filter($defs, fn($pid) => strpos($pid, 'entity_field:') === 0, ARRAY_FILTER_USE_KEY);
printf("entity_field definitions : %d\n", count($ef));
if ($ef) {
  $providers = array_count_values(array_map(fn($d) => $d['provider'] ?? '?', $ef));
  foreach ($providers as $p => $n) {
    printf("  provider %-20s %d\n", $p, $n);
  }
  printf("  sample id              : %s\n", array_key_first($ef));
}

// Who could consume them?
print "\nConsumer modules:\n";
foreach (['ctools', 'page_manager', 'panels', 'panelizer', 'panels_ipe', 'layout_builder'] as $m) {
  printf("  %-16s %s\n", $m, \Drupal::moduleHandler()->moduleExists($m) ? 'ENABLED' : 'not installed');
}

// The decisive scan: every config object, every nested string.
print "\nConfig referencing an entity_field plugin id:\n";
$factory = \Drupal::configFactory();
$names = $factory->listAll();
$hits = [];
$walk = function ($value, string $where) use (&$walk, &$hits) {
  if (is_string($value)) {
    if (strpos($value, 'entity_field:') === 0) {
      $hits[$where][] = $value;
    }
    return;
  }
  if (is_array($value)) {
    foreach ($value as $k => $v) {
      $walk($v, $where);
    }
  }
};
foreach ($names as $name) {
  $walk($factory->get($name)->getRawData(), $name);
}
if (!$hits) {
  printf("  NONE — scanned %d config objects, zero references\n", count($names));
}
else {
  foreach ($hits as $where => $ids) {
    printf("  %-56s %d  <-- IN USE\n", $where, count($ids));
    foreach (array_slice(array_unique($ids), 0, 3) as $id) {
      print "      $id\n";
    }
  }
}

// page_manager variants at all?
print "\npage_manager / panels config present:\n";
$found = FALSE;
foreach (['page_manager.page.', 'page_manager.page_variant.', 'panels.pattern.', 'panelizer.'] as $prefix) {
  $n = count($factory->listAll($prefix));
  if ($n) {
    $found = TRUE;
    printf("  %-32s %d objects\n", $prefix, $n);
  }
}
if (!$found) {
  print "  none\n";
}

printf("\nIf the scan is NONE and no consumer places them, these %d definitions are unreachable.\n", count($ef));
