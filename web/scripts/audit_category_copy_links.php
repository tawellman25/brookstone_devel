<?php

/**
 * @file
 * READ-ONLY: check every internal link in the category copy actually resolves.
 *
 * The copy files were written from the structure we designed rather than from
 * what got built, and the two have diverged twice (/fruit vs /fruit-trees). This
 * sweeps the stored copy on THIS environment and reports any link that does not
 * resolve, so a 404 is found here rather than by a customer.
 *
 * Scope: field_public_description, field_call_to_action, field_short_description
 * and field_teammate_description on the material_types and plant_characteristics
 * vocabularies.
 */

$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$aliasManager = \Drupal::service('path_alias.manager');
$fields = ['field_public_description', 'field_call_to_action', 'field_short_description', 'field_teammate_description'];
$checked = $ok = $bad = 0;
$problems = [];

foreach (['material_types', 'plant_characteristics'] as $vid) {
  foreach ($ts->loadTree($vid, 0, NULL, TRUE) as $term) {
    $html = '';
    foreach ($fields as $f) {
      if ($term->hasField($f) && !$term->get($f)->isEmpty()) { $html .= "\n" . $term->get($f)->value; }
    }
    if ($html === '') { continue; }
    // Delimiter is ~ : the pattern contains '#', which collided with a '#'
    // delimiter and silently matched nothing — a zero-link "pass".
    if (!preg_match_all('~href="(/[^"#?]*)~', $html, $m)) { continue; }
    $self = $aliasManager->getAliasByPath('/taxonomy/term/' . $term->id());
    foreach (array_unique($m[1]) as $href) {
      // Skip the shared CTA targets; they are routes, not aliases.
      if (in_array($href, ['/request-estimate', '/contact'], TRUE)) { continue; }
      $checked++;
      $internal = $aliasManager->getPathByAlias($href);
      $resolves = $internal !== $href;
      if (!$resolves) {
        // Might still be a real route rather than an alias.
        try {
          $resolves = (bool) \Drupal::service('router')->match($href);
        }
        catch (\Throwable $e) { $resolves = FALSE; }
      }
      if ($resolves) { $ok++; }
      else {
        $bad++;
        $problems[] = sprintf('%s (%s)  ->  %s', $term->label(), $self, $href);
      }
    }
  }
}

printf("links checked: %d    resolve: %d    BROKEN: %d\n", $checked, $ok, $bad);
foreach ($problems as $p) { print "   BROKEN  $p\n"; }
if ($checked === 0) {
  print "\nFOUND NO LINKS AT ALL — that is a broken audit, not a clean result.\n";
}
elseif (!$bad) {
  printf("\nEvery one of the %d internal links in the category copy resolves.\n", $checked);
}
