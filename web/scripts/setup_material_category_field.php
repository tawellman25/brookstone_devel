<?php

declare(strict_types=1);

/**
 * Give trees a subcategory, and put it in the URL.
 *
 * A material_types term binds to items only through `field_material_bundle` →
 * the material's BUNDLE, so the subcategories under Trees (Evergreens,
 * Junipers, Deciduous → Shade / Ornamental / Fruit) have nothing to hold
 * and their pages list nothing. This adds the missing link: a category
 * reference on the material itself, and a pathauto pattern that builds the item
 * URL from it.
 *
 * MODELLED ON decorative_rock, which already does this with `field_rock_type`,
 * but it cannot use rock's token. Rock's pattern inserts the term NAME as one
 * segment:
 *
 *     /material/rock/[material:field_rock_type:entity:name]/[material:title]
 *
 * That works there because `rock_types` is FLAT — every term's real alias is
 * /material/rock/{name}, so trimming the URL back lands on the term page.
 * `material_types` is NESTED: Fruit lives at
 * /material/plants/trees/deciduous/fruit. The name-only token would produce
 * /material/plants/trees/fruit/…, and trimming back to that would 404 — the
 * term is a level deeper. So the pattern uses the term's own alias:
 *
 *     [material:field_material_category:entity:url:path]/[material:title]
 *     → /material/plants/trees/deciduous/fruit/apple-tree
 *
 * Every truncation of that resolves, which is the point. It also stops the
 * hierarchy being restated in the pattern: move a category in the taxonomy and
 * its items follow, instead of needing the pattern edited by hand the way
 * Trees and Shrubs did when they moved under Plants.
 *
 * THE EMPTY CASE is handled in code, not here. With no category set this
 * pattern yields `/{title}` at the site root — measured, not assumed. The floor
 * is `material_pathauto_alias_alter()` in material.module, which rebuilds any
 * material alias that does not start with /material/ from its bundle's own
 * category term. That makes this safe to ship BEFORE the data entry: an
 * unassigned tree keeps exactly the URL it has today, and moves to its nested
 * URL (old one 301ing) as the office assigns it.
 *
 * Idempotent; entity-API (field configs silently skip on cim). Run per env:
 *   drush php:script web/scripts/setup_material_category_field.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

const BOS_CAT_FIELD = 'field_material_category';
const BOS_CAT_VOCAB = 'material_types';

/** Bundles that get the field. Add a bundle here and to $PATTERNS together. */
const BOS_CAT_BUNDLES = ['trees', 'shrubs', 'annuals', 'plants'];

/**
 * Bundle => pathauto pattern id whose pattern is rewritten to use the field.
 * `annuals` has never had a pattern, so its items sat on the raw /material/NNN
 * path; it is created here rather than assumed to exist.
 */
$PATTERNS = [
  'trees' => 'material_trees_path',
  'shrubs' => 'material_shrubs_path',
  'annuals' => 'material_annuals_path',
  'plants' => 'material_plants_path',
];

$NEW_PATTERN = '[material:' . BOS_CAT_FIELD . ':entity:url:path]/[material:title]';

$out = [];

// ---- 1. storage ------------------------------------------------------------
if (!FieldStorageConfig::loadByName('material', BOS_CAT_FIELD)) {
  FieldStorageConfig::create([
    'field_name' => BOS_CAT_FIELD,
    'entity_type' => 'material',
    'type' => 'entity_reference',
    'settings' => ['target_type' => 'taxonomy_term'],
    'cardinality' => 1,
  ])->save();
  $out[] = 'created field storage ' . BOS_CAT_FIELD;
}
else {
  $out[] = 'field storage ' . BOS_CAT_FIELD . ' already exists';
}

// ---- 2. instance + displays, per bundle ------------------------------------
$formStorage = \Drupal::entityTypeManager()->getStorage('entity_form_display');
$viewStorage = \Drupal::entityTypeManager()->getStorage('entity_view_display');

foreach (BOS_CAT_BUNDLES as $bundle) {
  if (!FieldConfig::loadByName('material', $bundle, BOS_CAT_FIELD)) {
    FieldConfig::create([
      'field_name' => BOS_CAT_FIELD,
      'entity_type' => 'material',
      'bundle' => $bundle,
      'label' => 'Category',
      'description' => 'The subcategory this item belongs to. Sets its page URL, and puts it on that subcategory\'s page.',
      // Deliberately NOT required: the alias guard keeps unassigned items on
      // their current URL, so the field can ship before the data entry.
      'required' => FALSE,
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => [BOS_CAT_VOCAB => BOS_CAT_VOCAB],
          'auto_create' => FALSE,
        ],
      ],
    ])->save();
    $out[] = "created instance on material.$bundle";
  }
  else {
    $out[] = "instance on material.$bundle already exists";
  }

  // Form: a select, as decorative_rock does for field_rock_type. A nested
  // vocabulary renders indented, so the office sees Deciduous › Fruit Trees.
  $form = $formStorage->load("material.$bundle.default");
  if ($form && !$form->getComponent(BOS_CAT_FIELD)) {
    $form->setComponent(BOS_CAT_FIELD, [
      'type' => 'options_select',
      'weight' => -1,
      'region' => 'content',
    ])->save();
    $out[] = "added to material.$bundle.default form display";
  }

  $view = $viewStorage->load("material.$bundle.default");
  if ($view && !$view->getComponent(BOS_CAT_FIELD)) {
    $view->setComponent(BOS_CAT_FIELD, [
      'type' => 'entity_reference_label',
      'label' => 'above',
      'weight' => 1,
      'region' => 'content',
      'settings' => ['link' => TRUE],
    ])->save();
    $out[] = "added to material.$bundle.default view display";
  }
}

// ---- 3. pathauto pattern ---------------------------------------------------
$patternStorage = \Drupal::entityTypeManager()->getStorage('pathauto_pattern');
foreach ($PATTERNS as $bundle => $patternId) {
  $pattern = $patternStorage->load($patternId);
  if (!$pattern) {
    // annuals never had one, so its items had no alias at all.
    $pattern = $patternStorage->create([
      'id' => $patternId,
      'label' => 'Material - ' . ucfirst($bundle) . ' - Path',
      'type' => 'canonical_entities:material',
      'pattern' => $NEW_PATTERN,
      'selection_criteria' => [],
      'selection_logic' => 'and',
      'weight' => -2,
    ]);
    $pattern->addSelectionCondition([
      'id' => 'entity_bundle:material',
      'bundles' => [$bundle => $bundle],
      'negate' => FALSE,
      'context_mapping' => ['material' => 'material'],
    ]);
    $pattern->save();
    $out[] = "created pathauto pattern $patternId for $bundle";
    continue;
  }
  if ($pattern->getPattern() === $NEW_PATTERN) {
    $out[] = "$patternId already uses the category token";
    continue;
  }
  $out[] = $patternId . ': ' . $pattern->getPattern() . "\n              -> " . $NEW_PATTERN;
  $pattern->setPattern($NEW_PATTERN);
  $pattern->save();
}

foreach ($out as $line) {
  print '  ' . $line . "\n";
}
print "\nExisting aliases are NOT regenerated — an item moves when it is saved with\n";
print "a category. Unassigned items keep their current URL via the alias guard.\n";
