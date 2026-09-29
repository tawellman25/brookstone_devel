<?php

declare(strict_types=1);

/**
 * Stop `material_type_items` rendering a heading over "No materials found".
 *
 * That view lists a category's items by BUNDLE, via the token
 * `[term:field_material_bundle:value]`. A category with no bundle — every
 * subcategory, since there is no `junipers` or `fruit_trees` bundle — resolves
 * the token to nothing, so the page ends with a stray "<h4> Materials</h4>"
 * (note the leading space where the bundle name should be) above the line
 * "No materials found for this type."
 *
 * It was always wrong; it only became visible now that subcategory pages carry a
 * real list above it. It also affects /material/plants, whose bundle is real but
 * holds zero records.
 *
 * Fix matches what `material_characteristic_items` and
 * `material_subcategory_items` already do: the header renders only when there
 * are results (`empty: FALSE`), and there is no empty-area message. A category
 * with nothing to list now renders nothing at all.
 *
 * Idempotent. Run per env (the view is not cim-managed).
 *   drush php:script web/scripts/fix_material_type_items_empty.php
 */

use Drupal\views\Entity\View;

$view = View::load('material_type_items');
if (!$view) {
  print "view material_type_items not found — nothing to do.\n";
  return;
}

$display = $view->get('display');
$o = &$display['default']['display_options'];

$changed = [];

if (($o['header']['area']['empty'] ?? NULL) !== FALSE) {
  $o['header']['area']['empty'] = FALSE;
  $changed[] = 'header now renders only when there are results';
}

if (!empty($o['empty'])) {
  $text = strip_tags((string) ($o['empty']['area']['content']['value'] ?? ''));
  $o['empty'] = [];
  $changed[] = 'removed the empty-area message (' . trim($text) . ')';
}

unset($o);

if (!$changed) {
  print "  already correct — nothing to do.\n";
  return;
}

$view->set('display', $display);
$view->save();

foreach ($changed as $c) {
  print '  ' . $c . "\n";
}
print "done.\n";
