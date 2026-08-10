<?php

/**
 * Add field_preferred_mow_height to the Mow-list views (teammate_mow_route +
 * admin_mow_crew_route). Both are based on property_lawn_maintenance, so the
 * field is a native column. Idempotent; entity-API edit (views are drifted — not
 * cim). Reports each view's row style so we know if a card template also needs it.
 *
 *   drush php:script web/scripts/add_mow_height_to_views.php
 */

use Drupal\views\Entity\View;

$FIELD = 'field_preferred_mow_height';
$views = ['teammate_mow_route', 'admin_mow_crew_route'];

$handler = [
  'id' => $FIELD,
  'table' => 'property_lawn_maintenance__field_preferred_mow_height',
  'field' => $FIELD,
  'relationship' => 'none',
  'group_type' => 'group',
  'admin_label' => '',
  'entity_type' => 'property_lawn_maintenance',
  'entity_field' => $FIELD,
  'plugin_id' => 'field',
  'type' => 'string',
  'label' => 'Preferred Mow Height',
  'settings' => [],
];

foreach ($views as $vid) {
  $v = View::load($vid);
  if (!$v) {
    print "  $vid: NOT FOUND\n";
    continue;
  }
  $display = $v->get('display');
  $do = &$display['default']['display_options'];
  $style = $do['style']['type'] ?? '?';
  $rowtpl = $do['row']['type'] ?? '?';
  if (isset($do['fields'][$FIELD])) {
    print "  $vid: field already present (style=$style row=$rowtpl)\n";
  }
  else {
    $do['fields'][$FIELD] = $handler;
    $v->set('display', $display);
    $v->save();
    print "  $vid: added $FIELD (style=$style row=$rowtpl)\n";
  }
  unset($do);
}
print "DONE.\n";
