<?php

/**
 * Two idempotent fixes for the property photo galleries (entity-API — not cim):
 *   1. Add css_class 'bos-photo-gallery' to every display of the property_photos
 *      and property_photos_public views (stable hook for the grid CSS).
 *   2. Move the public gallery EVA to the BOTTOM of the property page by raising
 *      its weight in properties.property.default above all field-groups.
 *
 *   drush php:script web/scripts/gallery_position_and_class.php
 */

use Drupal\views\Entity\View;

// ── 1. css_class on both gallery views ──────────────────────────────────────
foreach (['property_photos', 'property_photos_public'] as $vid) {
  $v = View::load($vid);
  if (!$v) { print "  view $vid: NOT FOUND\n"; continue; }
  $display = $v->get('display');
  $changed = FALSE;
  foreach ($display as $dk => &$disp) {
    $do = &$disp['display_options'];
    if (($do['css_class'] ?? '') !== 'bos-photo-gallery') {
      $do['css_class'] = 'bos-photo-gallery';
      $changed = TRUE;
    }
    unset($do);
  }
  unset($disp);
  if ($changed) { $v->set('display', $display); $v->save(); print "  view $vid: css_class set\n"; }
  else { print "  view $vid: css_class already set\n"; }
}

// ── 2. Move the public EVA to the bottom of the property page ────────────────
$COMPONENT = 'property_photos_public_entity_view_1';
$BOTTOM_WEIGHT = 40; // above group_office_admin (30) and christmas EVA (26).
$d = \Drupal::entityTypeManager()->getStorage('entity_view_display')
  ->load('properties.property.default');
if (!$d) {
  print "  display properties.property.default: NOT FOUND\n";
}
else {
  $content = $d->get('content');
  if (!isset($content[$COMPONENT])) {
    print "  EVA $COMPONENT not in content region — checking hidden…\n";
  }
  else {
    $old = $content[$COMPONENT]['weight'] ?? '?';
    if ((int) ($content[$COMPONENT]['weight'] ?? 0) !== $BOTTOM_WEIGHT) {
      $content[$COMPONENT]['weight'] = $BOTTOM_WEIGHT;
      $d->set('content', $content);
      $d->save();
      print "  EVA weight: $old -> $BOTTOM_WEIGHT (moved to bottom)\n";
    }
    else {
      print "  EVA weight already $BOTTOM_WEIGHT\n";
    }
  }
}

print "DONE.\n";
