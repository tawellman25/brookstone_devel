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
 *
 * NOT mapped (left null, by design): 710 Atmospheric Vacuum Breaker (AVB — not
 * one of the four types, and AVB != SVB), 810 Dual Check Valve (residential
 * dual check, not a testable DCVA), and every part / repair kit. No products
 * map to SVB (1893).
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
  'Reduced Pressure Assembly (RPA)' => 1892, // RP
  'Pressure Vacuum Breaker (PVB)'   => 1891, // PVB
  'Double Check Assembly (DCA)'     => 1890, // DCVA
];

$etm = \Drupal::entityTypeManager();
$ids = $etm->getStorage('material')->getQuery()
  ->accessCheck(FALSE)->condition('type', 'backflow')->execute();

$counts = [1892 => 0, 1891 => 0, 1890 => 0];
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
  $counts[$tid]++;
  $filled++;
  if ($APPLY) {
    $m->set('field_backflow_type', $tid)->save();
  }
}

print "\n";
print "RP (1892):   {$counts[1892]}\n";
print "PVB (1891):  {$counts[1891]}\n";
print "DCVA (1890): {$counts[1890]}\n";
print "would fill / filled: $filled | already set (skipped): $skipped_set | left null (parts/AVB/810): $left_null\n";
print $APPLY ? "APPLIED.\n" : "DRY RUN — re-run with BOS_APPLY=1 to write.\n";
