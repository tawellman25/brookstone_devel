<?php
/**
 * Give the `season` vocabulary the standard public-vocabulary field set.
 *
 * Step 0 found all four absent and every body sitting in core `description` —
 * the same shape as plant_characteristics before its migration, because
 * `season` is not in the bos_breadcrumbs allowlist and so was skipped by the
 * 2026-10-03 standardisation pass.
 *
 * Configuration is copied from material_types, which is the closest sibling:
 * the three description fields are text_long, single, optional and pinned to
 * full_html. The copy is HTML paragraphs, and a field left open to a more
 * restrictive format renders pasted markup as escaped text — a failure that
 * looks like a copy problem and is a field problem.
 *
 * ⚠ field_call_to_action is deliberately left with NO allowed_formats, because
 * that is how it is configured on material_types AND services. Matching the
 * siblings exactly was the instruction; the asymmetry is theirs, not ours, and
 * is reported rather than silently tightened.
 *
 * ⚠ field_short_description is added to the FORM only and to no view display.
 * It is read by the parent list as a Views field and must never render on the
 * term's own page above the body. bos_content_coverage enforces this on save.
 *
 * Idempotent. Field configs silently skip on cim, so this script is the deploy
 * path.
 */
$etm = \Drupal::entityTypeManager();
$VID = 'season';

$SPEC = [
  'field_short_description'    => ['Short Description', 'The one-line teaser. Shows on the Seasons list only — never on this page.', ['full_html']],
  'field_public_description'   => ['Public Description', 'The body of this season page.', ['full_html']],
  'field_teammate_description' => ['Teammate Instructions', 'Crew notes. Never shown publicly.', ['full_html']],
  'field_call_to_action'       => ['Call to Action', 'The closing ask, rendered below the services list.', NULL],
  'field_meta_tags'            => ['Meta tags', NULL, NULL],
];

foreach ($SPEC as $name => [$label, $desc, $formats]) {
  $storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('taxonomy_term', $name);
  if (!$storage) { printf("  %-30s NO STORAGE on taxonomy_term — skipped\n", $name); continue; }
  $field = \Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', $VID, $name);
  if ($field) { printf("  %-30s already on %s\n", $name, $VID); }
  else {
    $values = [
      'field_name' => $name, 'entity_type' => 'taxonomy_term', 'bundle' => $VID,
      'label' => $label, 'required' => FALSE,
    ];
    if ($desc !== NULL) { $values['description'] = $desc; }
    if ($formats !== NULL) { $values['settings'] = ['allowed_formats' => $formats]; }
    \Drupal\field\Entity\FieldConfig::create($values)->save();
    printf("  %-30s CREATED (formats: %s)\n", $name, $formats ? implode(',', $formats) : 'unrestricted — matches the siblings');
  }
}

// Form display: everything editable, in a sensible order.
$form = $etm->getStorage('entity_form_display')->load("taxonomy_term.$VID.default");
$weights = [
  'field_short_description' => 1, 'field_public_description' => 2,
  'field_call_to_action' => 3, 'field_teammate_description' => 4,
];
foreach ($weights as $name => $w) {
  if (!\Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', $VID, $name)) { continue; }
  if ($form->getComponent($name)) { continue; }
  $form->setComponent($name, ['type' => 'text_textarea', 'weight' => $w,
    'settings' => ['rows' => 5, 'placeholder' => ''], 'region' => 'content']);
  printf("  form widget added: %s\n", $name);
}
if (\Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', $VID, 'field_meta_tags') && !$form->getComponent('field_meta_tags')) {
  $form->setComponent('field_meta_tags', ['type' => 'metatag_firehose', 'weight' => 10, 'region' => 'content', 'settings' => ['sidebar' => TRUE]]);
  print "  form widget added: field_meta_tags\n";
}
$form->save();
print "\ndone\n";
