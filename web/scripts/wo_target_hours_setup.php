<?php

/**
 * @file
 * Target hours on cleanup work orders — idempotent setup + backfill.
 *
 * Job-clock guardrails item 5 (Todd, 2026-11-10). Safe to re-run; every step
 * checks before it writes. Rehearsed on DDEV, then run once on live after the
 * code deploy.
 *
 *   ddev drush php:script web/scripts/wo_target_hours_setup.php -- --dry-run
 *   ddev drush php:script web/scripts/wo_target_hours_setup.php
 *
 * Steps:
 *   1. Field storages + instances (field_target_hours decimal 10,2 and
 *      field_target_hours_source → work_order) on fall_cleanup and
 *      spring_cleanup, created from the config/sync YAML via
 *      FieldStorageConfig / FieldConfig — NOT cim, because of the BOS
 *      silent-skip bug on new field instances (working_with_claude.md).
 *   2. Form display: field_target_hours added to the Billing group right
 *      after field_total_time (weight +1 — free in that group on both
 *      bundles, measured on live 2026-11-10). The source field stays off the
 *      form (provenance only). View displays untouched: the clock block
 *      renders target + clocked, so no display component is needed.
 *   3. Backfill: open cleanup WOs created this year (status not Complete /
 *      Invoiced / Paid / Canceled / Warrantied) with an empty target get the
 *      prefill via wo_shared_target_hours_prefill() and are saved. Completed
 *      and billed WOs are never touched.
 *   4. Verify: fresh loads, counts, and the per-outcome report.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Symfony\Component\Yaml\Yaml;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';
$out = static function (string $line) use ($tag): void {
  echo $tag . $line . "\n";
};

const TH_BUNDLES = ['fall_cleanup', 'spring_cleanup'];
const TH_FIELDS = ['field_target_hours', 'field_target_hours_source'];
// Statuses that mean the WO is done or billed; the backfill never touches these.
const TH_CLOSED_STATUSES = [1097, 1281, 1504, 1098, 1283];

$sync = realpath(DRUPAL_ROOT . '/../config/sync');
if (!$sync) {
  throw new \RuntimeException('config/sync not found next to DRUPAL_ROOT');
}
$etm = \Drupal::entityTypeManager();

// ── 1. Fields ────────────────────────────────────────────────────────────
$out('== 1. fields ==');
foreach (TH_FIELDS as $field) {
  $storage = FieldStorageConfig::loadByName('work_order', $field);
  if ($storage) {
    $out("   storage work_order.$field exists (uuid " . $storage->uuid() . ')');
  }
  else {
    $data = Yaml::parseFile("$sync/field.storage.work_order.$field.yml");
    unset($data['_core'], $data['uuid']);
    if (!$dry) {
      FieldStorageConfig::create($data)->save();
    }
    $out("   storage work_order.$field CREATED");
  }
  foreach (TH_BUNDLES as $bundle) {
    $instance = FieldConfig::loadByName('work_order', $bundle, $field);
    if ($instance) {
      $out("   instance $bundle.$field exists (uuid " . $instance->uuid() . ')');
      continue;
    }
    $data = Yaml::parseFile("$sync/field.field.work_order.$bundle.$field.yml");
    unset($data['_core'], $data['uuid']);
    if (!$dry) {
      FieldConfig::create($data)->save();
    }
    $out("   instance $bundle.$field CREATED");
  }
}

// ── 2. Form displays ─────────────────────────────────────────────────────
$out('== 2. form displays ==');
foreach (TH_BUNDLES as $bundle) {
  $display = EntityFormDisplay::load("work_order.$bundle.default");
  if (!$display) {
    $out("   !! form display work_order.$bundle.default missing — skipped");
    continue;
  }
  $changed = FALSE;
  $existing = $display->getComponent('field_target_hours');
  $anchor = $display->getComponent('field_total_time');
  if ($existing) {
    $out("   $bundle: field_target_hours already on the form at weight {$existing['weight']}");
  }
  elseif (!$anchor) {
    $out("   !! $bundle: field_total_time not on the form — cannot anchor, skipped");
  }
  else {
    $weight = (int) $anchor['weight'] + 1;
    $display->setComponent('field_target_hours', [
      'type' => 'number',
      'weight' => $weight,
      'region' => 'content',
      'settings' => ['placeholder' => ''],
      'third_party_settings' => [],
    ]);
    $groups = $display->getThirdPartySettings('field_group');
    $placed = FALSE;
    foreach ($groups as $gname => $g) {
      $children = $g['children'] ?? [];
      $i = array_search('field_total_time', $children, TRUE);
      if ($i !== FALSE) {
        if (!in_array('field_target_hours', $children, TRUE)) {
          array_splice($children, $i + 1, 0, ['field_target_hours']);
          $g['children'] = $children;
          $display->setThirdPartySetting('field_group', $gname, $g);
        }
        $placed = $gname;
        break;
      }
    }
    $out("   $bundle: field_target_hours ADDED at weight $weight" . ($placed ? " in $placed after field_total_time" : ' (no field_group holds field_total_time — top level)'));
    $changed = TRUE;
  }
  // Provenance field: never a form widget.
  if ($display->getComponent('field_target_hours_source')) {
    $display->removeComponent('field_target_hours_source');
    $out("   $bundle: field_target_hours_source removed from the form (provenance only)");
    $changed = TRUE;
  }
  if ($changed && !$dry) {
    $display->save();
  }
}

// ── 3. Backfill ──────────────────────────────────────────────────────────
$out('== 3. backfill (open WOs created this year, empty target) ==');
if (!function_exists('wo_shared_target_hours_prefill')) {
  $out('   !! wo_shared_target_hours_prefill() not loaded — deploy the wo_shared code and `drush cr` first. Backfill skipped.');
}
elseif ($dry && !FieldStorageConfig::loadByName('work_order', 'field_target_hours')) {
  $out('   (dry-run) field does not exist yet — backfill would run after step 1 creates it.');
}
else {
  $tz = new \DateTimeZone(date_default_timezone_get());
  $year_start = (new \DateTime('now', $tz))->setDate((int) date('Y'), 1, 1)->setTime(0, 0, 0)->getTimestamp();
  $storage = $etm->getStorage('work_order');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', TH_BUNDLES, 'IN')
    ->condition('created', $year_start, '>=')
    ->condition('field_status', TH_CLOSED_STATUSES, 'NOT IN')
    ->sort('id')
    ->execute();
  $out('   candidates: ' . count($ids));
  $outcomes = [];
  $largest = [];
  foreach ($storage->loadMultiple($ids) as $wo) {
    $code = wo_shared_target_hours_prefill($wo);
    $outcomes[$code] = ($outcomes[$code] ?? 0) + 1;
    if ($code === 'set') {
      $h = (float) $wo->get('field_target_hours')->value;
      $largest[] = [$h, (int) $wo->id(), (int) $wo->get('field_target_hours_source')->target_id];
      if (!$dry) {
        $wo->save();
      }
    }
  }
  ksort($outcomes);
  foreach ($outcomes as $code => $n) {
    $out(sprintf('   %-12s %d', $code, $n));
  }
  usort($largest, static fn($a, $b) => $b[0] <=> $a[0]);
  foreach (array_slice($largest, 0, 5) as [$h, $id, $src]) {
    $out(sprintf('   largest: WO %d ← %.2f h from WO %d', $id, $h, $src));
  }
  $out('   implausible = prior-year WO carries more than the long-shift cap per clocked person; left blank, listed in watchdog (wo_shared) for the office.');
}

// ── 4. Verify ────────────────────────────────────────────────────────────
$out('== 4. verify (fresh loads) ==');
\Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
foreach (TH_BUNDLES as $bundle) {
  $defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('work_order', $bundle);
  $has = array_values(array_filter(TH_FIELDS, static fn($f) => isset($defs[$f])));
  $display = EntityFormDisplay::load("work_order.$bundle.default");
  $comp = $display ? $display->getComponent('field_target_hours') : NULL;
  $out(sprintf('   %-15s fields: %-45s form widget: %s', $bundle, implode(',', $has) ?: 'NONE', $comp ? $comp['type'] . ' @' . $comp['weight'] : 'none'));
}
if (FieldStorageConfig::loadByName('work_order', 'field_target_hours')) {
  $db = \Drupal::database();
  $n = $db->query("SELECT COUNT(*) FROM {work_order__field_target_hours} WHERE field_target_hours_value > 0")->fetchField();
  $out("   WOs with a target: $n");
}
$out('done');
