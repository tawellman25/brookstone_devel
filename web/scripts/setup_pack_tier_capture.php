<?php

declare(strict_types=1);

/**
 * Phase 3.11 — Pack-tier capture schema setup.
 *
 * Adds the schema needed to preserve Each/Mid/Case pack-quantity data
 * captured by the supplier scrape (today this data lands in BOS rows
 * but is dropped on commit because `material` has no destination
 * fields).
 *
 * Idempotent: safe to re-run. Skips creating anything that already
 * exists.
 *
 * Creates:
 *   1. taxonomy.vocabulary.pack_family (new vocab)
 *   2. 3 fields on taxonomy_term:pack_family (mid_label, mid, case)
 *   3. 5 fields on supplier_price_ingest_row:row (mid_label, mid, case,
 *      family ref, data_source)
 *   4. 5 fields on every material bundle (22 bundles × 5 = 110
 *      field instances; 5 shared storages)
 *
 * Usage:
 *   ddev drush scr web/scripts/setup_pack_tier_capture.php
 *
 * After this runs, use `drush cex` to capture the resulting YAMLs
 * into config/sync. The change set is large; review with `git status`.
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;

$etm = \Drupal::entityTypeManager();
$created = [
  'vocabulary' => [],
  'storages' => [],
  'fields' => [],
  'displays' => [],
];
$skipped = [];

// ── 1. Vocabulary ────────────────────────────────────────────────────
$vocabId = 'pack_family';
if (!Vocabulary::load($vocabId)) {
  Vocabulary::create([
    'vid' => $vocabId,
    'name' => 'Pack Family',
    'description' => 'Supplier pack-rule families (e.g., "Rain Bird VAN", "Hunter MP Rotator"). Each term carries the canonical Mid/Case pack rule for its member SKUs. Bridge to __BOS_AI/Extraction/siteone_families.md.',
  ])->save();
  $created['vocabulary'][] = $vocabId;
} else {
  $skipped[] = "vocabulary $vocabId";
}

// ── 2. Field definitions ────────────────────────────────────────────
// Five field profiles used across multiple entity types. Each profile
// = a storage shape + per-bundle instance metadata.
$packFields = [
  'field_pack_qty_mid_label' => [
    'storage' => [
      'type' => 'list_string',
      'settings' => [
        // Runtime form: flat [value => label]. Drush cex converts this
        // to the structured list when exporting to YAML.
        'allowed_values' => [
          'Bag'     => 'Bag',
          'Package' => 'Package',
          'Box'     => 'Box',
          'Carton'  => 'Carton',
          'Case'    => 'Case',
        ],
        'allowed_values_function' => '',
      ],
    ],
    'instance' => [
      'label' => 'Pack Mid-Tier Label',
      'description' => 'The supplier\'s intermediate packaging unit. E.g., a spiral barb fitting may come in a "Bag" of 50.',
    ],
  ],
  'field_pack_qty_mid' => [
    'storage' => ['type' => 'integer', 'settings' => ['unsigned' => TRUE, 'size' => 'normal']],
    'instance' => [
      'label' => 'Pack Mid-Tier Quantity',
      'description' => 'Items per Mid unit (e.g., 50 per Bag).',
      'settings' => ['min' => 1, 'max' => NULL, 'prefix' => '', 'suffix' => ''],
    ],
  ],
  'field_pack_qty_case' => [
    'storage' => ['type' => 'integer', 'settings' => ['unsigned' => TRUE, 'size' => 'normal']],
    'instance' => [
      'label' => 'Pack Case Quantity',
      'description' => 'Items per Case (e.g., 250 per Case).',
      'settings' => ['min' => 1, 'max' => NULL, 'prefix' => '', 'suffix' => ''],
    ],
  ],
  'field_pack_family' => [
    'storage' => [
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
    ],
    'instance' => [
      'label' => 'Pack Family',
      'description' => 'Family attribution. The taxonomy term carries the canonical pack rule; individual material may override via its own pack fields.',
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => ['pack_family' => 'pack_family'],
          'sort' => ['field' => 'name', 'direction' => 'asc'],
          'auto_create' => FALSE,
          'auto_create_bundle' => '',
        ],
      ],
    ],
  ],
  'field_pack_data_source' => [
    'storage' => [
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'confirmed'               => 'Confirmed (2+ PDPs)',
          'inferred'                => 'Inferred (1 PDP sampled)',
          'inferred_low_confidence' => 'Inferred - low confidence (no PDP sample)',
          'listing_only'            => 'Listing-only (no pack data)',
        ],
        'allowed_values_function' => '',
      ],
    ],
    'instance' => [
      'label' => 'Pack Data Source',
      'description' => 'Trust level for the pack-rule attribution. Sourced from the scrape.',
    ],
  ],
];

/**
 * Helper: ensure a field storage exists on a given entity type.
 */
