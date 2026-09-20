<?php

/**
 * County "Summary" field for the state-page cards.
 *
 * Chat wants the card line to differ from the full County Description, so add a
 * dedicated field_county_summary (short), put it on the county form, swap the
 * state_counties card to use it (IN-PLACE — no view recreate), and seed the
 * Delta/Montrose lines if empty.
 *
 * Idempotent; entity-API, no cim. Run per env:
 *   drush php:script web/scripts/setup_county_summary.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\views\Entity\View;

$out = [];

// 1) field_county_summary storage + instance.
if (!FieldStorageConfig::loadByName('county', 'field_county_summary')) {
  FieldStorageConfig::create([
    'field_name' => 'field_county_summary',
    'entity_type' => 'county',
    'type' => 'string_long',
    'cardinality' => 1,
  ])->save();
  $out[] = 'storage county.field_county_summary created';
}
if (!FieldConfig::loadByName('county', 'county', 'field_county_summary')) {
  FieldConfig::create([
    'field_name' => 'field_county_summary',
    'entity_type' => 'county',
    'bundle' => 'county',
    'label' => 'Summary',
    'description' => 'Short line shown on the "Counties We Serve" cards — keep it to a sentence. Different from the full County Description.',
  ])->save();
  $out[] = 'field_county_summary added to county';
}
$fd = \Drupal::service('entity_display.repository')->getFormDisplay('county', 'county', 'default');
if (!$fd->getComponent('field_county_summary')) {
  $fd->setComponent('field_county_summary', ['type' => 'string_textarea', 'weight' => 3, 'region' => 'content', 'settings' => ['rows' => 2]])->save();
  $out[] = 'field_county_summary added to the county form';
}

// 2) swap the card character line to the summary field (in-place; keep the rest).
$view = View::load('state_counties');
if ($view) {
  $d = $view->get('display');
  $fields = $d['default']['display_options']['fields'] ?? [];
  $summary = [
    'id' => 'field_county_summary', 'table' => 'county__field_county_summary', 'field' => 'field_county_summary',
    'relationship' => 'none', 'plugin_id' => 'field', 'label' => '', 'exclude' => FALSE,
    'type' => 'basic_string', 'settings' => [],
  ];
  $order = ['field_banner_image', 'title', 'field_county_summary', 'id', 'view', 'edit_county'];
  $new = [];
  foreach ($order as $k) {
    $new[$k] = ($k === 'field_county_summary') ? $summary : ($fields[$k] ?? NULL);
    if ($new[$k] === NULL) {
      unset($new[$k]);
    }
  }
  $d['default']['display_options']['fields'] = $new;
  $view->set('display', $d)->save();
  $out[] = 'state_counties: card line -> field_county_summary (description dropped from card)';
}

// 3) seed Delta / Montrose summaries if empty (office can edit).
$SEED = [
  'Delta County' => 'Three valleys — Surface Creek off the Grand Mesa, the North Fork, and the bottom of the Uncompahgre.',
  'Montrose County' => 'The Uncompahgre valley, on Gunnison Tunnel water.',
];
$cs = \Drupal::entityTypeManager()->getStorage('county');
foreach ($SEED as $name => $line) {
  $hits = $cs->loadByProperties(['title' => $name]);
  if ($hits) {
    $c = reset($hits);
    if ($c->hasField('field_county_summary') && $c->get('field_county_summary')->isEmpty()) {
      $c->set('field_county_summary', $line)->save();
      $out[] = "seeded summary on $name";
    }
  }
}

print implode("\n", $out) . "\nDONE.\n";
