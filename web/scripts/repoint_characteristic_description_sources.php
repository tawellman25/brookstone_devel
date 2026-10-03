<?php

/**
 * STEP 3 of the core-description retirement: repoint everything that reads
 * core `description` on plant_characteristics and growth_zone.
 *
 * Three concerns, which must land in one deploy because clearing core
 * description afterwards would otherwise blank whatever still reads it.
 *
 * A. TERM PAGE DISPLAYS → field_public_description.
 *    The leaf pages keep their body copy; the migration moved it, not deleted
 *    it. Core description is also dropped from the term form, so the office
 *    cannot keep typing into the retired field.
 *
 * B. ADMIN AND LANDING VIEWS → field_public_description.
 *    admin_material_plant_characteristics, admin_hardiness_zones and
 *    land_growth_zone list the body. Repointing them at the migrated field
 *    keeps those pages looking exactly as they do now, which is what the
 *    growth_zone verification requires.
 *
 * C. THE NINE CHARACTERISTIC LIST VIEWS → field_short_description.
 *    Eight char_cat_* views plus plant_characteristic_children. Per Todd: a
 *    list entry gets its own text, never a truncation of the body, and when the
 *    teaser is empty it renders NO text — an empty entry is a tracked to-do,
 *    whereas one quietly filled with stale copy hid this problem for weeks.
 *    No trim, no summary: the field is written to length.
 *
 * Idempotent. Dry-run by default.
 *
 * Usage:
 *   drush php:script web/scripts/repoint_characteristic_description_sources.php
 *   BOS_REPOINT_APPLY=1 drush php:script web/scripts/repoint_characteristic_description_sources.php
 */

$apply = getenv('BOS_REPOINT_APPLY') === '1';
$etm = \Drupal::entityTypeManager();
printf("MODE: %s\n\n", $apply ? 'APPLY' : 'DRY-RUN (set BOS_REPOINT_APPLY=1 to write)');

$changes = 0;

// --- A. term page displays + the form --------------------------------------
print "A. term page displays -> field_public_description\n";
foreach (['plant_characteristics', 'growth_zone'] as $vid) {
  foreach ($etm->getStorage('entity_view_display')->loadMultiple() as $id => $d) {
    if (strpos($id, 'taxonomy_term.' . $vid . '.') !== 0) {
      continue;
    }
    $core = $d->getComponent('description');
    if (!$core) {
      continue;
    }
    printf("   %-46s description(w%s) -> field_public_description\n", $id, $core['weight']);
    $changes++;
    if ($apply) {
      $d->setComponent('field_public_description', [
        'type' => 'text_default',
        'label' => 'hidden',
        'weight' => $core['weight'],
        'region' => 'content',
        'settings' => [],
        'third_party_settings' => [],
      ]);
      $d->removeComponent('description');
      $d->save();
    }
  }
  // Core description off the edit form, so nobody keeps filling the retired field.
  $form = $etm->getStorage('entity_form_display')->load('taxonomy_term.' . $vid . '.default');
  if ($form && $form->getComponent('description')) {
    printf("   %-46s removing core description from the FORM\n", 'taxonomy_term.' . $vid . '.default');
    $changes++;
    if ($apply) {
      $form->removeComponent('description')->save();
    }
  }
}

/**
 * Swap a view's core-description field for another field, preserving position.
 */
$swap = function (string $viewId, string $target, array $settings) use ($etm, $apply, &$changes): void {
  $view = $etm->getStorage('view')->load($viewId);
  if (!$view) {
    printf("   %-40s ** no such view — skipped **\n", $viewId);
    return;
  }
  $display = $view->get('display');
  $touched = FALSE;
  foreach ($display as $did => &$d) {
    $fields = $d['display_options']['fields'] ?? [];
    foreach ($fields as $key => $def) {
      if (strpos($key, 'description__value') !== 0 && $key !== 'description') {
        continue;
      }
      // Rebuild the field list in place so column order is preserved.
      $rebuilt = [];
      foreach ($fields as $k => $v) {
        if ($k === $key) {
          $rebuilt[$target] = [
            'id' => $target,
            'table' => 'taxonomy_term__' . $target,
            'field' => $target,
            'plugin_id' => 'field',
            'relationship' => $v['relationship'] ?? 'none',
            'label' => $v['label'] ?? '',
            'exclude' => $v['exclude'] ?? FALSE,
            'type' => 'text_default',
            'settings' => $settings,
            'entity_type' => 'taxonomy_term',
            'entity_field' => $target,
          ] + ($v['element_type'] ?? NULL ? ['element_type' => $v['element_type']] : [])
            + ($v['element_class'] ?? NULL ? ['element_class' => $v['element_class']] : []);
        }
        else {
          $rebuilt[$k] = $v;
        }
      }
      $d['display_options']['fields'] = $rebuilt;
      // Keep the table style's column map in step, or the column vanishes.
      if (isset($d['display_options']['style']['options']['columns'][$key])) {
        $cols = [];
        foreach ($d['display_options']['style']['options']['columns'] as $ck => $cv) {
          $cols[$ck === $key ? $target : $ck] = $cv === $key ? $target : $cv;
        }
        $d['display_options']['style']['options']['columns'] = $cols;
      }
      if (isset($d['display_options']['style']['options']['info'][$key])) {
        $info = [];
        foreach ($d['display_options']['style']['options']['info'] as $ik => $iv) {
          $info[$ik === $key ? $target : $ik] = $iv;
        }
        $d['display_options']['style']['options']['info'] = $info;
      }
      printf("   %-40s %-14s %s -> %s\n", $viewId, $did, $key, $target);
      $touched = TRUE;
      $changes++;
      break;
    }
  }
  unset($d);
  if ($touched && $apply) {
    // Saved as an ENTITY so routes and caches rebuild.
    $view->set('display', $display)->save();
  }
};

print "\nB. admin + landing views -> field_public_description (pages look unchanged)\n";
foreach (['admin_material_plant_characteristics', 'admin_hardiness_zones', 'land_growth_zone'] as $v) {
  $swap($v, 'field_public_description', []);
}

print "\nC. the nine characteristic list views -> field_short_description (no trim; empty renders nothing)\n";
$nine = [
  'char_cat_aesthetic_features', 'char_cat_environmental_tolerance', 'char_cat_growth_habit',
  'char_cat_maintenance_behavior', 'char_cat_origin', 'char_cat_seasonal_interest',
  'char_cat_special_uses', 'char_cat_wildlife_interaction', 'plant_characteristic_children',
];
foreach ($nine as $v) {
  $swap($v, 'field_short_description', []);
}

printf("\n%d change(s)%s\n", $changes, $apply ? ' applied' : ' pending');
if (!$apply) {
  print "Nothing written. Re-run with BOS_REPOINT_APPLY=1 to apply.\n";
}
