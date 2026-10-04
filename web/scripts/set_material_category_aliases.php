<?php

declare(strict_types=1);

/**
 * Alias the two new material categories.
 *
 * material_types has NO pathauto pattern - its aliases are set by hand - so a
 * newly created category term has no URL and 404s. The established convention
 * is /material/{BUNDLE MACHINE NAME}, not the term name: the term called "Rock"
 * lives at /material/decorative_rock. That is derived from the existing
 * root-level categories below rather than assumed.
 *
 *   drush php:script web/scripts/set_material_category_aliases.php
 *   BOS_ALIAS_APPLY=1 drush php:script web/scripts/set_material_category_aliases.php
 */

use Drupal\path_alias\Entity\PathAlias;

$apply = getenv('BOS_ALIAS_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$db = \Drupal::database();
$am = \Drupal::service('path_alias.manager');

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_ALIAS_APPLY=1 to write)\n\n";

// Prove the convention from live data before applying it.
print "existing root-level categories (alias vs bundle vs term name):\n";
$conv = 0; $tot = 0;
foreach ($db->query("SELECT entity_id, field_material_bundle_value AS b FROM {taxonomy_term__field_material_bundle}") as $r) {
  $t = $etm->getStorage('taxonomy_term')->load($r->entity_id);
  if (!$t || (int) $t->get('parent')->target_id !== 0) { continue; }
  $a = $am->getAliasByPath('/taxonomy/term/' . $r->entity_id);
  if (str_starts_with($a, '/taxonomy/')) { continue; }
  $tot++;
  $matches = $a === '/material/' . $r->b;
  if ($matches) { $conv++; }
  if (!$matches) { printf("  %-26s %-18s term \"%s\"  <- does NOT follow it\n", $a, $r->b, $t->label()); }
}
printf("  %d of %d root categories use /material/{bundle}\n\n", $conv, $tot);
if ($tot && $conv / $tot < 0.8) { print "ABORT — the convention does not hold; not guessing.\n"; return; }

foreach (['bulk_material', 'supplies'] as $bundle) {
  $tid = $db->query("SELECT entity_id FROM {taxonomy_term__field_material_bundle} WHERE field_material_bundle_value=:b",
    [':b' => $bundle])->fetchField();
  if (!$tid) { printf("  %-16s no category term — run create_material_category_terms.php first\n", $bundle); continue; }
  $sys = '/taxonomy/term/' . $tid;
  $cur = $am->getAliasByPath($sys);
  $want = '/material/' . $bundle;
  if ($cur === $want) { printf("✓ %-16s already %s\n", $bundle, $want); continue; }
  if ($cur !== $sys) { printf("⚠ %-16s already has a different alias (%s) — left alone\n", $bundle, $cur); continue; }
  // Never collide with an existing alias.
  if ($am->getPathByAlias($want) !== $want) {
    printf("⚠ %-16s %s is already taken — left alone\n", $bundle, $want); continue;
  }
  printf("+ %-16s %s -> %s\n", $bundle, $sys, $want);
  if ($apply) { PathAlias::create(['path' => $sys, 'alias' => $want, 'langcode' => 'en'])->save(); }
}

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }
drupal_flush_all_caches();
print "\nDone.\n";
