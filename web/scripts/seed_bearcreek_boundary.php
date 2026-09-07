<?php

/**
 * @file
 * PILOT: set a boundary polygon + Discounted flag on the Bear Creek HOA (144252)
 * so the GPS membership scan can auto-flag member homes. Rough rectangular
 * boundary enclosing the known Bear Creek cluster — the real shape gets drawn
 * with the Leaflet widget later. Suppresses the auto-sync so the scan can be
 * previewed via `drush bos:hoa:sync-members --hoa=144252` before applying.
 *
 * Reversible: uncheck Discounted on 144252 (or clear field_boundary) + re-run the
 * scan to release members.
 */

$id = 144252;
$wkt = 'POLYGON((-107.8670 38.5000, -107.8615 38.5000, -107.8615 38.5045, -107.8670 38.5045, -107.8670 38.5000))';

$p = \Drupal::entityTypeManager()->getStorage('properties')->load($id);
if (!$p || $p->bundle() !== 'hoa') { print "144252 not an HOA — abort\n"; return; }
$p->set('field_hoa_contracted', TRUE);
$p->set('field_boundary', ['value' => $wkt]);
$p->_bos_hoa_scan = TRUE; // skip auto-sync so we can preview via the command
$p->save();

$b = \Drupal::database()->query(
  "SELECT field_boundary_left l, field_boundary_right r, field_boundary_top t, field_boundary_bottom bo
   FROM {properties__field_boundary} WHERE entity_id = :id", [':id' => $id]
)->fetchAssoc();
print "Bear Creek HOA $id: Discounted=TRUE, boundary set. bbox l={$b['l']} r={$b['r']} t={$b['t']} b={$b['bo']}\n";
print "Now: drush bos:hoa:sync-members --hoa=$id  (dry run), then --apply\n";
