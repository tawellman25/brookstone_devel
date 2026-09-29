<?php

declare(strict_types=1);

/**
 * Fields the two fruit pages need, which neither vocabulary had.
 *
 * material_types had a public description but nowhere to put text BELOW the
 * list of items, and no per-term metatags. plant_characteristics had no short
 * description — its leaves carry their text in core description.
 *
 * Also adds client_view to material_types. The standing rule is Public /
 * Client / Teammate / Admin, and this vocabulary predates that decision with
 * only three tiers; an authenticated customer can be shown more than an
 * anonymous visitor, and adding the tier later costs more than now.
 *
 * All storages already exist on taxonomy_term — instances only.
 *
 * Idempotent. Run per environment.
 */

$etm = \Drupal::entityTypeManager();
$fc = $etm->getStorage('field_config');

$add = [
  ['material_types', 'field_call_to_action', 'Closing text', 'Shown BELOW the list of items. Leave empty and nothing renders.', 'text_textarea'],
  ['material_types', 'field_meta_tags', 'Meta tags', 'Per-page SEO title and description. Overrides the global taxonomy pattern.', 'metatag_firehose'],
  ['plant_characteristics', 'field_short_description', 'Short description', 'One-line teaser. Used where this characteristic is listed on its category page.', 'text_textarea'],
  ['plant_characteristics', 'field_meta_tags', 'Meta tags', 'Per-page SEO title and description. Overrides the global taxonomy pattern.', 'metatag_firehose'],
];

foreach ($add as [$bundle, $name, $label, $desc, $widget]) {
  $id = 'taxonomy_term.' . $bundle . '.' . $name;
  if ($fc->load($id)) {
    printf("  exists   %-28s on %s\n", $name, $bundle);
    continue;
  }
  $fc->create([
    'field_name' => $name,
    'entity_type' => 'taxonomy_term',
    'bundle' => $bundle,
    'label' => $label,
    'description' => $desc,
  ])->save();
  printf("  created  %-28s on %s\n", $name, $bundle);

  $form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . $bundle . '.default');
  if ($form && !$form->getComponent($name)) {
    $form->setComponent($name, [
      'type' => $widget,
      'weight' => 30,
      'region' => 'content',
    ] + ($widget === 'metatag_firehose' ? ['settings' => ['sidebar' => TRUE]] : []))->save();
  }
}

// --- material_types displays: closing text below the list, and a client tier
$storage = $etm->getStorage('entity_view_display');
$public = $storage->load('taxonomy_term.material_types.full');
if ($public && !$public->getComponent('field_call_to_action')) {
  // Weight 20 puts it after the EVAs, which sit at 10.
  $public->setComponent('field_call_to_action', [
    'type' => 'text_default', 'label' => 'hidden', 'weight' => 20, 'region' => 'content',
  ])->save();
  print "  material_types.full now renders the closing text below the list\n";
}

if (!$storage->load('taxonomy_term.material_types.client_view')) {
  // Clients see the public page today; the tier exists so that can change
  // without a rebuild.
  $clone = $public->createDuplicate();
  $clone->set('id', 'taxonomy_term.material_types.client_view')->set('mode', 'client_view');
  $clone->save();
  print "  material_types.client_view created (mirrors Public for now)\n";
}
else {
  print "  material_types.client_view exists\n";
}
print "DONE.\n";