function ensure_storage(string $entity_type, string $field_name, array $profile, array &$created, array &$skipped): void {
  $existing = FieldStorageConfig::loadByName($entity_type, $field_name);
  if ($existing) {
    $skipped[] = "storage $entity_type.$field_name";
    return;
  }
  $config = [
    'entity_type' => $entity_type,
    'field_name' => $field_name,
    'type' => $profile['storage']['type'],
    'settings' => $profile['storage']['settings'] ?? [],
    'cardinality' => 1,
    'translatable' => TRUE,
  ];
  FieldStorageConfig::create($config)->save();
  $created['storages'][] = "$entity_type.$field_name";
}

/**
 * Helper: ensure a field instance exists on a given bundle.
 */
function ensure_instance(string $entity_type, string $bundle, string $field_name, array $profile, array &$created, array &$skipped): void {
  $existing = FieldConfig::loadByName($entity_type, $bundle, $field_name);
  if ($existing) {
    $skipped[] = "instance $entity_type.$bundle.$field_name";
    return;
  }
  $inst = $profile['instance'];
  FieldConfig::create([
    'entity_type' => $entity_type,
    'bundle' => $bundle,
    'field_name' => $field_name,
    'label' => $inst['label'],
    'description' => $inst['description'] ?? '',
    'required' => FALSE,
    'settings' => $inst['settings'] ?? [],
  ])->save();
  $created['fields'][] = "$entity_type.$bundle.$field_name";
}

/**
 * Helper: add a field to the bundle's default form display + view display.
 * Field-type-aware widget/formatter selection.
 */
function ensure_displays(string $entity_type, string $bundle, string $field_name, string $field_type, int $weight, array &$created): void {
  $form_display = EntityFormDisplay::load("$entity_type.$bundle.default");
  if ($form_display && !$form_display->getComponent($field_name)) {
    $widget = match ($field_type) {
      'list_string' => ['type' => 'options_select'],
      'integer' => ['type' => 'number'],
      'entity_reference' => ['type' => 'entity_reference_autocomplete', 'settings' => ['match_operator' => 'CONTAINS', 'size' => 60, 'placeholder' => '']],
      default => ['type' => 'string_textfield'],
    };
    $form_display->setComponent($field_name, ['weight' => $weight] + $widget)->save();
    $created['displays'][] = "form $entity_type.$bundle.$field_name";
  }
  $view_display = EntityViewDisplay::load("$entity_type.$bundle.default");
  if ($view_display && !$view_display->getComponent($field_name)) {
    $formatter = match ($field_type) {
      'list_string' => ['type' => 'list_default'],
      'integer' => ['type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE]],
      'entity_reference' => ['type' => 'entity_reference_label', 'settings' => ['link' => FALSE]],
      default => ['type' => 'string'],
    };
    $view_display->setComponent($field_name, ['label' => 'inline', 'weight' => $weight] + $formatter)->save();
    $created['displays'][] = "view $entity_type.$bundle.$field_name";
  }
}

// ── 3. taxonomy_term:pack_family ────────────────────────────────────
// pack_family carries the canonical rule. NOT the data_source or the
// family ref (a term doesn't reference itself).
$termFields = ['field_pack_qty_mid_label', 'field_pack_qty_mid', 'field_pack_qty_case'];
$weight = 1;
foreach ($termFields as $fn) {
  ensure_storage('taxonomy_term', $fn, $packFields[$fn], $created, $skipped);
  ensure_instance('taxonomy_term', 'pack_family', $fn, $packFields[$fn], $created, $skipped);
  ensure_displays('taxonomy_term', 'pack_family', $fn, $packFields[$fn]['storage']['type'], $weight++, $created);
}

// ── 4. supplier_price_ingest_row:row ────────────────────────────────
// All 5 fields — scrape captures every one of them per row.
$rowFields = array_keys($packFields);
$weight = 50;
foreach ($rowFields as $fn) {
  ensure_storage('supplier_price_ingest_row', $fn, $packFields[$fn], $created, $skipped);
  ensure_instance('supplier_price_ingest_row', 'row', $fn, $packFields[$fn], $created, $skipped);
  ensure_displays('supplier_price_ingest_row', 'row', $fn, $packFields[$fn]['storage']['type'], $weight++, $created);
}

// ── 5. material (all 22 bundles) ────────────────────────────────────
$matBundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('material'));
$matFields = array_keys($packFields);
foreach ($matFields as $fn) {
  ensure_storage('material', $fn, $packFields[$fn], $created, $skipped);
}
$weight = 50;
foreach ($matBundles as $bundle) {
  foreach ($matFields as $fn) {
    ensure_instance('material', $bundle, $fn, $packFields[$fn], $created, $skipped);
    ensure_displays('material', $bundle, $fn, $packFields[$fn]['storage']['type'], $weight++, $created);
  }
}

