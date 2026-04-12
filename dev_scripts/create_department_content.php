<?php

/**
 * Drush PHP script: Create all department database content.
 *
 * Creates:
 * 1. Spray Department menu link, landing page, path alias
 * 2. Irrigation Department menu link, landing page, path alias
 * 3. Lighting Department menu link, landing page, path alias
 * 4. Material Info menu link, landing page, path alias
 * 5. Updates all department view configs to use correct menu parent UUIDs
 * 6. Updates System Content landing page (entity 2) with department links
 *
 * Usage (on live server, in Drupal root):
 *   drush php:script create_department_content.php
 *
 * Safe to run multiple times — checks for existing content before creating.
 */

$menu_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$slp_storage = \Drupal::entityTypeManager()->getStorage('site_landing_page');
$alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');

// ── Find System Content menu link (parent) ──────────────────────────────────
$system_content_links = $menu_storage->loadByProperties([
  'title' => 'System Content',
  'menu_name' => 'admin',
]);

if (empty($system_content_links)) {
  echo "ERROR: Could not find 'System Content' menu link. Aborting.\n";
  return;
}

$system_content_link = reset($system_content_links);
$system_content_uuid = $system_content_link->uuid();
echo "Found System Content menu link: UUID $system_content_uuid\n\n";

// ── Helper: create or find a department menu link ───────────────────────────
function ensure_department_menu_link($menu_storage, $title, $path, $parent_uuid, $weight) {
  $existing = $menu_storage->loadByProperties([
    'title' => $title,
    'menu_name' => 'admin',
  ]);

  if (!empty($existing)) {
    $link = reset($existing);
    echo "$title menu link already exists: UUID " . $link->uuid() . "\n";
    return $link->uuid();
  }

  $link = $menu_storage->create([
    'title' => $title,
    'link' => ['uri' => 'internal:' . $path],
    'menu_name' => 'admin',
    'parent' => 'menu_link_content:' . $parent_uuid,
    'weight' => $weight,
    'expanded' => TRUE,
    'enabled' => TRUE,
  ]);
  $link->save();
  echo "Created $title menu link: ID {$link->id()}, UUID {$link->uuid()}\n";
  return $link->uuid();
}

// ── Helper: create or find a landing page ───────────────────────────────────
function ensure_landing_page($slp_storage, $alias_storage, $title, $alias, $description) {
  $existing = $alias_storage->loadByProperties(['alias' => $alias]);
  if (!empty($existing)) {
    $a = reset($existing);
    echo "$title landing page already exists at {$a->get('path')->value}\n";
    return;
  }

  $entity = $slp_storage->create([
    'type' => 'office_administration',
    'title' => $title,
    'field_description' => ['value' => $description, 'format' => 'full_html'],
  ]);
  $entity->save();
  echo "Created $title landing page: ID {$entity->id()}\n";

  $path_alias = $alias_storage->create([
    'path' => '/site_landing_page/' . $entity->id(),
    'alias' => $alias,
  ]);
  $path_alias->save();
  echo "Created path alias: $alias\n";
}

// ── Helper: update view menu parents ────────────────────────────────────────
function update_view_parents($view_ids, $parent_value) {
  $updated = 0;
  foreach ($view_ids as $view_id) {
    $config = \Drupal::configFactory()->getEditable('views.view.' . $view_id);
    if ($config->isNew()) {
      echo "  View $view_id not found — skipped.\n";
      continue;
    }
    $current = $config->get('display.page_1.display_options.menu.parent');
    if ($current !== $parent_value) {
      $config->set('display.page_1.display_options.menu.parent', $parent_value);
      $config->save();
      echo "  Updated $view_id\n";
      $updated++;
    }
    else {
      echo "  $view_id already correct.\n";
    }
  }
  return $updated;
}

// ═══════════════════════════════════════════════════════════════════════════
// SPRAY DEPARTMENT
// ═══════════════════════════════════════════════════════════════════════════
echo "\n── Spray Department ──────────────────────────────────────────\n";

$spray_uuid = ensure_department_menu_link(
  $menu_storage,
  'Spray Department',
  '/admin/operations/system_content/spray_department',
  $system_content_uuid,
  2
);

