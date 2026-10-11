<?php
/**
 * Give `season` the same hero-banner field the other vocabularies use.
 *
 * field_banner_images is the existing shared storage on taxonomy_term —
 * equipment_types and backflow_device_types already carry it, and the theme
 * already has the hero region, the bo_hero_banner theme hook and the
 * brookstone_olivero/bo_hero library. So this is an instance, not a new field.
 * (services uses field_banner_image, SINGULAR; the multi-value one is the
 * rotating hero, which is what these pages want.)
 *
 * ⚠ Separate from field_icon, deliberately: the icon is the small mark in the
 * body and the list, the banner is the full-bleed photo at the top. field_icon
 * is left exactly as it is.
 *
 * ⚠ On the FORM only, never on a view display. The hero is rendered into the
 * theme's `hero` region by bos_seasons_preprocess_page(); leaving the field on
 * a display as well would stack the raw images down the page underneath it.
 *
 * Idempotent.
 */
$etm = \Drupal::entityTypeManager();
$VID = 'season';
$NAME = 'field_banner_images';

$storage = \Drupal\field\Entity\FieldStorageConfig::loadByName('taxonomy_term', $NAME);
if (!$storage) { print "  no $NAME storage on taxonomy_term — aborting rather than inventing one\n"; return; }
printf("  storage: %s, cardinality %s (reused, not created)\n", $storage->getType(), $storage->getCardinality());

$field = \Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', $VID, $NAME);
if ($field) { printf("  already on %s\n", $VID); }
else {
  // Copy the settings from the vocabulary where the hero already works.
  $sibling = \Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', 'backflow_device_types', $NAME);
  \Drupal\field\Entity\FieldConfig::create([
    'field_name' => $NAME, 'entity_type' => 'taxonomy_term', 'bundle' => $VID,
    'label' => 'Banner Images',
    'description' => 'Full-bleed photo at the top of this season page. More than one rotates. Shown to the public only.',
    'required' => FALSE,
    'settings' => $sibling ? $sibling->getSettings() : [],
  ])->save();
  printf("  CREATED on %s%s\n", $VID, $sibling ? ' (settings copied from backflow_device_types)' : '');
}

$form = $etm->getStorage('entity_form_display')->load("taxonomy_term.$VID.default");
if ($form && !$form->getComponent($NAME)) {
  $form->setComponent($NAME, [
    'type' => 'image_image', 'weight' => 0, 'region' => 'content',
    'settings' => ['progress_indicator' => 'throbber', 'preview_image_style' => 'thumbnail'],
  ])->save();
  print "  widget added to the term form\n";
}

// Never on a view display — the hero renders it.
foreach ($etm->getStorage('entity_view_display')->loadByProperties(['targetEntityType' => 'taxonomy_term', 'bundle' => $VID]) as $d) {
  if ($d->getComponent($NAME)) {
    $d->removeComponent($NAME)->save();
    printf("  removed from the %s display (the hero renders it)\n", $d->getMode());
  }
}
printf("  field_icon untouched: %s\n", \Drupal\field\Entity\FieldConfig::loadByName('taxonomy_term', $VID, 'field_icon') ? 'still there' : 'absent');
print "\ndone\n";
