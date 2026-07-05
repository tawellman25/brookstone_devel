<?php

/**
 * Fix the lighting service→bundle mismatch. The "Landscape Lighting" (1647) and
 * "Exterior Lighting" (1648) service terms had field_service_bundle pointing at
 * non-existent bundles (lighting_landscape / lighting_exterior); the real
 * work_order bundles are landscape_lighting / exterior_lighting. This violated
 * the WO invariant (work_order.bundle == field_service.field_service_bundle) and
 * broke voice-creating a lighting WO. Idempotent; guarded to only set a bundle
 * that actually exists. Run: drush php:script <this>.
 */

$map = [
  1647 => 'landscape_lighting',
  1648 => 'exterior_lighting',
];

$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$bundles = array_keys(\Drupal::service('entity_type.bundle.info')->getBundleInfo('work_order'));

foreach ($map as $tid => $bundle) {
  $t = $ts->load($tid);
  if (!$t || $t->bundle() !== 'services') {
    print "  term $tid: not a services term — skip\n";
    continue;
  }
  if (!in_array($bundle, $bundles, TRUE)) {
    print "  SKIP: target bundle '$bundle' does not exist on work_order\n";
    continue;
  }
  $before = (string) $t->get('field_service_bundle')->value;
  if ($before !== $bundle) {
    $t->set('field_service_bundle', $bundle)->save();
    print "  term $tid \"" . $t->label() . "\": '$before' -> '$bundle'\n";
  }
  else {
    print "  term $tid \"" . $t->label() . "\": already '$bundle'\n";
  }
}