$spray_description = "<p>The <strong>Spray Department</strong> section provides tools and reference data for managing weed control, fertilizing, and pest management operations.</p>
<ul>
<li><a href=\"/admin/operations/system_content/spray_department/site_locations\"><strong>Locations:</strong></a> Manage the spraying location types (Lawn, Landscape Beds, Gravel, Driveway, etc.) including teammate field instructions and applicable service assignments.</li>
<li><a href=\"/admin/operations/system_content/spray_department/weeds\"><strong>Weeds:</strong></a> Reference library of lawn and garden pests — weed identification, plant species, life cycle classification, and category tagging.</li>
<li><a href=\"/admin/operations/system_content/spray_department/carrier\"><strong>Carrier:</strong></a> The different types of carriers used in spraying operations.</li>
<li><a href=\"/admin/operations/system_content/spray_department/emergence_types\"><strong>Emergence Types:</strong></a> Times of emergence that we spray for — Pre and Post.</li>
<li><a href=\"/admin/operations/system_content/spray_department/frequency\"><strong>Frequency:</strong></a> Options for how many times weeds should be sprayed.</li>
<li><a href=\"/admin/operations/system_content/spray_department/methods\"><strong>Methods:</strong></a> Application methods used to apply fertilizers and chemicals, with applicable service assignments.</li>
<li><a href=\"/admin/operations/system_content/spray_department/soil_moisture\"><strong>Soil Moisture Levels:</strong></a> Levels of soil moisture tracked for spray condition assessment.</li>
<li><a href=\"/admin/operations/system_content/spray_department/weed_growth_stages\"><strong>Weed Growth Stages:</strong></a> Stages of weed growth tracked for state compliance reporting.</li>
<li><a href=\"/admin/operations/system_content/spray_department/wind_direction\"><strong>Wind Direction:</strong></a> Wind direction options recorded for spray conditions.</li>
<li><a href=\"/admin/operations/system_content/spray_department/wind_speed\"><strong>Wind Speed:</strong></a> Wind speed categories used to evaluate spraying feasibility.</li>
<li><a href=\"/admin/operations/system_content/spray_department/signal_words\"><strong>Signal Words:</strong></a> Signal words associated to chemicals (Caution, Warning, Danger, etc.).</li>
<li><a href=\"/admin/operations/system_content/spray_department/weed_categories\"><strong>Weed Categories:</strong></a> General categorizations of weeds based on growth characteristics, life cycles, and habitat.</li>
</ul>
<p>This section will expand to include additional spray department tools as they are built.</p>";

ensure_landing_page(
  $slp_storage, $alias_storage,
  'Spray Department',
  '/admin/operations/system_content/spray_department',
  $spray_description
);

$spray_views = [
  'admin_spraying_locations',
  'admin_spraying_carrier',
  'admin_spraying_emergence_types',
  'admin_spraying_frequency',
  'admin_spraying_methods',
  'admin_spraying_soil_moisture',
  'admin_spraying_weed_growth_stages',
  'admin_spraying_wind_direction',
  'admin_spraying_wind_speed',
  'admin_spraying_signal_words',
  'admin_spraying_weed_categories',
  'lawn_and_garden_pests',
];

echo "Updating spray view menu parents...\n";
update_view_parents($spray_views, 'menu_link_content:' . $spray_uuid);

// ═══════════════════════════════════════════════════════════════════════════
// IRRIGATION DEPARTMENT
// ═══════════════════════════════════════════════════════════════════════════
echo "\n── Irrigation Department ─────────────────────────────────────\n";

$irrigation_uuid = ensure_department_menu_link(
  $menu_storage,
  'Irrigation Department',
  '/admin/operations/system_content/irrigation_department',
  $system_content_uuid,
  3
);

