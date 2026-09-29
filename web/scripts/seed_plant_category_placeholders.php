<?php

declare(strict_types=1);

/**
 * Temporary placeholder items so every plant category has something in it.
 *
 * ⚠ THE MATERIAL ENTITY HAS NO PUBLISHED/UNPUBLISHED STATE — no `status` key at
 * all. Anything created here is immediately visible to anonymous visitors and
 * crawlable. So this is a DEV convenience for exercising the wiring, not
 * something to run against production: on live the categories stay empty until
 * the office enters real stock, and the title block covers them the moment it
 * does, because its path globs come from the category tree rather than from
 * item counts.
 *
 * Refuses to run unless BOS_PLACEHOLDER_I_MEAN_IT=1 is set, so it cannot be
 * fired at live by muscle memory.
 *
 * Idempotent: a placeholder already in a category is left alone.
 * Removable: BOS_PLACEHOLDER_REMOVE=1 deletes exactly what it created, matched
 * on the marker in field_description, never on the title.
 */

const BOS_PH_MARKER = '<!-- bos-placeholder -->';

if (getenv('BOS_PLACEHOLDER_I_MEAN_IT') !== '1') {
  print "Refusing to run.\n";
  print "material has no unpublished state, so every item created here is PUBLIC.\n";
  print "Set BOS_PLACEHOLDER_I_MEAN_IT=1 if this is a development environment.\n";
  return;
}

$remove = getenv('BOS_PLACEHOLDER_REMOVE') === '1';
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$materials = \Drupal::entityTypeManager()->getStorage('material');

/** Category name => [bundle, placeholder title, genus, species]. */
$SEED = [
  'Annuals' => ['annuals', 'Example Annual', 'Petunia', 'Petunia × atkinsiana'],
  'Perennials' => ['plants', 'Example Perennial', 'Echinacea', 'Echinacea purpurea'],
  'Groundcovers' => ['plants', 'Example Groundcover', 'Thymus', 'Thymus serpyllum'],
  'Ferns' => ['plants', 'Example Fern', 'Athyrium', 'Athyrium filix-femina'],
  'Grasses' => ['plants', 'Example Grass', 'Schizachyrium', 'Schizachyrium scoparium'],
  'Vines' => ['plants', 'Example Vine', 'Clematis', 'Clematis spp.'],
  'Roses' => ['shrubs', 'Example Rose', 'Rosa', 'Rosa spp.'],
];

if ($remove) {
  $ids = $materials->getQuery()->accessCheck(FALSE)
    ->condition('field_description', '%' . BOS_PH_MARKER . '%', 'LIKE')
    ->execute();
  printf("removing %d placeholder(s)\n", count($ids));
  foreach ($materials->loadMultiple($ids) as $m) {
    print '  delete ' . $m->label() . "\n";
    $m->delete();
  }
  return;
}

foreach ($SEED as $category => [$bundle, $title, $genus, $species]) {
  $found = $terms->loadByProperties(['vid' => 'material_types', 'name' => $category]);
  $term = reset($found);
  if (!$term) {
    printf("  SKIP   %-16s no such category\n", $category);
    continue;
  }

  $existing = $materials->getQuery()->accessCheck(FALSE)
    ->condition('type', $bundle)
    ->condition('field_material_category', $term->id())
    ->execute();
  if ($existing) {
    printf("  have   %-16s %d item(s) already — left alone\n", $category, count($existing));
    continue;
  }

  // The title is composed by auto_entitylabel from field_name on these bundles,
  // so setting `title` directly is silently overwritten with an empty string —
  // which then drops the title segment out of the pathauto alias and leaves the
  // item colliding with its own category page. Set the NAME and let the label
  // compose itself.
  $m = $materials->create([
    'type' => $bundle,
    'field_name' => $title,
    'field_material_category' => ['target_id' => $term->id()],
    'field_plant_genus' => $genus,
    'field_plant_species' => $species,
    'field_description' => [
      'value' => BOS_PH_MARKER . '<p>Placeholder so this category is not empty while the catalogue is built. Replace with real stock.</p>',
      'format' => 'full_html',
    ],
  ]);
  $m->save();
  printf("  CREATE %-16s %-22s %s\n", $category, $title,
    \Drupal::service('path_alias.manager')->getAliasByPath('/material/' . $m->id()));
}
