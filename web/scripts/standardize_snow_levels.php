<?php

/**
 * @file
 * Standardize the snow_levels vocabulary to the 4 contract plow tiers + Icy, and
 * migrate historical wo_tasks_list.field_snow_level references. Label-driven and
 * idempotent (tids differ dev vs live). Run AFTER setup_snow_depth_tier_field.php.
 *
 * Final terms (tier key on field_snow_depth_tier):
 *   0-2"  (0_2)   2-4"  (2_4)   4-6"  (4_6)   6"+  (6_plus)   Icy Conditions (icy)
 *
 * Migration of the old terms (approved map):
 *   Less than 1" -> 0-2" (repurpose in place)
 *   2"           -> 0-2" (repoint refs, then delete)
 *   1" - 3"      -> 2-4" (repurpose)
 *   4"           -> 4-6" (repurpose)
 *   Over 6"      -> 6"+  (repurpose)
 *   Icy Conditions -> Icy (name unchanged — keeps the icy detection in wo_sign_off)
 *
 * Run: ddev drush php:script web/scripts/standardize_snow_levels.php
 */

$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$by_label = function (string $label) use ($ts) {
  $t = $ts->loadByProperties(['vid' => 'snow_levels', 'name' => $label]);
  return $t ? reset($t) : NULL;
};

// old label => [new label, tier key]. Repurpose in place (preserves refs).
$repurpose = [
  'Less than 1"'   => ['0-2"', '0_2'],
  '1" - 3"'        => ['2-4"', '2_4'],
  '4"'             => ['4-6"', '4_6'],
  'Over 6"'        => ['6"+', '6_plus'],
  'Icy Conditions' => ['Icy Conditions', 'icy'],
];
foreach ($repurpose as $old => [$new, $tier]) {
  // Idempotent: after a first run the old label is gone, find by new label.
  $t = $by_label($old) ?: $by_label($new);
  if (!$t) {
    print "MISSING (skipped): $old\n";
    continue;
  }
  $t->setName($new);
  $t->set('field_snow_depth_tier', $tier);
  $t->save();
  print "set '{$new}' tier={$tier} (tid {$t->id()})\n";
}

// Merge '2"' into the 0-2" term, then delete it.
$zero_two = $by_label('0-2"');
$two = $by_label('2"');
if ($two && $zero_two) {
  $db = \Drupal::database();
  foreach (['wo_tasks_list__field_snow_level', 'wo_tasks_list_revision__field_snow_level'] as $tbl) {
    if ($db->schema()->tableExists($tbl)) {
      $n = $db->update($tbl)
        ->fields(['field_snow_level_target_id' => $zero_two->id()])
        ->condition('field_snow_level_target_id', $two->id())
        ->execute();
      print "repointed {$n} rows in {$tbl}: '2\"' -> 0-2\"\n";
    }
  }
  $two->delete();
  print "deleted '2\"' term (tid {$two->id()})\n";
}
elseif (!$two) {
  print "'2\"' already merged\n";
}

// Report orphaned refs (a deleted term still referenced by history) — left as-is;
// they fall back to the flat per-push rate in billing.
$db = \Drupal::database();
$orphans = 0;
$rows = $db->query("SELECT DISTINCT field_snow_level_target_id tid FROM wo_tasks_list__field_snow_level");
foreach ($rows as $r) {
  if ($r->tid && !$ts->load($r->tid)) {
    $c = $db->query("SELECT COUNT(*) FROM wo_tasks_list__field_snow_level WHERE field_snow_level_target_id = :t", [':t' => $r->tid])->fetchField();
    print "orphan snow_level tid {$r->tid}: {$c} historical refs (left as-is; billing falls back to per-push rate)\n";
    $orphans += $c;
  }
}
print "orphaned refs total: {$orphans}\n";

print "\nfinal snow_levels terms:\n";
foreach ($ts->loadByProperties(['vid' => 'snow_levels']) as $t) {
  $tier = ($t->hasField('field_snow_depth_tier') && !$t->get('field_snow_depth_tier')->isEmpty()) ? $t->get('field_snow_depth_tier')->value : '-';
  print "  {$t->id()}: '{$t->label()}' tier={$tier}\n";
}
print "DONE\n";
