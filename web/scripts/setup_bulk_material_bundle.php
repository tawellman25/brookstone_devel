<?php

declare(strict_types=1);

/**
 * One-off setup: create the `bulk_material` bundle on the `material`
 * ECK entity, mirroring decorative_rock's field profile but with
 * field_rock_type replaced by a new field_bulk_material_type
 * (taxonomy reference into a new bulk_material_types vocabulary).
 *
 * Run AFTER:
 *   ddev drush eck:clone-bundle material decorative_rock bulk_material \
 *     --label="Bulk Material"
 *
 * Then:
 *   ddev drush scr web/scripts/setup_bulk_material_bundle.php
 *
 * Idempotent: re-running skips anything already in place. Safe to
 * re-run if the first run partially failed.
 *
 * What it does:
 *   1. Sets the bundle's description (eck:clone-bundle copies only the
 *      label).
 *   2. Creates the bulk_material_types taxonomy vocabulary.
 *   3. Seeds 15 terms — alphabetical, with Soil Amendment (Other) and
 *      Other pinned to the bottom by weight.
 *   4. Creates field.storage.material.field_bulk_material_type (entity_reference
 *      → taxonomy_term, cardinality 1) if missing.
 *   5. Removes the cloned field_rock_type instance from bulk_material
 *      (we replace it; the field storage is left alone — decorative_rock
 *      and mulch still use it).
 *   6. Creates field.field.material.bulk_material.field_bulk_material_type
 *      (required, options_select widget, entity_reference_label formatter,
 *      target_bundles restricted to bulk_material_types).
 *   7. Configures the default form display with the field order specified
 *      in the spec.
 *   8. Configures the default view display by mirroring decorative_rock's
 *      structure, substituting field_bulk_material_type for field_rock_type.
 *   9. Grants per-bundle permissions to the same 6 roles that hold
 *      decorative_rock permissions, role-by-role (no broadening).
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\Role;

$SOURCE_BUNDLE = 'decorative_rock';
$TARGET_BUNDLE = 'bulk_material';
$TARGET_LABEL  = 'Bulk Material';
$TARGET_DESC   = 'Non-decorative bulk materials sold by the cubic yard or ton — topsoil, fill dirt, compost, soil amendments, lime, sulfur, gypsum, non-decorative sand and gravel, decomposed granite, and similar bulk goods. Excludes decorative rock (own bundle) and mulch (own bundle).';

$VOCAB_ID    = 'bulk_material_types';
$VOCAB_LABEL = 'Bulk Material Types';
$VOCAB_DESC  = 'Categorization for bulk material catalog entries. Used as the primary type axis on the bulk_material bundle.';

$NEW_FIELD   = 'field_bulk_material_type';
$NEW_LABEL   = 'Bulk Material Type';

$REPLACED_FIELD = 'field_rock_type';

// ── PRECHECK: bundle must exist (clone-bundle should've created it) ──
$bundle_info = \Drupal::service('entity_type.bundle.info')->getBundleInfo('material');
if (!isset($bundle_info[$TARGET_BUNDLE])) {
  echo "ERROR: bundle '$TARGET_BUNDLE' does not exist yet on entity 'material'.\n";
  echo "Run first: ddev drush eck:clone-bundle material $SOURCE_BUNDLE $TARGET_BUNDLE --label=\"$TARGET_LABEL\"\n";
  exit(1);
}
echo "✓ bundle $TARGET_BUNDLE exists on material\n";

// ── 1. Set bundle description ────────────────────────────────────────
$bundle_cfg = \Drupal::configFactory()->getEditable("eck.eck_type.material.$TARGET_BUNDLE");
if ($bundle_cfg->get('description') !== $TARGET_DESC) {
  $bundle_cfg->set('description', $TARGET_DESC)->save();
  echo "✓ set bundle description\n";
}
else {
  echo "· bundle description already set\n";
}

// ── 2. Create vocabulary ─────────────────────────────────────────────
$vocab = Vocabulary::load($VOCAB_ID);
if (!$vocab) {
  $vocab = Vocabulary::create([
    'vid'         => $VOCAB_ID,
    'name'        => $VOCAB_LABEL,
    'description' => $VOCAB_DESC,
    'hierarchy'   => 0,
    'weight'      => 0,
  ]);
  $vocab->save();
  echo "✓ created vocabulary $VOCAB_ID\n";
}
else {
  echo "· vocabulary $VOCAB_ID already exists\n";
}

// ── 3. Seed 15 terms ─────────────────────────────────────────────────
// Alphabetical at weight 0; pinned bottom at higher weights.
$SEEDS = [
  // Alphabetical (weight 0; Drupal sorts by weight ASC then name ASC).
  ['name' => 'Compost',            'weight' => 0],
  ['name' => 'Decomposed Granite', 'weight' => 0],
  ['name' => 'Fill Dirt',          'weight' => 0],
  ['name' => 'Garden Mix',         'weight' => 0],
  ['name' => 'Gravel',             'weight' => 0],
  ['name' => 'Gypsum',             'weight' => 0],
  ['name' => 'Iron Sulfate',       'weight' => 0],
  ['name' => 'Lime',               'weight' => 0],
  ['name' => 'Manure (Composted)', 'weight' => 0],
  ['name' => 'Sand',               'weight' => 0],
  ['name' => 'Screened Topsoil',   'weight' => 0],
  ['name' => 'Sulfur',             'weight' => 0],
  ['name' => 'Topsoil',            'weight' => 0],
  // Pinned to bottom: Soil Amendment (Other) before Other.
  ['name' => 'Soil Amendment (Other)', 'weight' => 100],
  ['name' => 'Other',                  'weight' => 101],
];

$tax_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$created_terms = 0;
foreach ($SEEDS as $seed) {
  $existing = $tax_storage->loadByProperties([
    'vid'  => $VOCAB_ID,
    'name' => $seed['name'],
  ]);
  if ($existing) {
    continue;
  }
  Term::create([
    'vid'    => $VOCAB_ID,
    'name'   => $seed['name'],
    'weight' => $seed['weight'],
  ])->save();
  $created_terms++;
}
echo "✓ seeded $created_terms terms (skipped " . (count($SEEDS) - $created_terms) . " already present)\n";

// ── 4. Create field storage for field_bulk_material_type ─────────────
if (!FieldStorageConfig::loadByName('material', $NEW_FIELD)) {
  FieldStorageConfig::create([
    'field_name'  => $NEW_FIELD,
    'entity_type' => 'material',
    'type'        => 'entity_reference',
    'cardinality' => 1,
    'settings'    => [
      'target_type' => 'taxonomy_term',
    ],
  ])->save();
  echo "✓ created field storage $NEW_FIELD\n";
}
else {
  echo "· field storage $NEW_FIELD already exists\n";
}

// ── 5. Remove cloned field_rock_type instance from bulk_material ─────
$rock_inst = FieldConfig::loadByName('material', $TARGET_BUNDLE, $REPLACED_FIELD);
if ($rock_inst) {
  $rock_inst->delete();
  echo "✓ removed cloned $REPLACED_FIELD instance from $TARGET_BUNDLE\n";
}
else {
  echo "· $REPLACED_FIELD instance not on $TARGET_BUNDLE (already removed)\n";
}

// ── 6. Create field_bulk_material_type instance ──────────────────────
if (!FieldConfig::loadByName('material', $TARGET_BUNDLE, $NEW_FIELD)) {
  FieldConfig::create([
    'field_name'  => $NEW_FIELD,
    'entity_type' => 'material',
    'bundle'      => $TARGET_BUNDLE,
    'label'       => $NEW_LABEL,
    'required'    => TRUE,
    'settings'    => [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => [$VOCAB_ID => $VOCAB_ID],
        'sort' => ['field' => '_none'],
        'auto_create' => FALSE,
        'auto_create_bundle' => '',
      ],
    ],
  ])->save();
  echo "✓ created field instance $NEW_FIELD on $TARGET_BUNDLE\n";
}
else {
  echo "· field instance $NEW_FIELD already exists on $TARGET_BUNDLE\n";
}

// ── 7. Configure form display ────────────────────────────────────────
// Specified field order. Sequential weights 0..N. feeds_item goes to
// hidden region. field_supplier description gets a deprecation note.
$FORM_ORDER = [
  'field_name',
  'field_bulk_material_type',
  'field_description',
  'field_main_image',
  'field_banner_images',
  'field_slideshow_image',
  'field_unit_of_measure',
  'field_est_wt_per_yard',
  'field_yard_per_ton',
  'field_cost_integer',
  'field_price',
  'field_price_updated',
  'field_suppliers',
  'field_supplier',
  'field_supplier_item_number',
  'field_supplier_website_item_link',
  'field_quantity_in_stock',
  'field_last_restocked_date',
  'field_lead_time',
  'field_material_tags',
  'field_front_promoted',
  'field_discontinued',
];

// Mark field_supplier as legacy/deprecated on this bundle.
$supplier_inst = FieldConfig::loadByName('material', $TARGET_BUNDLE, 'field_supplier');
if ($supplier_inst && !str_contains($supplier_inst->getDescription(), 'DEPRECATED')) {
  $supplier_inst->setDescription('(DEPRECATED — use field_suppliers instead. Kept on form for parity with decorative_rock.)')
    ->save();
  echo "✓ marked field_supplier as deprecated on $TARGET_BUNDLE\n";
}

// Borrow widget settings + view formatters from decorative_rock so this
// bundle behaves as a bulk-material sibling, not a fresh ground-up.
$src_form = \Drupal::entityTypeManager()
  ->getStorage('entity_form_display')
  ->load("material.$SOURCE_BUNDLE.default");
$src_view = \Drupal::entityTypeManager()
  ->getStorage('entity_view_display')
  ->load("material.$SOURCE_BUNDLE.default");

if (!$src_form || !$src_view) {
  echo "ERROR: source displays for $SOURCE_BUNDLE not found.\n";
  exit(1);
}

$src_form_components = $src_form->toArray()['content'] ?? [];
$src_view_components = $src_view->toArray()['content'] ?? [];

$form = \Drupal::entityTypeManager()
  ->getStorage('entity_form_display')
  ->load("material.$TARGET_BUNDLE.default")
  ?? \Drupal\Core\Entity\Entity\EntityFormDisplay::create([
    'targetEntityType' => 'material',
    'bundle'           => $TARGET_BUNDLE,
    'mode'             => 'default',
    'status'           => TRUE,
  ]);

// Wipe existing component layout so we re-apply the spec's order.
foreach (array_keys($form->toArray()['content'] ?? []) as $existing) {
  $form->removeComponent($existing);
}

$weight = 0;
foreach ($FORM_ORDER as $field) {
  $cfg = $src_form_components[$field] ?? NULL;
  if ($cfg === NULL) {
    // New field (field_bulk_material_type) — use options_select per spec.
    if ($field === $NEW_FIELD) {
      $cfg = [
        'type'     => 'options_select',
        'settings' => [],
        'third_party_settings' => [],
        'region'   => 'content',
      ];
    }
    else {
      echo "  ! field '$field' has no widget on $SOURCE_BUNDLE; skipping form placement\n";
      continue;
    }
  }
  $cfg['weight'] = $weight++;
  $cfg['region'] = 'content';
  $form->setComponent($field, $cfg);
}

// feeds_item hidden.
if (isset($src_form_components['feeds_item']) || FieldStorageConfig::loadByName('material', 'feeds_item') || \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("material.$SOURCE_BUNDLE.default")) {
  // ECK base field — set hidden explicitly.
  $form->removeComponent('feeds_item');
}

$form->save();
echo "✓ configured form display (" . count($FORM_ORDER) . " components in spec order)\n";

// ── 8. Configure view display (mirror decorative_rock) ───────────────
$view = \Drupal::entityTypeManager()
  ->getStorage('entity_view_display')
  ->load("material.$TARGET_BUNDLE.default")
  ?? \Drupal\Core\Entity\Entity\EntityViewDisplay::create([
    'targetEntityType' => 'material',
    'bundle'           => $TARGET_BUNDLE,
    'mode'             => 'default',
    'status'           => TRUE,
  ]);

foreach (array_keys($view->toArray()['content'] ?? []) as $existing) {
  $view->removeComponent($existing);
}

foreach ($src_view_components as $field => $cfg) {
  if ($field === $REPLACED_FIELD) {
    // Skip — replaced by field_bulk_material_type below.
    continue;
  }
  // Only mirror components for fields that actually exist on target bundle.
  if (str_starts_with($field, 'field_') && !FieldConfig::loadByName('material', $TARGET_BUNDLE, $field)) {
    continue;
  }
  $view->setComponent($field, $cfg);
}

// Add field_bulk_material_type using the same formatter shape as
// field_rock_type had on decorative_rock (entity_reference_label).
$rock_view_cfg = $src_view_components[$REPLACED_FIELD] ?? [
  'type'     => 'entity_reference_label',
  'label'    => 'inline',
  'settings' => ['link' => FALSE],
  'weight'   => 0,
  'region'   => 'content',
];
$view->setComponent($NEW_FIELD, $rock_view_cfg);
$view->save();
echo "✓ configured view display (mirrored from $SOURCE_BUNDLE, swapped $REPLACED_FIELD → $NEW_FIELD)\n";

// ── 9. Grant permissions matching decorative_rock per-role ───────────
$role_storage = \Drupal::entityTypeManager()->getStorage('user_role');
foreach ($role_storage->loadMultiple() as $rid => $role) {
  $src_perms = array_values(array_filter(
    $role->getPermissions(),
    fn($p) => str_contains($p, "material entities of bundle $SOURCE_BUNDLE")
  ));
  if (!$src_perms) {
    continue;
  }
  $added = 0;
  foreach ($src_perms as $p) {
    $tgt = str_replace("bundle $SOURCE_BUNDLE", "bundle $TARGET_BUNDLE", $p);
    if (!$role->hasPermission($tgt)) {
      $role->grantPermission($tgt);
      $added++;
    }
  }
  if ($added > 0) {
    $role->save();
    echo "  + granted $added permission(s) to role '$rid'\n";
  }
}
echo "✓ permissions aligned with $SOURCE_BUNDLE pattern\n";

echo "\nSetup complete. Next: capture configs to sync, write docs, stage.\n";
