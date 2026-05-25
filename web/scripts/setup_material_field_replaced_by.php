<?php

declare(strict_types=1);

/**
 * Phase 3.1 — add field_replaced_by (self-reference) to 17 material
 * bundles, with form widget revealed after field_discontinued and
 * default view display visible.
 *
 * Bundles INCLUDED: irrigation, pvc, brass, copper, galv, electric,
 *   poly, pumps, backflow, landscape, pavers, supplies, xmas, plants,
 *   shrubs, trees, annuals.
 * Bundles EXCLUDED (rationale in __BOS_AI/Entities/material.md):
 *   bulk_material, mulch, decorative_rock, sod, misc.
 *
 * Idempotent. Re-runnable.
 *
 * Usage:
 *   ddev drush scr web/scripts/setup_material_field_replaced_by.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$INCLUDED_BUNDLES = [
  'irrigation', 'pvc', 'brass', 'copper', 'galv', 'electric',
  'poly', 'pumps', 'backflow', 'landscape', 'pavers', 'supplies',
  'xmas', 'plants', 'shrubs', 'trees', 'annuals',
];

$FIELD = 'field_replaced_by';
$LABEL = 'Replaced By';
$DESCRIPTION = 'For discontinued materials, points to the current-generation replacement. Leave empty if no replacement is known.';

// ── 1. Field storage on material ────────────────────────────────────
if (!FieldStorageConfig::loadByName('material', $FIELD)) {
  FieldStorageConfig::create([
    'field_name'  => $FIELD,
    'entity_type' => 'material',
    'type'        => 'entity_reference',
    'cardinality' => 1,
    'settings'    => ['target_type' => 'material'],
  ])->save();
  echo "  + storage material.$FIELD\n";
}
else {
  echo "  · storage material.$FIELD already exists\n";
}

// ── 2. Field instance + display placement per bundle ────────────────
foreach ($INCLUDED_BUNDLES as $bundle) {
  // Instance
  if (!FieldConfig::loadByName('material', $bundle, $FIELD)) {
    FieldConfig::create([
      'field_name'  => $FIELD,
      'entity_type' => 'material',
      'bundle'      => $bundle,
      'label'       => $LABEL,
      'required'    => FALSE,
      'description' => $DESCRIPTION,
      'settings'    => [
        'handler' => 'default:material',
        'handler_settings' => [
          'target_bundles'     => NULL, // any material bundle
          'sort'               => ['field' => '_none'],
          'auto_create'        => FALSE,
          'auto_create_bundle' => '',
        ],
      ],
    ])->save();
    echo "  + instance material.$bundle.$FIELD\n";
  }
  else {
    echo "  · instance material.$bundle.$FIELD already exists\n";
  }

  // Form display: placement just after field_discontinued.
  $form = \Drupal::entityTypeManager()
    ->getStorage('entity_form_display')
    ->load("material.$bundle.default");
  if (!$form) {
    echo "  ! no form display for material.$bundle — skipping form placement\n";
  }
  else {
    $disc = $form->getComponent('field_discontinued');
    $weight = $disc ? ($disc['weight'] + 1) : 100;
    $form->setComponent($FIELD, [
      'type'     => 'entity_reference_autocomplete',
      'weight'   => $weight,
      'settings' => [
        'match_operator' => 'CONTAINS',
        'match_limit'    => 10,
        'size'           => 60,
        'placeholder'    => '',
      ],
      'third_party_settings' => [],
      'region'   => 'content',
    ]);
    // Bump any subsequent fields by 1 to keep ordering coherent.
    foreach ($form->toArray()['content'] ?? [] as $name => $cfg) {
      if ($name === $FIELD || $name === 'field_discontinued') continue;
      if (($cfg['weight'] ?? 0) >= $weight && ($cfg['weight'] ?? 0) <= $weight + 0) {
        $cfg['weight']++;
        $form->setComponent($name, $cfg);
      }
    }
    $form->save();
    echo "    -> form placed at weight $weight (after field_discontinued)\n";
  }

  // View display: visible by default with entity_reference_label.
  $view = \Drupal::entityTypeManager()
    ->getStorage('entity_view_display')
    ->load("material.$bundle.default");
  if (!$view) {
    echo "  ! no view display for material.$bundle — skipping view placement\n";
  }
  else {
    $disc_v = $view->getComponent('field_discontinued');
    $weight_v = $disc_v ? ($disc_v['weight'] + 1) : 100;
    $view->setComponent($FIELD, [
      'type'     => 'entity_reference_label',
      'label'    => 'inline',
      'weight'   => $weight_v,
      'settings' => ['link' => TRUE],
      'third_party_settings' => [],
      'region'   => 'content',
    ]);
    $view->save();
    echo "    -> view placed at weight $weight_v\n";
  }
}

echo "\nDone.\n";
