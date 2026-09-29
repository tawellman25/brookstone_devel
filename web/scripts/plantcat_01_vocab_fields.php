<?php

declare(strict_types=1);

/**
 * Plant Character Categories — the vocabulary the eight groupings should have
 * been all along.
 *
 * "Flowering" is a plant characteristic. "Aesthetic Features" is not — it is a
 * grouping OF characteristics. Storing both in one vocabulary makes that
 * vocabulary mean two things, and every view over it then needs a filter to say
 * which meaning it wants. A separate vocabulary keeps each one meaning one
 * thing, and it is the smaller change: field_characteristic_category already
 * treats the category as its own axis, just stored as a list.
 *
 * Fields reuse the storages already on taxonomy_term wherever they exist, so
 * this vocabulary matches backflow_device_types, backflow_uses and the spray
 * vocabularies rather than inventing a parallel set.
 *
 * Idempotent. Run per environment — vocabulary and fields are config, terms are
 * content, and on this project ECK/field config silently skips cim, so the
 * script is the deploy path either way.
 */

const CAT_VID = 'plant_character_categories';

$etm = \Drupal::entityTypeManager();

// --- vocabulary -----------------------------------------------------------
$vocab = $etm->getStorage('taxonomy_vocabulary')->load(CAT_VID);
if (!$vocab) {
  $etm->getStorage('taxonomy_vocabulary')->create([
    'vid' => CAT_VID,
    'name' => 'Plant Character Categories',
    'description' => 'The eight groupings the plant characteristics are sorted into. Each one has its own landing page under /material/plants/characteristics.',
  ])->save();
  print "  vocabulary created\n";
}
else {
  print "  vocabulary exists\n";
}

// --- fields ---------------------------------------------------------------
// type => [label, description]. Storages that already exist on taxonomy_term
// are reused rather than duplicated.
$fields = [
  'field_short_description' => ['text_long', 'Short description', 'The card text on the /material/plants/characteristics landing page.'],
  'field_public_description' => ['text_long', 'Public description', 'The body of this category page, above the list of characteristics. Each category needs its own — eight near-identical openings is the pattern that gets a section discounted in search.'],
  'field_teammate_description' => ['text_long', 'Teammate instructions', 'Crew-facing. Shown on the teammate view only, never to the public or to clients.'],
  'field_call_to_action' => ['text_long', 'Call to action', 'Optional closing text, shown BELOW the list of characteristics. Leave empty and nothing renders.'],
  'field_list_order' => ['integer', 'List order', 'Lower sorts first. Values in tens so a category can be inserted later without renumbering.'],
];

$storage_cfg = $etm->getStorage('field_storage_config');
$field_cfg = $etm->getStorage('field_config');

foreach ($fields as $name => [$type, $label, $desc]) {
  if (!$storage_cfg->load('taxonomy_term.' . $name)) {
    $storage_cfg->create([
      'field_name' => $name,
      'entity_type' => 'taxonomy_term',
      'type' => $type,
      'cardinality' => 1,
    ])->save();
    printf("  storage created  %s\n", $name);
  }
  if (!$field_cfg->load('taxonomy_term.' . CAT_VID . '.' . $name)) {
    $field_cfg->create([
      'field_name' => $name,
      'entity_type' => 'taxonomy_term',
      'bundle' => CAT_VID,
      'label' => $label,
      'description' => $desc,
    ])->save();
    printf("  instance created %s\n", $name);
  }
  else {
    printf("  exists           %s\n", $name);
  }
}

// --- the four audience view modes -----------------------------------------
// full (Public) ships with core. The other three are created if missing.
$mode_storage = $etm->getStorage('entity_view_mode');
foreach (['client_view' => 'Client View', 'teammate_view' => 'Teammate View', 'admin_view' => 'Admin View'] as $id => $label) {
  if (!$mode_storage->load('taxonomy_term.' . $id)) {
    $mode_storage->create([
      'id' => 'taxonomy_term.' . $id,
      'label' => $label,
      'targetEntityType' => 'taxonomy_term',
    ])->save();
    printf("  view mode created %s\n", $id);
  }
  else {
    printf("  view mode exists  %s\n", $id);
  }
}

// --- form display ---------------------------------------------------------
$form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . CAT_VID . '.default');
if (!$form) {
  $form = $etm->getStorage('entity_form_display')->create([
    'targetEntityType' => 'taxonomy_term',
    'bundle' => CAT_VID,
    'mode' => 'default',
    'status' => TRUE,
  ]);
}
$w = 5;
foreach ($fields as $name => [$type, $label, $desc]) {
  $form->setComponent($name, [
    'type' => $type === 'integer' ? 'number' : 'text_textarea',
    'weight' => $w++,
    'region' => 'content',
  ]);
}
$form->save();
print "  form display saved\n";
print "DONE.\n";
