<?php

$pm = \Drupal::service('plugin.manager.migration');
$defs = $pm->getDefinitions();

print "Definitions: ".count($defs)."\n";

$bad = [];
foreach ($defs as $id => $def) {
  if (isset($def['migration_dependencies']) && !is_array($def['migration_dependencies'])) {
    $bad[] = "$id => migration_dependencies is ".gettype($def['migration_dependencies']);
    continue;
  }
  if (isset($def['migration_dependencies']) && is_array($def['migration_dependencies'])) {
    foreach (['required','optional'] as $k) {
      if (isset($def['migration_dependencies'][$k]) && !is_array($def['migration_dependencies'][$k])) {
        $bad[] = "$id => $k is ".gettype($def['migration_dependencies'][$k]);
      }
    }
  }
}

if ($bad) {
  print "BAD DEFINITIONS:\n".implode("\n", $bad)."\n";
} else {
  print "OK: no bad migration_dependencies in plugin definitions\n";
}

print "\nNow instantiating each migration until one blows up...\n";

$ids = array_keys($defs);
sort($ids);
foreach ($ids as $id) {
  try {
    $m = $pm->createInstance($id);
    $m->getMigrationDependencies();
  } catch (\Throwable $e) {
    print "BROKEN MIGRATION: $id\n";
    print get_class($e).": ".$e->getMessage()."\n";
    throw $e;
  }
}

print "OK: all migrations instantiate and dependencies resolve\n";
