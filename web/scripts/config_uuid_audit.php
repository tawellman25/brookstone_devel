<?php

/**
 * @file
 * Read-only: which config/sync files carry a uuid that differs from ACTIVE?
 *
 *   drush php:script web/scripts/config_uuid_audit.php                 # whole sync dir
 *   drush php:script web/scripts/config_uuid_audit.php -- a.yml b.yml  # only these (exit 1 on any mismatch)
 *
 * Why this matters (core, StorageComparer::addChangelistUpdate): when a sync
 * file and active share a NAME but not a UUID, config import does not "update"
 * — it DELETES the active entity and CREATES it from sync. For a field storage
 * that drops the data table; for an ECK bundle it removes the bundle and its
 * field instances; for a vocabulary it deletes every term. So a file whose uuid
 * differs from the target environment's active must NEVER be staged for a
 * partial cim there. Run this against the staged file list first — zero
 * mismatches required. Governance: drupal_bos_gotchas.md → "A UUID mismatch
 * makes cim DELETE + CREATE". Live audit 2026-11-13: 192 of 9,835 sync files
 * mismatched live (49 field storages) — deferred_work.md #38.
 */

use Drupal\Core\Serialization\Yaml;

$dir = DRUPAL_ROOT . '/../config/sync';
$only = array_values(array_filter($extra ?? [], static fn($a) => str_ends_with($a, '.yml')));
$cf = \Drupal::configFactory();
$active = array_fill_keys($cf->listAll(), TRUE);

$files = $only ? array_map(static fn($f) => "$dir/" . basename($f), $only) : glob("$dir/*.yml");
$mismatch = [];
$onlySync = 0;
$noUuid = 0;
$same = 0;
$total = 0;
foreach ($files as $file) {
  $name = basename($file, '.yml');
  if ($name === 'core.extension') {
    continue;
  }
  if (!is_file($file)) {
    echo "!! not in sync: $name\n";
    continue;
  }
  $total++;
  $data = Yaml::decode(file_get_contents($file));
  if (!isset($data['uuid'])) {
    $noUuid++;
    continue;
  }
  if (!isset($active[$name])) {
    $onlySync++;
    continue;
  }
  if ($cf->get($name)->get('uuid') === $data['uuid']) {
    $same++;
  }
  else {
    $mismatch[] = $name;
  }
}
printf("checked %d sync files: uuid same as active %d | UUID DIFFERS %d | not in active (would be created) %d | no uuid key (simple config) %d\n", $total, $same, count($mismatch), $onlySync, $noUuid);
if ($mismatch) {
  $byPrefix = [];
  foreach ($mismatch as $n) {
    $p = preg_replace('/^((?:[a-z_]+\.){1,2}).*/', '$1', $n);
    $byPrefix[$p] = ($byPrefix[$p] ?? 0) + 1;
  }
  arsort($byPrefix);
  foreach ($byPrefix as $p => $c) {
    echo "  $p $c\n";
  }
  echo "DO NOT stage these for a partial cim here — import would DELETE + CREATE them:\n";
  foreach ($mismatch as $n) {
    echo "  $n\n";
  }
}
if ($only && $mismatch) {
  exit(1);
}
