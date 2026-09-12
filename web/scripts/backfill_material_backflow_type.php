<?php

/**
 * Backfill material/backflow -> field_backflow_type by product name.
 *
 * DEVICE (assembly) products get their backflow type; parts / repair kits are
 * left null. Matching is by an unambiguous phrase in field_name so it is
 * env-stable and idempotent (only fills when the field is empty).
 *
 *   RP   (1892): "Reduced Pressure Assembly (RPA)"   -> 825Y + 825YA RPAs
 *   PVB  (1891): "Pressure Vacuum Breaker (PVB)"      -> 765 PVB assemblies
 *   DCVA (1890): "Double Check Assembly (DCA)"        -> 805 DCAs
 *   AVB  (1949): "Atmospheric Vacuum Breaker (AVB)"   -> 710 AVBs (non-testable)
 *   DuC  (1950): "Dual Check Valve"                   -> 810 dual checks (non-testable)
 *
 * NOT mapped (left null): every part / repair kit. No products map to SVB (1893).
 * (AVB + DuC terms were added later — tids 1949/1950 — so those products now map.)
 *
 * Dry-run by default; set BOS_APPLY=1 to write.
 *
 *   drush php:script web/scripts/backfill_material_backflow_type.php            (dry-run)
 *   BOS_APPLY=1 drush php:script web/scripts/backfill_material_backflow_type.php (apply)
 */

$APPLY = getenv('BOS_APPLY') === '1';
print $APPLY ? "=== APPLY ===\n" : "=== DRY RUN (set BOS_APPLY=1 to write) ===\n";

// Phrase => type term id. Longest / most specific phrases; each matches only the
// assembly products, never the parts (parts are "765 PVB Bonnet", "805 and 825
// Rubber Part Kit", etc. — none contain these full assembly phrases).
$MAP = [
  'Reduced Pressure Assembly (RPA)'    => 1892, // RP
  'Pressure Vacuum Breaker (PVB)'      => 1891, // PVB
  'Double Check Assembly (DCA)'        => 1890, // DCVA
  'Atmospheric Vacuum Breaker (AVB)'   => 1949, // AVB (non-testable)
  'Dual Check Valve'                   => 1950, // DuC (non-testable)
];

$etm = \Drupal::entityTypeManager();
$ids = $etm->getStorage('material')->getQuery()
  ->accessCheck(FALSE)->condition('type', 'backflow')->execute();

$counts = [];
$filled = $skipped_set = $left_null = 0;

foreach ($etm->getStorage('material')->loadMultiple($ids) as $m) {
  if (!$m->hasField('field_backflow_type')) {
    print "! field_backflow_type missing — run setup_material_backflow_type.php first\n";
    return;
  }
  $name = (string) ($m->get('field_name')->value ?? $m->label());
  $tid = NULL;
  foreach ($MAP as $phrase => $type_tid) {
    if (mb_strpos($name, $phrase) !== FALSE) {
      $tid = $type_tid;
      break;
    }
  }
  if ($tid === NULL) {
    $left_null++;
    continue;
  }
  if (!$m->get('field_backflow_type')->isEmpty()) {
    $skipped_set++;
    continue;
  }
  print sprintf("  %s  %-46s -> %d\n", $m->id(), mb_substr($name, 0, 46), $tid);
  $counts[$tid] = ($counts[$tid] ?? 0) + 1;
  $filled++;
  if ($APPLY) {
    $m->set('field_backflow_type', $tid)->save();
  }
}

print "\n";
$labels = [1892 => 'RP', 1891 => 'PVB', 1890 => 'DCVA', 1949 => 'AVB', 1950 => 'DuC'];
foreach ($labels as $tid => $lbl) {
  if (!empty($counts[$tid])) {
    print sprintf("%-5s (%d): %d\n", $lbl, $tid, $counts[$tid]);
  }
}
print "would fill / filled: $filled | already set (skipped): $skipped_set | left null (parts): $left_null\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN — re-run with BOS_APPLY=1 to write.\n";
