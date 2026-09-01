<?php

/**
 * @file
 * Reversible test for equipment_labels: (1) an equipment_types term created with
 * only `name` keeps its name; (2) a power_tools equipment with make/model/type
 * and no title auto-builds a title. Creates then deletes test records.
 * Run: ddev drush php:script web/scripts/test_equipment_labels.php
 */

$termStorage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$equipStorage = \Drupal::entityTypeManager()->getStorage('equipment');

// 1) Term with only name set.
$term = $termStorage->create([
  'vid' => 'equipment_types',
  'name' => 'ZZ Test Cut-Off Saw',
]);
$term->save();
$reloaded = $termStorage->loadUnchanged($term->id());
$name = $reloaded->label();
$common = $reloaded->hasField('field_common_name') ? $reloaded->get('field_common_name')->value : '(no field)';
print "TERM #{$term->id()} name=\"{$name}\" | field_common_name=\"{$common}\"\n";
print '  term name preserved: ' . ($name === 'ZZ Test Cut-Off Saw' ? "✓\n" : "✗\n");

// 2) power_tools equipment, make + model + type, no title.
$equip = $equipStorage->create([
  'type' => 'power_tools',
  'field_equipment_make' => 'Husqvarna',
  'field_model' => 'K770-TEST',
  'field_equipment_type' => ['target_id' => $term->id()],
]);
$equip->save();
$eReloaded = $equipStorage->loadUnchanged($equip->id());
$title = $eReloaded->label();
print "EQUIP #{$equip->id()} title=\"{$title}\"\n";
print '  title auto-built: ' . ($title !== '' && str_contains($title, 'Husqvarna') && str_contains($title, 'K770-TEST') ? "✓\n" : "✗\n");

// 3) Clearing the title + re-saving regenerates.
$eReloaded->set('title', '');
$eReloaded->save();
$title2 = $equipStorage->loadUnchanged($equip->id())->label();
print "  after clear+save title=\"{$title2}\" — regenerates: " . ($title2 !== '' ? "✓\n" : "✗\n");

// Cleanup.
$equip->delete();
$term->delete();
print "cleaned up test records\nDONE\n";
