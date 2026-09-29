<?php

declare(strict_types=1);

/**
 * Stage 5 — the four audience displays on both plant vocabularies.
 *
 * Public / Client / Teammate / Admin, on one canonical URL each, routed by
 * material_entity_view_mode_alter with a user.roles cache context so the render
 * cache cannot hand one audience's body to another.
 *
 * Clients get their own tier rather than falling through to Public: an
 * authenticated customer can be shown more than an anonymous visitor, and
 * retrofitting the tier later is more work than including it now.
 *
 * Category page order is deliberate: body, then the list of characteristics,
 * then the call to action. The list sits INSIDE the writing rather than under
 * it, so the page reads as a page rather than as a sales block bolted to a
 * directory.
 *
 * Idempotent. Run per environment.
 */

const CAT_VID = 'plant_character_categories';
const LEAF_VID = 'plant_characteristics';
const EVA = 'plant_characteristic_children_entity_view_1';

$etm = \Drupal::entityTypeManager();

// Crew instructions belong on the characteristics too, not only the categories.
$fc = $etm->getStorage('field_config');
if (!$fc->load('taxonomy_term.' . LEAF_VID . '.field_teammate_description')) {
  $fc->create([
    'field_name' => 'field_teammate_description',
    'entity_type' => 'taxonomy_term',
    'bundle' => LEAF_VID,
    'label' => 'Teammate instructions',
    'description' => 'Crew-facing. Shown on the teammate view only, never to the public or to clients.',
  ])->save();
  print "  added field_teammate_description to " . LEAF_VID . "\n";
  $form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . LEAF_VID . '.default');
  if ($form) {
    $form->setComponent('field_teammate_description', ['type' => 'text_textarea', 'weight' => 20, 'region' => 'content'])->save();
  }
}

$text = fn(int $w) => ['type' => 'text_default', 'label' => 'hidden', 'weight' => $w, 'region' => 'content'];

// bundle => mode => [visible components, everything else hidden]
$plan = [
  CAT_VID => [
    // Public: the category's own words, its characteristics, then its CTA.
    'full' => ['field_public_description' => $text(0), EVA => ['weight' => 10, 'region' => 'content'], 'field_call_to_action' => $text(20)],
    // Clients see the public page today. The tier exists so that can change
    // without a rebuild.
    'client_view' => ['field_public_description' => $text(0), EVA => ['weight' => 10, 'region' => 'content'], 'field_call_to_action' => $text(20)],
    // Crew: instructions first, then the list. No marketing copy.
    'teammate_view' => ['field_teammate_description' => $text(0), EVA => ['weight' => 10, 'region' => 'content']],
    // Office: everything, so a single page shows what each audience gets.
    'admin_view' => ['field_short_description' => $text(0), 'field_public_description' => $text(1), 'field_teammate_description' => $text(2), 'field_call_to_action' => $text(3), 'field_list_order' => ['type' => 'number_integer', 'label' => 'inline', 'weight' => 4, 'region' => 'content'], EVA => ['weight' => 10, 'region' => 'content']],
  ],
  LEAF_VID => [
    // The characteristics keep their text in core description.
    'full' => ['description' => ['weight' => 0, 'region' => 'content']],
    'client_view' => ['description' => ['weight' => 0, 'region' => 'content']],
    'teammate_view' => ['description' => ['weight' => 0, 'region' => 'content'], 'field_teammate_description' => $text(1)],
    'admin_view' => ['description' => ['weight' => 0, 'region' => 'content'], 'field_teammate_description' => $text(1), 'field_character_category' => ['type' => 'entity_reference_label', 'label' => 'inline', 'weight' => 2, 'region' => 'content']],
  ],
];

$storage = $etm->getStorage('entity_view_display');
foreach ($plan as $bundle => $modes) {
  $all = array_keys(\Drupal::service('entity_field.manager')->getFieldDefinitions('taxonomy_term', $bundle));
  foreach ($modes as $mode => $components) {
    $id = 'taxonomy_term.' . $bundle . '.' . $mode;
    $d = $storage->load($id);
    if (!$d) {
      $d = $storage->create([
        'targetEntityType' => 'taxonomy_term',
        'bundle' => $bundle,
        'mode' => $mode,
        'status' => TRUE,
      ]);
    }
    // Hide everything first, then switch on only what this audience gets —
    // safer than trusting whatever a previous pass left enabled.
    foreach ($all as $f) {
      if (!isset($components[$f])) {
        $d->removeComponent($f);
      }
    }
    foreach ($components as $name => $spec) {
      $d->setComponent($name, $spec);
    }
    $d->setStatus(TRUE)->save();
    printf("  %-34s %-14s %s\n", $bundle, $mode, implode(', ', array_keys($components)));
  }
}
print "DONE.\n";
