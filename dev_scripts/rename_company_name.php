<?php

/**
 * @file
 * Replaces "S&E Ward's Landscape Management" / "S&E Ward's" with
 * "Brookstone Outdoors" across entity content in the database.
 *
 * Safe to run multiple times — only updates rows that still contain the old name.
 *
 * Usage:
 *   ddev drush php:script dev_scripts/rename_company_name.php
 *   drush php:script dev_scripts/rename_company_name.php   (on live)
 *
 * Add --dry-run to preview without making changes:
 *   ddev drush php:script dev_scripts/rename_company_name.php -- --dry-run
 */

$dry_run = in_array('--dry-run', $extra ?? []);

if ($dry_run) {
  echo "=== DRY RUN — no changes will be made ===\n\n";
}

$database = \Drupal::database();

// Define all replacements: [search => replace].
// Order matters — longer/more specific patterns first to avoid partial matches.
$replacements = [
  // HTML-encoded variants (taxonomy descriptions, formatted text fields).
  "S&amp;E Ward's Landscape Management LLC." => "Brookstone Outdoors LLC",
  "S&amp;E Ward's Landscape Management LLC"  => "Brookstone Outdoors LLC",
  "S&amp;E Ward's Landscape Management"      => "Brookstone Outdoors",
  "S&amp;E Ward's Landscaping"               => "Brookstone Outdoors",
  "S&amp;E Ward's"                           => "Brookstone Outdoors",
  // Plain-text variants (material descriptions, plain string fields).
  "S&E Ward's Landscape Management LLC."     => "Brookstone Outdoors LLC",
  "S&E Ward's Landscape Management LLC"      => "Brookstone Outdoors LLC",
  "S&E Ward's Landscape Management"          => "Brookstone Outdoors",
  "S&E Ward's Landscaping"                   => "Brookstone Outdoors",
  "S&E Ward's"                               => "Brookstone Outdoors",
];

// Tables and columns to process: [table => column].
$targets = [
  // Material descriptions (bulk of the hits).
  'material__field_description'          => 'field_description_value',
  'material_revision__field_description' => 'field_description_value',
  // Taxonomy term descriptions.
  'taxonomy_term_field_data'             => 'description__value',
  'taxonomy_term_revision__description'  => 'description__value',
  // Taxonomy term custom text fields.
  'taxonomy_term__field_public_description'             => 'field_public_description_value',
  'taxonomy_term_revision__field_public_description'    => 'field_public_description_value',
  'taxonomy_term__field_teammate_description'           => 'field_teammate_description_value',
  'taxonomy_term_revision__field_teammate_description'  => 'field_teammate_description_value',
  // Node body.
  'node__body'                           => 'body_value',
  'node_revision__body'                  => 'body_value',
  // Chemical descriptions.
  'chemical__field_description'          => 'field_description_value',
  'chemical_revision__field_description' => 'field_description_value',
  // Equipment public descriptions.
  'equipment__field_public_description'          => 'field_public_description_value',
  'equipment_revision__field_public_description' => 'field_public_description_value',
  // Handbook body.
  'handbook__field_body'                 => 'field_body_value',
  'handbook_revision__field_body'        => 'field_body_value',
  // Manual descriptions.
  'manual__field_description'            => 'field_description_value',
  'manual_revision__field_description'   => 'field_description_value',
  // SOP descriptions (table may not exist on all environments).
  'sop__field_body'                      => 'field_body_value',
  'sop_revision__field_body'             => 'field_body_value',
  // Lawn and garden pests.
  'lawn_and_garden_pests__field_description'          => 'field_description_value',
  'lawn_and_garden_pests_revision__field_description' => 'field_description_value',
  // Block content body.
  'block_content__body'                  => 'body_value',
  'block_content_revision__body'         => 'body_value',
];

$total_updated = 0;

foreach ($targets as $table => $column) {
  // Check if table exists.
  if (!$database->schema()->tableExists($table)) {
    continue;
  }

  foreach ($replacements as $search => $replace) {
    // Count matching rows.
    $count = $database->query(
      "SELECT COUNT(*) FROM {" . $table . "} WHERE " . $column . " LIKE :pattern",
      [':pattern' => '%' . $database->escapeLike($search) . '%']
    )->fetchField();

    if ($count > 0) {
      echo sprintf("%-50s %s => %s  (%d rows)\n", $table . '.' . $column, $search, $replace, $count);

      if (!$dry_run) {
        $database->query(
          "UPDATE {" . $table . "} SET " . $column . " = REPLACE(" . $column . ", :search, :replace) WHERE " . $column . " LIKE :pattern",
          [
            ':search'  => $search,
            ':replace' => $replace,
            ':pattern' => '%' . $database->escapeLike($search) . '%',
          ]
        );
      }

      $total_updated += $count;
    }
  }
}

echo "\n";
if ($dry_run) {
  echo "DRY RUN complete. $total_updated rows would be updated.\n";
  echo "Run without --dry-run to apply changes.\n";
}
else {
  echo "Done. $total_updated rows updated.\n";
  echo "Run 'drush cr' to clear cache.\n";
}
