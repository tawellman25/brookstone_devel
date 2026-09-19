<?php

/**
 * Open Graph (Facebook/social share) defaults. Requires metatag_open_graph.
 *
 *  - Global: og:site_name, og:type, and a default og:image (the homepage hero)
 *    so every page has a sensible share image + card. og:title/og:description
 *    fall back to the page <title> / meta description, which Facebook honors.
 *  - Services: og:image = the service's own banner photo (token), so each
 *    service page shares its own image. Per-service field_meta_tags overrides
 *    still win for title/description; og:image falls through to this.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/setup_open_graph_defaults.php
 */

use Drupal\metatag\Entity\MetatagDefaults;

$DEFAULT_IMAGE = '[site:url]modules/custom/bos_homepage/assets/home-hero.jpg';
$out = [];

// Global defaults.
$global = MetatagDefaults::load('global');
if ($global) {
  $tags = $global->get('tags') ?: [];
  $tags['og_site_name'] = 'Brookstone Outdoors';
  $tags['og_type'] = 'website';
  $tags['og_image'] = $DEFAULT_IMAGE;
  $tags['og_title'] = '[current-page:title] | Brookstone Outdoors';
  $global->set('tags', $tags)->save();
  $out[] = 'global: og:site_name + og:type + og:image + og:title set';
}
else {
  $out[] = 'global metatag defaults not found (unexpected)';
}

// Services bundle default: share the service's banner photo.
$svc = MetatagDefaults::load('taxonomy_term__services')
  ?: MetatagDefaults::create(['id' => 'taxonomy_term__services', 'label' => 'Taxonomy term: Services']);
$tags = $svc->get('tags') ?: [];
$tags['og_image'] = '[term:field_banner_image:entity:url]';
$svc->set('tags', $tags)->save();
$out[] = 'taxonomy_term__services: og:image = banner token';

print implode("\n", $out) . "\nDONE.\n";
