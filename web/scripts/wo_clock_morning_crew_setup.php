<?php

/**
 * @file
 * Morning-crew pre-select for the crew clock — idempotent setup.
 *
 *   drush php:script web/scripts/wo_clock_morning_crew_setup.php -- --dry-run
 *   drush php:script web/scripts/wo_clock_morning_crew_setup.php
 *
 * One field: wo_time_clock.entry.field_clocked_in_by (→ user), created from
 * config/sync through the entity API with the YAML's uuid KEPT, so active ends
 * up identical to sync and the partial cim afterwards has nothing to do
 * (gotchas: "A UUID mismatch makes cim DELETE + CREATE"). No display changes —
 * the field is stamped in code (WoClockService::createOpenEntry) and stays off
 * the entry form. No backfill: entries from before today simply have no value,
 * and the pre-select only looks at today's entries.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void { echo $tag . $line . "\n"; };

const MC_ET = 'wo_time_clock';
const MC_BUNDLE = 'entry';
const MC_FIELD = 'field_clocked_in_by';

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}

$out('== 1. field storage + instance ==');
if (FieldStorageConfig::loadByName(MC_ET, MC_FIELD)) {
  $out('   storage ' . MC_ET . '.' . MC_FIELD . ' exists');
}
else {
  $data = Yaml::parseFile("$sync/field.storage." . MC_ET . '.' . MC_FIELD . '.yml');
  unset($data['_core']);
  if (!$dry) {
    FieldStorageConfig::create($data)->save();
  }
  $out('   storage ' . MC_ET . '.' . MC_FIELD . ' CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}
if (FieldConfig::loadByName(MC_ET, MC_BUNDLE, MC_FIELD)) {
  $out('   instance ' . MC_ET . '.' . MC_BUNDLE . '.' . MC_FIELD . ' exists');
}
else {
  $data = Yaml::parseFile("$sync/field.field." . MC_ET . '.' . MC_BUNDLE . '.' . MC_FIELD . '.yml');
  unset($data['_core']);
  if (!$dry) {
    FieldConfig::create($data)->save();
  }
  $out('   instance ' . MC_ET . '.' . MC_BUNDLE . '.' . MC_FIELD . ' CREATED (uuid ' . $data['uuid'] . ' kept from sync)');
}

$out('== 2. verify ==');
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
$defs = \Drupal::service('entity_field.manager')->getFieldDefinitions(MC_ET, MC_BUNDLE);
if (isset($defs[MC_FIELD])) {
  $d = $defs[MC_FIELD];
  $out(sprintf('   %s: type=%s target=%s cardinality=%d label="%s"', MC_FIELD, $d->getType(), $d->getSetting('target_type'), $d->getFieldStorageDefinition()->getCardinality(), $d->getLabel()));
  $has = \Drupal::database()->schema()->tableExists(MC_ET . '__' . MC_FIELD);
  $out('   table ' . MC_ET . '__' . MC_FIELD . ': ' . ($has ? 'ok' : 'MISSING'));
}
else {
  $out('   ' . MC_FIELD . ($dry ? ' (would exist after a real run)' : ' MISSING'));
}
$svc = \Drupal::service('wo_clock.clock_service');
$out('   service method getMorningCrewForWo: ' . (method_exists($svc, 'getMorningCrewForWo') ? 'present' : 'MISSING — deploy the wo_clock code first'));
$out('done');
