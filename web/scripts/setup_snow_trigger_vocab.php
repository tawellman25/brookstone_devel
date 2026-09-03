<?php

/**
 * @file
 * Snow Trigger as a taxonomy vocabulary (so each option has a description page
 * — a client-facing reference link on a future online form + crew training on
 * the correct measurement). Creates the vocab + terms and switches
 * contracts.snow_removal field_snow_trigger from decimal to a term reference.
 * Idempotent. Safe field swap (only ~3 snow contracts, trigger unused).
 * Run: ddev drush php:script web/scripts/setup_snow_trigger_vocab.php
 */

use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

// 1. Vocabulary.
if (!Vocabulary::load('snow_trigger')) {
  Vocabulary::create([
    'vid' => 'snow_trigger',
    'name' => 'Snow Trigger',
    'description' => 'Minimum snow depth at which automatic snow removal is performed for a snow contract. Each term describes the measurement for clients + crews.',
  ])->save();
  print "vocab snow_trigger created\n";
}

// 2. Terms (find-or-create by name) with starter descriptions (refine later).
$terms = [
  '1" or more' => 'Snow removal is performed once accumulation reaches 1 inch or more. Depth is measured at the property at the time service begins, typically after the storm ends.',
  '2" or more' => 'Snow removal is performed once accumulation reaches 2 inches or more. Depth is measured at the property at the time service begins, typically after the storm ends.',
  '4" or more' => 'Snow removal is performed once accumulation reaches 4 inches or more. Depth is measured at the property at the time service begins, typically after the storm ends.',
  'Other' => 'A custom trigger depth agreed with the customer. Record the agreed minimum depth in the property-specific instructions.',
];
$term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
$weight = 0;
foreach ($terms as $name => $desc) {
  $existing = $term_storage->loadByProperties(['vid' => 'snow_trigger', 'name' => $name]);
  if (!$existing) {
    Term::create([
      'vid' => 'snow_trigger',
      'name' => $name,
      'weight' => $weight,
      'description' => ['value' => $desc, 'format' => 'basic_html'],
    ])->save();
    print "term '$name' created\n";
  }
  $weight++;
}

// 3. Swap field_snow_trigger: decimal -> entity_reference(taxonomy_term).
// Two-phase (a name can't be reliably deleted + recreated in one request):
// first run deletes the decimal field and returns; re-run creates the reference.
$storage = FieldStorageConfig::loadByName('contracts', 'field_snow_trigger');
if ($storage && $storage->getType() === 'decimal') {
  $inst = FieldConfig::loadByName('contracts', 'snow_removal', 'field_snow_trigger');
  if ($inst) {
    $inst->delete();
  }
  $storage->delete();
  try {
    field_purge_batch(500);
  }
  catch (\Throwable $e) {
    // Purge finishes on the re-run / cron; safe to ignore here.
  }
  print "old decimal field_snow_trigger deleted — RE-RUN this script to create the reference field\n";
  return;
}
if (!FieldStorageConfig::loadByName('contracts', 'field_snow_trigger')) {
  FieldStorageConfig::create([
    'field_name' => 'field_snow_trigger',
    'entity_type' => 'contracts',
    'type' => 'entity_reference',
    'settings' => ['target_type' => 'taxonomy_term'],
    'cardinality' => 1,
  ])->save();
  print "new entity_reference storage created\n";
}
if (!FieldConfig::loadByName('contracts', 'snow_removal', 'field_snow_trigger')) {
  FieldConfig::create([
    'field_name' => 'field_snow_trigger',
    'entity_type' => 'contracts',
    'bundle' => 'snow_removal',
    'label' => 'Snow Trigger',
    'description' => 'Minimum depth that triggers automatic service.',
    'settings' => [
      'handler' => 'default:taxonomy_term',
      'handler_settings' => [
        'target_bundles' => ['snow_trigger' => 'snow_trigger'],
        'sort' => ['field' => 'weight', 'direction' => 'ASC'],
        'auto_create' => FALSE,
      ],
    ],
  ])->save();
  print "new instance created\n";
}
foreach (['default', 'admin'] as $fd_id) {
  $fd = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load("contracts.snow_removal.$fd_id");
  if ($fd) {
    $fd->setComponent('field_snow_trigger', ['type' => 'options_buttons', 'weight' => 32])->save();
  }
}
$vd = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('contracts.snow_removal.default');
if ($vd) {
  $vd->setComponent('field_snow_trigger', ['type' => 'entity_reference_label', 'weight' => 32, 'label' => 'inline'])->save();
}
print "DONE\n";
