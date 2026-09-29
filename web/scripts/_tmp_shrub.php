<?php
$ms = \Drupal::entityTypeManager()->getStorage('material');
$fm = \Drupal::service('entity_field.manager');
$am = \Drupal::service('path_alias.manager');

print "=== A. growth-habit / evergreen field on shrubs? ===\n";
$hit = [];
foreach ($fm->getFieldDefinitions('material', 'shrubs') as $n => $d) {
  if (preg_match('/habit|evergreen|deciduous|origin|native|sun|water|height|width|mature|alternate|synonym/i', $n)) {
    $hit[] = $n . ' (' . $d->getType() . ')';
  }
}
print '  ' . (implode("\n  ", $hit) ?: 'none') . "\n";

print "\n=== B. all shrub items ===\n";
$ids = $ms->getQuery()->accessCheck(FALSE)->condition('type', 'shrubs')->sort('title')->execute();
$n = 0; $vines = []; $slash = [];
foreach ($ms->loadMultiple($ids) as $m) {
  $t = $m->label();
  $chars = [];
  foreach ($m->get('field_plant_characteristics')->referencedEntities() as $c) { $chars[] = $c->label(); }
  $ev = in_array('Evergreen', $chars, TRUE) ? 'EVER' : '';
  printf("%3d. %-36s %-5s %s\n", ++$n, $t, $ev, implode(', ', array_slice($chars, 0, 4)));
  if (preg_match('/ivy|clematis|climbing|grape|creeper|wisteria|trumpet|silver lace|vine/i', $t)) { $vines[] = $t; }
  if (str_contains($t, '/') || str_contains($t, '&')) { $slash[] = $t; }
}
printf("\n  total shrubs: %d\n", $n);
print "\n  vine-looking: " . implode(' | ', $vines) . "\n";
print "\n  slash/ampersand names (" . count($slash) . "): " . implode(' | ', $slash) . "\n";

print "\n=== C. characteristic population across shrubs (filter viability) ===\n";
$db = \Drupal::database();
$q = $db->query("SELECT t.name, COUNT(*) c FROM {material__field_plant_characteristics} f
  JOIN {material_field_data} m ON m.id = f.entity_id AND m.type = 'shrubs'
  JOIN {taxonomy_term_field_data} t ON t.tid = f.field_plant_characteristics_target_id
  GROUP BY t.name ORDER BY c DESC");
foreach ($q as $r) { printf("  %-26s %d\n", $r->name, $r->c); }
