<?php

/**
 * @file
 * Reverse exclude_hoa_from_property_lists.php: crews DO service HOA common areas,
 * so HOAs must NOT vanish from crew-facing property lists. Remove the non-exposed
 * type=property filter from teammate_properties (crew list + map + blocks) and
 * the properties_new block so ALL property types (property, hoa, future
 * commercial) show. Admin /admin/properties keeps its exposed Type filter.
 * Idempotent; run per env.
 *   drush php:script web/scripts/restore_hoa_in_property_lists.php
 */

foreach (['teammate_properties', 'properties_new'] as $vid) {
  $v = \Drupal::entityTypeManager()->getStorage('view')->load($vid);
  if (!$v) { print "$vid: MISSING\n"; continue; }
  $display = $v->get('display');
  $filters = $display['default']['display_options']['filters'] ?? [];
  if (!isset($filters['type'])) { print "$vid: no type filter — nothing to remove\n"; continue; }
  unset($filters['type']);
  $display['default']['display_options']['filters'] = $filters;
  $v->set('display', $display);
  $v->save();
  print "$vid: removed type filter — HOAs (and all property types) now show\n";
}
print "DONE.\n";
