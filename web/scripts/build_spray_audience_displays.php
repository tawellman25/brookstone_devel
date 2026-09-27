<?php

declare(strict_types=1);

/**
 * Build the audience view-mode tier displays (full / teammate_view /
 * admin_view) for the six spray vocabularies that carry field_teammate_
 * description. Closes the pre-existing public crew-content leak: today every
 * spray leaf term renders field_teammate_description on its single `default`
 * display, so the crew notes show to anonymous visitors.
 *
 * Tier model (see __BOS_AI/Governance/ui_patterns.md), routed by
 * bos_spray_types.module (office -> admin_view, crew -> teammate_view, else
 * full) with a user.roles cache context:
 *   - full (public):   the current public fields MINUS field_teammate_description.
 *                      wind_direction shows field_short_description (the new one-
 *                      line public copy) instead of the legacy `description`
 *                      travelogue. spraying_locations likewise renders
 *                      field_short_description + field_public_description and never
 *                      core `description` (all 20 terms were migrated onto the
 *                      dedicated fields; see
 *                      migrate_spraying_locations_descriptions.php).
 *   - teammate_view:   name + field_teammate_description (crew instruction only).
 *   - admin_view:      everything (name, description, short_desc, applicable
 *                      services, teammate_description) under the .bos-admin-view
 *                      wrapper.
 *
 * Formatters are copied from each vocab's existing default display where the
 * field is present, else synthesized by type. Idempotent (safe to re-run).
 * Entity-view-display configs skip on cim, so this script is the deploy path.
 *
 *   drush php:script web/scripts/build_spray_audience_displays.php
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;

$etm = \Drupal::entityTypeManager();

// Public fields per vocab (field_teammate_description deliberately excluded).
$FULL = [
  'carrier' => ['name', 'description'],
  'spraying_locations' => ['name', 'field_short_description', 'field_public_description', 'field_applicable_services'],
  'spraying_methods' => ['name', 'description', 'field_applicable_services'],
  'wind_direction' => ['name', 'field_short_description'],
  'spraying_wind_speed' => ['name', 'description'],
  'spraying_soil_moisture_levels' => ['name', 'description'],
];
// Office tier: everything the vocab has.
$ADMIN = [
  'carrier' => ['name', 'description', 'field_teammate_description'],
  'spraying_locations' => ['name', 'field_short_description', 'field_public_description', 'field_applicable_services', 'field_teammate_description'],
  'spraying_methods' => ['name', 'description', 'field_applicable_services', 'field_teammate_description'],
  // wind_direction: the legacy `description` travelogue is replaced entirely by
  // field_short_description (public) + field_teammate_description (crew), so it
  // is not rendered on any tier. The field value is left in place (dormant,
  // recoverable) but never displayed.
  'wind_direction' => ['name', 'field_short_description', 'field_teammate_description'],
  'spraying_wind_speed' => ['name', 'description', 'field_teammate_description'],
  'spraying_soil_moisture_levels' => ['name', 'description', 'field_teammate_description'],
];
// Crew tier: name + the crew instruction.
$TEAMMATE_FIELDS = ['name', 'field_teammate_description'];

/** Synthesize a component for a field we cannot copy from default. */
$synth = function (string $field, string $vid) use ($etm): array {
  if ($field === 'name') {
    return ['type' => 'string', 'label' => 'hidden', 'weight' => 0, 'settings' => ['link_to_entity' => FALSE]];
  }
  $fc = $etm->getStorage('field_config')->load("taxonomy_term.$vid.$field");
  $type = $fc ? $fc->getType() : 'string';
  $fmt = match ($type) {
    'text_long', 'text_with_summary', 'text' => 'text_default',
    'entity_reference' => 'entity_reference_label',
    default => 'string',
  };
  return ['type' => $fmt, 'label' => 'hidden', 'weight' => 5, 'settings' => []];
};

/** Load default display components (source of formatters). */
$defaults = [];
foreach (array_keys($FULL) as $vid) {
  $d = $etm->getStorage('entity_view_display')->load("taxonomy_term.$vid.default");
  $defaults[$vid] = $d ? ($d->get('content') ?? []) : [];
}

/** Build one display with an ordered field list. */
$build = function (string $vid, string $mode, array $fields) use ($etm, $defaults, $synth) {
  $id = "taxonomy_term.$vid.$mode";
  $disp = $etm->getStorage('entity_view_display')->load($id);
  if (!$disp) {
    $disp = EntityViewDisplay::create([
      'targetEntityType' => 'taxonomy_term',
      'bundle' => $vid,
      'mode' => $mode,
      'status' => TRUE,
    ]);
  }
  $disp->setStatus(TRUE);
  // Clear existing components so re-runs are clean and teammate_desc can't linger.
  foreach (array_keys($disp->get('content') ?? []) as $f) {
    $disp->removeComponent($f);
  }
  $w = 0;
  foreach ($fields as $field) {
    $comp = $defaults[$vid][$field] ?? $synth($field, $vid);
    $comp['weight'] = $w++;
    // Body fields render label-less; name is the heading.
    if (!isset($comp['label'])) {
      $comp['label'] = 'hidden';
    }
    $disp->setComponent($field, $comp);
  }
  $disp->save();
  return $id;
};

foreach ($FULL as $vid => $publicFields) {
  $build($vid, 'full', $publicFields);
  $build($vid, 'teammate_view', $TEAMMATE_FIELDS);
  $build($vid, 'admin_view', $ADMIN[$vid]);
  printf("built displays for %-32s full=[%s]\n", $vid, implode(',', $publicFields));
}

print "DONE.\n";
