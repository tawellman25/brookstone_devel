<?php

declare(strict_types=1);

/**
 * Add field_call_to_action to the services vocabulary.
 *
 * Marketing supplied CTA copy for Lighting and Patios; the field exists on
 * material_types and renders there on `full`, but the services vocabulary never
 * got it, so that copy had nowhere to go. Storage is shared on taxonomy_term -
 * instance and display only.
 *
 * Mirrors the material_types instance rather than inventing settings, and
 * renders at a weight BELOW the public description so the ask closes the page.
 *
 *   drush php:script web/scripts/setup_services_cta_field.php
 *   BOS_CTA_APPLY=1 drush php:script web/scripts/setup_services_cta_field.php
 */

use Drupal\field\Entity\FieldConfig;

$apply = getenv('BOS_CTA_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
$FIELD = 'field_call_to_action';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_CTA_APPLY=1 to write)\n\n";

$model = $etm->getStorage('field_config')->load("taxonomy_term.material_types.$FIELD");
if (!$model) { print "ABORT — no material_types instance to mirror.\n"; return; }
printf("mirroring material_types instance (type %s, required %s)\n",
  $model->getType(), $model->isRequired() ? 'yes' : 'no');

$inst = $etm->getStorage('field_config')->load("taxonomy_term.services.$FIELD");
if ($inst) { print "✓ services instance already exists\n"; }
else {
  print "+ creating services instance\n";
  if ($apply) {
    FieldConfig::create([
      'field_storage' => $etm->getStorage('field_storage_config')->load("taxonomy_term.$FIELD"),
      'bundle' => 'services',
      'label' => $model->label(),
      'description' => $model->getDescription(),
      'required' => FALSE,
      'settings' => $model->getSettings(),
    ])->save();
  }
}

// Form, so the office can edit it without a deploy.
$fd = $etm->getStorage('entity_form_display')->load('taxonomy_term.services.default');
if ($fd && !$fd->getComponent($FIELD)) {
  print "+ adding to the term edit form\n";
  if ($apply) { $fd->setComponent($FIELD, ['type' => 'text_textarea', 'weight' => 30, 'region' => 'content'])->save(); }
}
elseif ($fd) { print "✓ already on the term edit form\n"; }

// Public display only. NOT teammate_view - a crew page has no ask on it.
$mt = $etm->getStorage('entity_view_display')->load('taxonomy_term.material_types.full');
$weight = $mt && ($c = $mt->getComponent($FIELD)) ? $c['weight'] : 20;
$vd = $etm->getStorage('entity_view_display')->load('taxonomy_term.services.full');
if (!$vd) { print "ABORT — services.full display not found.\n"; return; }
if ($vd->getComponent($FIELD)) { print "✓ already on the public display\n"; }
else {
  printf("+ adding to services.full at weight %d (material_types uses %d)\n", $weight, $weight);
  if ($apply) {
    $vd->setComponent($FIELD, ['type' => 'text_default', 'label' => 'hidden', 'weight' => $weight, 'region' => 'content'])->save();
  }
}

// It must NOT appear on the crew display.
foreach (['teammate_view', 'admin_view'] as $mode) {
  $d = $etm->getStorage('entity_view_display')->load("taxonomy_term.services.$mode");
  if ($d && $d->getComponent($FIELD) && $mode === 'teammate_view') {
    print "⚠ CTA is on teammate_view — a crew page should not carry the ask.\n";
  }
}

print $apply ? "\nDone.\n" : "\n(dry-run — nothing written)\n";
