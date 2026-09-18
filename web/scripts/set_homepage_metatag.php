<?php

/**
 * Hand-tuned homepage (<front>) SEO title + meta description via Metatag's
 * "front" default (overrides the global pattern for the home page only).
 *
 * Title kept ~60 chars so the geo stays visible in Google's SERP; description
 * ~155 chars with services + service area + the "since 1995" trust signal.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/set_homepage_metatag.php
 */

$title = 'Brookstone Outdoors | Landscaping & Snow | Delta & Montrose CO';
$description = 'Full-service landscaping, irrigation, lawn care, lighting & snow removal in Delta & Montrose counties, CO. Locally owned, serving the valley since 1995.';

$c = \Drupal::configFactory()->getEditable('metatag.metatag_defaults.front');
if ($c->isNew()) {
  print "SKIP: metatag.metatag_defaults.front not present (is metatag enabled?)\n";
  return;
}
$tags = $c->get('tags') ?: [];
$tags['title'] = $title;
$tags['description'] = $description;
$c->set('tags', $tags)->save();

print "homepage title:       \"$title\" (" . strlen($title) . " chars)\n";
print "homepage description: \"$description\" (" . strlen($description) . " chars)\n";
print "DONE.\n";
