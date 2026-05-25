<?php

declare(strict_types=1);

/**
 * Phase 3.1 — add field_ingest_batch to material_price_history.entry,
 * place it inside the existing "Source / Origin" form fieldset group
 * (alongside field_source / field_wo_reference), and make it visible
 * on the default view display.
 *
 * Idempotent. Re-runnable.
 *
 * Usage:
 *   ddev drush scr web/scripts/setup_material_price_history_field_ingest_batch.php
 */

if (PHP_SAPI !== 'cli') {
  exit('CLI only.');
}

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$ENT    = 'material_price_history';
$BUNDLE = 'entry';
$FIELD  = 'field_ingest_batch';

// ── 1. Storage ──────────────────────────────────────────────────────
if (!FieldStorageConfig::loadByName($ENT, $FIELD)) {
  FieldStorageConfig::create([
    'field_name'  => $FIELD,
    'entity_type' => $ENT,
    'type'        => 'entity_reference',
    'cardinality' => 1,
    'settings'    => ['target_type' => 'supplier_price_ingest_batch'],
  ])->save();
  echo "  + storage $ENT.$FIELD\n";
}
else {
  echo "  · storage $ENT.$FIELD already exists\n";
}

// ── 2. Instance ─────────────────────────────────────────────────────
if (!FieldConfig::loadByName($ENT, $BUNDLE, $FIELD)) {
  FieldConfig::create([
    'field_name'  => $FIELD,
    'entity_type' => $ENT,
    'bundle'      => $BUNDLE,
    'label'       => 'Ingest Batch',
    'required'    => FALSE,
    'description' => 'Set when this history entry originated from a supplier price ingest. Links to the batch that produced it. NULL for all non-ingest sources (wo_entry, manual, invoice, etc.).',
    'settings'    => [
      'handler' => 'default:supplier_price_ingest_batch',
      'handler_settings' => [
        'target_bundles'     => ['batch' => 'batch'],
        'sort'               => ['field' => '_none'],
        'auto_create'        => FALSE,
        'auto_create_bundle' => '',
      ],
    ],
  ])->save();
  echo "  + instance $ENT.$BUNDLE.$FIELD\n";
}
else {
  echo "  · instance $ENT.$BUNDLE.$FIELD already exists\n";
}

// ── 3. Form display: add to group_source_origin fieldset ────────────
$form = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("$ENT.$BUNDLE.default");
if ($form) {
  // Add component (after field_wo_reference is logical — same fieldset)
  $wo_ref_w = ($form->getComponent('field_wo_reference')['weight'] ?? 5) + 1;
  $form->setComponent($FIELD, [
    'type'     => 'entity_reference_autocomplete',
    'weight'   => $wo_ref_w,
    'settings' => [
      'match_operator' => 'CONTAINS',
      'match_limit'    => 10,
      'size'           => 60,
      'placeholder'    => '',
    ],
    'third_party_settings' => [],
    'region'   => 'content',
  ]);

  // Attach to group_source_origin children list — insert after field_wo_reference
  $tps = $form->getThirdPartySettings('field_group');
  if (isset($tps['group_source_origin'])) {
    $children = $tps['group_source_origin']['children'] ?? [];
    if (!in_array($FIELD, $children, TRUE)) {
      // Insert after field_wo_reference
      $idx = array_search('field_wo_reference', $children, TRUE);
      if ($idx === FALSE) {
        $children[] = $FIELD;
      }
      else {
        array_splice($children, $idx + 1, 0, [$FIELD]);
      }
      $tps['group_source_origin']['children'] = $children;
      $form->setThirdPartySetting('field_group', 'group_source_origin', $tps['group_source_origin']);
    }
  }

  $form->save();
  echo "  + form display: placed in group_source_origin\n";
}
else {
  echo "  ! no form display found — skipped form placement\n";
}

// ── 4. View display: visible by default ─────────────────────────────
$view = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load("$ENT.$BUNDLE.default");
if ($view) {
  $src_v = $view->getComponent('field_source');
  $w = ($src_v['weight'] ?? 0) + 1;
  $view->setComponent($FIELD, [
    'type'     => 'entity_reference_label',
    'label'    => 'inline',
    'weight'   => $w,
    'settings' => ['link' => TRUE],
    'third_party_settings' => [],
    'region'   => 'content',
  ]);
  $view->save();
  echo "  + view display: visible at weight $w\n";
}

echo "\nDone.\n";
