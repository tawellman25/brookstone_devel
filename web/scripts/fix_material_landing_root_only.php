<?php

declare(strict_types=1);

/**
 * Limit the /material landing view (material_types_landing) to TOP-LEVEL
 * material categories.
 *
 * The view listed every material_types term that backs a bundle, flat, with no
 * parent/depth filter — so a child term that also backs a bundle (Annuals, under
 * Plants) leaked onto /material as if it were a top-level category, while also
 * (correctly) appearing under /material/plants. Fix: only show ROOT terms
 * (taxonomy parent = 0). Children stay under their parent's page.
 *
 * Idempotent; edits active config via the View entity API (the view is drifted
 * from sync — NOT a cim). Run per env.
 *
 *   drush php:script web/scripts/fix_material_landing_root_only.php
 */

$view = \Drupal::entityTypeManager()->getStorage('view')->load('material_types_landing');
if (!$view) {
  print "view material_types_landing not found.\n";
  return;
}

$display = $view->get('display');
$filters = &$display['default']['display_options']['filters'];
if (isset($filters['parent_target_id'])) {
  print "root-only filter already present.\n";
  return;
}
$filters['parent_target_id'] = [
  'id' => 'parent_target_id',
  'table' => 'taxonomy_term__parent',
  'field' => 'parent_target_id',
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'operator' => '=',
  'value' => ['min' => '', 'max' => '', 'value' => '0'],
  'group' => 1,
  'exposed' => FALSE,
  'entity_type' => 'taxonomy_term',
  'entity_field' => 'parent',
  'plugin_id' => 'numeric',
];
$view->set('display', $display);
$view->save();
print "Added root-only filter (parent_target_id = 0) to material_types_landing.\n";
print "DONE.\n";
