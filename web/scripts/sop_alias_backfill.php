<?php

/**
 * @file
 * Give the SOPs that have no URL alias the one pathauto gives every other SOP.
 *
 *   drush php:script web/scripts/sop_alias_backfill.php -- --dry-run
 *   drush php:script web/scripts/sop_alias_backfill.php
 *
 * Found 2026-11-11: sop ids 1–6 (the oldest) answer only at /sop/N because their
 * pathauto state is SKIP (0) — generation was off when they were entered, so the
 * pattern `teammates/sop/[sop:type]/[sop:field_sop_code]` never ran for them.
 * This flips the stored state to CREATE and generates the alias through the
 * pathauto generator itself (same pattern, same ignore-words, same uniqueness
 * handling), without re-saving the SOP entity. Idempotent: an SOP that already
 * has an alias is never touched, so no existing URL changes.
 */

use Drupal\pathauto\PathautoState;

$dry = in_array('--dry-run', $extra ?? [], TRUE);
$tag = $dry ? '[DRY-RUN] ' : '';

$gen = \Drupal::service('pathauto.generator');
$repo = \Drupal::service('path_alias.repository');
$kv = \Drupal::keyValue('pathauto_state.sop');
$sops = \Drupal::entityTypeManager()->getStorage('sop')->loadMultiple();
$fixed = $kept = 0;
foreach ($sops as $sop) {
  $sys = '/sop/' . $sop->id();
  if ($repo->lookupBySystemPath($sys, 'en')) { $kept++; continue; }
  $would = $gen->createEntityAlias($sop, 'return');
  if (!$would) { printf("%s  sop %d (%s): pattern produced nothing — SKIPPED\n", $tag, $sop->id(), $sop->get('field_sop_code')->value); continue; }
  if (!$dry) {
    $kv->set($sop->id(), PathautoState::CREATE);
    $sop->path->pathauto = PathautoState::CREATE;
    $alias = $gen->updateEntityAlias($sop, 'bulkupdate', ['force' => TRUE, 'message' => FALSE]);
    // pathauto returns a PathAlias entity in some versions and a ['source','alias','langcode'] array in others.
    $got = is_array($alias) ? ($alias['alias'] ?? '(FAILED)') : ($alias ? $alias->getAlias() : '(FAILED)');
  }
  printf("%s  sop %2d %-22s %-18s %s → %s\n", $tag, $sop->id(), $sop->bundle(), $sop->get('field_sop_code')->value, $sys, $dry ? $would : $got);
  $fixed++;
}
printf("%s%d aliased, %d already had an alias (untouched)\n", $tag, $fixed, $kept);
