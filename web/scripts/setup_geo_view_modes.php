<?php

/**
 * Public / Teammate / Admin view modes + displays for the geographic entity
 * types (city, county, state, zipcodes) — the role-aware geo-landing pages.
 *
 *   Public   -> anon + clients: public info only.
 *   Teammate -> crew: public info + operational hierarchy.
 *   Admin    -> office/admin: everything (settings we don't want crews changing).
 *
 * The role switch + anon access live in the bos_geo module; this script only
 * creates the view modes and their starting displays. Field sets are a safe
 * first pass — refine in the UI (/admin/structure/display-modes/view and each
 * type's Manage Display) as we go. Formatter settings are copied from each
 * type's existing `default` display so fields render the same way.
 *
 * Idempotent; entity-API (no cim). Run per env:
 *   drush php:script web/scripts/setup_geo_view_modes.php
 */

use Drupal\Core\Entity\Entity\EntityViewMode;

$out = [];

$modes = ['public' => 'Public', 'teammate' => 'Teammate', 'admin' => 'Admin'];

// Bundle per entity type (usually the same name; zipcodes uses 'zipcode').
$bundles = [
  'city' => 'city',
  'county' => 'county',
  'state' => 'state',
  'zipcodes' => 'zipcode',
];

// Starting field sets per (type, mode). Everything else on the display is hidden.
$fieldsets = [
  'city' => [
    'public'   => ['field_banner_image', 'field_city_name', 'field_city_description'],
    'teammate' => ['field_banner_image', 'field_city_name', 'field_city_description', 'field_county', 'field_state', 'field_type'],
    'admin'    => ['field_banner_image', 'field_city_name', 'field_city_description', 'field_county', 'field_state', 'field_type'],
  ],
  'county' => [
    'public'   => ['field_banner_image', 'field_county_name', 'field_county_description', 'field_gis_map_url', 'field_county_records_search'],
    'teammate' => ['field_banner_image', 'field_county_name', 'field_county_description', 'field_gis_map_url', 'field_county_records_search', 'field_state'],
    'admin'    => ['field_banner_image', 'field_county_name', 'field_county_description', 'field_gis_map_url', 'field_county_records_search', 'field_state'],
  ],
  'state' => [
    'public'   => ['field_banner_image', 'field_state_name', 'field_state_description'],
    'teammate' => ['field_banner_image', 'field_state_name', 'field_state_description', 'field_abbreviation'],
    'admin'    => ['field_banner_image', 'field_state_name', 'field_state_description', 'field_abbreviation', 'field_type'],
  ],
  // zipcodes: field_trip_fee is pricing (admin only); field_check_up_route_day
  // is internal routing (teammate + admin). Public sees the zip + description +
  // its city/county/state hierarchy.
  'zipcodes' => [
    'public'   => ['field_zipcode', 'field_zipcode_description', 'field_city', 'field_county', 'field_state'],
    'teammate' => ['field_zipcode', 'field_zipcode_description', 'field_city', 'field_county', 'field_state', 'field_check_up_route_day'],
    'admin'    => ['field_zipcode', 'field_zipcode_description', 'field_city', 'field_county', 'field_state', 'field_check_up_route_day', 'field_trip_fee'],
  ],
];

$repo = \Drupal::service('entity_display.repository');

foreach ($fieldsets as $type => $byMode) {
  $bundle = $bundles[$type] ?? $type;

  // 1) View modes.
  foreach ($modes as $mode => $label) {
    $id = "$type.$mode";
    if (!EntityViewMode::load($id)) {
      EntityViewMode::create([
        'id' => $id,
        'targetEntityType' => $type,
        'label' => $label,
      ])->save();
      $out[] = "view mode $id";
    }
  }

  // 2) Displays. Copy formatter settings from the bundle's default display.
  $default = $repo->getViewDisplay($type, $bundle, 'default');

  foreach ($byMode as $mode => $show) {
    $display = $repo->getViewDisplay($type, $bundle, $mode);
    $display->setStatus(TRUE);
    $weight = 0;
    foreach ($show as $field) {
      $comp = $default->getComponent($field);
      if (!$comp) {
        // Field not on the default display — a sensible generic fallback.
        $comp = ['label' => 'above', 'region' => 'content', 'settings' => [], 'third_party_settings' => []];
      }
      $comp['weight'] = $weight++;
      $comp['region'] = 'content';
      $display->setComponent($field, $comp);
    }
    // Hide everything not explicitly shown — fields AND extra components
    // (title/created/langcode/links); the page h1 already renders the label.
    foreach (array_keys($display->getComponents()) as $name) {
      if (!in_array($name, $show, TRUE)) {
        $display->removeComponent($name);
      }
    }
    $display->save();
    $out[] = "display $type.$bundle.$mode (" . count($show) . " fields)";
  }
}

print implode("\n", $out) . "\nDONE.\n";
