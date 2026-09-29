<?php
declare(strict_types=1);
/**
 * Repoint the two hardcoded links to the retired Junipers category.
 *
 * The category page was dissolved into Evergreens › Juniper. A 301 covers the
 * old path, but an internal link should not need one — and if the redirect is
 * ever pruned the link dies silently.
 *
 * Resolves the destination at run time rather than hardcoding it, so this stays
 * right if the category moves again.
 *
 * Idempotent. Dry-run by default; BOS_JLINK_APPLY=1 to write.
 */
$apply = getenv('BOS_JLINK_APPLY') === '1';
$terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$target = NULL;
foreach ($terms->loadByProperties(['vid' => 'material_types', 'name' => 'Juniper']) as $t) {
  $target = $t;
}
if (!$target) {
  print "no Juniper category — aborting.\n";
  return;
}
$new = \Drupal::service('path_alias.manager')->getAliasByPath('/taxonomy/term/' . $target->id());
printf("destination: %s\n", $new);

$changed = 0;
foreach ($terms->loadByProperties(['vid' => 'material_types']) as $t) {
  if (!$t->hasField('field_public_description') || $t->get('field_public_description')->isEmpty()) {
    continue;
  }
  $body = (string) $t->get('field_public_description')->value;
  // Every path this link has had: the original, and the one the Plants
  // reparent gave it this morning. It was on the FIRST, so it was already
  // redirecting twice before the category was even dissolved.
  $updated = $body;
  foreach (['/material/trees/junipers', '/material/plants/trees/junipers'] as $old) {
    $updated = str_replace('href="' . $old . '"', 'href="' . $new . '"', $updated);
  }
  if ($updated === $body) {
    continue;
  }
  printf("  %s %s (tid %s)\n", $apply ? 'FIX   ' : 'would ', $t->label(), $t->id());
  $changed++;
  if ($apply) {
    $t->set('field_public_description', ['value' => $updated, 'format' => $t->get('field_public_description')->format]);
    $t->save();
  }
}
print $changed ? '' : "  nothing to change\n";
if (!$apply && $changed) { print "\nDRY RUN. BOS_JLINK_APPLY=1 to write.\n"; }
