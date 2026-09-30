<?php

/**
 * @file
 * Repair internal links that predate Trees moving under Plants.
 *
 * Four links on the Trees category still point at /material/trees/... , which
 * 404s — the path has been /material/plants/trees/... since the move. Found by
 * audit_category_copy_links.php.
 *
 * Idempotent and narrow: only "/material/trees/" is rewritten, and a negative
 * lookahead means an already-correct "/material/plants/trees/" can never be
 * turned into "/material/plants/plants/trees/". Dry run unless BOS_LINKFIX_APPLY=1.
 */

$apply = (bool) getenv('BOS_LINKFIX_APPLY');
$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$fields = ['field_public_description', 'field_call_to_action', 'field_short_description', 'field_teammate_description'];
// href="/material/trees/  ->  href="/material/plants/trees/
$pattern = '~(href=")/material/(?!plants/)trees/~';
$fixed = 0;

foreach (['material_types', 'plant_characteristics'] as $vid) {
  foreach ($ts->loadTree($vid, 0, NULL, TRUE) as $term) {
    $changed = [];
    foreach ($fields as $f) {
      if (!$term->hasField($f) || $term->get($f)->isEmpty()) { continue; }
      $val = (string) $term->get($f)->value;
      $new = preg_replace($pattern, '$1/material/plants/trees/', $val);
      if ($new !== $val) {
        $changed[] = $f;
        if ($apply) {
          $item = $term->get($f)->first()->getValue();
          $item['value'] = $new;
          $term->set($f, $item);
        }
      }
    }
    if (!$changed) { continue; }
    if ($apply) { $term->save(); }
    printf("%s %-20s %s\n", $apply ? 'fixed' : 'would fix', $term->label(), implode(', ', $changed));
    $fixed++;
  }
}

printf("\n%d term(s) %s.\n", $fixed, $apply ? 'updated' : 'would change');
print $apply ? '' : "Dry run. Set BOS_LINKFIX_APPLY=1 to apply.\n";
