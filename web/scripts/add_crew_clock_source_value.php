<?php

/**
 * @file
 * Add `wo_clock_crew` to wo_time_clock.field_source allowed values.
 *
 * `field_source` is how anyone later tells an observed clock-in from an
 * invented one — it is the field that showed reconciliation was producing more
 * entries than the button was. The crew clock needs its own value so a crew
 * attestation is distinguishable from N independent clock-ins, which matters
 * because the GPS on those entries is ONE person's reading stamped on
 * everybody (see WoClockService::crewClockIn).
 *
 * ⚠ A programmatic save() does not run entity validation, so an unlisted value
 * would store happily and then render as a raw machine name forever. This adds
 * it properly rather than relying on that.
 *
 * Idempotent; DRY-RUN unless BOS_APPLY=1.
 *
 * Run: drush php:script web/scripts/add_crew_clock_source_value.php
 */

const FIELD = 'field_source';
const ENTITY = 'wo_time_clock';
const KEY = 'wo_clock_crew';
const LABEL = 'Crew clock-in';

$apply = getenv('BOS_APPLY') === '1';

$storage = \Drupal::entityTypeManager()->getStorage('field_storage_config')
  ->load(ENTITY . '.' . FIELD);
if (!$storage) {
  printf("ABORT: no field storage %s.%s\n", ENTITY, FIELD);
  return;
}

$values = $storage->getSetting('allowed_values') ?: [];
print "current allowed values:\n";
foreach ($values as $k => $v) {
  printf("  %-26s %s\n", $k, $v);
}

if (array_key_exists(KEY, $values)) {
  printf("\n'%s' already present — nothing to do.\n", KEY);
  return;
}
printf("\nwill add: %-26s %s\n", KEY, LABEL);
if (!$apply) {
  print "\nDRY RUN — re-run with BOS_APPLY=1.\n";
  return;
}

$values[KEY] = LABEL;
$storage->setSetting('allowed_values', $values);
$storage->save();

$fresh = \Drupal::entityTypeManager()->getStorage('field_storage_config')
  ->load(ENTITY . '.' . FIELD);
$now = $fresh->getSetting('allowed_values') ?: [];
printf("\nsaved. '%s': %s\n", KEY, array_key_exists(KEY, $now) ? 'present' : '** MISSING **');
printf("total values: %d\n", count($now));
