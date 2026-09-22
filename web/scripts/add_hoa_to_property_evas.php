<?php

/**
 * Attach the property-detail EVAs (mowing, spraying, fertilizing, snow, contacts,
 * instructions, landscape details, weed pulling, backflow devices, etc.) to the
 * `hoa` properties bundle as well as `property`. The HOA bundle is a superset of
 * property, so these panels should render on HOA pages the same as residential.
 *
 * Adds `hoa` to any entity_view (EVA) display whose entity_type is `properties`
 * and whose bundles list contains `property` but not `hoa`. Empty bundle lists
 * already attach to every bundle, so they're left alone. Idempotent, no cim.
 *
 * Run per env:
 *   drush php:script web/scripts/add_hoa_to_property_evas.php
 */

$views = \Drupal::entityTypeManager()->getStorage('view')->loadMultiple();
$changed = [];

foreach ($views as $view) {
  $display = $view->get('display');
  $dirty = FALSE;
  foreach ($display as $did => &$d) {
    if (($d['display_plugin'] ?? '') !== 'entity_view') {
      continue;
    }
    $o = &$d['display_options'];
    if (($o['entity_type'] ?? '') !== 'properties') {
      continue;
    }
    $bundles = (array) ($o['bundles'] ?? []);
    if ($bundles && in_array('property', $bundles, TRUE) && !in_array('hoa', $bundles, TRUE)) {
      $bundles[] = 'hoa';
      $o['bundles'] = array_values($bundles);
      $dirty = TRUE;
      $changed[] = $view->id() . ':' . $did;
    }
    unset($o);
  }
  unset($d);
  if ($dirty) {
    $view->set('display', $display);
    $view->save();
  }
}

print $changed ? ("Added hoa to:\n  " . implode("\n  ", $changed) . "\n") : "Nothing to change (all already include hoa).\n";
print "DONE.\n";
