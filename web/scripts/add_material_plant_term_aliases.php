<?php

/**
 * Give the public material/plant attribute vocabularies Pathauto patterns and
 * generate aliases, so their terms stop appearing as /taxonomy/term/{id} in the
 * sitemap. material_types is left alone (it has 30 custom hierarchical aliases —
 * a pattern would rewrite them); its single un-aliased straggler ("Roses") is
 * hand-aliased under its parent (Shrubs) to match.
 *
 * Idempotent; entity-API (no cim). Run on live:
 *   drush php:script web/scripts/add_material_plant_term_aliases.php
 */

use Drupal\pathauto\PathautoState;
use Drupal\path_alias\Entity\PathAlias;

$patterns = [
  'hardscape_types_aliases'      => ['hardscape_types',     'Material - Hardscape Type - Path',  '/material/hardscape/[term:name]'],
  'bulk_material_types_aliases'  => ['bulk_material_types', 'Material - Bulk Type - Path',       '/material/bulk/[term:name]'],
  'bloom_time_aliases'           => ['bloom_time',          'Plant - Bloom Time - Path',         '/material/plants/bloom-time/[term:name]'],
  'growth_zone_aliases'          => ['growth_zone',         'Plant - Growth Zone - Path',        '/material/plants/growth-zone/[term:name]'],
];

$out = [];
$patStorage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');

// 1) Create the patterns (idempotent).
foreach ($patterns as $id => [$vocab, $label, $pat]) {
  if ($patStorage->load($id)) {
    $out[] = "pattern $id exists";
    continue;
  }
  $p = $patStorage->create([
    'id' => $id,
    'label' => $label,
    'type' => 'canonical_entities:taxonomy_term',
    'pattern' => $pat,
  ]);
  $p->addSelectionCondition([
    'id' => 'entity_bundle:taxonomy_term',
    'bundles' => [$vocab => $vocab],
    'negate' => FALSE,
    'context_mapping' => ['taxonomy_term' => 'taxonomy_term'],
  ]);
  $p->save();
  $out[] = "pattern $id created ($pat)";
}

// 2) Generate aliases for each vocab (flip checkbox to CREATE + save).
$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$aliasStorage = \Drupal::entityTypeManager()->getStorage('path_alias');
$am = \Drupal::service('path_alias.manager');
foreach ($patterns as [$vocab]) {
  $tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', $vocab)->execute();
  $done = 0;
  foreach ($termStorage->loadMultiple($tids) as $t) {
    $existing = $aliasStorage->loadByProperties(['path' => '/taxonomy/term/' . $t->id()]);
    if ($existing) {
      $aliasStorage->delete($existing);
    }
    if ($t->hasField('path')) {
      $t->get('path')->pathauto = PathautoState::CREATE;
    }
    $t->save();
    $done++;
  }
  $miss = 0;
  foreach ($tids as $tid) {
    if ($am->getAliasByPath('/taxonomy/term/' . $tid) === '/taxonomy/term/' . $tid) {
      $miss++;
    }
  }
  $sample = $tids ? $am->getAliasByPath('/taxonomy/term/' . reset($tids)) : '(none)';
  $out[] = "$vocab: aliased $done/" . count($tids) . " (missing $miss) e.g. $sample";
}

// 3) material_types straggler "Roses" (1432) -> /material/shrubs/roses (manual, matches siblings).
$roses = 1432;
if (!$aliasStorage->loadByProperties(['path' => '/taxonomy/term/' . $roses])) {
  PathAlias::create(['path' => '/taxonomy/term/' . $roses, 'alias' => '/material/shrubs/roses', 'langcode' => 'und'])->save();
  $out[] = "material_types: created /material/shrubs/roses for term $roses";
}
else {
  $out[] = "material_types: term $roses already aliased (" . $am->getAliasByPath('/taxonomy/term/' . $roses) . ')';
}

print implode("\n", $out) . "\nDONE.\n";
