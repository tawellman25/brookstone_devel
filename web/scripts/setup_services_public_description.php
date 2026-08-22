<?php

/**
 * Idempotent: split the services-taxonomy description into public + training.
 *
 *  - Creates field_public_description (text_with_summary, full_html) on
 *    taxonomy_term.services — the public "what we do" body.
 *  - Copies the existing field_description ("Crew Description") into it on every
 *    services term (only when public is empty — nothing is lost, re-runnable).
 *  - Relabels field_description → "Crew / Training Description" (the teammate
 *    "how we do it" body; content is left in place to be rewritten as training).
 *  - Adds field_public_description to the services term form display.
 *
 *   drush php:script web/scripts/setup_services_public_description.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

$out = [];

// 1. Field storage.
$storage = FieldStorageConfig::loadByName('taxonomy_term', 'field_public_description');
if (!$storage) {
  $storage = FieldStorageConfig::create([
    'field_name' => 'field_public_description',
    'entity_type' => 'taxonomy_term',
    'type' => 'text_with_summary',
    'cardinality' => 1,
  ]);
  $storage->save();
  $out[] = 'created field storage field_public_description';
}
else {
  $out[] = 'field storage field_public_description already exists';
}

// 2. Field instance on services.
$instance = FieldConfig::loadByName('taxonomy_term', 'services', 'field_public_description');
if (!$instance) {
  $instance = FieldConfig::create([
    'field_storage' => $storage,
    'bundle' => 'services',
    'label' => 'Service Description (Public)',
    'description' => 'Public-facing "what we do" description shown on the service page to clients and the public.',
    'required' => FALSE,
    'settings' => [
      'display_summary' => TRUE,
      'required_summary' => FALSE,
      'allowed_formats' => ['full_html'],
    ],
  ]);
  $instance->save();
  $out[] = 'created field instance field_public_description on services';
}
else {
  $out[] = 'field instance field_public_description already exists';
}

// 3. Relabel the existing description as the crew/training body.
$crew = FieldConfig::loadByName('taxonomy_term', 'services', 'field_description');
if ($crew && $crew->label() !== 'Crew / Training Description') {
  $crew->setLabel('Crew / Training Description');
  $crew->setDescription('Teammate-facing "how we do it" training content. Shown on the same service page when a crew/office member views it (teammate_view). Public/clients never see this.');
  $crew->save();
  $out[] = 'relabeled field_description → "Crew / Training Description"';
}
else {
  $out[] = 'field_description already relabeled';
}

// 4. Copy existing Crew Description → public where public is empty.
$etm = \Drupal::entityTypeManager();
$tids = $etm->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)
  ->condition('vid', 'services')->execute();
// Choose the public "what we do" body. The core taxonomy `description`, when a
// term has one, is the intentional public/marketing copy → it wins. Otherwise
// the old Crew Description is the only public-ish source. The crew text stays in
// field_description as the training body regardless.
// Correction is safe/idempotent: we only overwrite an auto-copy (public still
// equals the crew text) — a hand-edited public body is never clobbered.
$norm = fn($t) => trim(preg_replace('/\s+/', ' ', strip_tags((string) $t)));
$fromCrew = 0; $fromCore = 0; $corrected = 0; $skipped = 0;
foreach ($etm->getStorage('taxonomy_term')->loadMultiple($tids) as $term) {
  if (!$term->hasField('field_public_description')) {
    continue;
  }
  $pub = $term->get('field_public_description');
  $crew = $term->get('field_description');
  $core = $term->get('description');
  $coreText = (!$core->isEmpty() && $norm($core->value) !== '') ? $core->value : NULL;
  $crewVal = !$crew->isEmpty() ? $crew->first()->getValue() : NULL;

  if ($pub->isEmpty()) {
    if ($coreText !== NULL) {
      $pub->setValue(['value' => $coreText, 'summary' => '', 'format' => $core->format ?: 'full_html']);
      $term->save();
      $fromCore++;
    }
    elseif ($crewVal !== NULL) {
      $pub->setValue($crewVal);
      $term->save();
      $fromCrew++;
    }
    else {
      $skipped++;
    }
  }
  elseif ($coreText !== NULL && $crewVal !== NULL
    && $norm($pub->value) === $norm($crewVal['value'] ?? '')
    && $norm($pub->value) !== $norm($coreText)) {
    // Public was auto-filled from crew, but a real public copy lives in core.
    $pub->setValue(['value' => $coreText, 'summary' => '', 'format' => $core->format ?: 'full_html']);
    $term->save();
    $corrected++;
  }
  else {
    $skipped++;
  }
}
$out[] = "public description: $fromCore from core, $fromCrew from crew, $corrected corrected to core ($skipped unchanged)";

// 5. Add field_public_description to the services term form display.
$form = EntityFormDisplay::load('taxonomy_term.services.default');
if ($form && !$form->getComponent('field_public_description')) {
  // Place it just above the crew/training body if we can find its weight.
  $crewWidget = $form->getComponent('field_description');
  $weight = is_array($crewWidget) && isset($crewWidget['weight']) ? ((int) $crewWidget['weight'] - 1) : 0;
  $form->setComponent('field_public_description', [
    'type' => 'text_textarea_with_summary',
    'weight' => $weight,
    'region' => 'content',
    'settings' => ['rows' => 6, 'summary_rows' => 3, 'placeholder' => '', 'show_summary' => TRUE],
    'third_party_settings' => [],
  ]);
  $form->save();
  $out[] = 'added field_public_description to the services term form';
}
else {
  $out[] = 'field_public_description already on the form (or no form display)';
}

echo "== setup_services_public_description ==\n";
foreach ($out as $line) {
  echo "  - $line\n";
}
