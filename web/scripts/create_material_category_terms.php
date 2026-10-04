<?php

declare(strict_types=1);

/**
 * Give bulk_material and supplies a public material_types category.
 *
 * Todd, 3 October: make them public. They were the 2 of 22 material bundles
 * with no category term at all, so they had no page and no URL - which
 * undercuts the promise in the live /material header that a material on your
 * invoice can be looked up.
 *
 * Modelled on the existing root categories (Mulch, Rock, Sod): root-level term,
 * field_material_bundle set, published. Named from the ECK BUNDLE LABEL rather
 * than invented - the office owns names.
 *
 * ⚠ Bodies are left EMPTY. Marketing has written copy for both and is holding
 * it; this script does not invent public copy. Mulch is the precedent for a
 * category with zero items but real copy, so these two are not finished until
 * that copy lands.
 *
 *   drush php:script web/scripts/create_material_category_terms.php
 *   BOS_CAT_APPLY=1 drush php:script web/scripts/create_material_category_terms.php
 */

use Drupal\taxonomy\Entity\Term;

$apply = getenv('BOS_CAT_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$BUNDLES = ['bulk_material', 'supplies'];

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_CAT_APPLY=1 to write)\n\n";

$labels = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');

foreach ($BUNDLES as $bundle) {
  if (!isset($labels[$bundle])) { print "ABORT — material bundle '$bundle' does not exist.\n"; return; }

  // Already claimed? Idempotent on the bundle mapping, not the name.
  $existing = $db->query("SELECT entity_id FROM {taxonomy_term__field_material_bundle} WHERE field_material_bundle_value=:b",
    [':b' => $bundle])->fetchField();
  if ($existing) {
    $t = $etm->getStorage('taxonomy_term')->load($existing);
    printf("✓ %-16s already has a category: \"%s\" (tid %s)\n", $bundle, $t->label(), $existing);
    continue;
  }

  $name = (string) $labels[$bundle]['label'];
  $items = (int) $db->query("SELECT COUNT(*) FROM {material_field_data} WHERE type=:t", [':t' => $bundle])->fetchField();
  printf("+ %-16s create \"%s\"  (%d item%s in the bundle today)\n", $bundle, $name, $items, $items === 1 ? '' : 's');
  if ($items === 0) {
    printf("    ⚠ zero items — the page will list nothing until the catalogue has some.\n");
    printf("      Not a blocker: Mulch and Annuals are already public with zero items.\n");
  }

  if (!$apply) { continue; }

  $term = Term::create([
    'vid' => 'material_types',
    'name' => $name,
    'parent' => [0],
    'status' => 1,
    'field_material_bundle' => $bundle,
  ]);
  $term->save();
  printf("    created tid %s -> %s\n", $term->id(),
    \Drupal::service('path_alias.manager')->getAliasByPath('/taxonomy/term/' . $term->id()));
}

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }

drupal_flush_all_caches();

// Prove every bundle is now claimed.
$all = array_keys($labels);
$unclaimed = [];
foreach ($all as $b) {
  if (!$db->query("SELECT COUNT(*) FROM {taxonomy_term__field_material_bundle} WHERE field_material_bundle_value=:b",
    [':b' => $b])->fetchField()) { $unclaimed[] = $b; }
}
printf("\n%d material bundles, %d claimed by a category, %d unclaimed%s\n",
  count($all), count($all) - count($unclaimed), count($unclaimed),
  $unclaimed ? ': ' . implode(', ', $unclaimed) : '');
print "\n⚠ Bodies and card lines are EMPTY on the new terms. Marketing is holding copy for both.\n";
