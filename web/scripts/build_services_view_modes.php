<?php

/**
 * Idempotent: build the public (full) and training (teammate_view) view-mode
 * displays for the services taxonomy. Same /services/{name} URL renders one or
 * the other depending on the viewer's role (see the bos_services module).
 *
 *   drush php:script web/scripts/build_services_view_modes.php
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;

$default = EntityViewDisplay::load('taxonomy_term.services.default');

/** Clone a field's formatter from the default display, or fall back. */
$component = function (string $field, array $override) use ($default): array {
  $base = ($default && $default->getComponent($field)) ? $default->getComponent($field) : [];
  return $override + $base + ['region' => 'content', 'settings' => [], 'third_party_settings' => []];
};

$build = function (string $mode, array $components) {
  $display = EntityViewDisplay::load("taxonomy_term.services.$mode");
  if (!$display) {
    $display = EntityViewDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => 'services',
      'mode' => $mode,
      'status' => TRUE,
    ]);
  }
  // Start clean: drop every existing component so the mode shows ONLY our set.
  foreach (array_keys($display->getComponents()) as $name) {
    $display->removeComponent($name);
  }
  foreach ($components as $field => $spec) {
    $display->setComponent($field, $spec);
  }
  $display->save();
  return $mode . ': ' . implode(', ', array_keys($components));
};

$out = [];

// ── Public "what we do" page (full) ─────────────────────────────────────────
$out[] = $build('full', [
  'field_iconic_image' => $component('field_iconic_image', ['label' => 'hidden', 'type' => 'image', 'weight' => 0]),
  'field_subtitle' => $component('field_subtitle', ['label' => 'hidden', 'type' => 'string', 'weight' => 1]),
  'field_public_description' => ['type' => 'text_default', 'label' => 'hidden', 'weight' => 2, 'region' => 'content', 'settings' => [], 'third_party_settings' => []],
]);

// ── Teammate "how we do it" training page (teammate_view) ────────────────────
$out[] = $build('teammate_view', [
  'field_subtitle' => $component('field_subtitle', ['label' => 'hidden', 'type' => 'string', 'weight' => 0]),
  'field_description' => ['type' => 'text_default', 'label' => 'above', 'weight' => 1, 'region' => 'content', 'settings' => [], 'third_party_settings' => []],
  'field_department' => $component('field_department', ['label' => 'inline', 'type' => 'entity_reference_label', 'weight' => 2]),
  'field_sop_code' => $component('field_sop_code', ['label' => 'inline', 'type' => 'string', 'weight' => 3]),
]);

echo "== build_services_view_modes ==\n";
foreach ($out as $line) {
  echo "  - $line\n";
}
