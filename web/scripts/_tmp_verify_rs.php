<?php
$ts = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$ms = \Drupal::entityTypeManager()->getStorage('material');
$db = \Drupal::database();
$am = \Drupal::service('path_alias.manager');
$count = function ($name) use ($ts, $db) {
  foreach ($ts->loadByProperties(['vid' => 'material_types', 'name' => $name]) as $t) {
    return (int) $db->query('SELECT COUNT(*) FROM {material__field_material_category} WHERE field_material_category_target_id = :t', [':t' => $t->id()])->fetchField();
  }
  return NULL;
};
print "category counts:\n";
foreach (['Pine','Spruce','Fir','Juniper','Arborvitae','Evergreens','Junipers','Shade','Ornamental','Fruit Trees','Evergreen Shrubs','Deciduous Shrubs','Roses','Vines','Groundcovers'] as $c) {
  $n = $count($c);
  printf("  %-18s %s\n", $c, $n === NULL ? 'TERM GONE' : $n);
}
print "\nspot checks:\n";
foreach (['Douglas Fir','Yew','Mugo Pine','Creeping Juniper','Willow','Pyracantha / Firethorn','English Ivy'] as $t) {
  foreach ($ms->loadByProperties(['title' => $t]) as $m) {
    $c = $m->get('field_material_category')->entity;
    printf("  %-24s %-8s %-18s genus=%-14s %s\n", $t, $m->bundle(), $c ? $c->label() : '(none)',
      $m->hasField('field_plant_genus') ? (string) $m->get('field_plant_genus')->value : '', $am->getAliasByPath('/material/' . $m->id()));
  }
}