// ── 6. Targeted export ──────────────────────────────────────────────
// `drush cex` would steamroll any active-vs-sync drift in unrelated
// configs (per __BOS_AI/Governance — see feedback_no_drush_cex.md).
// Export only the specific config names this script created or
// touched. Idempotent.
$configsToExport = [];
// Vocabulary
$configsToExport[] = "taxonomy.vocabulary.$vocabId";
// taxonomy_term field storages + pack_family instances + displays
foreach ($termFields as $fn) {
  $configsToExport[] = "field.storage.taxonomy_term.$fn";
  $configsToExport[] = "field.field.taxonomy_term.pack_family.$fn";
}
$configsToExport[] = 'core.entity_form_display.taxonomy_term.pack_family.default';
$configsToExport[] = 'core.entity_view_display.taxonomy_term.pack_family.default';
// supplier_price_ingest_row storages + instances + displays
foreach ($rowFields as $fn) {
  $configsToExport[] = "field.storage.supplier_price_ingest_row.$fn";
  $configsToExport[] = "field.field.supplier_price_ingest_row.row.$fn";
}
$configsToExport[] = 'core.entity_form_display.supplier_price_ingest_row.row.default';
$configsToExport[] = 'core.entity_view_display.supplier_price_ingest_row.row.default';
// material storages + per-bundle instances + per-bundle displays
foreach ($matFields as $fn) {
  $configsToExport[] = "field.storage.material.$fn";
}
foreach ($matBundles as $bundle) {
  foreach ($matFields as $fn) {
    $configsToExport[] = "field.field.material.$bundle.$fn";
  }
  $configsToExport[] = "core.entity_form_display.material.$bundle.default";
  $configsToExport[] = "core.entity_view_display.material.$bundle.default";
}
// Drush form mode displays — only export if they exist on this bundle.

$activeStorage = \Drupal::service('config.storage');
$syncDir = DRUPAL_ROOT . '/../config/sync';
$exported = 0;
$exportSkipped = 0;
foreach ($configsToExport as $name) {
  $data = $activeStorage->read($name);
  if ($data === FALSE) {
    $exportSkipped++;
    continue;
  }
  // Use Drupal's own YAML encoder for byte-equivalence with `drush cex`
  // output. Avoids cosmetic `{  }` ↔ `[]` differences in modified
  // displays that would add noise to the diff.
  $yaml = \Drupal\Core\Serialization\Yaml::encode($data);
  file_put_contents("$syncDir/$name.yml", $yaml);
  $exported++;
}

// ── 7. Report ───────────────────────────────────────────────────────
echo "=== CREATED ===\n";
foreach ($created as $kind => $items) {
  echo "  $kind: " . count($items) . "\n";
  foreach (array_slice($items, 0, 5) as $i) {
    echo "    - $i\n";
  }
  if (count($items) > 5) {
    echo "    ... and " . (count($items) - 5) . " more\n";
  }
}
echo "\n=== SKIPPED (already existed) === " . count($skipped) . "\n";
foreach (array_slice($skipped, 0, 5) as $s) {
  echo "  - $s\n";
}
if (count($skipped) > 5) {
  echo "  ... and " . (count($skipped) - 5) . " more\n";
}
echo "\n=== EXPORTED TO config/sync ===\n";
echo "  exported: $exported\n";
echo "  skipped (config doesn't exist): $exportSkipped\n";
echo "\nDone. Next: git status to review the change set.\n";
