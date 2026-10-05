<?php

declare(strict_types=1);

/**
 * field_scope_summary on estimate.christmas_decorations.
 *
 * 15 of the 45 estimate bundles carry this field, INCLUDING both lighting
 * siblings (exterior_lighting, landscape_lighting) — christmas_decorations does
 * not. That is an inconsistency in the bundle, not a deliberate exclusion.
 *
 * It matters because estimate_intake copies the request's field_client_requested
 * into field_scope_summary when it auto-creates an estimate, guarded by
 * hasField(). Without the field the scope a teammate wrote at the curb is
 * silently dropped from the estimate and survives only on the request.
 *
 * Storage already exists; this adds the instance and the displays only.
 *
 *   drush php:script web/scripts/add_scope_summary_to_christmas_estimate.php
 *   BOS_SCOPE_APPLY=1 drush php:script web/scripts/add_scope_summary_to_christmas_estimate.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

$apply = getenv('BOS_SCOPE_APPLY') === '1';
print $apply ? "MODE: APPLY\n\n" : "MODE: DRY-RUN (BOS_SCOPE_APPLY=1 to write)\n\n";

$FIELD = 'field_scope_summary';
$BUNDLE = 'christmas_decorations';
$etm = \Drupal::entityTypeManager();

$storage = FieldStorageConfig::loadByName('estimate', $FIELD);
if (!$storage) {
  print "ERROR: no $FIELD storage on estimate — refusing to invent one. Aborting.\n";
  return;
}
printf("  storage exists (%s), reusing\n", $storage->getType());

// Copy the instance settings from a sibling that already has it, rather than
// choosing new ones — landscape_lighting is the closest relative.
$model = FieldConfig::loadByName('estimate', 'landscape_lighting', $FIELD)
  ?: FieldConfig::loadByName('estimate', 'landscaping', $FIELD);
if (!$model) {
  print "ERROR: no sibling instance to copy settings from. Aborting.\n";
  return;
}
printf("  modelled on estimate.%s (label \"%s\")\n", $model->getTargetBundle(), $model->label());

if (FieldConfig::loadByName('estimate', $BUNDLE, $FIELD)) {
  print "  instance already exists — nothing to do.\n";
}
else {
  printf("+ instance  %s on estimate.%s\n", $FIELD, $BUNDLE);
  if ($apply) {
    $new = $model->createDuplicate();
    $new->set('bundle', $BUNDLE);
    $new->save();
  }
}

// Mirror the sibling's form and view placement so it appears where an estimator
// expects it, rather than at the bottom of the form.
// Displays are modelled on `landscaping`, NOT on the instance model above.
// landscape_lighting carries the field but renders it on neither display — that
// is an oversight in that bundle, not a convention, and copying it would mean
// writing a scope summary nobody can read. 12 bundles display it; landscaping
// is the canonical one.
foreach (['entity_form_display' => 'form', 'entity_view_display' => 'view'] as $type => $label) {
  $src = $etm->getStorage($type)->load('estimate.landscaping.default');
  $dst = $etm->getStorage($type)->load("estimate.$BUNDLE.default");
  if (!$src || !$dst) {
    printf("  %s display: source or target missing, skipped\n", $label);
    continue;
  }
  $component = $src->getComponent($FIELD);
  if (!$component) {
    printf("  %s display: sibling does not show it, skipped\n", $label);
    continue;
  }
  if ($dst->getComponent($FIELD)) {
    printf("  %s display: already present\n", $label);
    continue;
  }
  printf("+ %s display: adding with the sibling's settings\n", $label);
  if ($apply) {
    $dst->setComponent($FIELD, $component)->save();
  }
}

if (!$apply) {
  print "\n(dry-run — nothing written)\n";
  return;
}
print "\nDone.\n";
