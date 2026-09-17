<?php

/**
 * De-duplicate the brookstone_tags vocabulary (imported twice). For each set of
 * terms sharing a name, keep the lowest term id and delete the rest — but only
 * after re-confirming the term to delete has ZERO references anywhere (across
 * every entity_reference->taxonomy_term field). A referenced duplicate is left
 * in place and reported, never blindly removed.
 *
 * Idempotent (a second run finds no duplicates). One-off cleanup; run on live:
 *   drush php:script web/scripts/dedupe_brookstone_tags.php
 */

$VID = 'brookstone_tags';
$out = [];

// All taxonomy_term-target reference tables (for the safety usage re-check).
$map = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('entity_reference');
$tables = [];
foreach ($map as $etype => $fields) {
  foreach ($fields as $fname => $info) {
    $s = \Drupal\field\Entity\FieldStorageConfig::loadByName($etype, $fname);
    if (!$s || $s->getSetting('target_type') !== 'taxonomy_term') {
      continue;
    }
    $t = $etype . '__' . $fname;
    if (\Drupal::database()->schema()->tableExists($t)) {
      $tables[$t] = $fname . '_target_id';
    }
  }
}
$refCount = function (int $tid) use ($tables): int {
  $n = 0;
  foreach ($tables as $tbl => $col) {
    $n += (int) \Drupal::database()->query("SELECT COUNT(*) FROM {" . $tbl . "} WHERE " . $col . " = :t", [':t' => $tid])->fetchField();
  }
  return $n;
};

$storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $VID)->execute();

// Group by normalized name.
$byName = [];
foreach ($storage->loadMultiple($tids) as $t) {
  $byName[strtolower(trim($t->label()))][] = (int) $t->id();
}

$deleted = [];
$kept = [];
$skipped = [];
foreach ($byName as $name => $ids) {
  if (count($ids) < 2) {
    continue;
  }
  sort($ids);
  $keeper = array_shift($ids); // lowest id
  $kept[] = $keeper;
  foreach ($ids as $dup) {
    $refs = $refCount($dup);
    if ($refs > 0) {
      $skipped[] = "$dup (\"$name\") has $refs ref(s) — kept";
      continue;
    }
    $storage->load($dup)->delete();
    $deleted[] = $dup;
  }
}

$remaining = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $VID)->count()->execute();
$out[] = 'duplicate groups: ' . count(array_filter($byName, fn($x) => count($x) > 1));
$out[] = 'deleted ' . count($deleted) . ' duplicate term(s): ' . implode(',', $deleted);
if ($skipped) {
  $out[] = 'SKIPPED (still referenced): ' . implode(' | ', $skipped);
}
$out[] = "brookstone_tags terms remaining: $remaining";

print implode("\n", $out) . "\nDONE.\n";
