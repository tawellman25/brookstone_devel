<?php
/**
 * Photos of the air-hose hookup on the sprinkler water sources.
 *
 * Todd, 13 October: crews need pictures of the hookup location and of it
 * actually hooked up with the air hose, and thought a field already existed.
 *
 * It did not. What exists is `field_ss_hookup_location`, which is a STRING — a
 * typed description of where the hookup is — and `field_ss_shut_off_location_pic`,
 * which is an image but belongs to the SHUT-OFF, not the hookup. So the photo
 * fields were not hidden; there simply was no hookup photo field.
 *
 * Two fields rather than one, because they are two different pictures doing two
 * different jobs: where the fitting is (find it next October), and what a
 * correct connection looks like on this property (whoever shows up next can see
 * it was done right, and how).
 *
 * Both UNLIMITED, matching field_ss_shut_off_location_pic — a second angle is
 * extra information, not a replacement. Settings are CLONED from that field so
 * the file directory, extensions and alt handling match the house pattern
 * rather than being invented here.
 *
 * All three source bundles: a blowout happens on ditch, domestic and well
 * alike.
 *
 * Idempotent; dry run unless BOS_HOOKUP_APPLY=1.
 */
$apply = getenv('BOS_HOOKUP_APPLY') === '1';
printf("%s\n\n", $apply ? 'APPLYING' : 'DRY RUN (BOS_HOOKUP_APPLY=1 to apply)');

$etm = \Drupal::entityTypeManager();
$repo = \Drupal::service('entity_display.repository');
$ENTITY = 'property_ss_sources';
$BUNDLES = ['dirty_water_source', 'domestic_source', 'well_water_source'];
$CLONE_FROM = 'field_ss_shut_off_location_pic';
$AFTER = 'field_ss_hookup_location';

$NEW = [
  'field_ss_hookup_location_pic' => [
    'label' => 'Hookup Location Photo(s)',
    'description' => 'Where the air hookup is — enough context to find it again next October.',
  ],
  'field_ss_hookup_connected_pic' => [
    'label' => 'Hooked Up (air hose) Photo(s)',
    'description' => 'The hose actually connected, so the next person can see how it goes on.',
  ],
];

// --- storages ------------------------------------------------------------
$storageSrc = $etm->getStorage('field_storage_config')->load("$ENTITY.$CLONE_FROM");
if (!$storageSrc) {
  printf("  ABORT: %s has no storage to clone from.\n", $CLONE_FROM);
  return;
}
foreach (array_keys($NEW) as $name) {
  if (strlen($name) > 32) {
    printf("  ABORT: %s is %d chars — the field name limit is 32.\n", $name, strlen($name));
    return;
  }
  $existing = $etm->getStorage('field_storage_config')->load("$ENTITY.$name");
  if ($existing) {
    printf("  storage %-30s already exists (cardinality %d)\n", $name, $existing->getCardinality());
    continue;
  }
  printf("  storage %-30s CREATE (image, unlimited)\n", $name);
  if ($apply) {
    $etm->getStorage('field_storage_config')->create([
      'field_name' => $name,
      'entity_type' => $ENTITY,
      'type' => 'image',
      'cardinality' => -1,
      'settings' => $storageSrc->getSettings(),
    ])->save();
  }
}

// --- instances + displays ------------------------------------------------
$instSrcSettings = NULL;
foreach ($BUNDLES as $bundle) {
  $src = $etm->getStorage('field_config')->load("$ENTITY.$bundle.$CLONE_FROM");
  if ($src && $instSrcSettings === NULL) {
    $instSrcSettings = $src->getSettings();
  }
  print "\n  --- $bundle ---\n";
  $form = $repo->getFormDisplay($ENTITY, $bundle);
  $view = $repo->getViewDisplay($ENTITY, $bundle);
  $anchorForm = $form->getComponent($AFTER);
  $anchorView = $view->getComponent($AFTER);
  $w = 0;
  foreach ($NEW as $name => $meta) {
    $inst = $etm->getStorage('field_config')->load("$ENTITY.$bundle.$name");
    if ($inst) {
      printf("    instance %-30s already on the bundle\n", $name);
    }
    else {
      printf("    instance %-30s CREATE\n", $name);
      if ($apply) {
        $etm->getStorage('field_config')->create([
          'field_name' => $name,
          'entity_type' => $ENTITY,
          'bundle' => $bundle,
          'label' => $meta['label'],
          'description' => $meta['description'],
          'required' => FALSE,
          'settings' => $instSrcSettings ?: [],
        ])->save();
      }
    }
    $w++;
    // Sit with the hookup fields rather than at the bottom of the form.
    $weight = ($anchorForm['weight'] ?? 10) + ($w * 0.1);
    if (!$form->getComponent($name)) {
      printf("      form  -> added after %s (weight %.1f)\n", $AFTER, $weight);
      if ($apply) {
        $form->setComponent($name, [
          'type' => 'image_image',
          'weight' => $weight,
          'region' => 'content',
          'settings' => ['progress_indicator' => 'throbber', 'preview_image_style' => 'thumbnail'],
          'third_party_settings' => [],
        ]);
      }
    }
    else { print "      form  -> already there\n"; }

    $vweight = ($anchorView['weight'] ?? 10) + ($w * 0.1);
    if (!$view->getComponent($name)) {
      printf("      view  -> added after %s (weight %.1f)\n", $AFTER, $vweight);
      if ($apply) {
        $view->setComponent($name, [
          'type' => 'image',
          'label' => 'above',
          'weight' => $vweight,
          'region' => 'content',
          'settings' => ['image_style' => 'medium', 'image_link' => 'file'],
          'third_party_settings' => [],
        ]);
      }
    }
    else { print "      view  -> already there\n"; }
  }
  if ($apply) {
    $form->save();
    $view->save();
  }
}
print $apply ? "\ndone\n" : "\nnothing written\n";
