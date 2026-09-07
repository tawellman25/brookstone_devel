<?php

/**
 * @file
 * Make the properties.hoa bundle a SUPERSET of properties.property: clone every
 * property field instance (and its form/view display component) onto hoa that
 * isn't already there, keeping the HOA-specific extras (boundary, public_desc,
 * service dates). This gives HOAs full property capability (contacts, zipcode,
 * WO notes, flags, gate code, maps, …) and makes a property→hoa conversion
 * lossless. Field storages are shared on the `properties` entity type, so this
 * only adds instances — no new storage.
 *
 * Idempotent. Run: drush php:script web/scripts/add_all_property_fields_to_hoa.php
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

$ENTITY = 'properties';
$SRC = 'property';
$DST = 'hoa';
$efm = \Drupal::service('entity_field.manager');
$out = [];

$srcFields = $efm->getFieldDefinitions($ENTITY, $SRC);
$dstFields = $efm->getFieldDefinitions($ENTITY, $DST);

$srcForm = EntityFormDisplay::load("$ENTITY.$SRC.default");
$dstForm = EntityFormDisplay::load("$ENTITY.$DST.default");
$srcView = EntityViewDisplay::load("$ENTITY.$SRC.default");
$dstView = EntityViewDisplay::load("$ENTITY.$DST.default");

$added = 0;
$baseWeight = 20; // residential fields sort below the HOA-specific ones (0–8).
$i = 0;
foreach ($srcFields as $name => $def) {
  if (strpos($name, 'field_') !== 0) { continue; }
  if (isset($dstFields[$name])) { continue; } // already on hoa
  if (!($def instanceof \Drupal\field\FieldConfigInterface)) { continue; }

  // Clone the field instance onto hoa.
  $arr = $def->toArray();
  unset($arr['uuid'], $arr['id'], $arr['_core']);
  $arr['bundle'] = $DST;
  $arr['id'] = "$ENTITY.$DST.$name";
  // Fix dependency on the source bundle → destination bundle.
  if (!empty($arr['dependencies']['config'])) {
    $arr['dependencies']['config'] = array_values(array_map(
      fn($d) => $d === "eck.eck_type.$ENTITY.$SRC" ? "eck.eck_type.$ENTITY.$DST" : $d,
      $arr['dependencies']['config']
    ));
    if (!in_array("eck.eck_type.$ENTITY.$DST", $arr['dependencies']['config'], TRUE)) {
      $arr['dependencies']['config'][] = "eck.eck_type.$ENTITY.$DST";
    }
  }
  FieldConfig::create($arr)->save();
  $added++;

  // Copy form display component (keep its widget; re-weight below HOA extras).
  if ($srcForm && $dstForm) {
    $comp = $srcForm->getComponent($name);
    if ($comp) {
      $comp['weight'] = $baseWeight + $i;
      $dstForm->setComponent($name, $comp);
    }
  }
  // Copy view display component.
  if ($srcView && $dstView) {
    $vcomp = $srcView->getComponent($name);
    if ($vcomp) {
      $vcomp['weight'] = $baseWeight + $i;
      $dstView->setComponent($name, $vcomp);
    }
    elseif ($srcView && in_array($name, array_keys($srcView->get('hidden') ?? []), TRUE)) {
      $dstView->removeComponent($name);
    }
  }
  $i++;
}
if ($dstForm) { $dstForm->save(); }
if ($dstView) { $dstView->save(); }

$out[] = "cloned $added property field instances onto properties.$DST (+ display components)";
$now = array_keys(array_filter(
  array_keys($efm->getFieldDefinitions($ENTITY, $DST)),
  fn($f) => strpos($f, 'field_') === 0
));
// Re-fetch clean count.
$hoaCount = count(array_filter(array_keys(\Drupal::service('entity_field.manager')->getFieldDefinitions($ENTITY, $DST)), fn($f) => strpos($f, 'field_') === 0));
$out[] = "properties.$DST now has $hoaCount field instances";
print implode("\n", $out) . "\nDONE.\n";
