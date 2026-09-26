<?php

declare(strict_types=1);

/**
 * Disable the RSS feed display on the core `taxonomy_term` view.
 *
 * That view's `feed_1` display is attached to the term page (`page_1`), so it
 * injects a <link rel="alternate" type="application/rss+xml"> + a visible feed
 * icon on EVERY taxonomy term page site-wide (services, backflow, tags, …) and
 * exposes a /taxonomy/term/%/feed route. Nothing in BOS consumes these feeds and
 * they are clutter on the public service pages. Disabling the display removes the
 * link, the icon, and the /feed route.
 *
 * Fully reversible: set enabled back to TRUE (or re-enable the display in the
 * Views UI). Edits active config via the View entity API (routes rebuild on
 * save); the view is not managed by cim here. Idempotent; run per env.
 *
 *   drush php:script web/scripts/disable_taxonomy_term_feed.php
 */

$view = \Drupal::entityTypeManager()->getStorage('view')->load('taxonomy_term');
if (!$view) {
  print "core taxonomy_term view not found — nothing to do.\n";
  return;
}

$display = $view->get('display');
if (!isset($display['feed_1'])) {
  print "no feed_1 display on taxonomy_term — nothing to do.\n";
  return;
}

$current = $display['feed_1']['display_options']['enabled'] ?? TRUE;
if ($current === FALSE) {
  print "feed_1 already disabled — nothing to do.\n";
  return;
}

$display['feed_1']['display_options']['enabled'] = FALSE;
$view->set('display', $display);
$view->save();
print "Disabled the RSS feed display (feed_1) on the taxonomy_term view.\n";
print "DONE.\n";
