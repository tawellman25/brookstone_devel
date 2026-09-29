<?php

declare(strict_types=1);

/**
 * Repoint the cross-link that went stale when the Fruit category was renamed.
 *
 * The fruit category under Deciduous was created as "Fruit Trees" at
 * /material/plants/trees/deciduous/fruit-trees, then renamed by the office to
 * "Fruit" — sensible, since it already sits under /trees/, so "Fruit Trees"
 * said trees twice. The Fruit-Bearing characteristic page links to it in its
 * copy, and that link still carried the old path. It worked, because the alias
 * change left a redirect behind, but an internal link should not need one.
 *
 * Resolves the category's CURRENT alias at run time rather than hardcoding the
 * new one, so this stays correct if the office moves or renames it again.
 *
 * Idempotent. Dry-run by default; set BOS_FRUIT_LINK_APPLY=1 to write.
 *   drush php:script web/scripts/fix_fruit_category_link.php
 */

use Drupal\Component\Utility\Html;

$apply = getenv('BOS_FRUIT_LINK_APPLY') === '1';
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$aliases = \Drupal::service('path_alias.manager');

// Find the fruit category: a child of Deciduous whose name begins "Fruit".
// Matched on the RELATIONSHIP plus the concept, never on the exact name the
// office owns and has already changed once.
$deciduous = $terms->loadByProperties(['vid' => 'material_types', 'name' => 'Deciduous']);
$deciduous = reset($deciduous);
if (!$deciduous) {
  print "Deciduous category not found — aborting.\n";
  return;
}

$fruit = NULL;
foreach ($terms->loadTree('material_types', (int) $deciduous->id(), 1, TRUE) as $child) {
  if (stripos($child->label(), 'fruit') === 0) {
    $fruit = $child;
    break;
  }
}
if (!$fruit) {
  print "no Fruit category under Deciduous — nothing to point at. Aborting.\n";
  return;
}

$current = $aliases->getAliasByPath('/taxonomy/term/' . $fruit->id());
printf("fruit category: tid %s \"%s\" -> %s\n", $fruit->id(), $fruit->label(), $current);

// Every path this link has ever had, minus the one it should have now.
$stale = array_diff([
  '/material/plants/trees/deciduous/fruit-trees',
  '/material/plants/trees/deciduous/fruit',
], [$current]);

$changed = 0;
foreach ($terms->loadByProperties(['vid' => 'plant_characteristics']) as $term) {
  if (!$term->hasField('description')) {
    continue;
  }
  $body = (string) $term->get('description')->value;
  if ($body === '') {
    continue;
  }
  $new = $body;
  foreach ($stale as $old) {
    // Only inside an href, so prose mentioning the words is left alone.
    $new = str_replace('href="' . $old . '"', 'href="' . $current . '"', $new);
  }
  if ($new === $body) {
    continue;
  }
  $changed++;
  printf("  %s tid %s (%s): link -> %s\n",
    $apply ? 'FIX   ' : 'would ', $term->id(), Html::escape($term->label()), $current);
  if ($apply) {
    $term->set('description', ['value' => $new, 'format' => $term->get('description')->format]);
    $term->save();
  }
}

if (!$changed) {
  print "  nothing to change — all links already current.\n";
}
elseif (!$apply) {
  print "\nDRY RUN. Re-run with BOS_FRUIT_LINK_APPLY=1 to write.\n";
}
