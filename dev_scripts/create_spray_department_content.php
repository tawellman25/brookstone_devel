<?php

/**
 * Drush PHP script: Create Spray Department database content on live.
 *
 * Creates:
 * 1. Spray Department landing page (site_landing_page entity)
 * 2. Spray Department menu link under System Content
 * 3. Updates System Content landing page (entity 2) with Spray Department link
 *
 * Usage (on live server, in Drupal root):
 *   drush php:script create_spray_department_content.php
 *
 * Safe to run multiple times — checks for existing content before creating.
 */

// ── 1. Find the System Content menu link (parent for Spray Department) ──────
$menu_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
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
echo "Found System Content menu link: UUID $system_content_uuid\n";

// ── 2. Create Spray Department menu link (if not exists) ────────────────────
$existing_spray_links = $menu_storage->loadByProperties([
  'title' => 'Spray Department',
  'menu_name' => 'admin',
]);

if (!empty($existing_spray_links)) {
  $spray_link = reset($existing_spray_links);
  $spray_uuid = $spray_link->uuid();
  echo "Spray Department menu link already exists: UUID $spray_uuid\n";
}
else {
  $spray_link = $menu_storage->create([
    'title' => 'Spray Department',
    'link' => ['uri' => 'internal:/admin/operations/system_content/spray_department'],
    'menu_name' => 'admin',
    'parent' => 'menu_link_content:' . $system_content_uuid,
    'weight' => 2,
    'expanded' => TRUE,
    'enabled' => TRUE,
  ]);
  $spray_link->save();
  $spray_uuid = $spray_link->uuid();
  echo "Created Spray Department menu link: ID {$spray_link->id()}, UUID $spray_uuid\n";
}

// ── 3. Update view configs to use the new menu link UUID as parent ───────────
// The views reference the parent by UUID. If the UUID on live differs from
// local (2281f592-...), we need to update the view configs.
$view_ids = [
  'admin_spraying_locations',
  'admin_spraying_carrier',
  'admin_spraying_emergence_types',
  'admin_spraying_frequency',
  'admin_spraying_methods',
  'admin_spraying_soil_moisture',
  'admin_spraying_weed_growth_stages',
  'admin_spraying_wind_direction',
  'admin_spraying_wind_speed',
  'lawn_and_garden_pests',
];

$parent_value = 'menu_link_content:' . $spray_uuid;
$updated_views = 0;

foreach ($view_ids as $view_id) {
  $config = \Drupal::configFactory()->getEditable('views.view.' . $view_id);
  if ($config->isNew()) {
    echo "  View $view_id not found — skipped.\n";
    continue;
  }

  $current_parent = $config->get('display.page_1.display_options.menu.parent');
  if ($current_parent !== $parent_value) {
    $config->set('display.page_1.display_options.menu.parent', $parent_value);
    $config->save();
    echo "  Updated $view_id menu parent to $parent_value\n";
    $updated_views++;
  }
  else {
    echo "  $view_id already correct.\n";
  }
}

echo "Updated $updated_views view menu parents.\n";

// ── 4. Create Spray Department landing page (if not exists) ─────────────────
$slp_storage = \Drupal::entityTypeManager()->getStorage('site_landing_page');
$alias_storage = \Drupal::entityTypeManager()->getStorage('path_alias');

// Check if a landing page already exists at this alias.
$existing_aliases = $alias_storage->loadByProperties([
  'alias' => '/admin/operations/system_content/spray_department',
]);

if (!empty($existing_aliases)) {
  $alias = reset($existing_aliases);
  echo "Spray Department landing page already exists at {$alias->get('path')->value}\n";
}
else {
  $description = "<p>The <strong>Spray Department</strong> section provides tools and reference data for managing weed control, fertilizing, and pest management operations.</p>
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
</ul>
<p>This section will expand to include additional spray department tools — chemical inventory, spray route management, reconciliation reports, and compliance documentation — as they are built.</p>";

  $entity = $slp_storage->create([
    'type' => 'office_administration',
    'title' => 'Spray Department',
    'field_description' => [
      'value' => $description,
      'format' => 'full_html',
    ],
  ]);
  $entity->save();
  echo "Created Spray Department landing page: ID {$entity->id()}\n";

  $path_alias = $alias_storage->create([
    'path' => '/site_landing_page/' . $entity->id(),
    'alias' => '/admin/operations/system_content/spray_department',
  ]);
  $path_alias->save();
  echo "Created path alias.\n";
}

// ── 5. Update System Content landing page (entity 2) ────────────────────────
$system_content_page = $slp_storage->load(2);
if ($system_content_page) {
  $current_desc = $system_content_page->get('field_description')->value ?? '';

  if (strpos($current_desc, 'spray_department') === FALSE) {
    $spray_link_html = '<li><a href="/admin/operations/system_content/spray_department"><strong>Spray Department:</strong></a> Spraying location types, teammate field instructions, and spray-related reference data for weed control, fertilizing, and pest management operations.</li>';

    // Try to insert before the closing </ul>.
    if (strpos($current_desc, '</ul>') !== FALSE) {
      $updated_desc = str_replace('</ul>', $spray_link_html . '</ul>', $current_desc);
    }
    else {
      $updated_desc = $current_desc . '<ul>' . $spray_link_html . '</ul>';
    }

    $system_content_page->set('field_description', [
      'value' => $updated_desc,
      'format' => 'full_html',
    ]);
    $system_content_page->save();
    echo "Updated System Content page with Spray Department link.\n";
  }
  else {
    echo "System Content page already has Spray Department link.\n";
  }
}
else {
  echo "WARNING: System Content landing page (entity 2) not found — skipped.\n";
}

// ── 6. Clear caches ─────────────────────────────────────────────────────────
drupal_flush_all_caches();
echo "\nDone. Caches cleared.\n";
