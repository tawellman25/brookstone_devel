<?php

/**
 * Set SEO <title> + meta description on the category/landing Views via the
 * metatag_views display extender (metatag_views must be enabled + the
 * metatag_display_extender registered in views.settings).
 *
 * Title pattern matches the site default: "{Label} | Brookstone Outdoors |
 * Delta & Montrose CO". Descriptions are per-landing.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/build_landing_metatags.php
 */

use Drupal\views\Entity\View;

$SUFFIX = ' | Brookstone Outdoors | Delta & Montrose CO';

// view id => [page title label, meta description]
$MAP = [
  // Plant characteristics.
  'char_cat_aesthetic_features' => ['Aesthetic Features', 'Browse plants by aesthetic features — flower color, foliage, form and texture — for landscapes in Delta and Montrose counties, Colorado.'],
  'char_cat_environmental_tolerance' => ['Environmental Tolerance', 'Find plants by environmental tolerance — drought, cold, sun and soil — suited to Delta and Montrose County, Colorado landscapes.'],
  'char_cat_growth_habit' => ['Growth Habit', 'Explore plants by growth habit — height, spread, shape and rate — for Western Colorado landscapes in Delta and Montrose counties.'],
  'char_cat_maintenance_behavior' => ['Maintenance & Behavior', 'Choose plants by maintenance needs and behavior — pruning, spread and upkeep — for low-fuss Delta and Montrose County landscapes.'],
  'char_cat_origin' => ['Origin', 'Browse plants by origin, including natives suited to the Western Colorado climate of Delta and Montrose counties.'],
  'char_cat_seasonal_interest' => ['Seasonal Interest', 'Find plants by seasonal interest — spring bloom to winter form — for year-round color in Delta and Montrose County landscapes.'],
  'char_cat_special_uses' => ['Special Uses', 'Plants by special use — screening, erosion control, pollinators and more — for landscapes in Delta and Montrose counties, Colorado.'],
  'char_cat_wildlife_interaction' => ['Wildlife Interaction', 'Choose plants by wildlife interaction — pollinator-friendly or deer-resistant — for Delta and Montrose County, Colorado landscapes.'],
  // Material.
  'land_rock' => ['Rock', 'Decorative rock and landscape stone by type — flagstone, sandstone, cobble and more — supplied and installed in Delta and Montrose counties, CO.'],
  'land_bulk' => ['Bulk Materials', 'Bulk landscape materials by the yard or ton — topsoil, compost, sand and soil amendments — in Delta and Montrose counties, Colorado.'],
  'land_hardscape' => ['Hardscape', 'Pavers, wall block and hardscape materials for patios and retaining walls, supplied and installed across Delta and Montrose counties, Colorado.'],
  'land_bloom_time' => ['Bloom Time', 'Browse plants by bloom time to plan continuous color through the season in Delta and Montrose County, Colorado landscapes.'],
  'land_growth_zone' => ['Growth Zones', 'Plants by USDA growth zone for the Western Colorado climate of Delta and Montrose counties — pick varieties that thrive here.'],
  // Spraying.
  'land_carrier' => ['Carrier', 'Spray carriers used in our licensed lawn and landscape applications across Delta and Montrose counties, Colorado.'],
  'land_spray_frequency' => ['Spraying Frequency', 'How often we schedule lawn and landscape spray applications for properties in Delta and Montrose counties, Colorado.'],
  'land_spray_location' => ['Spraying Locations', 'Where we apply lawn and landscape sprays — turf, beds and more — for properties in Delta and Montrose counties, Colorado.'],
  'land_spray_methods' => ['Spraying Methods', 'Our lawn and landscape spray application methods, used in licensed treatments across Delta and Montrose counties, Colorado.'],
  'land_wind_direction' => ['Wind Direction', 'How wind direction guides safe, compliant lawn and landscape spray applications in Delta and Montrose counties, Colorado.'],
  'land_wind_speed' => ['Wind Speed', 'Wind speed limits that keep our lawn and landscape spray applications safe and compliant in Delta and Montrose counties, Colorado.'],
  'land_signal_words' => ['Signal Words', 'What the Caution, Warning and Danger signal words on a pesticide label mean — from our licensed spray team in Delta & Montrose CO.'],
  // Services.
  'land_backflow_uses' => ['Backflow Uses', 'Backflow prevention assembly types and their uses — tested and certified across Delta and Montrose counties, Colorado.'],
  'land_christmas_lights' => ['Christmas Lights', 'Holiday and Christmas light types we install, maintain and take down across Delta and Montrose counties, Colorado.'],
  'land_light_colors' => ['Light Colors', 'Christmas light color options for our holiday lighting installations in Delta and Montrose counties, Colorado.'],
  'land_snow_levels' => ['Snow Levels', 'Snow depth levels that trigger our commercial and residential snow removal service in Delta and Montrose counties, Colorado.'],
  'land_sprinkler_checkup' => ['Sprinkler Check-Up', 'Sprinkler system check-up options and frequencies to keep irrigation running right in Delta and Montrose counties, Colorado.'],
  'land_system_operation' => ['System Operation', 'Sprinkler system operation types we install and service across Delta and Montrose counties, Colorado.'],
  // About.
  'land_our_equipment' => ['Our Equipment', 'The equipment Brookstone Outdoors runs for landscaping, lawn care, irrigation and snow work in Delta and Montrose counties, Colorado.'],
  'land_seasons' => ['Seasons', 'How Brookstone Outdoors\' landscaping, lawn, irrigation and snow services change through the seasons in Delta and Montrose counties, Colorado.'],
];

$out = [];
foreach ($MAP as $vid => [$label, $desc]) {
  $view = View::load($vid);
  if (!$view) {
    $out[] = "SKIP $vid — not found";
    continue;
  }
  $display = $view->get('display');
  if (!isset($display['page_1'])) {
    $out[] = "SKIP $vid — no page_1";
    continue;
  }
  $display['page_1']['display_options']['display_extenders']['metatag_display_extender']['metatags'] = [
    'title' => $label . $SUFFIX,
    'description' => $desc,
  ];
  $view->set('display', $display)->save();
  $out[] = "$vid: title + description set";
}
print implode("\n", $out) . "\nTOTAL: " . count($MAP) . "\nDONE.\n";
