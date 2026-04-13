<?php

/**
 * Drush PHP script: Create Mulch and Backflow material_types taxonomy terms.
 *
 * Creates the taxonomy terms and path aliases needed for the new material
 * bundles to have proper landing pages.
 *
 * Usage (on live server, in Drupal root):
 *   drush php:script create_material_type_terms.php
 *
 * Safe to run multiple times — checks for existing terms before creating.
 */

$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');

$terms = [
  [
    'name' => 'Mulch',
    'alias' => '/material/mulch',
    'public' => '<p>Brookstone Outdoors offers a selection of mulch products for landscape beds, tree rings, and ground cover applications. Mulch helps retain soil moisture, suppress weeds, regulate soil temperature, and enhance the appearance of your landscape.</p>',
    'teammate' => '<p>Mulch materials for landscape bed coverage, tree rings, and ground cover. Spread evenly at 2-3 inches depth. Keep mulch pulled back from plant stems and tree root flares to prevent moisture damage.</p>',
  ],
  [
    'name' => 'Backflow',
    'alias' => '/material/backflow',
    'public' => '<p>Brookstone Outdoors carries a complete line of backflow prevention devices and replacement parts for residential and commercial irrigation systems. Our inventory includes Febco pressure vacuum breakers (PVBs), reduced pressure assemblies (RPAs), double check assemblies (DCAs), atmospheric vacuum breakers (AVBs), and all associated repair kits, test cocks, and ball valves.</p>',
    'teammate' => '<p>Backflow prevention devices and parts — PVBs, RPAs, DCAs, AVBs, repair kits, test cocks, and ball valves. All Febco products. Ensure correct sizing when replacing components. Backflow testing and certification requirements apply per Colorado regulations.</p>',
  ],
];

foreach ($terms as $t) {
  // Check if term already exists.
  $existing = $term_storage->loadByProperties([
    'vid' => 'material_types',
    'name' => $t['name'],
  ]);

  if (!empty($existing)) {
    $term = reset($existing);
    echo "{$t['name']} term already exists: TID {$term->id()}\n";
  }
  else {
    $term = $term_storage->create([
      'vid' => 'material_types',
      'name' => $t['name'],
      'field_public_description' => [
        'value' => $t['public'],
        'format' => 'full_html',
      ],
      'field_teammate_description' => [
        'value' => $t['teammate'],
        'format' => 'full_html',
      ],
    ]);
    $term->save();
    echo "Created {$t['name']} term: TID {$term->id()}\n";
  }

  // Check if path alias already exists.
  $existing_alias = $alias_storage->loadByProperties(['alias' => $t['alias']]);
  if (!empty($existing_alias)) {
    echo "  Path alias {$t['alias']} already exists.\n";
  }
  else {
    $alias_storage->create([
      'path' => '/taxonomy/term/' . $term->id(),
      'alias' => $t['alias'],
    ])->save();
    echo "  Created path alias: {$t['alias']}\n";
  }
}

echo "\nDone.\n";
