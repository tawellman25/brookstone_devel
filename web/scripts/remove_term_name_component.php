<?php
/**
 * Stop a vocabulary printing its own name inside the page body.
 *
 * A taxonomy term page already prints the term name twice over before any field
 * renders it: the core `page_title_block` puts it in the page heading, and on a
 * public page with a banner the hero carries it as the H1 instead. So a `name`
 * component enabled on the view display is a THIRD copy — which is what Todd saw
 * on the Fall admin page: "Fall" as the page heading, then "Fall" again at the
 * top of the body.
 *
 * Removing the component leaves the title coming from exactly one place per
 * audience: the hero for the public where a banner exists, the page title block
 * everywhere else. Neither can be switched off by this change, so the title can
 * never disappear.
 *
 * ⚠ Not run as a sweep. Todd's standing instruction is to convert a surface while
 * already working on it (2026-10-11), so this takes a vocabulary and does one.
 * `material_types` has the same duplication and is deliberately left.
 *
 * Usage:
 *   BOS_VID=season                    drush php:script …   (dry run)
 *   BOS_VID=season BOS_NAME_APPLY=1   drush php:script …
 */
$vid = getenv('BOS_VID') ?: '';
$apply = getenv('BOS_NAME_APPLY') === '1';
if ($vid === '') {
  print "  ABORT: set BOS_VID to the vocabulary machine name.\n";
  return;
}
printf("%s — %s\n\n", $apply ? 'APPLYING' : 'DRY RUN (set BOS_NAME_APPLY=1)', $vid);

$repo = \Drupal::service('entity_display.repository');
$modes = array_merge(['default'], array_keys($repo->getViewModeOptionsByBundle('taxonomy_term', $vid)));
$modes = array_values(array_unique($modes));
$changed = 0;

foreach ($modes as $mode) {
  $d = $repo->getViewDisplay('taxonomy_term', $vid, $mode);
  if ($d->isNew()) {
    continue;
  }
  if (!$d->getComponent('name')) {
    printf("  %-16s name not shown — nothing to do\n", $mode);
    continue;
  }
  printf("  %-16s REMOVING the duplicate name component\n", $mode);
  $changed++;
  if ($apply) {
    $d->removeComponent('name')->save();
  }
}
printf("\n  %d display(s) %s\n", $changed, $apply ? 'changed' : 'would change');
print $apply ? "\ndone\n" : "\nnothing written\n";
