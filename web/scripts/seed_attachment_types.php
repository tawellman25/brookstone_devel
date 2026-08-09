<?php

/**
 * Seed equipment_types terms for attachment kinds. Idempotent (matched by
 * field_common_name — the label source; equipment_types auto-labels from
 * [term:field_common_name], so name alone would save empty). Each term sets
 * field_equipment_bundle = attachements. Content, not config — no cim.
 *
 *   drush php:script web/scripts/seed_attachment_types.php
 */

use Drupal\taxonomy\Entity\Term;

$VOCAB = 'equipment_types';
$SEEDS = [
  'Bucket',
  'Forks',
  'Hydraulic Thumb/Clamp',
  'Landscape Rake',
  'Mower Deck',
  'Trencher Attachment',
  'Blade / Plow',
  'Mulching Kit',
  'Aerator Attachment',
];

$store = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$created = 0;
foreach ($SEEDS as $name) {
  $existing = $store->loadByProperties(['vid' => $VOCAB, 'field_common_name' => $name]);
  if ($existing) {
    printf("  exists: %-24s tid %s\n", $name, reset($existing)->id());
    continue;
  }
  $t = Term::create([
    'vid' => $VOCAB,
    'name' => $name,
    'field_common_name' => $name,
    'field_equipment_bundle' => 'attachements',
  ]);
  $t->save();
  printf("  created: %-24s tid %s\n", $name, $t->id());
  $created++;
}
print "DONE — attachment types seeded ($created new).\n";
