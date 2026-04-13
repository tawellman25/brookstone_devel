<?php

/**
 * @file
 * Ensures the 4 department parent menu links exist and updates all department
 * admin views to reference them as their menu parent.
 *
 * Departments: Spray, Irrigation, Lighting, Material Info
 *
 * Safe to run multiple times — checks for existing menu links before creating.
 * This does NOT create the landing page entities or path aliases — it only
 * handles the menu link parenting for the view configs.
 *
 * Usage:
 *   ddev drush php:script dev_scripts/fix_department_menu_parents.php
 *   drush php:script dev_scripts/fix_department_menu_parents.php        (on live)
 */

$menu_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$config_factory = \Drupal::configFactory();

// ── 1. Find the System Content menu link (grandparent for all departments) ──
$system_content_links = $menu_storage->loadByProperties([
  'title' => 'System Content',
  'menu_name' => 'admin',
]);

if (empty($system_content_links)) {
  echo "ERROR: 'System Content' menu link not found in admin menu. Aborting.\n";
  return;
}

$system_content_link = reset($system_content_links);
$system_content_uuid = $system_content_link->uuid();
echo "Found System Content menu link (UUID: $system_content_uuid)\n\n";

// ── 2. Department definitions ──────────────────────────────────────────────
$departments = [
  'Spray Department' => [
    'path' => 'internal:/admin/operations/system_content/spray_department',
    'weight' => 2,
    'views' => [
      'admin_spraying_locations',
      'admin_spraying_carrier',
      'admin_spraying_emergence_types',
      'admin_spraying_frequency',
      'admin_spraying_methods',
      'admin_spraying_signal_words',
      'admin_spraying_soil_moisture',
      'admin_spraying_weed_categories',
      'admin_spraying_weed_growth_stages',
      'admin_spraying_wind_direction',
      'admin_spraying_wind_speed',
    ],
  ],
  'Irrigation Department' => [
    'path' => 'internal:/admin/operations/system_content/irrigation_department',
    'weight' => 3,
    'views' => [
      'admin_irrigation_check_up_frequency',
      'admin_irrigation_sprinkler_system_types',
      'admin_irrigation_sprinkler_types',
      'admin_irrigation_system_complexity',
      'admin_irrigation_system_operation',
    ],
  ],
  'Lighting Department' => [
    'path' => 'internal:/admin/operations/system_content/lighting_department',
    'weight' => 4,
    'views' => [
      'admin_lighting_christmas_colors',
      'admin_lighting_christmas_types',
    ],
  ],
  'Material Info' => [
    'path' => 'internal:/admin/operations/system_content/material_info',
    'weight' => 5,
    'views' => [
      'admin_hardiness_zones',
      'admin_material_bloom_time',
      'admin_material_plant_characteristics',
      'admin_material_rock_types',
      'admin_material_supplier_types',
      'admin_material_tags',
      'admin_material_types',
    ],
  ],
];

$total_views_updated = 0;

// ── 3. For each department: ensure menu link exists, update view parents ──
foreach ($departments as $title => $info) {
  echo "── $title ──\n";

  // Load or create the parent menu link.
  $existing = $menu_storage->loadByProperties([
    'title' => $title,
    'menu_name' => 'admin',
  ]);

  if (!empty($existing)) {
    $link = reset($existing);
    echo "  Menu link exists (ID {$link->id()}, UUID {$link->uuid()})\n";
  }
  else {
    $link = $menu_storage->create([
      'title' => $title,
      'link' => ['uri' => $info['path']],
      'menu_name' => 'admin',
      'parent' => 'menu_link_content:' . $system_content_uuid,
      'weight' => $info['weight'],
      'expanded' => TRUE,
      'enabled' => TRUE,
    ]);
    $link->save();
    echo "  Created menu link (ID {$link->id()}, UUID {$link->uuid()})\n";
  }

  $parent_value = 'menu_link_content:' . $link->uuid();

  // Update each view's menu parent.
  foreach ($info['views'] as $view_id) {
    $config = $config_factory->getEditable('views.view.' . $view_id);
    if ($config->isNew()) {
      echo "  [!] View $view_id not found — skipped\n";
      continue;
    }

    $current_parent = $config->get('display.page_1.display_options.menu.parent');
    if ($current_parent === $parent_value) {
      echo "  ✓ $view_id already correct\n";
      continue;
    }

    $config->set('display.page_1.display_options.menu.parent', $parent_value);
    $config->save();
    echo "  → Updated $view_id\n";
    $total_views_updated++;
  }

  echo "\n";
}

// ── 4. Clear cache ─────────────────────────────────────────────────────────
drupal_flush_all_caches();
echo "Done. $total_views_updated views updated. Caches cleared.\n";