$irrigation_description = "<p>The <strong>Irrigation Department</strong> section provides tools and reference data for managing sprinkler system services, check-ups, and system classification.</p>
<ul>
<li><a href=\"/admin/operations/system_content/irrigation_department/check_up_frequency\"><strong>Check-Up Frequency:</strong></a> Options for how many times we can perform a sprinkler system check-up per season.</li>
<li><a href=\"/admin/operations/system_content/irrigation_department/system_complexity\"><strong>System Complexity:</strong></a> Complexity levels used to rate every sprinkler system for scheduling and pricing.</li>
<li><a href=\"/admin/operations/system_content/irrigation_department/system_operation\"><strong>System Operation:</strong></a> Types of how the sprinkler system is operated (manual, automatic, etc.).</li>
<li><a href=\"/admin/operations/system_content/irrigation_department/sprinkler_system_types\"><strong>Sprinkler System Types:</strong></a> Classification of sprinkler system types with crew and public descriptions.</li>
<li><a href=\"/admin/operations/system_content/irrigation_department/sprinkler_types\"><strong>Sprinkler Types:</strong></a> Types of individual sprinkler heads and components with images and descriptions.</li>
</ul>
<p>This section will expand to include additional irrigation department tools as they are built.</p>";

ensure_landing_page(
  $slp_storage, $alias_storage,
  'Irrigation Department',
  '/admin/operations/system_content/irrigation_department',
  $irrigation_description
);

$irrigation_views = [
  'admin_irrigation_check_up_frequency',
  'admin_irrigation_system_complexity',
  'admin_irrigation_system_operation',
  'admin_irrigation_sprinkler_system_types',
  'admin_irrigation_sprinkler_types',
];

echo "Updating irrigation view menu parents...\n";
update_view_parents($irrigation_views, 'menu_link_content:' . $irrigation_uuid);

// ═══════════════════════════════════════════════════════════════════════════
// LIGHTING DEPARTMENT
// ═══════════════════════════════════════════════════════════════════════════
echo "\n── Lighting Department ───────────────────────────────────────\n";

$lighting_uuid = ensure_department_menu_link(
  $menu_storage,
  'Lighting Department',
  '/admin/operations/system_content/lighting_department',
  $system_content_uuid,
  4
);

$lighting_description = "<p>The <strong>Lighting Department</strong> section provides reference data for managing Christmas lighting and exterior lighting services.</p>
<ul>
<li><a href=\"/admin/operations/system_content/lighting_department/christmas_light_colors\"><strong>Christmas Light Colors:</strong></a> Colors of Christmas lights available for decorating projects.</li>
<li><a href=\"/admin/operations/system_content/lighting_department/christmas_light_types\"><strong>Christmas Light Types:</strong></a> Types of Christmas lights available for decorating projects.</li>
</ul>
<p>This section will expand to include additional lighting department tools as they are built.</p>";

ensure_landing_page(
  $slp_storage, $alias_storage,
  'Lighting Department',
  '/admin/operations/system_content/lighting_department',
  $lighting_description
);

$lighting_views = [
  'admin_lighting_christmas_colors',
  'admin_lighting_christmas_types',
];

echo "Updating lighting view menu parents...\n";
update_view_parents($lighting_views, 'menu_link_content:' . $lighting_uuid);

// ═══════════════════════════════════════════════════════════════════════════
// MATERIAL INFO
// ═══════════════════════════════════════════════════════════════════════════
echo "\n── Material Info ─────────────────────────────────────────────\n";

$material_uuid = ensure_department_menu_link(
  $menu_storage,
  'Material Info',
  '/admin/operations/system_content/material_info',
  $system_content_uuid,
  5
);

$material_description = "<p>The <strong>Material Info</strong> section provides reference data for managing materials, plants, and supplier classifications used across the system.</p>
<ul>
<li><a href=\"/admin/operations/system_content/material_info/bloom_time\"><strong>Bloom Time:</strong></a> Bloom timing options for perennials, shrubs, and annuals.</li>
<li><a href=\"/admin/operations/system_content/material_info/hardiness_zones\"><strong>Hardiness Zones:</strong></a> Climate zones indicating where plants thrive, helping customers in different regions.</li>
<li><a href=\"/admin/operations/system_content/material_info/material_tags\"><strong>Material Tags:</strong></a> Tag terms attached to materials for searching and organizing inventory.</li>
<li><a href=\"/admin/operations/system_content/material_info/material_types\"><strong>Material Types:</strong></a> Types of materials used to create the material bundles in the system.</li>
<li><a href=\"/admin/operations/system_content/material_info/plant_characteristics\"><strong>Plant Characteristics:</strong></a> Key plant traits for filtering and customer decision-making.</li>
<li><a href=\"/admin/operations/system_content/material_info/rock_types\"><strong>Rock Types:</strong></a> Decorative rock types used in landscaping projects.</li>
<li><a href=\"/admin/operations/system_content/material_info/supplier_types\"><strong>Supplier Types:</strong></a> Supplier classifications that help limit lists in the materials section.</li>
</ul>
<p>This section will expand to include additional material management tools as they are built.</p>";

