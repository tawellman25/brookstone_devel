<?php

/**
 * Open Graph (Facebook/social share) defaults. Requires metatag_open_graph.
 *
 *  - Global: og:site_name, og:type, og:title, and a default og:image (the
 *    homepage hero) so every page has a sensible share image. og:description
 *    falls back to the page meta description, which Facebook honors.
 *  - Services: the SHARE image should be the service's framed "Home Page Slide"
 *    (field_home_page_slide) — NOT the wide banner (which is cropped for the
 *    hero). Only ~18 of 60 services have a slide, and a bundle-level token that
 *    resolves empty SUPPRESSES the global fallback — so instead of a bundle
 *    default we set og:image per-service in field_meta_tags ONLY where a slide
 *    exists; the rest inherit the global default image.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/setup_open_graph_defaults.php
 */

use Drupal\metatag\Entity\MetatagDefaults;

$DEFAULT_IMAGE = '[site:url]modules/custom/bos_homepage/assets/home-hero.jpg';
$SLIDE_TOKEN = '[term:field_home_page_slide:entity:url]';
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

// Remove any services BUNDLE default og:image so slide-less services fall back
// to the global default image (a bundle token that resolves empty would blank it).
$svc = MetatagDefaults::load('taxonomy_term__services');
if ($svc) {
  $tags = $svc->get('tags') ?: [];
  unset($tags['og_image']);
  if (empty($tags)) {
    $svc->delete();
    $out[] = 'removed empty taxonomy_term__services default';
  }
  else {
    $svc->set('tags', $tags)->save();
    $out[] = 'cleared og:image from taxonomy_term__services default';
  }
}

// Per-service: set og:image = the service's own Home Page Slide, only where one
// exists. Merge into any existing field_meta_tags (title/description) overrides.
$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$tids = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'services')->execute();
$set = 0;
foreach ($ts->loadMultiple($tids) as $term) {
  $hasSlide = $term->hasField('field_home_page_slide') && !$term->get('field_home_page_slide')->isEmpty();
  if (!$hasSlide || !$term->hasField('field_meta_tags')) {
    continue;
  }
  $raw = $term->get('field_meta_tags')->value;
  $tags = $raw ? @unserialize($raw) : [];
  if (!is_array($tags)) {
    $tags = [];
  }
  if (($tags['og_image'] ?? NULL) !== $SLIDE_TOKEN) {
    $tags['og_image'] = $SLIDE_TOKEN;
    $term->set('field_meta_tags', ['value' => serialize($tags)])->save();
    $set++;
  }
}
$out[] = "per-service og:image (Home Page Slide) set on $set service(s) with a slide";

print implode("\n", $out) . "\nDONE.\n";
