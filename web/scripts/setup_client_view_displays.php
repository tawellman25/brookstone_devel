<?php

declare(strict_types=1);

/**
 * Give every public-facing vocabulary a Client tier.
 *
 * The BOS audience rule is four tiers on one URL — office, crew, CLIENT, public.
 * A survey of what is actually built found the client tier present on only three
 * of the public bundles (material_types, plant_characteristics,
 * plant_character_categories) and on the material entity; `services`,
 * `equipment_types`, both backflow vocabularies and all six spray vocabularies
 * had no client display and no client routing, so a signed-in customer was
 * served the anonymous Public page. material_types was worse: the display
 * existed and enabled, and the routing hook simply never used it.
 *
 * THIS SCRIPT ONLY CREATES THE DISPLAYS, and it must run BEFORE the routing is
 * changed. `hook_entity_view_mode_alter` naming a view mode that has no
 * configured display does not fail — Drupal falls back to `default`, and on
 * these bundles `default` carries the crew copy and the internal wording
 * document (verified on material_types). So routing first would not have gated
 * clients, it would have handed them the internal page.
 *
 * Each client_view is created as an exact copy of `full`, which is the
 * conservative default: a client sees precisely the public page until the office
 * decides to show them more. Nothing is copied from `default`.
 *
 * Never overwrites an existing client_view — office may already have curated it.
 *
 * Idempotent. Dry-run by default; BOS_CLIENT_VIEW_APPLY=1 to write.
 *   drush php:script web/scripts/setup_client_view_displays.php
 */

$apply = getenv('BOS_CLIENT_VIEW_APPLY') === '1';

/** Public-facing taxonomy bundles in the audience-tier scheme. */
$BUNDLES = [
  'services',
  'equipment_types',
  'backflow_device_types',
  'backflow_uses',
  'carrier',
  'spraying_locations',
  'spraying_methods',
  'wind_direction',
  'spraying_wind_speed',
  'spraying_soil_moisture_levels',
];

$storage = \Drupal::entityTypeManager()->getStorage('entity_view_display');
$created = $skipped = $blocked = 0;

foreach ($BUNDLES as $bundle) {
  $fullId = 'taxonomy_term.' . $bundle . '.full';
  $clientId = 'taxonomy_term.' . $bundle . '.client_view';

  if ($storage->load($clientId)) {
    printf("  exists   %-32s client_view already present — left alone\n", $bundle);
    $skipped++;
    continue;
  }

  $full = $storage->load($fullId);
  if (!$full) {
    // Without a Public display there is nothing safe to copy, and copying
    // `default` would publish the crew copy. Report instead.
    printf("  SKIP     %-32s no `full` display to copy — not guessing\n", $bundle);
    $blocked++;
    continue;
  }

  printf("  %s %-32s client_view <- copy of full (%d fields)\n",
    $apply ? 'CREATE  ' : 'would   ', $bundle, count($full->get('content') ?? []));

  if ($apply) {
    $clone = $full->createDuplicate();
    $clone->set('id', $clientId);
    $clone->set('mode', 'client_view');
    $clone->save();
  }
  $created++;
}

printf("\n  %s: %d   already present: %d   skipped: %d\n",
  $apply ? 'created' : 'would create', $created, $skipped, $blocked);

if (!$apply) {
  print "\nDRY RUN. Re-run with BOS_CLIENT_VIEW_APPLY=1 to write.\n";
  print "Run this BEFORE deploying the routing change.\n";
}