ensure_landing_page(
  $slp_storage, $alias_storage,
  'Material Info',
  '/admin/operations/system_content/material_info',
  $material_description
);

$material_views = [
  'admin_material_bloom_time',
  'admin_material_hardiness_zones',
  'admin_material_tags',
  'admin_material_types',
  'admin_material_plant_characteristics',
  'admin_material_rock_types',
  'admin_material_supplier_types',
];

echo "Updating material view menu parents...\n";
update_view_parents($material_views, 'menu_link_content:' . $material_uuid);

// ═══════════════════════════════════════════════════════════════════════════
// UPDATE SYSTEM CONTENT PAGE
// ═══════════════════════════════════════════════════════════════════════════
echo "\n── System Content Page ───────────────────────────────────────\n";

$system_content_page = $slp_storage->load(2);
if ($system_content_page) {
  $current_desc = $system_content_page->get('field_description')->value ?? '';
  $changed = FALSE;

  if (strpos($current_desc, 'spray_department') === FALSE) {
    $spray_link = '<li><a href="/admin/operations/system_content/spray_department"><strong>Spray Department:</strong></a> Spraying location types, teammate field instructions, and spray-related reference data for weed control, fertilizing, and pest management operations.</li>';
    $current_desc = str_replace('</ul>', $spray_link . '</ul>', $current_desc);
    $changed = TRUE;
    echo "Added Spray Department link.\n";
  }
  else {
    echo "Spray Department link already present.\n";
  }

  if (strpos($current_desc, 'irrigation_department') === FALSE) {
    $irrigation_link = '<li><a href="/admin/operations/system_content/irrigation_department"><strong>Irrigation Department:</strong></a> Sprinkler system types, check-up frequency, system complexity ratings, and irrigation-related reference data.</li>';
    $current_desc = str_replace('</ul>', $irrigation_link . '</ul>', $current_desc);
    $changed = TRUE;
    echo "Added Irrigation Department link.\n";
  }
  else {
    echo "Irrigation Department link already present.\n";
  }

  if (strpos($current_desc, 'lighting_department') === FALSE) {
    $lighting_link = '<li><a href="/admin/operations/system_content/lighting_department"><strong>Lighting Department:</strong></a> Christmas light colors, types, and lighting-related reference data.</li>';
    $current_desc = str_replace('</ul>', $lighting_link . '</ul>', $current_desc);
    $changed = TRUE;
    echo "Added Lighting Department link.\n";
  }
  else {
    echo "Lighting Department link already present.\n";
  }

  if (strpos($current_desc, 'material_info') === FALSE) {
    $material_link = '<li><a href="/admin/operations/system_content/material_info"><strong>Material Info:</strong></a> Plant characteristics, bloom times, hardiness zones, rock types, material tags, and supplier classifications.</li>';
    $current_desc = str_replace('</ul>', $material_link . '</ul>', $current_desc);
    $changed = TRUE;
    echo "Added Material Info link.\n";
  }
  else {
    echo "Material Info link already present.\n";
  }

  if ($changed) {
    $system_content_page->set('field_description', ['value' => $current_desc, 'format' => 'full_html']);
    $system_content_page->save();
    echo "System Content page saved.\n";
  }
}
else {
  echo "WARNING: System Content landing page (entity 2) not found.\n";
}

// ── Clear caches ────────────────────────────────────────────────────────────
drupal_flush_all_caches();
echo "\nDone. Caches cleared.\n";
