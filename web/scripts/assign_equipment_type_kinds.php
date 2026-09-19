<?php

/**
 * Assign field_equipment_bundle (KIND) to the equipment_types terms that have
 * no equipment records yet, so they group correctly on the equipment lists.
 * Idempotent; entity-API, no cim. Run per env.
 */

$MAP = [
  61 => 'sprayers',      // Back-Pack Sprayer
  60 => 'sprayers',      // Pull-Behind Sprayer
  62 => 'sprayers',      // Reel Sprayer
  59 => 'trailers',      // Mowing Crew Trailer
  44 => 'small_engine',  // Reel Mower
  43 => 'small_engine',  // Riding Mower
  53 => 'small_engine',  // Walk-Behind Trencher
];

$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$out = [];
foreach ($MAP as $tid => $bundle) {
  $t = $ts->load($tid);
  if (!$t || $t->bundle() !== 'equipment_types') {
    $out[] = "SKIP $tid — not an equipment_types term";
    continue;
  }
  if ($t->get('field_equipment_bundle')->value === $bundle) {
    $out[] = "$tid {$t->label()} already $bundle";
    continue;
  }
  $t->set('field_equipment_bundle', $bundle)->save();
  $out[] = "$tid {$t->label()} -> $bundle";
}
print implode("\n", $out) . "\nDONE.\n";
