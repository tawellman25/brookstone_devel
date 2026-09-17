<?php

/**
 * Set the site's <title> pattern to keep the brand and add the local-SEO geo
 * suffix, across the Metatag defaults that actually drive page titles:
 *   global         -> [current-page:title] | Brookstone Outdoors | Delta & Montrose CO
 *   node           -> [node:title]         | Brookstone Outdoors | Delta & Montrose CO
 *   taxonomy_term  -> [term:name]          | Brookstone Outdoors | Delta & Montrose CO
 *
 * (Metatag's per-entity-type defaults override global, so node + taxonomy_term
 *  must be set too. `user` is left brand-only — teammate/user pages aren't SEO
 *  targets.)
 *
 * Idempotent; entity-API (no cim). Requires metatag enabled. Run per env:
 *   drush php:script web/scripts/set_metatag_title_defaults.php
 */

$SUFFIX = ' | Brookstone Outdoors | Delta & Montrose CO';
$titles = [
  'global' => '[current-page:title]' . $SUFFIX,
  'node' => '[node:title]' . $SUFFIX,
  'taxonomy_term' => '[term:name]' . $SUFFIX,
];

$out = [];
foreach ($titles as $id => $title) {
  $c = \Drupal::configFactory()->getEditable("metatag.metatag_defaults.$id");
  if ($c->isNew()) {
    $out[] = "SKIP $id — metatag default not present (is metatag enabled?)";
    continue;
  }
  $tags = $c->get('tags') ?: [];
  if (($tags['title'] ?? '') === $title) {
    $out[] = "$id already set";
    continue;
  }
  $tags['title'] = $title;
  $c->set('tags', $tags)->save();
  $out[] = "$id -> \"$title\"";
}

print implode("\n", $out) . "\nDONE.\n";
