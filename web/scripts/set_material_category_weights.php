<?php

declare(strict_types=1);

/**
 * Order the plant category cards.
 *
 * The restructure prompts specify a `field_list_order` per category, but that
 * field does not exist on material_types — so the guard in the restructure
 * script skipped it silently and the cards fell back to their default order
 * (Roses first under Shrubs, which is the reverse of what was asked).
 *
 * The `material_children` card view sorts by taxonomy term WEIGHT then name, so
 * weight is the lever that actually works here. Setting it rather than adding a
 * field the view does not read.
 *
 * Idempotent. Dry-run by default; BOS_WEIGHT_APPLY=1 to write.
 */

$apply = getenv('BOS_WEIGHT_APPLY') === '1';

/** category name => weight, from the prompts' field_list_order values. */
$WEIGHTS = [
  // Shrubs
  'Evergreen Shrubs' => 10,
  'Deciduous Shrubs' => 20,
  'Roses' => 30,
  // Evergreens, ordered by item count, roughly descending sales order
  'Pine' => 10,
  'Spruce' => 20,
  'Fir' => 30,
  'Juniper' => 40,
  'Arborvitae' => 50,
  // Deciduous Trees, by purpose
  'Shade' => 10,
  'Ornamental' => 20,
  'Fruit Trees' => 30,
  // Trees branch
  'Evergreens' => 10,
  'Junipers' => 15,
  'Deciduous Trees' => 20,
];

$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$changed = 0;
foreach ($WEIGHTS as $name => $weight) {
  $found = $terms->loadByProperties(['vid' => 'material_types', 'name' => $name]);
  if (!$found) {
    printf("  skip    %-20s no such category\n", $name);
    continue;
  }
  foreach ($found as $t) {
    if ((int) $t->getWeight() === $weight) {
      printf("  ok      %-20s already %d\n", $name, $weight);
      continue;
    }
    printf("  %s %-20s weight %d -> %d\n", $apply ? 'WRITE  ' : 'would  ', $name, $t->getWeight(), $weight);
    $changed++;
    if ($apply) {
      $t->setWeight($weight);
      $t->save();
    }
  }
}
printf("\n  %s: %d\n", $apply ? 'changed' : 'would change', $changed);
if (!$apply) { print "\nDRY RUN. BOS_WEIGHT_APPLY=1 to write.\n"; }
