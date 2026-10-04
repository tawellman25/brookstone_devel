<?php

declare(strict_types=1);

/**
 * Footer services menu: the Lighting link.
 *
 * It was titled "Landscape Lighting" and pointed at base:lighting - a hardcoded
 * path that 301s to /services/lighting. Two problems, and the split made the
 * first one matter: the destination is the PARENT Lighting page, so a link
 * labelled "Landscape Lighting" now sends people to the wrong one of two pages
 * that are genuinely different.
 *
 * Every other service link in this menu uses the taxonomy term route. This one
 * was the only hardcoded path, so it is brought in line: the term route resolves
 * through the alias system, takes no redirect hop, and survives any future alias
 * change.
 *
 * The /lighting redirect itself is LEFT IN PLACE - it was created deliberately
 * on 19 September and is still doing its job for anything external.
 *
 *   drush php:script web/scripts/fix_footer_lighting_link.php
 *   BOS_MENU_APPLY=1 drush php:script web/scripts/fix_footer_lighting_link.php
 */

$apply = getenv('BOS_MENU_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$TITLE = 'Lighting';
$TERM = 1505;

print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_MENU_APPLY=1 to write)\n\n";

// The destination term must be the one we think it is.
$term = $etm->getStorage('taxonomy_term')->load($TERM);
if (!$term || $term->bundle() !== 'services') { print "ABORT — term $TERM is not a services term.\n"; return; }
printf("destination: term %d \"%s\" at %s\n", $TERM, $term->label(),
  \Drupal::service('path_alias.manager')->getAliasByPath('/taxonomy/term/' . $TERM));

// Find the link by its CURRENT uri rather than by title, so a re-run after the
// title changes still finds nothing to do instead of matching the wrong link.
$want = 'route:entity.taxonomy_term.canonical;taxonomy_term=' . $TERM . '&view_id=taxonomy_term&display_id=page_1';
$found = NULL;
foreach ($etm->getStorage('menu_link_content')->loadMultiple() as $l) {
  if ($l->getMenuName() !== 'footer-services') { continue; }
  $uri = $l->getUrlObject()->toUriString();
  if ($uri === 'base:lighting' || ($uri === $want && $l->getTitle() === $TITLE)) { $found = $l; break; }
}
if (!$found) { print "ABORT — no footer-services link at base:lighting (already fixed, or moved).\n"; return; }

printf("\n  link %-4s title  %-22s -> %s\n", $found->id(), '"' . $found->getTitle() . '"', '"' . $TITLE . '"');
printf("            uri    %-22s -> %s\n", $found->getUrlObject()->toUriString(), $want);

if (!$apply) { print "\n(dry-run — nothing written)\n"; return; }

$found->set('title', $TITLE);
$found->set('link', ['uri' => $want]);
$found->save();
drupal_flush_all_caches();

// Prove it by reading the saved link back.
$re = $etm->getStorage('menu_link_content')->load($found->id());
printf("\nSaved: \"%s\" -> %s\n", $re->getTitle(), $re->getUrlObject()->toString());
